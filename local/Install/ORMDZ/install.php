<?php

/**
 * Инсталлятор ORMDZ. Запускается вручную (браузер или CLI), один раз.
 * Не зависит от init.php - все подключения явные.
 */

define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);

require_once $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/prolog_before.php';

use Bitrix\Main\Loader;
use Bitrix\Main\Config\Option;

Loader::includeModule('iblock');

require_once __DIR__.'/../../App/ORMDZ/Model/ProductTable.php';

const MEATSHOP_MODULE      = 'main';
const MEATSHOP_IBLOCK_TYPE = 'lists'; // используем стандартный тип "Универсальные списки", свой тип не создаём

// 1. Кастомная таблица через ORM
$connection = \Bitrix\Main\Application::getConnection();
$tableName  = \App\ORMDZ\Model\ProductTable::getTableName();

if (!$connection->isTableExists($tableName)) {
    \App\ORMDZ\Model\ProductTable::getEntity()->createDbTable();
    echo "Таблица $tableName создана\n";
} else {
    echo "Таблица $tableName уже существует\n";
}

// 2. Проверяем, что тип "lists" реально существует в этой установке
$iblockTypeExists = \CIBlockType::GetByID(MEATSHOP_IBLOCK_TYPE)->Fetch();

if (!$iblockTypeExists) {
    die('Тип инфоблока "'.MEATSHOP_IBLOCK_TYPE.'" не найден в системе. '.
        'Убедитесь, что модуль iblock корректно установлен, либо смените ORMDZ_IBLOCK_TYPE на свой тип.');
}

echo "Тип инфоблоков '".MEATSHOP_IBLOCK_TYPE."' найден, используем его\n";

// 3. Инфоблок "Производители"
$manufacturerIblockId = (int) Option::get(MEATSHOP_MODULE, 'meatshop_manufacturer_iblock_id', 0);

if (!$manufacturerIblockId) {
    $ib = new \CIBlock();
    $manufacturerIblockId = $ib->Add([
        'ACTIVE'         => 'Y',
        'NAME'           => 'Производители (meatshop)',
        'CODE'           => 'meatshop_manufacturers',
        'API_CODE'       => 'meatshopManufacturers',
        'IBLOCK_TYPE_ID' => MEATSHOP_IBLOCK_TYPE,
        'SITE_ID'        => ['s1'],
        'GROUP_ID'       => ['2' => 'X'],
    ]);

    if (!$manufacturerIblockId) {
        die('Ошибка создания инфоблока "Производители": '.$ib->LAST_ERROR);
    }

    (new \CIBlockProperty())->Add([
        'IBLOCK_ID'     => $manufacturerIblockId,
        'NAME'          => 'Тип',
        'CODE'          => 'TYPE',
        'PROPERTY_TYPE' => 'S',
        'MULTIPLE'      => 'N',
    ]);

    Option::set(MEATSHOP_MODULE, 'meatshop_manufacturer_iblock_id', $manufacturerIblockId);
    echo "Инфоблок 'Производители (meatshop)' создан, ID = $manufacturerIblockId\n";
} else {
    echo "Инфоблок 'Производители (meatshop)' уже существует, ID = $manufacturerIblockId\n";
}

// 4. Инфоблок "Тип товара"
$productTypeIblockId = (int) Option::get(MEATSHOP_MODULE, 'meatshop_product_type_iblock_id', 0);

if (!$productTypeIblockId) {
    $ib = new \CIBlock();
    $productTypeIblockId = $ib->Add([
        'ACTIVE'         => 'Y',
        'NAME'           => 'Тип товара (meatshop)',
        'CODE'           => 'meatshop_product_types',
        'API_CODE'       => 'meatshopProductTypes',
        'IBLOCK_TYPE_ID' => MEATSHOP_IBLOCK_TYPE,
        'SITE_ID'        => ['s1'],
        'GROUP_ID'       => ['2' => 'X'],
    ]);

    if (!$productTypeIblockId) {
        die('Ошибка создания инфоблока "Тип товара": '.$ib->LAST_ERROR);
    }

    (new \CIBlockProperty())->Add([
        'IBLOCK_ID'     => $productTypeIblockId,
        'NAME'          => 'Класс',
        'CODE'          => 'CLASS',
        'PROPERTY_TYPE' => 'S',
        'MULTIPLE'      => 'N',
    ]);

    Option::set(MEATSHOP_MODULE, 'meatshop_product_type_iblock_id', $productTypeIblockId);
    echo "Инфоблок 'Тип товара (meatshop)' создан, ID = $productTypeIblockId\n";
} else {
    echo "Инфоблок 'Тип товара (meatshop)' уже существует, ID = $productTypeIblockId\n";
}

// 5. Демо-данные (можно закомментировать после первого запуска)
$el = new \CIBlockElement();

$manufacturerId = $el->Add([
    'IBLOCK_ID'       => $manufacturerIblockId,
    'NAME'            => 'Мясной двор',
    'PROPERTY_VALUES' => ['TYPE' => 'Фермерское хозяйство'],
]);

$productTypeId = $el->Add([
    'IBLOCK_ID'       => $productTypeIblockId,
    'NAME'            => 'Свинина',
    'PROPERTY_VALUES' => ['CLASS' => 'Мясо'],
]);

if ($manufacturerId && $productTypeId) {
    \App\ORMDZ\Model\ProductTable::add([
        'NAME'            => 'Свиная вырезка',
        'PRICE'           => 590,
        'PRODUCT_TYPE_ID' => $productTypeId,
        'MANUFACTURER_ID' => $manufacturerId,
    ]);
    echo "Демо-товар добавлен\n";
} elseif (!$manufacturerId || !$productTypeId) {
    echo "Демо-товар не добавлен - демо-элементы уже могли существовать\n";
}

echo "Установка meatshop завершена.\n";
