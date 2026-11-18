<?php

namespace Alex\Autoservice\Migrations;

use Alex\Autoservice\Migration\AbstractMigration;
use Alex\Autoservice\Model\CarTable;
use Bitrix\Main\Application;
use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

/**
 * Таблица автомобилей клиентов.
 *
 * Уникальный индекс по госномеру — страховка уровня БД: сервисный слой
 * тоже проверяет дубли, но два одновременных запроса проверку обойдут.
 */
class Migration20261109090000CarTable extends AbstractMigration
{
    private const INDEX_NAME = 'ux_alex_autoservice_car_number';

    /**
     * @return string
     */
    public function getDescription(): string
    {
        return Loc::getMessage('ALEX_AUTOSERVICE_MIGRATION_CAR_TABLE_DESCRIPTION');
    }

    /**
     * @return void
     */
    public function up(): void
    {
        $connection = Application::getConnection();
        $tableName  = CarTable::getTableName();

        if ($connection->isTableExists($tableName)) {
            $this->note(Loc::getMessage('ALEX_AUTOSERVICE_CAR_TABLE_EXISTS', ['#TABLE#' => $tableName]));
        } else {
            CarTable::getEntity()->createDbTable();

            $this->note(Loc::getMessage('ALEX_AUTOSERVICE_CAR_TABLE_CREATED', ['#TABLE#' => $tableName]));
        }

        if ($connection->isIndexExists($tableName, ['NUMBER'])) {
            $this->note(Loc::getMessage('ALEX_AUTOSERVICE_CAR_INDEX_EXISTS'));
        } else {
            $connection->query(
                'CREATE UNIQUE INDEX ' . self::INDEX_NAME . ' ON ' . $tableName . ' (NUMBER)'
            );

            $this->note(Loc::getMessage('ALEX_AUTOSERVICE_CAR_INDEX_CREATED', ['#INDEX#' => self::INDEX_NAME]));
        }
    }

    /**
     * @return void
     */
    public function down(): void
    {
        $connection = Application::getConnection();
        $tableName  = CarTable::getTableName();

        if (!$connection->isTableExists($tableName)) {
            $this->note(Loc::getMessage('ALEX_AUTOSERVICE_CAR_TABLE_MISSING', ['#TABLE#' => $tableName]));

            return;
        }

        $connection->dropTable($tableName);

        $this->note(Loc::getMessage('ALEX_AUTOSERVICE_CAR_TABLE_DROPPED', ['#TABLE#' => $tableName]));
    }
}
