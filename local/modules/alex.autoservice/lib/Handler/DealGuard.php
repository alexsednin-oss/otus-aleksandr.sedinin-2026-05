<?php

namespace Alex\Autoservice\Handler;

use Alex\Autoservice\Service\CarCrudService;
use Alex\Autoservice\Service\Log;
use Alex\Autoservice\Service\Notifier;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

/**
 * Запрет второго незакрытого заказ-наряда по одному автомобилю.
 *
 * По ТЗ при создании наряда система проверяет, нет ли по этой машине
 * сделки в нефинальной стадии, уведомляет её ответственного и не даёт
 * создать новую, пока предыдущая не закрыта.
 */
class DealGuard
{
    /**
     * Отключение проверки для служебных операций — разворачивания
     * демо-данных и миграций, где наряды создаются пакетом.
     *
     * @var bool
     */
    public static bool $disabled = false;

    /**
     * Обработчик crm / OnBeforeCrmDealAdd.
     *
     * @param array $fields поля создаваемой сделки
     *
     * @return bool false отменяет создание
     */
    public static function onBeforeAdd(&$fields): bool
    {
        if (self::$disabled || !is_array($fields)) {
            return true;
        }

        $carId = (int) ($fields[CarCrudService::DEAL_CAR_FIELD] ?? 0);

        // Наряд без автомобиля и сделки других воронок не ограничиваем
        if ($carId <= 0 || !Loader::includeModule('crm')) {
            return true;
        }

        $opened = self::findOpenedDeal($carId);

        if (!$opened) {
            return true;
        }

        $car = self::describeCar($carId);

        $message = Loc::getMessage('ALEX_AUTOSERVICE_GUARD_MESSAGE', [
            '#CAR#'   => $car,
            '#TITLE#' => $opened['TITLE'],
            '#ID#'    => $opened['ID'],
        ]);

        Notifier::toUser((int) $opened['ASSIGNED_BY_ID'], $message, 'deal-guard-' . $carId);

        Log::add([
            'stage'      => 'DEAL_GUARD',
            'car'        => $carId,
            'blocked_by' => $opened['ID'],
            'message'    => $message,
        ]);

        // CRM читает текст отказа отсюда: без RESULT_MESSAGE пользователь
        // увидит служебное «Создание сделки отменено обработчиком события»
        $fields['RESULT_MESSAGE'] = $message;

        global $APPLICATION;

        if (is_object($APPLICATION)) {
            $APPLICATION->ThrowException($message);
        }

        return false;
    }

    /**
     * Незакрытый заказ-наряд по автомобилю.
     *
     * Незакрытым считается наряд в стадии с семантикой «в процессе»:
     * успешные и провальные стадии финальные по определению.
     *
     * @param int $carId
     *
     * @return array|null
     */
    public static function findOpenedDeal(int $carId): ?array
    {
        $process = class_exists('\Bitrix\Crm\PhaseSemantics')
            ? \Bitrix\Crm\PhaseSemantics::PROCESS
            : 'P';

        $row = \CCrmDeal::GetListEx(
            ['ID' => 'DESC'],
            [
                CarCrudService::DEAL_CAR_FIELD => $carId,
                '=STAGE_SEMANTIC_ID'           => $process,
                'CHECK_PERMISSIONS'            => 'N',
            ],
            false,
            ['nTopCount' => 1],
            ['ID', 'TITLE', 'STAGE_ID', 'ASSIGNED_BY_ID']
        )->Fetch();

        return $row ?: null;
    }

    /**
     * Человекочитаемое описание автомобиля для сообщения.
     *
     * @param int $carId
     *
     * @return string
     */
    private static function describeCar(int $carId): string
    {
        try {
            $car = CarCrudService::read(['ID' => $carId]);
        } catch (\Throwable $e) {
            return '#' . $carId;
        }

        return trim($car['BRAND'] . ' ' . $car['MODEL'] . ' (' . $car['NUMBER'] . ')');
    }
}
