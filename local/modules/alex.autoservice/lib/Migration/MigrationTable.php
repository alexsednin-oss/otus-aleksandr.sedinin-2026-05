<?php

namespace Alex\Autoservice\Migration;

use Bitrix\Main\Application;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\Type\DateTime;

/**
 * Журнал применённых миграций.
 */
class MigrationTable extends DataManager
{
    /**
     * @return string
     */
    public static function getTableName(): string
    {
        return 'alex_autoservice_migration';
    }

    /**
     * @return array
     */
    public static function getMap(): array
    {
        return [
            new IntegerField('ID', [
                'primary'      => true,
                'autocomplete' => true,
            ]),

            new StringField('VERSION', [
                'required'   => true,
                'validation' => static fn (): array => [new \Bitrix\Main\ORM\Fields\Validators\LengthValidator(1, 32)],
            ]),

            new StringField('NAME', [
                'required' => true,
            ]),

            new DatetimeField('APPLIED_AT', [
                'default_value' => static fn (): DateTime => new DateTime(),
            ]),
        ];
    }

    /**
     * @return string строка отчёта
     */
    public static function ensureTable(): string
    {
        $connection = Application::getConnection();
        $tableName  = static::getTableName();

        if ($connection->isTableExists($tableName)) {
            return 'Таблица ' . $tableName . ' уже существует';
        }

        static::getEntity()->createDbTable();

        return 'Таблица ' . $tableName . ' создана';
    }

    /**
     * @return void
     */
    public static function dropTable(): void
    {
        $connection = Application::getConnection();

        if ($connection->isTableExists(static::getTableName())) {
            $connection->dropTable(static::getTableName());
        }
    }

    /**
     * @param string $version
     *
     * @return bool
     */
    public static function isApplied(string $version): bool
    {
        return (bool) static::query()
            ->setSelect(['ID'])
            ->where('VERSION', $version)
            ->setLimit(1)
            ->fetch();
    }
}
