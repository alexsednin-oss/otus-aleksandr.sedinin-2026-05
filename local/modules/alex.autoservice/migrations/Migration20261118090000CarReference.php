<?php

namespace Alex\Autoservice\Migrations;

use Alex\Autoservice\Migration\AbstractMigration;
use Alex\Autoservice\Model\CarTable;
use Alex\Autoservice\Service\CarReference;
use Bitrix\Main\Application;
use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

/**
 * Справочники марок и моделей.
 *
 * Марки и модели переезжают из свободного текста в highload-блоки:
 * интерфейс выбирает из списка, внешние системы продолжают слать
 * названия строкой, а в карточке автомобиля хранится и то и другое.
 */
class Migration20261118090000CarReference extends AbstractMigration
{
    /**
     * @return string
     */
    public function getDescription(): string
    {
        return Loc::getMessage('ALEX_AUTOSERVICE_MIGRATION_REFERENCE_DESCRIPTION');
    }

    /**
     * @return void
     */
    public function up(): void
    {
        $this->requireModules('highloadblock');

        $blocks = CarReference::ensureBlocks();

        $this->note(Loc::getMessage('ALEX_AUTOSERVICE_REFERENCE_BLOCKS_READY', [
            '#BRAND#' => $blocks['BRAND'],
            '#MODEL#' => $blocks['MODEL'],
        ]));

        foreach (CarReference::seed() as $line) {
            $this->note($line);
        }

        $this->addColumns();
        $this->fillReferences();
    }

    /**
     * @return void
     */
    public function down(): void
    {
        $this->requireModules('highloadblock');

        $connection = Application::getConnection();
        $table      = CarTable::getTableName();

        foreach (['BRAND_ID', 'MODEL_ID'] as $column) {
            if ($connection->isTableExists($table)
                && isset($connection->getTableFields($table)[$column])) {

                $connection->queryExecute('ALTER TABLE ' . $table . ' DROP COLUMN ' . $column);

                $this->note(Loc::getMessage('ALEX_AUTOSERVICE_REFERENCE_COLUMN_DROPPED', ['#COLUMN#' => $column]));
            }
        }

        foreach (CarReference::removeBlocks() as $line) {
            $this->note($line);
        }
    }

    /**
     * Колонки добавляем вручную: таблица уже создана предыдущей миграцией,
     * а пересоздавать её нельзя — в ней данные.
     *
     * @return void
     */
    private function addColumns(): void
    {
        $connection = Application::getConnection();
        $table      = CarTable::getTableName();

        if (!$connection->isTableExists($table)) {
            $this->fail(Loc::getMessage('ALEX_AUTOSERVICE_REFERENCE_NO_TABLE', ['#TABLE#' => $table]));
        }

        $fields  = $this->tableColumns($table);
        $changed = false;

        foreach (['BRAND_ID', 'MODEL_ID'] as $column) {
            if (in_array($column, $fields, true)) {
                $this->note(Loc::getMessage('ALEX_AUTOSERVICE_REFERENCE_COLUMN_EXISTS', ['#COLUMN#' => $column]));

                continue;
            }

            $connection->queryExecute('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' int NULL');

            $this->note(Loc::getMessage('ALEX_AUTOSERVICE_REFERENCE_COLUMN_ADDED', ['#COLUMN#' => $column]));

            $changed = true;
        }

        if ($changed) {
            $this->refreshTableFieldsCache($table);
        }
    }

    /**
     * Сбрасывает кэш колонок таблицы.
     *
     * @param string $table
     *
     * @throws \RuntimeException если кэш сбросить не удалось
     *
     * @return void
     */
    private function refreshTableFieldsCache(string $table): void
    {
        $connection = Application::getConnection();
        $reflection = new \ReflectionObject($connection);

        foreach (['tableColumnsCache', 'tableColumns'] as $property) {
            if (!$reflection->hasProperty($property)) {
                continue;
            }

            $cache = $reflection->getProperty($property);
            $cache->setAccessible(true);
            $cache->setValue($connection, []);
        }

        $fields = $this->tableColumns($table);

        foreach (['BRAND_ID', 'MODEL_ID'] as $column) {
            if (!in_array($column, $fields, true)) {
                $this->fail(Loc::getMessage('ALEX_AUTOSERVICE_REFERENCE_CACHE_STALE', ['#COLUMN#' => $column]));
            }
        }
    }

    /**
     * Проставляет ссылки на справочники существующим автомобилям.
     *
     * Марку или модель, которых нет в справочнике, миграция заводит сама:
     * терять данные при переходе на справочники недопустимо.
     *
     * @return void
     */
    private function fillReferences(): void
    {
        $connection = Application::getConnection();
        $table      = CarTable::getTableName();

        $rows = $connection->query(
            'SELECT ID, BRAND, MODEL FROM ' . $table . ' WHERE BRAND_ID IS NULL OR MODEL_ID IS NULL'
        )->fetchAll();

        if (!$rows) {
            $this->note(Loc::getMessage('ALEX_AUTOSERVICE_REFERENCE_NOTHING_TO_FILL'));

            return;
        }

        $filled  = 0;
        $created = 0;

        foreach ($rows as $row) {
            $brandName = trim((string) $row['BRAND']);
            $modelName = trim((string) $row['MODEL']);

            if ($brandName === '') {
                continue;
            }

            $brandId = CarReference::findBrand($brandName);

            if (!$brandId) {
                $brandId = CarReference::addReferenceBrand($brandName);
                $created++;
            }

            $modelId = $modelName !== '' ? CarReference::findModel($brandId, $modelName) : 0;

            if ($modelName !== '' && !$modelId) {
                $modelId = CarReference::addReferenceModel($brandId, $modelName);
                $created++;
            }

            $connection->queryExecute(sprintf(
                'UPDATE %s SET BRAND_ID = %d, MODEL_ID = %s WHERE ID = %d',
                $table,
                $brandId,
                $modelId ? (string) $modelId : 'NULL',
                (int) $row['ID']
            ));

            $filled++;
        }

        $this->note(Loc::getMessage('ALEX_AUTOSERVICE_REFERENCE_FILLED', [
            '#COUNT#'   => $filled,
            '#CREATED#' => $created,
        ]));
    }

    /**
     * Колонки таблицы, прочитанные из базы.
     *
     * Намеренно в обход Connection::getTableFields(): тот кэширует список
     * на весь хит, и после пересоздания таблицы соседней миграцией отдаёт
     * колонки, которых уже нет. Миграция, поверившая кэшу, молча пропускает
     * ALTER и оставляет таблицу неполной.
     *
     * @param string $table
     *
     * @return string[]
     */
    private function tableColumns(string $table): array
    {
        return array_column(
            Application::getConnection()->query('SHOW COLUMNS FROM ' . $table)->fetchAll(),
            'Field'
        );
    }
}
