<?php

namespace App\ORMDZ\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\Relations\Reference as ReferenceField;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Iblock\ElementTable;
use Bitrix\Main\SystemException;

/**
 * ORM-модель таблицы "Товары"
 */
class ProductTable extends DataManager
{
    /**
     * @return string
     */
    public static function getTableName(): string
    {
        return 'meatshop_product';
    }

    /**
     * @throws SystemException
     *
     * @return array
     */
    public static function getMap(): array
    {
        return [
            //первичный ключ
            new IntegerField('ID', [
                'primary' => true,
                'autocomplete' => true,
            ]),

            //наименование товара
            new StringField('NAME', [
                'required' => true,
            ]),

            //цена товара
            new IntegerField('PRICE', [
                'required' => true,
            ]),

            //тип товара
            new IntegerField('PRODUCT_TYPE_ID', [
                'required' => true,
            ]),

            //производитель
            new IntegerField('MANUFACTURER_ID', [
                'required' => true,
            ]),

            //дата и время добавления
            new DatetimeField('CREATED_AT', [
                'default_value' => function() {
                    return new \Bitrix\Main\Type\DateTime();
                },
            ]),

            //связываем элементы с инфоблоками по ID
            new ReferenceField(
                'PRODUCT_TYPE_ELEMENT',
                ElementTable::class,
                ['=this.PRODUCT_TYPE_ID' => 'ref.ID']
            ),

            new ReferenceField(
                'MANUFACTURER_ELEMENT',
                ElementTable::class,
                ['=this.MANUFACTURER_ID' => 'ref.ID']
            )
        ];
    }
}
