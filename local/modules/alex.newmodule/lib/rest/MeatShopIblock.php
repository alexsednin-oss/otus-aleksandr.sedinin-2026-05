<?php

namespace Alex\Newmodule\Rest;

use Alex\Newmodule\Rest\Model\ProductTable;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;

/**
 * Инфоблоки мясной лавки и таблица товаров: доступ и развёртывание кодом.
 *
 * ID инфоблоков лежат в тех же опциях, что создал инсталлятор урока, поэтому
 * на стенде, где урок уже выполнен, ничего не создаётся заново, а на чистом
 * портале модуль поднимает структуру сам.
 */
class MeatShopIblock
{
    public const OPTION_MODULE       = 'main';
    public const OPTION_MANUFACTURER = 'meatshop_manufacturer_iblock_id';
    public const OPTION_PRODUCT_TYPE = 'meatshop_product_type_iblock_id';

    private const IBLOCK_TYPE = 'lists';

    public static function manufacturerId(): int
    {
        return (int) Option::get(self::OPTION_MODULE, self::OPTION_MANUFACTURER, 0);
    }

    public static function productTypeId(): int
    {
        return (int) Option::get(self::OPTION_MODULE, self::OPTION_PRODUCT_TYPE, 0);
    }

    /**
     * Создаёт таблицу и инфоблоки, если их ещё нет. Идемпотентно.
     *
     * @return string[] отчёт для страницы установки модуля
     */
    public static function install(): array
    {
        $report = [];

        if (!Loader::includeModule('iblock')) {
            return ['Модуль iblock не установлен — структура мясной лавки не создана'];
        }

        $report[] = self::ensureTable();

        $report[] = self::ensureIblock(
            self::OPTION_MANUFACTURER,
            'Производители (meatshop)',
            'meatshop_manufacturers',
            'meatshopManufacturers',
            ['NAME' => 'Тип', 'CODE' => 'TYPE']
        );

        $report[] = self::ensureIblock(
            self::OPTION_PRODUCT_TYPE,
            'Тип товара (meatshop)',
            'meatshop_product_types',
            'meatshopProductTypes',
            ['NAME' => 'Класс', 'CODE' => 'CLASS']
        );

        return $report;
    }

    private static function ensureTable(): string
    {
        $connection = Application::getConnection();
        $tableName  = ProductTable::getTableName();

        if ($connection->isTableExists($tableName)) {
            return 'Таблица ' . $tableName . ' уже существует';
        }

        ProductTable::getEntity()->createDbTable();

        return 'Таблица ' . $tableName . ' создана';
    }

    private static function ensureIblock(
        string $option,
        string $name,
        string $code,
        string $apiCode,
        array $property
    ): string {
        $existingId = (int) Option::get(self::OPTION_MODULE, $option, 0);

        if ($existingId && \CIBlock::GetByID($existingId)->Fetch()) {
            return 'Инфоблок «' . $name . '» уже существует, ID = ' . $existingId;
        }

        // Инфоблок мог быть создан раньше без опции — ищем по символьному коду
        $found = \CIBlock::GetList([], ['CODE' => $code, 'CHECK_PERMISSIONS' => 'N'])->Fetch();

        if ($found) {
            Option::set(self::OPTION_MODULE, $option, (int) $found['ID']);

            return 'Инфоблок «' . $name . '» найден по коду, ID = ' . $found['ID'];
        }

        if (!\CIBlockType::GetByID(self::IBLOCK_TYPE)->Fetch()) {
            return 'Тип инфоблока «' . self::IBLOCK_TYPE . '» не найден — инфоблок «' . $name . '» не создан';
        }

        $iblock   = new \CIBlock();
        $iblockId = (int) $iblock->Add([
            'ACTIVE'         => 'Y',
            'NAME'           => $name,
            'CODE'           => $code,
            'API_CODE'       => $apiCode,
            'IBLOCK_TYPE_ID' => self::IBLOCK_TYPE,
            'SITE_ID'        => ['s1'],
            'GROUP_ID'       => ['2' => 'X'],
        ]);

        if (!$iblockId) {
            return 'Не удалось создать инфоблок «' . $name . '»: ' . $iblock->LAST_ERROR;
        }

        (new \CIBlockProperty())->Add([
            'IBLOCK_ID'     => $iblockId,
            'NAME'          => $property['NAME'],
            'CODE'          => $property['CODE'],
            'PROPERTY_TYPE' => 'S',
            'MULTIPLE'      => 'N',
        ]);

        Option::set(self::OPTION_MODULE, $option, $iblockId);

        return 'Инфоблок «' . $name . '» создан, ID = ' . $iblockId;
    }
}
