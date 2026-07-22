<?php

namespace App\ORMDZ\Repository;

use App\ORMDZ\Model\ProductTable;
use App\ORMDZ\Model\ManufacturerElementTable;
use App\ORMDZ\Model\ProductTypeElementTable;

class ProductRepository
{
    public static function getList(): array
    {
        $products = ProductTable::query()
            ->setSelect([
                'ID',
                'NAME',
                'PRICE',
                'CREATED_AT',
                'PRODUCT_TYPE_ID',
                'MANUFACTURER_ID',
                'PRODUCT_TYPE_NAME' => 'PRODUCT_TYPE_ELEMENT.NAME',
                'MANUFACTURER_NAME' => 'MANUFACTURER_ELEMENT.NAME',
            ])
            ->fetchAll();

        if(empty($products)) {
            return [];
        }

        $productTypeIds     = array_unique(array_column($products, 'PRODUCT_TYPE_ID'));
        $manufacturerIds    = array_unique(array_column($products, 'MANUFACTURER_ID'));

        $productTypesProps  = self::getElementProperties(ProductTypeElementTable::class, $productTypeIds, ['CLASS']);
        $manufacturersProps = self::getElementProperties(ManufacturerElementTable::class, $manufacturerIds, ['TYPE']);

        foreach ($products as &$product) {
            $product['PRODUCT_TYPE_CLASS']   = $productTypesProps[$product['PRODUCT_TYPE_ID']]['CLASS'] ?? null;
            $product['MANUFACTURER_TYPE']    = $manufacturersProps[$product['MANUFACTURER_ID']]['TYPE'] ?? null;
        }
        unset($product);

        return $products;
    }

    private static function getElementProperties(string $modelClass, array $ids, array $propertyCodes): array
    {
        if(empty($ids)) {
            return [];
        }

        // Строим select с алиасами вида CLASS_VALUE => CLASS.VALUE, чтобы не конфликтовать с существующим полем CLASS
        $select = ['ID'];
        $aliasMap = [];
        foreach ($propertyCodes as $code) {
            $alias = $code.'_VALUE';
            $select[$alias] = $code.'.VALUE';
            $aliasMap[$code] = $alias;
        }

        $rows = $modelClass::getList([
            'select' => $select,
            'filter' => ['=ID' => $ids],
        ])->fetchAll();

        $result = [];
        foreach ($rows as $row) {
            $normalized = ['ID' => $row['ID']];
            foreach ($aliasMap as $originalCode => $alias) {
                $normalized[$originalCode] = $row[$alias] ?? null;
            }
            $result[$row['ID']] = $normalized;
        }

        return $result;
    }
}

