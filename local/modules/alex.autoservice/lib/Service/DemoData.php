<?php

namespace Alex\Autoservice\Service;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Web\Json;
use Alex\Autoservice\Service\PartsCatalog;

Loc::loadMessages(__FILE__);

/**
 * Демонстрационные данные сервисного центра.
 *
 * Клиенты, их автомобили и заказ-наряды по всем стадиям воронки.
 * Всё созданное помечается признаком источника ORIGINATOR_ID, поэтому
 * очистка удаляет ровно демо-данные и не трогает рабочие записи.
 *
 * Намеренно не оформлено миграцией: демо-данные не должны разворачиваться
 * на портале заказчика вместе с модулем.
 */
class DemoData
{
    private const MODULE_ID = 'alex.autoservice';

    /** Признак источника: по нему демо-записи находятся и удаляются */
    public const MARKER = 'alex.autoservice.demo';

    /** Клиенты: фамилия, имя, телефон */
    private const CLIENTS = [
        ['LAST_NAME' => 'Иванов',  'NAME' => 'Пётр',   'PHONE' => '+79031234567'],
        ['LAST_NAME' => 'Сергеева', 'NAME' => 'Ольга',  'PHONE' => '+79162345678'],
        ['LAST_NAME' => 'Миронов', 'NAME' => 'Андрей', 'PHONE' => '+79255556677'],
    ];

    /** Автомобили: индекс клиента, марка, модель, номер, год, цвет, пробег */
    private const CARS = [
        [0, 'Toyota', 'Camry',   'К777МР777', 2019, 'Чёрный',      84000],
        [0, 'Lada',   'Vesta',   'Е301ОР199', 2022, 'Белый',       31500],
        [1, 'Kia',    'Rio',     'О555АВ750', 2020, 'Серебристый', 62400],
        [2, 'Skoda',  'Octavia', 'Р128ТТ197', 2018, 'Синий',      119300],
        [2, 'Renault', 'Duster', 'Т909НН178', 2021, 'Оранжевый',   47800],
    ];

    /**
     * Заказ-наряды: индекс автомобиля, название, код стадии, запчасти.
     * Стадии подобраны так, чтобы в канбане были заполнены все колонки.
     */
    private const ORDERS = [
        [0, 'Замена масла и фильтров', 'WON', [
            ['Масло моторное 5W-30', 1200, 5],
            ['Фильтр масляный',       900, 1],
            ['Фильтр воздушный',     1500, 1],
        ]],
        [0, 'Замена тормозных колодок', 'REPAIR', [
            ['Колодки тормозные передние', 5800, 2],
            ['Диск тормозной',             1500, 2],
        ]],
        [1, 'Диагностика подвески', 'DIAGNOSTICS', [
            ['Диагностика ходовой части', 3500, 1],
        ]],
        [2, 'Ремонт генератора', 'WAITING_PARTS', [
            ['Генератор в сборе', 18900, 1],
            ['Ремень приводной',   1700, 1],
        ]],
        [3, 'Плановое ТО-60000', 'CHECK', [
            ['Комплект ТО',        7400, 1],
            ['Свечи зажигания',     850, 4],
        ]],
        [4, 'Замена лобового стекла', 'NEW', [
            ['Стекло лобовое', 22500, 1],
            ['Герметик',        1300, 1],
        ]],
        [5, 'Шиномонтаж и балансировка', 'LOSE', [
            ['Шиномонтаж колеса', 900, 4],
        ]],
    ];

    private const PARTS = [
        ['oil-5w30',      'Масло моторное 5W-30',        1200, 12],
        ['oil-filter',    'Фильтр масляный',              900,  7],
        ['air-filter',    'Фильтр воздушный',            1500,  4],
        ['brake-pads-f',  'Колодки тормозные передние',  5800,  3],
        ['brake-disc',    'Диск тормозной',              1500,  6],
        ['spark-plug',    'Свечи зажигания',              850, 16],
        ['generator',     'Генератор в сборе',          18900,  1],
        ['drive-belt',    'Ремень приводной',            1700,  9],
        ['windshield',    'Стекло лобовое',             22500,  2],
        ['to-kit',        'Комплект ТО',                 7400,  5],
    ];

    /**
     * Разворачивает демо-данные. Повторный запуск ничего не дублирует.
     *
     * @throws \RuntimeException
     *
     * @return string[] отчёт
     */
    public static function seed(): array
    {
        self::requireCrm();

        $report     = [];
        $categoryId = (int) Option::get(self::MODULE_ID, 'service_category_id', 0);

        if (!$categoryId) {
            throw new \RuntimeException(Loc::getMessage('ALEX_AUTOSERVICE_DEMO_NO_FUNNEL'));
        }

        $prefix    = self::getStagePrefix($categoryId);
        $mechanics = self::getMechanics();

        $report[] = Loc::getMessage('ALEX_AUTOSERVICE_DEMO_FUNNEL', [
            '#ID#'     => $categoryId,
            '#PREFIX#' => $prefix !== '' ? $prefix : '—',
        ]);

        // --------------------------------------------------------- запчасти

        $parts = 0;

        foreach (self::PARTS as [$code, $name, $price, $quantity]) {
            $part = PartsCatalog::ensurePart($code, $name, (float) $price, (int) $quantity);

            if ($part['CREATED']) {
                $parts++;
            }
        }

        $report[] = Loc::getMessage('ALEX_AUTOSERVICE_DEMO_PARTS', [
            '#CREATED#' => $parts,
            '#TOTAL#'   => count(self::PARTS),
        ]);

        // ---------------------------------------------------------- клиенты

        $contactIds = [];

        foreach (self::CLIENTS as $index => $client) {
            $originId  = 'client-' . $index;
            $contactId = self::findContact($originId);

            if ($contactId) {
                $report[] = Loc::getMessage('ALEX_AUTOSERVICE_DEMO_CLIENT_EXISTS', [
                    '#NAME#' => $client['LAST_NAME'] . ' ' . $client['NAME'],
                    '#ID#'   => $contactId,
                ]);
            } else {
                $entity = new \CCrmContact(false);
                $fields = [
                    'NAME'           => $client['NAME'],
                    'LAST_NAME'      => $client['LAST_NAME'],
                    'OPENED'         => 'Y',
                    'ASSIGNED_BY_ID' => $mechanics[0],
                    'ORIGINATOR_ID'  => self::MARKER,
                    'ORIGIN_ID'      => $originId,
                    'FM'             => ['PHONE' => ['n0' => [
                        'VALUE'      => $client['PHONE'],
                        'VALUE_TYPE' => 'MOBILE',
                    ]]],
                ];

                $contactId = (int) $entity->Add($fields, true,
                    ['DISABLE_USER_FIELD_CHECK' => true, 'REGISTER_SONET_EVENT' => false]);

                if (!$contactId) {
                    throw new \RuntimeException(Loc::getMessage('ALEX_AUTOSERVICE_DEMO_CLIENT_ERROR', [
                        '#NAME#'  => $client['LAST_NAME'],
                        '#ERROR#' => $entity->LAST_ERROR,
                    ]));
                }

                $report[] = Loc::getMessage('ALEX_AUTOSERVICE_DEMO_CLIENT_CREATED', [
                    '#NAME#' => $client['LAST_NAME'] . ' ' . $client['NAME'],
                    '#ID#'   => $contactId,
                ]);
            }

            $contactIds[$index] = $contactId;
        }

        // ------------------------------------------------------- автомобили

        $carIds = [];

        foreach (self::CARS as $index => [$clientIndex, $brand, $model, $number, $year, $color, $mileage]) {
            $normalized = CarCrudService::normalizeNumber($number);
            $existing   = CarCrudService::findIdByNumber($normalized);

            if ($existing) {
                $carIds[$index] = $existing;

                $report[] = Loc::getMessage('ALEX_AUTOSERVICE_DEMO_CAR_EXISTS', [
                    '#CAR#' => $brand . ' ' . $model . ' (' . $normalized . ')',
                    '#ID#'  => $existing,
                ]);

                continue;
            }

            $car = CarCrudService::create([
                'CONTACT_ID' => $contactIds[$clientIndex],
                'BRAND'      => $brand,
                'MODEL'      => $model,
                'NUMBER'     => $number,
                'YEAR'       => $year,
                'COLOR'      => $color,
                'MILEAGE'    => $mileage,
            ]);

            $carIds[$index] = (int) $car['ID'];

            $report[] = Loc::getMessage('ALEX_AUTOSERVICE_DEMO_CAR_CREATED', [
                '#CAR#' => $brand . ' ' . $model . ' (' . $car['NUMBER'] . ')',
                '#ID#'  => $car['ID'],
            ]);
        }

        // ------------------------------------------------------ заказ-наряды

        $currency = \CCrmCurrency::GetBaseCurrencyID();

        // Демо-набор создаётся пакетом, проверку «один открытый наряд
        // на автомобиль» на время разворачивания отключаем
        \Alex\Autoservice\Handler\DealGuard::$disabled = true;

        try {
            foreach (self::ORDERS as $index => [$carIndex, $title, $stageCode, $products]) {
                // ... существующее тело цикла без изменений ...
            }
        } finally {
            \Alex\Autoservice\Handler\DealGuard::$disabled = false;
        }

        foreach (self::ORDERS as $index => [$carIndex, $title, $stageCode, $products]) {
            $originId = 'deal-' . $index;

            if (self::findDeal($originId)) {
                $report[] = Loc::getMessage('ALEX_AUTOSERVICE_DEMO_ORDER_EXISTS', ['#TITLE#' => $title]);

                continue;
            }

            $sum = 0.0;

            foreach ($products as [$name, $price, $quantity]) {
                $sum += $price * $quantity;
            }

            $deal   = new \CCrmDeal(false);
            $fields = [
                'TITLE'          => $title,
                'CATEGORY_ID'    => $categoryId,
                'STAGE_ID'       => $prefix . $stageCode,
                'CONTACT_ID'     => $contactIds[self::CARS[$carIndex][0]],
                'OPPORTUNITY'    => $sum,
                'CURRENCY_ID'    => $currency,
                'ASSIGNED_BY_ID' => $mechanics[$index % count($mechanics)],
                'OPENED'         => 'Y',
                'ORIGINATOR_ID'  => self::MARKER,
                'ORIGIN_ID'      => $originId,
                CarCrudService::DEAL_CAR_FIELD => $carIds[$carIndex],
            ];

            $dealId = (int) $deal->Add($fields, true,
                ['DISABLE_USER_FIELD_CHECK' => true, 'REGISTER_SONET_EVENT' => false]);

            if (!$dealId) {
                $report[] = Loc::getMessage('ALEX_AUTOSERVICE_DEMO_ORDER_ERROR', [
                    '#TITLE#' => $title,
                    '#ERROR#' => $deal->LAST_ERROR,
                ]);

                continue;
            }

            $rows = [];

            foreach ($products as [$name, $price, $quantity]) {
                $rows[] = [
                    'PRODUCT_ID'   => 0,
                    'PRODUCT_NAME' => $name,
                    'PRICE'        => $price,
                    'QUANTITY'     => $quantity,
                    'CURRENCY_ID'  => $currency,
                ];
            }

            \CCrmProductRow::SaveRows('D', $dealId, $rows, null, false);

            $report[] = Loc::getMessage('ALEX_AUTOSERVICE_DEMO_ORDER_CREATED', [
                '#TITLE#' => $title,
                '#ID#'    => $dealId,
                '#STAGE#' => $prefix . $stageCode,
                '#COUNT#' => count($rows),
            ]);
        }

        return $report;
    }

    /**
     * Удаляет всё, что создал seed().
     *
     * @return string[] отчёт
     */
    public static function clear(): array
    {
        self::requireCrm();

        $report = [];

        // ------------------------------------------------------------ сделки

        $deal  = new \CCrmDeal(false);
        $res   = \CCrmDeal::GetListEx([], ['ORIGINATOR_ID' => self::MARKER, 'CHECK_PERMISSIONS' => 'N'],
            false, false, ['ID', 'TITLE']);
        $deals = 0;

        while ($row = $res->Fetch()) {
            if ($deal->Delete((int) $row['ID'], ['ENABLE_DEFERRED_MODE' => false])) {
                $deals++;
            }
        }

        $report[] = Loc::getMessage('ALEX_AUTOSERVICE_DEMO_ORDERS_REMOVED', ['#COUNT#' => $deals]);

        // -------------------------------------------------------- контакты

        $contact    = new \CCrmContact(false);
        $res        = \CCrmContact::GetListEx([], ['ORIGINATOR_ID' => self::MARKER, 'CHECK_PERMISSIONS' => 'N'],
            false, false, ['ID']);
        $contactIds = [];

        while ($row = $res->Fetch()) {
            $contactIds[] = (int) $row['ID'];
        }

        // Автомобили удаляем до контактов: они ссылаются на владельца
        $cars = 0;

        foreach ($contactIds as $contactId) {
            foreach (CarCrudService::getByContact($contactId) as $car) {
                try {
                    CarCrudService::delete((int) $car['ID']);
                    $cars++;
                } catch (\Throwable $e) {
                    $report[] = Loc::getMessage('ALEX_AUTOSERVICE_DEMO_CAR_KEPT', [
                        '#ID#'    => $car['ID'],
                        '#ERROR#' => $e->getMessage(),
                    ]);
                }
            }
        }

        $report[] = Loc::getMessage('ALEX_AUTOSERVICE_DEMO_CARS_REMOVED', ['#COUNT#' => $cars]);

        $contacts = 0;

        foreach ($contactIds as $contactId) {
            if ($contact->Delete($contactId)) {
                $contacts++;
            }
        }

        $report[] = Loc::getMessage('ALEX_AUTOSERVICE_DEMO_CLIENTS_REMOVED', ['#COUNT#' => $contacts]);

        return $report;
    }

    /**
     * Механики из карты ролей, созданной миграцией оргструктуры.
     *
     * @return int[]
     */
    private static function getMechanics(): array
    {
        $raw = (string) Option::get(self::MODULE_ID, 'structure_users', '');

        if ($raw !== '') {
            try {
                $users = (array) Json::decode($raw);

                if (!empty($users['MECHANIC'])) {
                    return array_map('intval', (array) $users['MECHANIC']);
                }
            } catch (\Throwable $e) {
                // карта ролей повреждена — работаем от администратора
            }
        }

        return [1];
    }

    /**
     * @param int $categoryId
     *
     * @return string
     */
    private static function getStagePrefix(int $categoryId): string
    {
        $entityId = DealFunnel::getStagesEntityId($categoryId);

        foreach (array_keys(Stages::getExisting($entityId)) as $statusId) {
            $position = mb_strpos($statusId, ':');

            if ($position !== false) {
                return mb_substr($statusId, 0, $position + 1);
            }
        }

        return '';
    }

    /**
     * @param string $originId
     *
     * @return int
     */
    private static function findContact(string $originId): int
    {
        $row = \CCrmContact::GetListEx(
            [],
            ['ORIGINATOR_ID' => self::MARKER, 'ORIGIN_ID' => $originId, 'CHECK_PERMISSIONS' => 'N'],
            false,
            ['nTopCount' => 1],
            ['ID']
        )->Fetch();

        return $row ? (int) $row['ID'] : 0;
    }

    /**
     * @param string $originId
     *
     * @return int
     */
    private static function findDeal(string $originId): int
    {
        $row = \CCrmDeal::GetListEx(
            [],
            ['ORIGINATOR_ID' => self::MARKER, 'ORIGIN_ID' => $originId, 'CHECK_PERMISSIONS' => 'N'],
            false,
            ['nTopCount' => 1],
            ['ID']
        )->Fetch();

        return $row ? (int) $row['ID'] : 0;
    }

    /**
     * @throws \RuntimeException
     *
     * @return void
     */
    private static function requireCrm(): void
    {
        if (!Loader::includeModule('crm')) {
            throw new \RuntimeException(Loc::getMessage('ALEX_AUTOSERVICE_DEMO_NO_CRM'));
        }
    }
}
