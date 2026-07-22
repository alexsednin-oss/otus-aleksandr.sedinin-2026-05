<?php

define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);

require_once $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/prolog_before.php';

use Bitrix\Main\Loader;
use Bitrix\Main\Config\Option;

Loader::includeModule('iblock');

require_once __DIR__.'/../../App/ORMDZ/Model/ProductTable.php';

const MEATSHOP_MODULE = 'main';

// 1. Удаляем инфоблоки и их элементы
// ⚠️ Тип "lists" НЕ удаляем - это системный тип, общий для всего сайта
$manufacturerIblockId = (int) Option::get(MEATSHOP_MODULE, 'meatshop_manufacturer_iblock_id', 0);
$productTypeIblockId  = (int) Option::get(MEATSHOP_MODULE, 'meatshop_product_type_iblock_id', 0);

foreach ([$manufacturerIblockId, $productTypeIblockId] as $iblockId) {
    if ($iblockId && \CIBlock::GetByID($iblockId)->Fetch()) {
        $elementsResult = \CIBlockElement::GetList(
            [],
            ['IBLOCK_ID' => $iblockId],
            false,
            false,
            ['ID']
        );

        while ($element = $elementsResult->Fetch()) {
            \CIBlockElement::Delete($element['ID']);
        }

        \CIBlock::Delete($iblockId);
        echo "Инфоблок ID=$iblockId и его элементы удалены\n";
    }
}

// 2. Удаляем кастомную таблицу
$connection = \Bitrix\Main\Application::getConnection();
$tableName  = \App\ORMDZ\Model\ProductTable::getTableName();

if ($connection->isTableExists($tableName)) {
    $connection->dropTable($tableName);
    echo "Таблица $tableName удалена\n";
}

// 3. Чистим опции
Option::delete(MEATSHOP_MODULE, ['name' => 'meatshop_manufacturer_iblock_id']);
Option::delete(MEATSHOP_MODULE, ['name' => 'meatshop_product_type_iblock_id']);

echo "Деинсталляция meatshop завершена.\n";
