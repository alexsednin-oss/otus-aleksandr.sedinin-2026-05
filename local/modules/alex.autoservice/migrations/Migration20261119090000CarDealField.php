<?php

namespace Alex\Autoservice\Migrations;

use Alex\Autoservice\Migration\AbstractMigration;
use Alex\Autoservice\Service\CarCrudService;
use Alex\Autoservice\Service\UserField;
use Alex\Autoservice\UserField\CarType;
use Bitrix\Main\Application;
use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

/**
 * Перевод поля сделки «Автомобиль» на собственный тип.
 *
 * Сменить USER_TYPE_ID у существующего поля Битрикс не позволяет, поэтому
 * поле пересоздаётся. Значения в сделках сохраняются вручную: удаление
 * поля сносит колонку в таблице значений вместе с данными.
 */
class Migration20261119090000CarDealField extends AbstractMigration
{
    private const VALUES_TABLE = 'b_uts_crm_deal';

    /**
     * @return string
     */
    public function getDescription(): string
    {
        return Loc::getMessage('ALEX_AUTOSERVICE_MIGRATION_CAR_FIELD_DESCRIPTION');
    }

    /**
     * @return void
     */
    public function up(): void
    {
        $this->convert(CarType::USER_TYPE_ID, 'integer');
    }

    /**
     * @return void
     */
    public function down(): void
    {
        $this->convert('integer', CarType::USER_TYPE_ID);
    }

    /**
     * Пересоздаёт поле с нужным типом, сохранив значения.
     *
     * @param string $target текущий тип, к которому приводим
     * @param string $source тип, который ожидаем увидеть сейчас
     *
     * @return void
     */
    private function convert(string $target, string $source): void
    {
        $this->requireModules('crm');

        $fieldName = CarCrudService::DEAL_CAR_FIELD;
        $current   = $this->getFieldType($fieldName);

        if ($current === $target) {
            $this->note(Loc::getMessage('ALEX_AUTOSERVICE_CAR_FIELD_ALREADY', [
                '#FIELD#' => $fieldName,
                '#TYPE#'  => $target,
            ]));

            return;
        }

        if ($current !== $source && $current !== '') {
            $this->note(Loc::getMessage('ALEX_AUTOSERVICE_CAR_FIELD_UNEXPECTED', [
                '#FIELD#' => $fieldName,
                '#TYPE#'  => $current,
            ]));
        }

        $values = $this->readValues($fieldName);

        $this->note(Loc::getMessage('ALEX_AUTOSERVICE_CAR_FIELD_VALUES_SAVED', [
            '#COUNT#' => count($values),
        ]));

        if ($current !== '') {
            UserField::remove('CRM_DEAL', $fieldName);

            $this->note(Loc::getMessage('ALEX_AUTOSERVICE_CAR_FIELD_DROPPED', ['#FIELD#' => $fieldName]));
        }

        $field = UserField::ensure('CRM_DEAL', [
            'FIELD_NAME'   => $fieldName,
            'USER_TYPE_ID' => $target,
            'LABEL'        => Loc::getMessage('ALEX_AUTOSERVICE_CAR_FIELD_LABEL'),
            'SORT'         => 100,
            'SHOW_FILTER'  => 'E',
        ]);

        $this->note(Loc::getMessage('ALEX_AUTOSERVICE_CAR_FIELD_CREATED', [
            '#FIELD#' => $fieldName,
            '#TYPE#'  => $target,
            '#ID#'    => $field['ID'],
        ]));

        $restored = $this->restoreValues($fieldName, $values);

        $this->note(Loc::getMessage('ALEX_AUTOSERVICE_CAR_FIELD_VALUES_RESTORED', [
            '#COUNT#' => $restored,
        ]));
    }

    /**
     * @param string $fieldName
     *
     * @return string тип поля или пустая строка, если поля нет
     */
    private function getFieldType(string $fieldName): string
    {
        $row = \Bitrix\Main\UserFieldTable::query()
            ->setSelect(['USER_TYPE_ID'])
            ->where('ENTITY_ID', 'CRM_DEAL')
            ->where('FIELD_NAME', $fieldName)
            ->setLimit(1)
            ->fetch();

        return $row ? (string) $row['USER_TYPE_ID'] : '';
    }

    /**
     * @param string $fieldName
     *
     * @return array<int, int> VALUE_ID => идентификатор автомобиля
     */
    private function readValues(string $fieldName): array
    {
        $connection = Application::getConnection();

        if (!$connection->isTableExists(self::VALUES_TABLE)) {
            return [];
        }

        $columns = array_column(
            $connection->query('SHOW COLUMNS FROM ' . self::VALUES_TABLE)->fetchAll(),
            'Field'
        );

        if (!in_array($fieldName, $columns, true)) {
            return [];
        }

        $rows = $connection->query(
            'SELECT VALUE_ID, ' . $fieldName . ' AS CAR_ID FROM ' . self::VALUES_TABLE
            . ' WHERE ' . $fieldName . ' IS NOT NULL AND ' . $fieldName . ' > 0'
        )->fetchAll();

        $values = [];

        foreach ($rows as $row) {
            $values[(int) $row['VALUE_ID']] = (int) $row['CAR_ID'];
        }

        return $values;
    }

    /**
     * @param string          $fieldName
     * @param array<int, int> $values
     *
     * @return int сколько строк восстановлено
     */
    private function restoreValues(string $fieldName, array $values): int
    {
        if (!$values) {
            return 0;
        }

        $connection = Application::getConnection();
        $restored   = 0;

        foreach ($values as $valueId => $carId) {
            $connection->queryExecute(sprintf(
                'UPDATE %s SET %s = %d WHERE VALUE_ID = %d',
                self::VALUES_TABLE,
                $fieldName,
                $carId,
                $valueId
            ));

            $restored++;
        }

        return $restored;
    }
}
