<?php

namespace Alex\Autoservice\Model;

use Bitrix\Main\Loader;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\Relations\Reference as ReferenceField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\Validators\LengthValidator;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Localization\Loc;

/**
 * Автомобили клиентов.
 *
 * Отдельная таблица, а не смарт-процесс: сущность нужна прежде всего как
 * справочник для вкладки «Гараж» и внешних интеграций, а карточку и права
 * CRM мы в этой задаче всё равно переопределяем своими средствами.
 *
 * Владелец хранится как ID контакта CRM, связь описана Reference —
 * по ней метод чтения отдаёт ФИО владельца вместо идентификатора.
 */
class CarTable extends DataManager
{
    /**
     * @return string
     */
    public static function getTableName(): string
    {
        return 'alex_autoservice_car';
    }

    /**
     * @return array
     */
    public static function getMap(): array
    {
        $map = [
            new IntegerField('ID', [
                'primary'      => true,
                'autocomplete' => true,
            ]),

            // Владелец — контакт CRM
            new IntegerField('CONTACT_ID', [
                'required' => true,
            ]),

            new StringField('BRAND', [
                'required'   => true,
                'validation' => static fn (): array => [new LengthValidator(1, 100)],
            ]),

            new StringField('MODEL', [
                'required'   => true,
                'validation' => static fn (): array => [new LengthValidator(1, 100)],
            ]),

            new IntegerField('BRAND_ID', [
                'nullable' => true,
                'title'    => Loc::getMessage('ALEX_AUTOSERVICE_CAR_FIELD_BRAND_ID'),
            ]),

            new IntegerField('MODEL_ID', [
                'nullable' => true,
                'title'    => Loc::getMessage('ALEX_AUTOSERVICE_CAR_FIELD_MODEL_ID'),
            ]),

            // Госномер в нормализованном виде: верхний регистр, без пробелов
            new StringField('NUMBER', [
                'required'   => true,
                'validation' => static fn (): array => [new LengthValidator(1, 32)],
            ]),

            new IntegerField('YEAR', [
                'nullable' => true,
            ]),

            new StringField('COLOR', [
                'nullable'   => true,
                'validation' => static fn (): array => [new LengthValidator(0, 50)],
            ]),

            new IntegerField('MILEAGE', [
                'nullable' => true,
            ]),

            new DatetimeField('CREATED_AT', [
                'default_value' => static fn (): DateTime => new DateTime(),
            ]),

            new DatetimeField('UPDATED_AT', [
                'nullable' => true,
            ]),
        ];

        // Связь с контактом добавляем только когда CRM доступен,
        // иначе компиляция сущности упадёт на отсутствующем классе
        if (Loader::includeModule('crm')) {
            $map[] = new ReferenceField(
                'CONTACT',
                \Bitrix\Crm\ContactTable::class,
                ['=this.CONTACT_ID' => 'ref.ID']
            );
        }

        return $map;
    }
}
