<?php

namespace Alex\Newmodule;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;

/**
 * Двусторонняя синхронизация «Заявка (элемент ИБ) ↔ Сделка CRM».
 *
 * Заявка изменилась  → обновляем OPPORTUNITY / ASSIGNED_BY_ID сделки.
 * Сделка изменилась  → обновляем свойства AMOUNT / ASSIGNED_BY заявок.
 *
 * Защита от замкнутого цикла — два независимых механизма:
 *
 *  1. Флаг $lock. Пока идёт запись в одну сторону, встречный обработчик
 *     выходит сразу. Оба события приходят в одном php-запросе, поэтому
 *     обычной статической переменной достаточно.
 *  2. Сравнение значений. В целевую сущность пишутся только реально
 *     изменившиеся поля; если менять нечего — записи не происходит вовсе,
 *     а значит не возникает и следующего события. Это страхует на случай,
 *     когда встречное изменение приходит отдельным запросом (например,
 *     через очередь или агент).
 */
class DealRequestSync
{
    public const MODULE_ID = 'alex.newmodule';

    private static bool $lock = false;

    /**
     * Список обработчиков: [модуль-источник, событие, класс, метод].
     * Используется и инсталлятором модуля, и скриптом развёртывания.
     */
    public static function getEvents(): array
    {
        return [
            ['iblock', 'OnAfterIBlockElementAdd', '\\' . self::class, 'onIblockElementAdd'],
            ['iblock', 'OnAfterIBlockElementUpdate', '\\' . self::class, 'onIblockElementUpdate'],
            ['crm', 'OnAfterCrmDealUpdate', '\\' . self::class, 'onCrmDealUpdate'],
        ];
    }

    public static function registerEvents(): void
    {
        foreach (self::getEvents() as [$module, $event, $class, $method]) {
            // сначала снимаем, чтобы не плодить дубли при повторном запуске
            UnRegisterModuleDependences($module, $event, self::MODULE_ID, $class, $method);
            RegisterModuleDependences($module, $event, self::MODULE_ID, $class, $method);
        }
    }

    public static function unregisterEvents(): void
    {
        foreach (self::getEvents() as [$module, $event, $class, $method]) {
            UnRegisterModuleDependences($module, $event, self::MODULE_ID, $class, $method);
        }
    }

    // ------------------------------------------------------- события инфоблока

    public static function onIblockElementAdd($arFields): void
    {
        self::handleElement($arFields);
    }

    public static function onIblockElementUpdate($arFields): void
    {
        self::handleElement($arFields);
    }

    private static function handleElement($arFields): void
    {
        if (self::$lock || !is_array($arFields)) {
            return;
        }

        $elementId = (int) ($arFields['ID'] ?? 0);
        $iblockId  = (int) ($arFields['IBLOCK_ID'] ?? 0);

        if (!$elementId) {
            return;
        }

        if (!$iblockId) {
            $row      = \CIBlockElement::GetList([], ['ID' => $elementId], false, false, ['ID', 'IBLOCK_ID'])->Fetch();
            $iblockId = $row ? (int) $row['IBLOCK_ID'] : 0;
        }

        if (!$iblockId || $iblockId !== RequestIblockInstaller::getIblockId()) {
            return;
        }

        self::syncElementToDeal($elementId, $iblockId);
    }

    // ----------------------------------------------------------- события CRM

    /**
     * crm / OnAfterCrmDealUpdate: $arFields = ['ID' => .., 'FIELDS' => [..]]
     */
    public static function onCrmDealUpdate($arFields): void
    {
        if (self::$lock || !is_array($arFields)) {
            return;
        }

        $dealId = (int) ($arFields['ID'] ?? 0);

        if (!$dealId) {
            return;
        }

        self::syncDealToElements($dealId);
    }

    // ------------------------------------------------------- заявка → сделка

    public static function syncElementToDeal(int $elementId, int $iblockId = 0): bool
    {
        if (!$elementId) {
            return false;
        }

        if (!Loader::includeModule('iblock') || !Loader::includeModule('crm')) {
            return false;
        }

        $iblockId = $iblockId ?: RequestIblockInstaller::getIblockId();

        if (!$iblockId) {
            return false;
        }

        $values = self::readElementValues($elementId, $iblockId);
        $dealId = self::toId($values[RequestIblockInstaller::PROP_DEAL] ?? '');

        if (!$dealId) {
            return false;
        }

        $deal = self::readDeal($dealId);

        if (!$deal) {
            self::log('Заявка ' . $elementId . ': сделка ' . $dealId . ' не найдена');

            return false;
        }

        $update = [];

        $amount = $values[RequestIblockInstaller::PROP_AMOUNT];

        // Пустая сумма в заявке — это «не задано», а не ноль: сделку не трогаем
        if ($amount !== null && $amount !== '' && !self::sameMoney($amount, $deal['OPPORTUNITY'])) {
            $update['OPPORTUNITY'] = (float) $amount;
        }

        $assignedBy = self::toId($values[RequestIblockInstaller::PROP_ASSIGNED_BY] ?? '');

        if ($assignedBy > 0 && $assignedBy !== (int) $deal['ASSIGNED_BY_ID']) {
            $update['ASSIGNED_BY_ID'] = $assignedBy;
        }

        if (!$update) {
            // Ничего не изменилось — цикл останавливается здесь
            return false;
        }

        self::$lock = true;

        try {
            $entity = new \CCrmDeal(false);

            $result = $entity->Update(
                $dealId,
                $update,
                true,
                false,
                [
                    'DISABLE_USER_FIELD_CHECK' => true,
                    'REGISTER_SONET_EVENT'     => false,
                ]
            );

            if (!$result) {
                self::log('Не удалось обновить сделку ' . $dealId . ': ' . $entity->LAST_ERROR);

                return false;
            }
        } finally {
            self::$lock = false;
        }

        self::log('Заявка ' . $elementId . ' → сделка ' . $dealId . ': ' . self::describe($update));

        return true;
    }

    // ------------------------------------------------------- сделка → заявки

    public static function syncDealToElements(int $dealId): int
    {
        if (!$dealId) {
            return 0;
        }

        if (!Loader::includeModule('iblock') || !Loader::includeModule('crm')) {
            return 0;
        }

        $iblockId = RequestIblockInstaller::getIblockId();

        if (!$iblockId) {
            return 0;
        }

        $deal = self::readDeal($dealId);

        if (!$deal) {
            return 0;
        }

        $changed = 0;

        foreach (self::findElementsByDeal($iblockId, $dealId) as $elementId) {
            $values = self::readElementValues($elementId, $iblockId);
            $update = [];

            $amount = $values[RequestIblockInstaller::PROP_AMOUNT];

            if (!self::sameMoney($amount, $deal['OPPORTUNITY'])) {
                $update[RequestIblockInstaller::PROP_AMOUNT] = (float) $deal['OPPORTUNITY'];
            }

            $assignedBy  = self::toId($values[RequestIblockInstaller::PROP_ASSIGNED_BY] ?? '');
            $dealAssigned = (int) $deal['ASSIGNED_BY_ID'];

            if ($dealAssigned > 0 && $assignedBy !== $dealAssigned) {
                $update[RequestIblockInstaller::PROP_ASSIGNED_BY] = $dealAssigned;
            }

            if (!$update) {
                // Ничего не изменилось — цикл останавливается здесь
                continue;
            }

            self::$lock = true;

            try {
                \CIBlockElement::SetPropertyValuesEx($elementId, $iblockId, $update);
            } finally {
                self::$lock = false;
            }

            $changed++;

            self::log('Сделка ' . $dealId . ' → заявка ' . $elementId . ': ' . self::describe($update));
        }

        return $changed;
    }

    // ------------------------------------------------------------ вспомогательное

    /**
     * @return array<string,mixed> значения свойств DEAL / AMOUNT / ASSIGNED_BY
     */
    private static function readElementValues(int $elementId, int $iblockId): array
    {
        $values = [
            RequestIblockInstaller::PROP_DEAL        => null,
            RequestIblockInstaller::PROP_AMOUNT      => null,
            RequestIblockInstaller::PROP_ASSIGNED_BY => null,
        ];

        // Фильтр в GetProperty — ПЯТЫЙ аргумент, но массив значений он не понимает
        // (CODE уходит в SQL через ForSql), поэтому читаем все свойства и выбираем нужные
        $res = \CIBlockElement::GetProperty($iblockId, $elementId, 'sort', 'asc');

        while ($row = $res->Fetch()) {
            $code = (string) $row['CODE'];

            if (array_key_exists($code, $values)) {
                $values[$code] = $row['VALUE'];
            }
        }

        return $values;
    }

    /**
     * @return int[] ID элементов, привязанных к сделке
     */
    private static function findElementsByDeal(int $iblockId, int $dealId): array
    {
        $filter = [
            'IBLOCK_ID' => $iblockId,
            [
                'LOGIC' => 'OR',
                // число (ID сделки) и привязка CRM (значение вида D_12)
                ['=PROPERTY_' . RequestIblockInstaller::PROP_DEAL => $dealId],
                ['=PROPERTY_' . RequestIblockInstaller::PROP_DEAL => 'D_' . $dealId],
            ],
        ];

        $ids = [];
        $res = \CIBlockElement::GetList([], $filter, false, false, ['ID']);

        while ($row = $res->Fetch()) {
            $ids[] = (int) $row['ID'];
        }

        return $ids;
    }

    /**
     * @return array{ID:int,OPPORTUNITY:float,ASSIGNED_BY_ID:int}|null
     */
    private static function readDeal(int $dealId): ?array
    {
        $row = \CCrmDeal::GetListEx(
            [],
            ['=ID' => $dealId, 'CHECK_PERMISSIONS' => 'N'],
            false,
            false,
            ['ID', 'TITLE', 'OPPORTUNITY', 'ASSIGNED_BY_ID']
        )->Fetch();

        if (!$row) {
            return null;
        }

        return [
            'ID'             => (int) $row['ID'],
            'TITLE'          => (string) $row['TITLE'],
            'OPPORTUNITY'    => (float) $row['OPPORTUNITY'],
            'ASSIGNED_BY_ID' => (int) $row['ASSIGNED_BY_ID'],
        ];
    }

    /**
     * Приводит значение свойства к ID: 12, '12', 'D_12', ['D_12'] → 12
     */
    public static function toId($value): int
    {
        if (is_array($value)) {
            $value = reset($value);
        }

        $digits = preg_replace('/\D+/', '', (string) $value);

        return (int) $digits;
    }

    private static function sameMoney($a, $b): bool
    {
        return abs((float) $a - (float) $b) < 0.01;
    }

    private static function describe(array $fields): string
    {
        $parts = [];

        foreach ($fields as $key => $value) {
            $parts[] = $key . ' = ' . (is_scalar($value) ? $value : json_encode($value));
        }

        return implode(', ', $parts);
    }

    private static function log(string $message): void
    {
        if (Option::get(self::MODULE_ID, 'sync_debug', 'N') !== 'Y') {
            return;
        }

        AddMessage2Log($message, self::MODULE_ID);
    }
}
