<?php

namespace Alex\Newmodule;

use Bitrix\Main\Loader;

/**
 * Синхронизация PROC_IDS_MULTI -> PROCEDURES_BOOKING_WIDGET.
 *
 * Колбэки кастомного свойства (GetAdminListViewHTML / GetPropertyFieldHtml) не получают
 * ID текущего элемента, поэтому список процедур врача складывается в само значение свойства
 * (CSV из ID процедур) при каждом сохранении элемента.
 */
class ProcedureSyncHandler
{
    public const SOURCE_PROPERTY_CODE = 'PROC_IDS_MULTI';

    /** Защита от повторного входа */
    private static bool $inProgress = false;

    public static function onAfterAdd($arFields): void
    {
        self::handle($arFields);
    }

    public static function onAfterUpdate($arFields): void
    {
        self::handle($arFields);
    }

    private static function handle($arFields): void
    {
        if (self::$inProgress || !is_array($arFields)) {
            return;
        }

        $elementId = (int) ($arFields['ID'] ?? 0);
        $iblockId  = (int) ($arFields['IBLOCK_ID'] ?? 0);

        if (!$elementId) {
            return;
        }

        if (!$iblockId) {
            // В некоторых сценариях IBLOCK_ID в событие не приходит — достаём сами
            $row = \CIBlockElement::GetList([], ['ID' => $elementId], false, false, ['ID', 'IBLOCK_ID'])->Fetch();
            $iblockId = $row ? (int) $row['IBLOCK_ID'] : 0;
        }

        if (!$iblockId || $iblockId !== BookingInstaller::getDoctorIblockId()) {
            return;
        }

        self::$inProgress = true;

        try {
            self::sync($elementId, $iblockId);
        } finally {
            self::$inProgress = false;
        }
    }

    /**
     * Читает привязанные процедуры и пишет их ID через запятую в свойство-виджет.
     * Публичный метод — используется ещё и бэкфиллом в инсталляторе.
     */
    public static function sync(int $elementId, int $iblockId): void
    {
        if (!$elementId || !$iblockId) {
            return;
        }

        Loader::includeModule('iblock');

        $procIds = [];

        // Сигнатура: GetProperty($IBLOCK_ID, $ELEMENT_ID, $by, $order, $arFilter)
        // Фильтр — ПЯТЫЙ аргумент; если передать его третьим/четвёртым,
        // вернутся значения ВСЕХ свойств элемента.
        $res = \CIBlockElement::GetProperty(
            $iblockId,
            $elementId,
            'sort',
            'asc',
            ['CODE' => self::SOURCE_PROPERTY_CODE]
        );

        while ($row = $res->Fetch()) {
            $value = (int) ($row['VALUE'] ?? 0);

            if ($value > 0) {
                $procIds[] = $value;
            }
        }

        $procIds = array_values(array_unique($procIds));

        \CIBlockElement::SetPropertyValuesEx(
            $elementId,
            $iblockId,
            [BookingInstaller::WIDGET_PROPERTY_CODE => implode(',', $procIds)]
        );
    }
}
