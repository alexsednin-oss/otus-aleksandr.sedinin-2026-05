<?php

namespace Alex\Autoservice\Service;

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\UserFieldTable;

Loc::loadMessages(__FILE__);

/**
 * Создание и удаление пользовательских полей.
 *
 * Отдельный слой нужен, потому что поля заводятся у трёх разных сущностей:
 * у смарт-процесса автомобилей, у смарт-процесса заявок и у сделки.
 */
class UserField
{
    /**
     * @param string $entityId
     * @param array  $field
     *
     * @throws \RuntimeException
     *
     * @return array{ID:int, CREATED:bool}
     */
    public static function ensure(string $entityId, array $field): array
    {
        $fieldName = (string) $field['FIELD_NAME'];
        $existing  = self::getId($entityId, $fieldName);

        if ($existing) {
            return ['ID' => $existing, 'CREATED' => false];
        }

        $label = (string) ($field['LABEL'] ?? $fieldName);

        $entity = new \CUserTypeEntity();

        $id = (int) $entity->Add([
            'ENTITY_ID'         => $entityId,
            'FIELD_NAME'        => $fieldName,
            'USER_TYPE_ID'      => (string) $field['USER_TYPE_ID'],
            'XML_ID'            => (string) ($field['XML_ID'] ?? $fieldName),
            'SORT'              => (int) ($field['SORT'] ?? 500),
            'MULTIPLE'          => (string) ($field['MULTIPLE'] ?? 'N'),
            'MANDATORY'         => (string) ($field['MANDATORY'] ?? 'N'),
            'SHOW_FILTER'       => (string) ($field['SHOW_FILTER'] ?? 'N'),
            'SHOW_IN_LIST'      => (string) ($field['SHOW_IN_LIST'] ?? 'Y'),
            'EDIT_IN_LIST'      => (string) ($field['EDIT_IN_LIST'] ?? 'Y'),
            'IS_SEARCHABLE'     => (string) ($field['IS_SEARCHABLE'] ?? 'N'),
            'SETTINGS'          => (array) ($field['SETTINGS'] ?? []),
            'EDIT_FORM_LABEL'   => ['ru' => $label, 'en' => $label],
            'LIST_COLUMN_LABEL' => ['ru' => $label, 'en' => $label],
            'LIST_FILTER_LABEL' => ['ru' => $label, 'en' => $label],
        ]);

        if (!$id) {
            global $APPLICATION;

            $error     = '';
            $exception = is_object($APPLICATION) ? $APPLICATION->GetException() : null;

            if ($exception) {
                $error = $exception->GetString();
            }

            throw new \RuntimeException(Loc::getMessage('ALEX_AUTOSERVICE_UF_ADD_ERROR', [
                '#FIELD#' => $fieldName,
                '#ERROR#' => $error,
            ]));
        }

        return ['ID' => $id, 'CREATED' => true];
    }

    /**
     * @param string $entityId
     * @param string $fieldName
     *
     * @return bool
     */
    public static function remove(string $entityId, string $fieldName): bool
    {
        $id = self::getId($entityId, $fieldName);

        if (!$id) {
            return false;
        }

        return (bool) (new \CUserTypeEntity())->Delete($id);
    }

    /**
     * @param string $entityId
     * @param string $fieldName
     *
     * @return int
     */
    public static function getId(string $entityId, string $fieldName): int
    {
        $row = UserFieldTable::query()
            ->setSelect(['ID'])
            ->where('ENTITY_ID', $entityId)
            ->where('FIELD_NAME', $fieldName)
            ->setLimit(1)
            ->fetch();

        return $row ? (int) $row['ID'] : 0;
    }

    /**
     * @param string $entityId
     * @param string $prefix
     *
     * @return int
     */
    public static function removeByPrefix(string $entityId, string $prefix): int
    {
        $rows = UserFieldTable::query()
            ->setSelect(['ID', 'FIELD_NAME'])
            ->where('ENTITY_ID', $entityId)
            ->whereLike('FIELD_NAME', $prefix . '%')
            ->fetchAll();

        $entity  = new \CUserTypeEntity();
        $removed = 0;

        foreach ($rows as $row) {
            if ($entity->Delete((int) $row['ID'])) {
                $removed++;
            }
        }

        return $removed;
    }
}
