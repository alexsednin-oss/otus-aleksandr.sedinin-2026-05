<?php

namespace Alex\Newmodule\Rest;

use Alex\Newmodule\Rest\Model\ProductTable;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;

/**
 * CRUD над таблицей meatshop_product.
 */
class ProductCrudService
{
    public const UPDATABLE_FIELDS = ['NAME', 'PRICE'];

    private const LIST_LIMIT = 50;

    /**
     * @param array $fields NAME, PRICE, MANUFACTURER, PRODUCT_TYPE
     *
     * @return array{ID:int, MANUFACTURER:array, PRODUCT_TYPE:array}
     */
    public static function create(array $fields): array
    {
        $name  = self::requireString($fields, 'NAME');
        $price = self::requirePrice($fields);

        $manufacturer = IblockElementResolver::resolve(
            MeatShopIblock::manufacturerId(),
            self::requireString($fields, 'MANUFACTURER'),
            'Производители'
        );

        $productType = IblockElementResolver::resolve(
            MeatShopIblock::productTypeId(),
            self::requireString($fields, 'PRODUCT_TYPE'),
            'Тип товара'
        );

        $result = ProductTable::add([
            'NAME'            => $name,
            'PRICE'           => $price,
            'MANUFACTURER_ID' => $manufacturer['ID'],
            'PRODUCT_TYPE_ID' => $productType['ID'],
        ]);

        if (!$result->isSuccess()) {
            throw new \RuntimeException('не удалось добавить товар: ' . implode('; ', $result->getErrorMessages()));
        }

        return [
            'ID'           => (int) $result->getId(),
            'MANUFACTURER' => $manufacturer,
            'PRODUCT_TYPE' => $productType,
        ];
    }

    /**
     * @param array $params
     * @return array
     * @throws \Bitrix\Main\ArgumentException
     * @throws \Bitrix\Main\LoaderException
     * @throws \Bitrix\Main\ObjectPropertyException
     * @throws \Bitrix\Main\SystemException
     */
    public static function read(array $params): array
    {
        if (!Loader::includeModule('iblock')) {
            throw new \RuntimeException('не установлен модуль iblock');
        }

        $query = ProductTable::query()
            ->setSelect([
                'ID',
                'NAME',
                'PRICE',
                'CREATED_AT',
                // имена тянем связями, ID инфоблоков наружу не отдаём
                'MANUFACTURER' => 'MANUFACTURER_ELEMENT.NAME',
                'PRODUCT_TYPE' => 'PRODUCT_TYPE_ELEMENT.NAME',
            ])
            ->setOrder(['ID' => 'ASC']);

        $id = (int) ($params['ID'] ?? 0);

        if ($id > 0) {
            $row = $query->where('ID', $id)->setLimit(1)->fetch();

            if (!$row) {
                throw new \OutOfBoundsException('товар с ID ' . $id . ' не найден');
            }

            return self::formatRow($row);
        }

        $limit  = (int) ($params['LIMIT'] ?? self::LIST_LIMIT);
        $limit  = max(1, min($limit, self::LIST_LIMIT));
        $offset = max(0, (int) ($params['OFFSET'] ?? 0));

        $rows = $query->setLimit($limit)->setOffset($offset)->fetchAll();

        return array_map([self::class, 'formatRow'], $rows);
    }

    /**
     * @param int $id
     * @param array $fields
     * @return array
     * @throws \Exception
     */
    public static function update(int $id, array $fields): array
    {
        if ($id <= 0) {
            throw new \InvalidArgumentException('не передан ID товара');
        }

        $forbidden = array_diff(array_keys($fields), self::UPDATABLE_FIELDS);

        if ($forbidden) {
            throw new \InvalidArgumentException(
                'обновлять можно только ' . implode(', ', self::UPDATABLE_FIELDS)
                . '; лишние поля: ' . implode(', ', $forbidden)
            );
        }

        $update = [];

        if (array_key_exists('NAME', $fields)) {
            $update['NAME'] = self::requireString($fields, 'NAME');
        }

        if (array_key_exists('PRICE', $fields)) {
            $update['PRICE'] = self::requirePrice($fields);
        }

        if (!$update) {
            throw new \InvalidArgumentException('нечего обновлять: передайте NAME и/или PRICE');
        }

        if (!self::exists($id)) {
            throw new \OutOfBoundsException('товар с ID ' . $id . ' не найден');
        }

        $result = ProductTable::update($id, $update);

        if (!$result->isSuccess()) {
            throw new \RuntimeException('не удалось обновить товар: ' . implode('; ', $result->getErrorMessages()));
        }

        return ['ID' => $id, 'UPDATED' => array_keys($update)];
    }

    /**
     * @param int $id
     * @return array
     * @throws \Exception
     */
    public static function delete(int $id): array
    {
        if ($id <= 0) {
            throw new \InvalidArgumentException('не передан ID товара');
        }

        if (!self::exists($id)) {
            throw new \OutOfBoundsException('товар с ID ' . $id . ' не найден');
        }

        $result = ProductTable::delete($id);

        if (!$result->isSuccess()) {
            throw new \RuntimeException('не удалось удалить товар: ' . implode('; ', $result->getErrorMessages()));
        }

        return ['ID' => $id, 'DELETED' => true];
    }

    /**
     * @param int $id
     * @return bool
     * @throws \Bitrix\Main\ArgumentException
     * @throws \Bitrix\Main\ObjectPropertyException
     * @throws \Bitrix\Main\SystemException
     */
    private static function exists(int $id): bool
    {
        return (bool) ProductTable::query()
            ->setSelect(['ID'])
            ->where('ID', $id)
            ->setLimit(1)
            ->fetch();
    }

    /**
     * @param array $row
     * @return array
     */
    private static function formatRow(array $row): array
    {
        $createdAt = $row['CREATED_AT'] ?? null;

        return [
            'ID'           => (int) $row['ID'],
            'NAME'         => (string) $row['NAME'],
            'PRICE'        => (int) $row['PRICE'],
            'MANUFACTURER' => $row['MANUFACTURER'] !== null ? (string) $row['MANUFACTURER'] : null,
            'PRODUCT_TYPE' => $row['PRODUCT_TYPE'] !== null ? (string) $row['PRODUCT_TYPE'] : null,
            'CREATED_AT'   => $createdAt instanceof DateTime ? $createdAt->toString() : $createdAt,
        ];
    }

    /**
     * @param array $fields
     * @param string $key
     * @return string
     */
    private static function requireString(array $fields, string $key): string
    {
        $value = trim((string) ($fields[$key] ?? ''));

        if ($value === '') {
            throw new \InvalidArgumentException('не передано обязательное поле ' . $key);
        }

        if (mb_strlen($value) > 255) {
            throw new \InvalidArgumentException('поле ' . $key . ' длиннее 255 символов');
        }

        return $value;
    }

    /**
     * @param array $fields
     * @return int
     */
    private static function requirePrice(array $fields): int
    {
        $raw = $fields['PRICE'] ?? null;

        if ($raw === null || $raw === '' || !is_numeric($raw)) {
            throw new \InvalidArgumentException('поле PRICE обязательно и должно быть числом');
        }

        if ((float) $raw < 0) {
            throw new \InvalidArgumentException('поле PRICE не может быть отрицательным');
        }

        return (int) round((float) $raw);
    }
}
