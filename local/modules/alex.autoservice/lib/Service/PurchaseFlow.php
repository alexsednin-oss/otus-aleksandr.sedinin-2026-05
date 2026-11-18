<?php

namespace Alex\Autoservice\Service;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Web\Json;

Loc::loadMessages(__FILE__);

/**
 * Согласование ручных заявок на закупку.
 *
 * Обработка построена как приведение заявок к целевому состоянию, а не
 * как реакция на отдельное действие: каждый проход заново смотрит, что
 * нужно сделать. Поэтому повторный запуск безопасен, пропущенный запуск
 * не теряет заявку, а ускорить реакцию можно откуда угодно — хоть
 * агентом, хоть обработчиком события.
 */
class PurchaseFlow
{
    private const MODULE_ID = 'alex.autoservice';

    public const OPTION_TIMEOUT  = 'approval_timeout_minutes';
    public const DEFAULT_TIMEOUT = 1440;

    /**
     * Один проход обработки.
     *
     * @return string[] отчёт
     */
    public static function process(): array
    {
        $report  = [];
        $factory = Purchase::getFactory();

        foreach (self::findIdsByStage('NEW') as $id) {
            $report[] = self::startApproval($factory->getItem($id));
        }

        foreach (self::findExpiredIds() as $id) {
            $report[] = self::escalate($factory->getItem($id));
        }

        foreach (['SUCCESS', 'FAIL'] as $code) {
            foreach (self::findUnprocessedIds($code) as $id) {
                $report[] = self::finalize($factory->getItem($id), $code);
            }
        }

        if (!$report) {
            $report[] = Loc::getMessage('ALEX_AUTOSERVICE_FLOW_NOTHING');
        }

        Log::add(['stage' => 'PURCHASE_FLOW', 'report' => $report]);

        return $report;
    }

    /**
     * Элементы для изменения берём не из выборки, а по одному через
     * getItem(): объект из getItems() загружен частично, и запись
     * стадии на нём молча не сохраняется.
     *
     * @param string $code
     *
     * @return int[]
     */
    private static function findIdsByStage(string $code): array
    {
        return self::toIds(Purchase::getFactory()->getItems([
            'select' => ['ID'],
            'filter' => ['=STAGE_ID' => Purchase::stageId($code)],
            'limit'  => 50,
        ]));
    }

    /**
     * Заявки, зависшие на согласовании дольше срока и ещё не эскалированные.
     *
     * @return int[]
     */
    private static function findExpiredIds(): array
    {
        $deadline = (new DateTime())->add('-' . self::getTimeout() . ' minutes');

        return self::toIds(Purchase::getFactory()->getItems([
            'select' => ['ID'],
            'filter' => [
                '=STAGE_ID'                => Purchase::stageId('APPROVAL'),
                '<UF_PURCHASE_APPROVAL_AT' => $deadline,
                [
                    'LOGIC' => 'OR',
                    ['=UF_PURCHASE_ESCALATED' => 0],
                    ['=UF_PURCHASE_ESCALATED' => null],
                ],
            ],
            'limit'  => 50,
        ]));
    }

    /**
     * @param string $code
     *
     * @return int[]
     */
    private static function findUnprocessedIds(string $code): array
    {
        return self::toIds(Purchase::getFactory()->getItems([
            'select' => ['ID'],
            'filter' => [
                '=STAGE_ID' => Purchase::stageId($code),
                [
                    'LOGIC' => 'OR',
                    ['=UF_PURCHASE_PROCESSED' => 0],
                    ['=UF_PURCHASE_PROCESSED' => null],
                ],
            ],
            'limit'  => 50,
        ]));
    }

    /**
     * @param array $items
     *
     * @return int[]
     */
    private static function toIds(array $items): array
    {
        $ids = [];

        foreach ($items as $item) {
            $ids[] = (int) $item->getId();
        }

        return $ids;
    }

    // ------------------------------------------------------------- стадии

    /**
     * Назначение согласующего и перевод заявки на одобрение.
     *
     * @param \Bitrix\Crm\Item $item
     *
     * @return string
     */
    private static function startApproval($item): string
    {
        $approver = self::pickApprover();

        if (!$approver) {
            return Loc::getMessage('ALEX_AUTOSERVICE_FLOW_NO_APPROVER', ['#ID#' => $item->getId()]);
        }

        $item->set('UF_PURCHASE_APPROVER', $approver);
        $item->set('UF_PURCHASE_APPROVAL_AT', new DateTime());
        $item->set('UF_PURCHASE_ESCALATED', 0);
        $item->set('STAGE_ID', Purchase::stageId('APPROVAL'));

        if (!self::save($item)) {
            return Loc::getMessage('ALEX_AUTOSERVICE_FLOW_SAVE_ERROR', ['#ID#' => $item->getId()]);
        }

        Notifier::toUser($approver, Loc::getMessage('ALEX_AUTOSERVICE_FLOW_NOTIFY_APPROVER', [
            '#TITLE#' => $item->getTitle(),
            '#ID#'    => $item->getId(),
        ]), 'purchase-approval-' . $item->getId());

        return Loc::getMessage('ALEX_AUTOSERVICE_FLOW_ASSIGNED', [
            '#ID#'   => $item->getId(),
            '#USER#' => $approver,
        ]);
    }

    /**
     * Согласующий: закупщик с наименьшим числом заявок на руках.
     *
     * @return int
     */
    private static function pickApprover(): int
    {
        $buyers = self::getRole('BUYER');

        if (!$buyers) {
            return self::getHead();
        }

        $load = array_fill_keys($buyers, 0);

        $items = Purchase::getFactory()->getItems([
            'select' => ['ID', 'UF_PURCHASE_APPROVER'],
            'filter' => ['=STAGE_ID' => Purchase::stageId('APPROVAL')],
            'limit'  => 100,
        ]);

        foreach ($items as $item) {
            $approver = (int) $item->get('UF_PURCHASE_APPROVER');

            if (isset($load[$approver])) {
                $load[$approver]++;
            }
        }

        asort($load);

        return (int) array_key_first($load);
    }

    /**
     * Эскалация зависшей заявки на начальника отдела закупок.
     *
     * @param \Bitrix\Crm\Item $item
     *
     * @return string
     */
    private static function escalate($item): string
    {
        $head = self::getHead();

        if (!$head) {
            return Loc::getMessage('ALEX_AUTOSERVICE_FLOW_NO_HEAD', ['#ID#' => $item->getId()]);
        }

        $item->set('UF_PURCHASE_APPROVER', $head);
        $item->set('UF_PURCHASE_APPROVAL_AT', new DateTime());
        $item->set('UF_PURCHASE_ESCALATED', 1);

        if (!self::save($item)) {
            return Loc::getMessage('ALEX_AUTOSERVICE_FLOW_SAVE_ERROR', ['#ID#' => $item->getId()]);
        }

        $message = Loc::getMessage('ALEX_AUTOSERVICE_FLOW_NOTIFY_ESCALATED', [
            '#TITLE#'   => $item->getTitle(),
            '#ID#'      => $item->getId(),
            '#MINUTES#' => self::getTimeout(),
        ]);

        Notifier::toUser($head, $message, 'purchase-escalated-' . $item->getId());

        $initiator = (int) $item->get('UF_PURCHASE_INITIATOR');

        if ($initiator) {
            Notifier::toUser($initiator, $message, 'purchase-escalated-init-' . $item->getId());
        }

        return Loc::getMessage('ALEX_AUTOSERVICE_FLOW_ESCALATED', [
            '#ID#'   => $item->getId(),
            '#USER#' => $head,
        ]);
    }

    /**
     * Завершение заявки: пополнение остатков и уведомление инициатора.
     *
     * @param \Bitrix\Crm\Item $item
     * @param string           $code SUCCESS или FAIL
     *
     * @return string
     */
    private static function finalize($item, string $code): string
    {
        $initiator = (int) $item->get('UF_PURCHASE_INITIATOR');
        $reason    = trim((string) $item->get('UF_PURCHASE_REASON'));

        // Причина обязательна только при отказе. Пока её нет, заявку
        // не считаем обработанной и просим согласующего дозаполнить
        if ($code === 'FAIL' && $reason === '') {
            $approver = (int) $item->get('UF_PURCHASE_APPROVER');

            if ($approver) {
                Notifier::toUser($approver, Loc::getMessage('ALEX_AUTOSERVICE_FLOW_NEED_REASON', [
                    '#TITLE#' => $item->getTitle(),
                    '#ID#'    => $item->getId(),
                ]), 'purchase-reason-' . $item->getId());
            }

            return Loc::getMessage('ALEX_AUTOSERVICE_FLOW_WAIT_REASON', ['#ID#' => $item->getId()]);
        }

        $restocked = [];

        if ($code === 'SUCCESS') {
            $restocked = self::restock($item);
        }

        $item->set('UF_PURCHASE_PROCESSED', 1);

        if (!self::save($item)) {
            return Loc::getMessage('ALEX_AUTOSERVICE_FLOW_SAVE_ERROR', ['#ID#' => $item->getId()]);
        }

        if ($initiator) {
            Notifier::toUser($initiator, $code === 'SUCCESS'
                ? Loc::getMessage('ALEX_AUTOSERVICE_FLOW_NOTIFY_DONE', [
                    '#TITLE#' => $item->getTitle(),
                    '#LIST#'  => $restocked ? implode(', ', $restocked) : '—',
                ])
                : Loc::getMessage('ALEX_AUTOSERVICE_FLOW_NOTIFY_REJECTED', [
                    '#TITLE#'  => $item->getTitle(),
                    '#REASON#' => $reason,
                ]), 'purchase-result-' . $item->getId());
        }

        return $code === 'SUCCESS'
            ? Loc::getMessage('ALEX_AUTOSERVICE_FLOW_DONE', [
                '#ID#'    => $item->getId(),
                '#COUNT#' => count($restocked),
            ])
            : Loc::getMessage('ALEX_AUTOSERVICE_FLOW_REJECTED', ['#ID#' => $item->getId()]);
    }

    /**
     * Пополнение остатков по товарным позициям заявки.
     *
     * @param \Bitrix\Crm\Item $item
     *
     * @return string[] что и на сколько пополнено
     */
    private static function restock($item): array
    {
        $rows = \CCrmProductRow::LoadRows(
            \CCrmOwnerTypeAbbr::ResolveByTypeID(Purchase::getEntityTypeId()),
            $item->getId()
        );

        $restocked = [];

        foreach ((array) $rows as $row) {
            $productId = (int) ($row['PRODUCT_ID'] ?? 0);
            $quantity  = (int) ($row['QUANTITY'] ?? 0);

            if ($productId <= 0 || $quantity <= 0) {
                continue;
            }

            $before = PartsCatalog::getQuantity($productId);

            PartsCatalog::setQuantity($productId, $before + $quantity);

            $restocked[] = (string) ($row['PRODUCT_NAME'] ?? $productId)
                . ' +' . $quantity . ' (' . $before . ' → ' . ($before + $quantity) . ')';
        }

        return $restocked;
    }

    // ------------------------------------------------------------ выборки

    // ------------------------------------------------------------- хелперы

    /**
     * Сохраняет заявку от имени системы.
     *
     * Агент выполняется на хите любого пользователя портала, а меняет
     * чужие заявки: механик, открывший страницу, прав на них не имеет.
     * Поэтому проверка доступа снимается — решение принимает не он,
     * а логика согласования.
     *
     * @param \Bitrix\Crm\Item $item
     *
     * @return bool
     */
    private static function save($item): bool
    {
        $operation = Purchase::getFactory()->getUpdateOperation($item);

        if (method_exists($operation, 'disableCheckAccess')) {
            $operation->disableCheckAccess();
        }

        $result = $operation->launch();

        if (!$result->isSuccess()) {
            Log::add([
                'stage' => 'PURCHASE_FLOW_SAVE_ERROR',
                'item'  => $item->getId(),
                'error' => implode('; ', $result->getErrorMessages()),
            ]);
        }

        return $result->isSuccess();
    }

    /**
     * @return int
     */
    private static function getHead(): int
    {
        $head = self::getRole('PURCH_HEAD');

        return $head ? (int) $head[0] : 0;
    }

    /**
     * @param string $role
     *
     * @return int[]
     */
    private static function getRole(string $role): array
    {
        return Access::getUsersByRole($role);
    }

    /**
     * @return int срок согласования в минутах
     */
    public static function getTimeout(): int
    {
        return max(1, (int) Option::get(self::MODULE_ID, self::OPTION_TIMEOUT, self::DEFAULT_TIMEOUT));
    }
}
