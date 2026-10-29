<?php

namespace Alex\Newmodule\Rest\Model;

use Bitrix\Iblock\ElementTable;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\Relations\Reference as ReferenceField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\Type\DateTime;

/**
 * ORM-модель таблицы товаров мясной лавки.
 *
 * Таблица та же, что в домашней работе по ORM (meatshop_product): модуль
 * работает с существующими данными, но держит собственную модель, чтобы
 * не зависеть от кода вне модулей и переноситься целиком.
 */
class ProductTable extends DataManager
{
    public static function getTableName(): string
    {
        return 'meatshop_product';
    }

    public static function getMap(): array
    {
        return [
            new IntegerField('ID', [
                'primary'      => true,
                'autocomplete' => true,
            ]),

            new StringField('NAME', [
                'required' => true,
            ]),

            new IntegerField('PRICE', [
                'required' => true,
            ]),

            new IntegerField('PRODUCT_TYPE_ID', [
                'required' => true,
            ]),

            new IntegerField('MANUFACTURER_ID', [
                'required' => true,
            ]),

            new DatetimeField('CREATED_AT', [
                'default_value' => static function () {
                    return new DateTime();
                },
            ]),

            // Связи с элементами инфоблоков — по ним метод чтения отдаёт наименования
            new ReferenceField(
                'PRODUCT_TYPE_ELEMENT',
                ElementTable::class,
                ['=this.PRODUCT_TYPE_ID' => 'ref.ID']
            ),

            new ReferenceField(
                'MANUFACTURER_ELEMENT',
                ElementTable::class,
                ['=this.MANUFACTURER_ID' => 'ref.ID']
            ),
        ];
    }
}
