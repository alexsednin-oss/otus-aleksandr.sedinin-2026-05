<?php

namespace Alex\Newmodule;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;

/**
 * Запись текущих даты и времени в поле контакта «Дата последней коммуникации».
 *
 * Вызывается из обработчика события приложения (app_handler.php) по ID дела.
 * Контакты берутся из привязок дела и из его адресатов (communications).
 */
class LastCommunicationHandler
{
    public const MODULE_ID = 'alex.newmodule';

    /**
     * @return array{ACTIVITY_ID:int, CONTACTS:int[], UPDATED:int[], MESSAGES:string[]}
     */
    public static function touchByActivity(int $activityId): array
    {
        $result = [
            'ACTIVITY_ID' => $activityId,
            'CONTACTS'    => [],
            'UPDATED'     => [],
            'MESSAGES'    => [],
        ];

        if (!$activityId) {
            $result['MESSAGES'][] = 'не передан ID дела';

            return $result;
        }

        if (!Loader::includeModule('crm')) {
            $result['MESSAGES'][] = 'модуль CRM не установлен';

            return $result;
        }

        if (!LastCommunicationInstaller::getFieldId()) {
            $result['MESSAGES'][] = 'поле ' . LastCommunicationInstaller::FIELD_NAME
                . ' не создано — переустановите модуль';

            return $result;
        }

        $activity = \CCrmActivity::GetByID($activityId, false);

        if (!$activity) {
            $result['MESSAGES'][] = 'дело ' . $activityId . ' не найдено';

            return $result;
        }

        $contactIds = self::resolveContacts($activityId, $activity);

        if (!$contactIds) {
            $result['MESSAGES'][] = 'у дела ' . $activityId . ' нет привязанных контактов';

            return $result;
        }

        $result['CONTACTS'] = $contactIds;

        // Формат даты — как у сайта, иначе UF типа datetime значение не примет
        $now = ConvertTimeStamp(time(), 'FULL');

        foreach ($contactIds as $contactId) {
            $fields = [LastCommunicationInstaller::FIELD_NAME => $now];

            $entity = new \CCrmContact(false);

            $updated = $entity->Update(
                $contactId,
                $fields,
                false,
                false,
                [
                    'DISABLE_USER_FIELD_CHECK' => true,
                    'REGISTER_SONET_EVENT'     => false,
                ]
            );

            if ($updated) {
                $result['UPDATED'][] = $contactId;
            } else {
                $result['MESSAGES'][] = 'контакт ' . $contactId . ': ' . $entity->LAST_ERROR;
            }
        }

        if ($result['UPDATED']) {
            self::log(
                'Дело ' . $activityId . ' → контакты ' . implode(', ', $result['UPDATED'])
                . ': ' . LastCommunicationInstaller::FIELD_NAME . ' = ' . $now
            );
        }

        return $result;
    }

    /**
     * Контакты дела: из привязок (bindings) и из адресатов (communications).
     *
     * @return int[]
     */
    private static function resolveContacts(int $activityId, array $activity): array
    {
        $contactIds = [];
        $contactTypeId = \CCrmOwnerType::Contact;

        // 1. Привязки дела к сущностям CRM
        if (method_exists('\CCrmActivity', 'GetBindings')) {
            $bindings = \CCrmActivity::GetBindings($activityId);

            foreach ((array) $bindings as $binding) {
                if ((int) ($binding['OWNER_TYPE_ID'] ?? 0) === $contactTypeId) {
                    $contactIds[] = (int) $binding['OWNER_ID'];
                }
            }
        }

        // 2. Адресаты дела (кому звонили, кому писали)
        if (method_exists('\CCrmActivity', 'GetCommunications')) {
            $communications = \CCrmActivity::GetCommunications($activityId);

            foreach ((array) $communications as $communication) {
                if ((int) ($communication['ENTITY_TYPE_ID'] ?? 0) === $contactTypeId) {
                    $contactIds[] = (int) $communication['ENTITY_ID'];
                }
            }
        }

        // 3. Владелец дела, если это контакт
        if ((int) ($activity['OWNER_TYPE_ID'] ?? 0) === $contactTypeId) {
            $contactIds[] = (int) $activity['OWNER_ID'];
        }

        $contactIds = array_values(array_unique(array_filter($contactIds)));

        return $contactIds;
    }

    public static function isDebug(): bool
    {
        return Option::get(self::MODULE_ID, 'app_debug', 'N') === 'Y';
    }

    public static function log(string $message): void
    {
        if (!self::isDebug()) {
            return;
        }

        AddMessage2Log($message, self::MODULE_ID);
    }
}
