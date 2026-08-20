<?php

namespace Alex\Newmodule;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\DatetimeField;

class ItemTable extends DataManager
{
    public static function getTableName(): string
    {
        return 'alex_newmodule_item';
    }

    public static function getMap(): array
    {
        return [
            new IntegerField('ID', [
                'primary' => true,
                'autocomplete' => true,
            ]),

            new StringField('NAME', [
                'required' => true,
            ]),

            new IntegerField('VALUE', [
                'required' => true,
            ]),

            new DatetimeField('CREATED_AT', [
                'default_value' => function () {
                    return new \Bitrix\Main\Type\DateTime();
                },
            ]),
        ];
    }
}
