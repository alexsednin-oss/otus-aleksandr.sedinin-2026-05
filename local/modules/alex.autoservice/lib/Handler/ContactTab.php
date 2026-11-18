<?php

namespace Alex\Autoservice\Handler;

use Bitrix\Main\EventResult;
use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

/**
 * Вкладка «Гараж» в карточке контакта.
 *
 * Событие crm / onEntityDetailsTabsInitialized в разных сборках передаёт
 * аргументы по-разному, поэтому список вкладок ищем среди переданных
 * аргументов, а не полагаемся на позицию.
 */
class ContactTab
{
    public const TAB_ID = 'alex_autoservice_garage';

    /**
     * @param mixed $entityID
     * @param mixed $entityTypeID
     * @param mixed $guid
     * @param mixed $tabs
     *
     * @return EventResult|null
     */
    public static function onEntityDetailsTabsInitialized($entityID = null, $entityTypeID = null, $guid = null, $tabs = null)
    {
        if (!is_array($tabs)) {
            foreach (func_get_args() as $argument) {
                if (is_array($argument)) {
                    $tabs = $argument;
                    break;
                }
            }
        }

        $tabs       = is_array($tabs) ? $tabs : [];
        $contactId  = (int) $entityID;
        $entityType = (int) $entityTypeID;

        // Вкладка нужна только в карточке контакта и только у сохранённого клиента
        if (!class_exists('\CCrmOwnerType') || $entityType !== \CCrmOwnerType::Contact || $contactId <= 0) {
            return null;
        }

        $tabs[] = [
            'id'     => self::TAB_ID,
            'name'   => Loc::getMessage('ALEX_AUTOSERVICE_GARAGE_TAB_NAME'),
            'loader' => [
                'serviceUrl'    => '/alex_autoservice/garage_tab.php?site=' . SITE_ID
                    . '&' . bitrix_sessid_get() . '&contactId=' . $contactId,
                'componentData' => [
                    'template' => '',
                    'params'   => [
                        'CONTACT_ID' => $contactId,
                    ],
                ],
            ],
        ];

        return new EventResult(EventResult::SUCCESS, ['tabs' => $tabs]);
    }
}
