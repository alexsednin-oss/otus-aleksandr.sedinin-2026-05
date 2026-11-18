<?php

namespace Alex\Autoservice\Service;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

/**
 * Запчасти в каталоге товаров Битрикс24.
 *
 * По ТЗ запчасти живут именно в каталоге, а не в отдельной сущности:
 * так они попадают в товарные позиции заказ-нарядов и заявок на закупку
 * штатными средствами. Остаток — это количество товара.
 */
class PartsCatalog
{
    private const MODULE_ID = 'alex.autoservice';

    public const OPTION_SECTION_ID = 'parts_section_id';

    /** Признак раздела и товаров модуля */
    private const XML_PREFIX = 'alex.autoservice.part.';
    private const SECTION_XML_ID = 'alex.autoservice.parts';

    /**
     * Инфоблок каталога товаров CRM.
     *
     * @throws \RuntimeException
     *
     * @return int
     */
    public static function getCatalogId(): int
    {
        if (!Loader::includeModule('crm') || !Loader::includeModule('iblock')) {
            throw new \RuntimeException(Loc::getMessage('ALEX_AUTOSERVICE_PARTS_NO_MODULES'));
        }

        $catalogId = (int) Option::get('crm', 'default_product_catalog_id', 0);

        if (!$catalogId) {
            throw new \RuntimeException(Loc::getMessage('ALEX_AUTOSERVICE_PARTS_NO_CATALOG'));
        }

        return $catalogId;
    }

    /**
     * Раздел «Запчасти» в каталоге. Создаёт при отсутствии.
     *
     * @param string $name
     *
     * @throws \RuntimeException
     *
     * @return array{ID:int, CREATED:bool}
     */
    public static function ensureSection(string $name): array
    {
        $catalogId = self::getCatalogId();

        $existing = \CIBlockSection::GetList(
            [],
            ['IBLOCK_ID' => $catalogId, 'XML_ID' => self::SECTION_XML_ID, 'CHECK_PERMISSIONS' => 'N'],
            false,
            ['ID']
        )->Fetch();

        if ($existing) {
            return ['ID' => (int) $existing['ID'], 'CREATED' => false];
        }

        $section   = new \CIBlockSection();
        $sectionId = (int) $section->Add([
            'IBLOCK_ID' => $catalogId,
            'NAME'      => $name,
            'XML_ID'    => self::SECTION_XML_ID,
            'ACTIVE'    => 'Y',
            'SORT'      => 100,
        ]);

        if (!$sectionId) {
            throw new \RuntimeException(Loc::getMessage('ALEX_AUTOSERVICE_PARTS_SECTION_ERROR', [
                '#ERROR#' => $section->LAST_ERROR,
            ]));
        }

        return ['ID' => $sectionId, 'CREATED' => true];
    }

    /**
     * @return int ID раздела запчастей или 0
     */
    public static function getSectionId(): int
    {
        return (int) Option::get(self::MODULE_ID, self::OPTION_SECTION_ID, 0);
    }

    /**
     * Добавляет запчасть в каталог, если её ещё нет.
     *
     * @param string $code     код запчасти, он же часть XML_ID
     * @param string $name
     * @param float  $price
     * @param int    $quantity стартовый остаток
     *
     * @throws \RuntimeException
     *
     * @return array{ID:int, CREATED:bool}
     */
    public static function ensurePart(string $code, string $name, float $price, int $quantity): array
    {
        $catalogId = self::getCatalogId();
        $sectionId = self::getSectionId();

        if (!$sectionId) {
            throw new \RuntimeException(Loc::getMessage('ALEX_AUTOSERVICE_PARTS_NO_SECTION'));
        }

        if (!Loader::includeModule('catalog')) {
            throw new \RuntimeException(Loc::getMessage('ALEX_AUTOSERVICE_PARTS_NO_CATALOG_MODULE'));
        }

        $xmlId    = self::XML_PREFIX . $code;
        $existing = \CIBlockElement::GetList(
            [],
            ['IBLOCK_ID' => $catalogId, 'XML_ID' => $xmlId, 'CHECK_PERMISSIONS' => 'N'],
            false,
            false,
            ['ID']
        )->Fetch();

        if ($existing) {
            return ['ID' => (int) $existing['ID'], 'CREATED' => false];
        }

        $element   = new \CIBlockElement();
        $elementId = (int) $element->Add([
            'IBLOCK_ID'         => $catalogId,
            'IBLOCK_SECTION_ID' => $sectionId,
            'NAME'              => $name,
            'XML_ID'            => $xmlId,
            'ACTIVE'            => 'Y',
            'SORT'              => 500,
        ]);

        if (!$elementId) {
            throw new \RuntimeException(Loc::getMessage('ALEX_AUTOSERVICE_PARTS_ELEMENT_ERROR', [
                '#NAME#'  => $name,
                '#ERROR#' => $element->LAST_ERROR,
            ]));
        }

        // Карточка товара: без неё элемент каталога не считается товаром
        // и не попадёт в товарные позиции
        \CCatalogProduct::Add([
            'ID'             => $elementId,
            'QUANTITY'       => $quantity,
            'QUANTITY_TRACE' => 'Y',
            'CAN_BUY_ZERO'   => 'Y',
            'VAT_INCLUDED'   => 'Y',
        ]);

        \CPrice::SetBasePrice($elementId, $price, \CCrmCurrency::GetBaseCurrencyID());

        return ['ID' => $elementId, 'CREATED' => true];
    }

    /**
     * Все запчасти раздела с остатками и ценами.
     *
     * @return array<int, array{ID:int, NAME:string, XML_ID:string, QUANTITY:int, PRICE:float}>
     */
    public static function getParts(): array
    {
        $sectionId = self::getSectionId();

        if (!$sectionId || !Loader::includeModule('catalog')) {
            return [];
        }

        $catalogId = self::getCatalogId();

        $res = \CIBlockElement::GetList(
            ['NAME' => 'ASC'],
            [
                'IBLOCK_ID'         => $catalogId,
                'SECTION_ID'        => $sectionId,
                'ACTIVE'            => 'Y',
                'CHECK_PERMISSIONS' => 'N',
            ],
            false,
            false,
            ['ID', 'NAME', 'XML_ID']
        );

        $parts = [];

        while ($row = $res->Fetch()) {
            $product = \CCatalogProduct::GetByID((int) $row['ID']);
            $price   = \CPrice::GetBasePrice((int) $row['ID']);

            $parts[] = [
                'ID'       => (int) $row['ID'],
                'NAME'     => (string) $row['NAME'],
                'XML_ID'   => (string) $row['XML_ID'],
                'QUANTITY' => (int) ($product['QUANTITY'] ?? 0),
                'PRICE'    => (float) ($price['PRICE'] ?? 0),
            ];
        }

        return $parts;
    }

    /**
     * @param int $productId
     *
     * @return int
     */
    public static function getQuantity(int $productId): int
    {
        if (!Loader::includeModule('catalog')) {
            return 0;
        }

        $product = \CCatalogProduct::GetByID($productId);

        return (int) ($product['QUANTITY'] ?? 0);
    }

    /**
     * Устанавливает остаток запчасти.
     *
     * @param int $productId
     * @param int $quantity
     *
     * @return bool
     */
    public static function setQuantity(int $productId, int $quantity): bool
    {
        if (!Loader::includeModule('catalog')) {
            return false;
        }

        return (bool) \CCatalogProduct::Update($productId, ['QUANTITY' => max(0, $quantity)]);
    }

    /**
     * Удаляет раздел запчастей вместе с товарами — для отката миграции.
     *
     * @return int сколько товаров удалено
     */
    public static function removeSection(): int
    {
        $sectionId = self::getSectionId();

        if (!$sectionId) {
            return 0;
        }

        $removed = 0;

        foreach (self::getParts() as $part) {
            if (\CIBlockElement::Delete($part['ID'])) {
                $removed++;
            }
        }

        \CIBlockSection::Delete($sectionId);

        return $removed;
    }
}
