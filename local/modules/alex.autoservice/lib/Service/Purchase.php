<?php

namespace Alex\Autoservice\Service;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Web\Json;

Loc::loadMessages(__FILE__);

/**
 * Заявки на закупку.
 *
 * Два сценария из ТЗ различаются принципиально. Автоматическая заявка
 * создаётся при нулевом остатке, согласования не проходит и сразу
 * попадает в «Выполнено». Ручная заявка идёт через одобрение отдела
 * закупок — этим занимается бизнес-процесс.
 */
class Purchase
{
    private const MODULE_ID = 'alex.autoservice';

    /** Сколько единиц закупается автоматически при нулевом остатке */
    public const AUTO_QUANTITY = 10;

    /** Окно защиты от повторных автозаявок по одной запчасти, часов */
    private const AUTO_GUARD_HOURS = 20;

    /**
     * @throws \RuntimeException
     *
     * @return int
     */
    public static function getEntityTypeId(): int
    {
        $entityTypeId = (int) Option::get(self::MODULE_ID, 'purchase_type_id', 0);

        if (!$entityTypeId) {
            throw new \RuntimeException(Loc::getMessage('ALEX_AUTOSERVICE_PURCHASE_NO_TYPE'));
        }

        return $entityTypeId;
    }

    /**
     * @return int
     */
    public static function getCategoryId(): int
    {
        return (int) Option::get(self::MODULE_ID, 'purchase_category_id', 0);
    }

    /**
     * @throws \RuntimeException
     *
     * @return \Bitrix\Crm\Service\Factory
     */
    public static function getFactory()
    {
        return SmartProcess::getFactory(self::getEntityTypeId());
    }

    /**
     * Полный идентификатор стадии по короткому коду.
     *
     * @param string $code NEW, APPROVAL, SUCCESS, FAIL
     *
     * @return string
     */
    public static function stageId(string $code): string
    {
        $entityId = self::getFactory()->getStagesEntityId(self::getCategoryId());

        return Stages::prefixFromEntityId($entityId) . $code;
    }

    /**
     * Автоматическая заявка при нулевом остатке.
     *
     * По ТЗ: создаётся запрос на 10 единиц, закупщику уходит уведомление,
     * остаток выставляется в 10, заявка сразу переходит в «Выполнено».
     * Одобрения этот сценарий не требует.
     *
     * @param array $part строка из PartsCatalog::getParts()
     *
     * @throws \RuntimeException
     *
     * @return array{ID:int, QUANTITY:int, SKIPPED:bool}
     */
    public static function createAuto(array $part): array
    {
        $productId = (int) $part['ID'];

        if (self::hasRecentAuto($productId)) {
            Log::add([
                'stage'   => 'PURCHASE_AUTO_SKIPPED',
                'product' => $productId,
                'name'    => $part['NAME'],
            ]);

            return ['ID' => 0, 'QUANTITY' => 0, 'SKIPPED' => true];
        }

        $factory = self::getFactory();
        $item    = $factory->createItem();

        $item->setTitle(Loc::getMessage('ALEX_AUTOSERVICE_PURCHASE_AUTO_TITLE', ['#NAME#' => $part['NAME']]));
        $item->set('STAGE_ID', self::stageId('SUCCESS'));
        $item->set('UF_PURCHASE_AUTO', 1);
        $item->set('UF_PURCHASE_PRODUCT', $productId);
        $item->set('UF_PURCHASE_PROCESSED', 1);

        self::addProductRow($item, $part, self::AUTO_QUANTITY);

        $operation = $factory->getAddOperation($item);

        if (method_exists($operation, 'disableCheckAccess')) {
            $operation->disableCheckAccess();
        }

        $result = $operation->launch();

        if (!$result->isSuccess()) {
            throw new \RuntimeException(Loc::getMessage('ALEX_AUTOSERVICE_PURCHASE_AUTO_ERROR', [
                '#NAME#'  => $part['NAME'],
                '#ERROR#' => implode('; ', $result->getErrorMessages()),
            ]));
        }

        PartsCatalog::setQuantity($productId, self::AUTO_QUANTITY);

        $message = Loc::getMessage('ALEX_AUTOSERVICE_PURCHASE_AUTO_NOTIFY', [
            '#NAME#'     => $part['NAME'],
            '#QUANTITY#' => self::AUTO_QUANTITY,
        ]);

        Notifier::toUsers(self::getBuyers(), $message, 'auto-purchase-' . $productId);

        Log::add([
            'stage'    => 'PURCHASE_AUTO_CREATED',
            'item'     => $item->getId(),
            'product'  => $productId,
            'name'     => $part['NAME'],
            'quantity' => self::AUTO_QUANTITY,
        ]);

        return ['ID' => (int) $item->getId(), 'QUANTITY' => self::AUTO_QUANTITY, 'SKIPPED' => false];
    }

    /**
     * Была ли недавно автозаявка по этой запчасти.
     *
     * Защита от повторов: пока остаток не обновится из внешнего сервиса,
     * повторные прогоны синхронизации не должны плодить заявки.
     *
     * @param int $productId
     *
     * @return bool
     */
    public static function hasRecentAuto(int $productId): bool
    {
        $since = (new DateTime())->add('-' . self::AUTO_GUARD_HOURS . ' hours');

        $items = self::getFactory()->getItems([
            'select' => ['ID'],
            'filter' => [
                '=UF_PURCHASE_PRODUCT' => $productId,
                '=UF_PURCHASE_AUTO'    => 1,
                '>=CREATED_TIME'       => $since,
            ],
            'limit'  => 1,
        ]);

        return !empty($items);
    }

    /**
     * Закупщики для уведомлений. Если их нет — начальник отдела,
     * если нет и его — администратор портала.
     *
     * @return int[]
     */
    public static function getBuyers(): array
    {
        $buyers = array_merge(
            Access::getUsersByRole(Access::ROLE_BUYER),
            Access::getUsersByRole(Access::ROLE_PURCH_HEAD)
        );

        return $buyers ?: [1];
    }

    /**
     * Товарная позиция заявки.
     *
     * Способ добавления зависит от сборки, поэтому пробуем современный
     * API элемента, а при его отсутствии — классический CCrmProductRow.
     *
     * @param \Bitrix\Crm\Item $item
     * @param array            $part
     * @param int              $quantity
     *
     * @return bool
     */
    private static function addProductRow($item, array $part, int $quantity): bool
    {
        if (!Loader::includeModule('crm')) {
            return false;
        }

        if (method_exists($item, 'addToProductRows') && class_exists('\Bitrix\Crm\ProductRow')) {
            try {
                $item->addToProductRows(\Bitrix\Crm\ProductRow::createFromArray([
                    'PRODUCT_ID'   => (int) $part['ID'],
                    'PRODUCT_NAME' => (string) $part['NAME'],
                    'PRICE'        => (float) $part['PRICE'],
                    'QUANTITY'     => $quantity,
                ]));

                return true;
            } catch (\Throwable $e) {
                Log::add([
                    'stage' => 'PURCHASE_PRODUCT_ROW_FALLBACK',
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return false;
    }

    /**
     * Ручная заявка сотрудника. Создаётся в стадии «Новая», дальше ею
     * занимается PurchaseFlow: назначает согласующего и ведёт по стадиям.
     *
     * @param array $rows      позиции: [['ID' => productId, 'NAME' => ..., 'PRICE' => ..., 'QUANTITY' => n], ...]
     * @param int   $initiator автор заявки
     * @param string $title
     *
     * @throws \RuntimeException
     *
     * @return int ID заявки
     */
    public static function createManual(array $rows, int $initiator, string $title = ''): int
    {
        $factory = self::getFactory();
        $item    = $factory->createItem();

        $item->setTitle($title !== '' ? $title : Loc::getMessage('ALEX_AUTOSERVICE_PURCHASE_MANUAL_TITLE', [
            '#DATE#' => (new DateTime())->toString(),
        ]));

        $item->set('STAGE_ID', self::stageId('NEW'));
        $item->set('UF_PURCHASE_AUTO', 0);
        $item->set('UF_PURCHASE_INITIATOR', $initiator);
        $item->set('UF_PURCHASE_PROCESSED', 0);

        foreach ($rows as $row) {
            self::addProductRow($item, $row, (int) $row['QUANTITY']);
        }

        $result = $factory->getAddOperation($item)->launch();

        if (!$result->isSuccess()) {
            throw new \RuntimeException(Loc::getMessage('ALEX_AUTOSERVICE_PURCHASE_MANUAL_ERROR', [
                '#ERROR#' => implode('; ', $result->getErrorMessages()),
            ]));
        }

        Log::add(['stage' => 'PURCHASE_MANUAL_CREATED', 'item' => $item->getId(), 'initiator' => $initiator]);

        return (int) $item->getId();
    }
}
