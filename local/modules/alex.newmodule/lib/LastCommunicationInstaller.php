<?php

namespace Alex\Newmodule;

use Bitrix\Main\Loader;

/**
 * Пользовательское поле контакта «Дата последней коммуникации»
 * и его позиция в карточке.
 */
class LastCommunicationInstaller
{
    public const ENTITY_ID   = 'CRM_CONTACT';
    public const FIELD_NAME  = 'UF_CRM_LAST_COMMUNICATION';
    public const FIELD_LABEL = 'Дата последней коммуникации';

    /**
     * @return string[] отчёт для страницы установки
     */
    public static function install(): array
    {
        $report = [];

        $report[] = self::createField();

        try {
            $report[] = self::moveFieldFirst();
        } catch (\Throwable $e) {
            $report[] = 'Поле не удалось поставить первым: ' . $e->getMessage()
                . '. Перетащите его наверх в настройках карточки контакта.';
        }

        return $report;
    }

    /**
     * При удалении модуля убираем поле вместе со значениями.
     */
    public static function uninstall(): void
    {
        $fieldId = self::getFieldId();

        if ($fieldId) {
            (new \CUserTypeEntity())->Delete($fieldId);
        }
    }

    public static function getFieldId(): int
    {
        $row = \CUserTypeEntity::GetList(
            [],
            ['ENTITY_ID' => self::ENTITY_ID, 'FIELD_NAME' => self::FIELD_NAME]
        )->Fetch();

        return $row ? (int) $row['ID'] : 0;
    }

    // ---------------------------------------------------------------- поле

    private static function createField(): string
    {
        $existingId = self::getFieldId();

        if ($existingId) {
            return 'Поле «' . self::FIELD_LABEL . '» уже есть, ID = ' . $existingId;
        }

        $entity = new \CUserTypeEntity();

        $fieldId = (int) $entity->Add([
            'ENTITY_ID'         => self::ENTITY_ID,
            'FIELD_NAME'        => self::FIELD_NAME,
            'USER_TYPE_ID'      => 'datetime',
            'XML_ID'            => self::FIELD_NAME,
            'SORT'              => 10,
            'MULTIPLE'          => 'N',
            'MANDATORY'         => 'N',
            'SHOW_FILTER'       => 'N',
            'SHOW_IN_LIST'      => 'Y',
            'EDIT_IN_LIST'      => 'Y',
            'IS_SEARCHABLE'     => 'N',
            'SETTINGS'          => ['DEFAULT_VALUE' => ['TYPE' => 'NONE', 'VALUE' => '']],
            'EDIT_FORM_LABEL'   => ['ru' => self::FIELD_LABEL, 'en' => 'Last communication date'],
            'LIST_COLUMN_LABEL' => ['ru' => self::FIELD_LABEL, 'en' => 'Last communication date'],
            'LIST_FILTER_LABEL' => ['ru' => self::FIELD_LABEL, 'en' => 'Last communication date'],
        ]);

        if (!$fieldId) {
            global $APPLICATION;

            $error = '';

            if (is_object($APPLICATION)) {
                $ex = $APPLICATION->GetException();

                if ($ex) {
                    $error = $ex->GetString();
                }
            }

            throw new \RuntimeException('Не удалось создать поле ' . self::FIELD_NAME . ': ' . $error);
        }

        return 'Создано поле «' . self::FIELD_LABEL . '» (' . self::FIELD_NAME . '), ID = ' . $fieldId;
    }

    // ------------------------------------------------------------ карточка

    /**
     * Ставит поле первым элементом первой секции карточки контакта
     * и делает конфигурацию общей для всех пользователей.
     */
    public static function moveFieldFirst(): string
    {
        if (!Loader::includeModule('crm')) {
            throw new \RuntimeException('модуль CRM не установлен');
        }

        $configClass = '\Bitrix\Crm\Entity\EntityEditorConfig';
        $scopeClass  = '\Bitrix\Crm\Entity\EntityEditorConfigScope';

        if (!class_exists($configClass) || !class_exists($scopeClass)) {
            throw new \RuntimeException('классы конфигурации карточки недоступны в этой версии CRM');
        }

        global $USER;

        $userId = is_object($USER) && $USER->IsAuthorized() ? (int) $USER->GetID() : 1;

        /** @var \Bitrix\Crm\Entity\EntityEditorConfig $config */
        $config = new $configClass(
            \CCrmOwnerType::Contact,
            $userId,
            constant($scopeClass . '::COMMON'),
            []
        );

        // get() — сохранённая конфигурация, её может не быть вовсе.
        // getConfiguration() возвращает ОБЪЕКТ Configuration и для записи не годится,
        // поэтому схему по умолчанию берём из getDefault() — это массив секций.
        $data = $config->get();

        if (!is_array($data) || !$data) {
            $data = $config->getDefault();
        }

        if (!is_array($data) || !$data) {
            throw new \RuntimeException('не удалось получить конфигурацию карточки контакта');
        }

        // Сначала убираем поле отовсюду, чтобы не было дубля
        // (CRM сама кладёт новые UF в секцию «Дополнительно»)
        foreach ($data as $key => $section) {
            if (!is_array($section) || !isset($section['elements']) || !is_array($section['elements'])) {
                continue;
            }

            $data[$key]['elements'] = array_values(array_filter(
                $section['elements'],
                static function ($element) {
                    $name = is_array($element) ? ($element['name'] ?? '') : '';

                    return (string) $name !== self::FIELD_NAME;
                }
            ));
        }

        // Первая секция, в которую можно положить поле
        $targetKey = null;

        foreach ($data as $key => $section) {
            if (is_array($section) && isset($section['elements']) && is_array($section['elements'])) {
                $targetKey = $key;
                break;
            }
        }

        if ($targetKey === null) {
            throw new \RuntimeException('в конфигурации карточки нет ни одной секции с полями');
        }

        array_unshift($data[$targetKey]['elements'], ['name' => self::FIELD_NAME]);

        $config->set($data);

        // Сбрасываем персональные настройки карточки, чтобы поле встало первым у всех
        if (method_exists($config, 'forceCommonScopeForAll')) {
            $config->forceCommonScopeForAll();
        }

        $sectionTitle = (string) ($data[$targetKey]['title'] ?? $data[$targetKey]['name'] ?? $targetKey);

        return 'Поле «' . self::FIELD_LABEL . '» поставлено первым в секции «' . $sectionTitle . '» карточки контакта';
    }
}
