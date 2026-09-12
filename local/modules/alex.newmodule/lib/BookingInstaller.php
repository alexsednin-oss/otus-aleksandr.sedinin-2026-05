<?php

namespace Alex\Newmodule;

use Bitrix\Iblock\IblockTable;
use Bitrix\Iblock\PropertyTable;
use Bitrix\Main\Application;
use Bitrix\Main\Loader;

/**
 * Создание списка "Бронирование" и подключение свойства-виджета к инфоблоку "Врачи".
 * Вызывается из install/index.php (DoInstall / DoUninstall).
 */
class BookingInstaller
{
    public const BOOKING_IBLOCK_CODE = 'alex_newmodule_booking';
    public const BOOKING_IBLOCK_TYPE = 'lists';

    /**
     * Инфоблок "Врачи" из ДЗ №3 ищется по трём признакам подряд:
     * символьный код -> название -> ID. Первый сработавший и используется.
     */
    public const DOCTOR_IBLOCK_CODE = 'doctors';
    public const DOCTOR_IBLOCK_NAME = 'Врачи';
    public const DOCTOR_IBLOCK_ID   = 16;

    public const WIDGET_PROPERTY_CODE = 'PROCEDURES_BOOKING_WIDGET';
    public const WIDGET_PROPERTY_NAME = 'Процедуры (бронирование)';

    /** Кэш ID инфоблока "Врачи" в рамках запроса */
    private static ?int $doctorIblockId = null;

    /**
     * @return string[] отчёт для страницы step2.php
     */
    public static function install(): array
    {
        Loader::includeModule('iblock');

        $report = [];

        $bookingIblockId = self::createBookingIblock();
        $report[] = 'Список «Бронирование» готов, IBLOCK_ID = ' . $bookingIblockId;

        $doctorIblockId = self::getDoctorIblockId();

        if (!$doctorIblockId) {
            $report[] = 'ВНИМАНИЕ: инфоблок «Врачи» не найден (искали CODE="' . self::DOCTOR_IBLOCK_CODE
                . '", NAME="' . self::DOCTOR_IBLOCK_NAME . '", ID=' . self::DOCTOR_IBLOCK_ID
                . '). Свойство-виджет не добавлено — поправьте константы в BookingInstaller.';

            return $report;
        }

        $propertyId = self::attachProcedureWidgetToDoctors($doctorIblockId);
        $report[]   = 'Свойство «' . self::WIDGET_PROPERTY_NAME . '» добавлено к инфоблоку «Врачи» (IBLOCK_ID = '
            . $doctorIblockId . '), PROPERTY_ID = ' . $propertyId;

        $synced   = self::backfillExistingDoctors($doctorIblockId);
        $report[] = 'Пересчитано существующих врачей: ' . $synced;

        return $report;
    }

    /**
     * При удалении модуля убираем только служебное свойство у "Врачей".
     * Сам список "Бронирование" и записи в нём остаются — при повторной установке он переиспользуется.
     */
    public static function uninstall(): void
    {
        Loader::includeModule('iblock');

        $doctorIblockId = self::getDoctorIblockId();

        if (!$doctorIblockId) {
            return;
        }

        $property = PropertyTable::getList([
            'select' => ['ID'],
            'filter' => ['=IBLOCK_ID' => $doctorIblockId, '=CODE' => self::WIDGET_PROPERTY_CODE],
            'limit'  => 1,
        ])->fetch();

        if ($property) {
            (new \CIBlockProperty())->Delete((int) $property['ID']);
            self::clearIblockPropertyCache();
        }
    }

    /**
     * ID инфоблока "Врачи": CODE -> NAME -> ID.
     */
    public static function getDoctorIblockId(): int
    {
        if (self::$doctorIblockId !== null) {
            return self::$doctorIblockId;
        }

        Loader::includeModule('iblock');

        $row = IblockTable::getList([
            'select' => ['ID'],
            'filter' => ['=CODE' => self::DOCTOR_IBLOCK_CODE],
            'limit'  => 1,
        ])->fetch();

        if (!$row) {
            $row = IblockTable::getList([
                'select' => ['ID'],
                'filter' => ['=NAME' => self::DOCTOR_IBLOCK_NAME],
                'limit'  => 1,
            ])->fetch();
        }

        if (!$row && self::DOCTOR_IBLOCK_ID > 0) {
            $row = IblockTable::getList([
                'select' => ['ID'],
                'filter' => ['=ID' => self::DOCTOR_IBLOCK_ID],
                'limit'  => 1,
            ])->fetch();
        }

        self::$doctorIblockId = $row ? (int) $row['ID'] : 0;

        return self::$doctorIblockId;
    }

    /**
     * @return int
     * @throws \Bitrix\Main\ArgumentException
     * @throws \Bitrix\Main\LoaderException
     * @throws \Bitrix\Main\ObjectPropertyException
     * @throws \Bitrix\Main\SystemException
     */
    public static function getBookingIblockId(): int
    {
        Loader::includeModule('iblock');

        $row = IblockTable::getList([
            'select' => ['ID'],
            'filter' => ['=CODE' => self::BOOKING_IBLOCK_CODE],
            'limit'  => 1,
        ])->fetch();

        return $row ? (int) $row['ID'] : 0;
    }

    /**
     * Разовый пересчёт свойства-виджета для уже существующих врачей.
     */
    private static function backfillExistingDoctors(int $doctorIblockId): int
    {
        $count = 0;

        $res = \CIBlockElement::GetList(
            [],
            ['IBLOCK_ID' => $doctorIblockId],
            false,
            false,
            ['ID', 'IBLOCK_ID']
        );

        while ($row = $res->Fetch()) {
            ProcedureSyncHandler::sync((int) $row['ID'], $doctorIblockId);
            $count++;
        }

        return $count;
    }

    /**
     * @return int
     * @throws \Bitrix\Main\ArgumentException
     * @throws \Bitrix\Main\LoaderException
     * @throws \Bitrix\Main\ObjectPropertyException
     * @throws \Bitrix\Main\SystemException
     */
    private static function createBookingIblock(): int
    {
        $existingId = self::getBookingIblockId();

        if ($existingId) {
            self::ensureBookingProperties($existingId);

            return $existingId;
        }

        $type = \CIBlockType::GetByID(self::BOOKING_IBLOCK_TYPE)->Fetch();

        if (!$type) {
            throw new \RuntimeException(
                'Тип инфоблока "' . self::BOOKING_IBLOCK_TYPE . '" не найден — создайте его или поменяйте '
                . 'константу BookingInstaller::BOOKING_IBLOCK_TYPE.'
            );
        }

        $ib = new \CIBlock();

        $iblockId = $ib->Add([
            'ACTIVE'         => 'Y',
            'NAME'           => 'Бронирование',
            'CODE'           => self::BOOKING_IBLOCK_CODE,
            'API_CODE'       => 'AlexBooking',
            'IBLOCK_TYPE_ID' => self::BOOKING_IBLOCK_TYPE,
            'SITE_ID'        => ['s1'],
            'GROUP_ID'       => ['2' => 'X'],
            'SORT'           => 500,
        ]);

        if (!$iblockId) {
            throw new \RuntimeException('Не удалось создать инфоблок «Бронирование»: ' . $ib->LAST_ERROR);
        }

        self::ensureBookingProperties((int) $iblockId);

        return (int) $iblockId;
    }

    /**
     * Идемпотентно: добавляет только те свойства, которых ещё нет.
     */
    private static function ensureBookingProperties(int $iblockId): void
    {
        $properties = [
            [
                'NAME'          => 'ФИО пациента',
                'CODE'          => 'PATIENT_NAME',
                'PROPERTY_TYPE' => 'S',
                'SORT'          => 100,
            ],
            [
                'NAME'          => 'Время записи',
                'CODE'          => 'APPOINTMENT_TIME',
                'PROPERTY_TYPE' => 'S',
                'SORT'          => 200,
            ],
            [
                'NAME'          => 'Процедура',
                'CODE'          => 'PROCEDURE',
                'PROPERTY_TYPE' => 'E',
                'SORT'          => 300,
            ],
        ];

        foreach ($properties as $fields) {
            $exists = PropertyTable::getList([
                'select' => ['ID'],
                'filter' => ['=IBLOCK_ID' => $iblockId, '=CODE' => $fields['CODE']],
                'limit'  => 1,
            ])->fetch();

            if ($exists) {
                continue;
            }

            $fields['IBLOCK_ID'] = $iblockId;
            $fields['ACTIVE']    = 'Y';
            $fields['MULTIPLE']  = 'N';

            $prop = new \CIBlockProperty();

            if (!$prop->Add($fields)) {
                throw new \RuntimeException(
                    'Не удалось создать свойство ' . $fields['CODE'] . ': ' . $prop->LAST_ERROR
                );
            }
        }
    }

    /**
     * @return int ID созданного (или уже существующего) свойства
     */
    public static function attachProcedureWidgetToDoctors(int $doctorIblockId): int
    {
        $existing = PropertyTable::getList([
            'select' => ['ID', 'USER_TYPE'],
            'filter' => ['=IBLOCK_ID' => $doctorIblockId, '=CODE' => self::WIDGET_PROPERTY_CODE],
            'limit'  => 1,
        ])->fetch();

        if ($existing) {
            $propertyId = (int) $existing['ID'];

            if ((string) $existing['USER_TYPE'] !== ProcedurePickerPropertyType::USER_TYPE) {
                self::forceUserType($propertyId);
            }

            return $propertyId;
        }

        $fields = [
            'IBLOCK_ID'     => $doctorIblockId,
            'ACTIVE'        => 'Y',
            'NAME'          => self::WIDGET_PROPERTY_NAME,
            'CODE'          => self::WIDGET_PROPERTY_CODE,
            'PROPERTY_TYPE' => 'S',
            'USER_TYPE'     => ProcedurePickerPropertyType::USER_TYPE,
            'MULTIPLE'      => 'N',
            'SORT'          => 900,
        ];

        $prop       = new \CIBlockProperty();
        $propertyId = (int) $prop->Add($fields);

        if (!$propertyId) {
            unset($fields['USER_TYPE']);

            $prop       = new \CIBlockProperty();
            $propertyId = (int) $prop->Add($fields);

            if (!$propertyId) {
                throw new \RuntimeException(
                    'Не удалось создать свойство ' . self::WIDGET_PROPERTY_CODE . ': ' . $prop->LAST_ERROR
                );
            }
        }

        // Подстраховка: часть версий молча обнуляет неизвестный USER_TYPE при Add.
        self::forceUserType($propertyId);

        return $propertyId;
    }

    /**
     * @param int $propertyId
     * @return void
     * @throws \Exception
     */
    private static function forceUserType(int $propertyId): void
    {
        PropertyTable::update($propertyId, [
            'PROPERTY_TYPE' => 'S',
            'USER_TYPE'     => ProcedurePickerPropertyType::USER_TYPE,
        ]);

        self::clearIblockPropertyCache();
    }

    /**
     * @return void
     */
    private static function clearIblockPropertyCache(): void
    {
        $managedCache = Application::getInstance()->getManagedCache();
        $managedCache->clean('b_iblock_property');
    }
}
