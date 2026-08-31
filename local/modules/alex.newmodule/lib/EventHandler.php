<?php

namespace Alex\Newmodule;

use Bitrix\Main\EventResult;

class EventHandler
{
    /**
     * @param $aGlobalMenu
     * @param $aModuleMenu
     * @return void
     */
    public static function onBuildGlobalMenu(&$aGlobalMenu, &$aModuleMenu)
    {
        global $USER;

        if (!is_object($USER) || !$USER->IsAdmin()) {
            return;
        }

        $aGlobalMenu['global_menu_alex_newmodule'] = [
            'menu_id'   => 'alex_newmodule',
            'items_id'  => 'global_menu_alex_newmodule',
            'text'      => 'Alex NewModule',
            'title'     => 'Список записей модуля Alex NewModule',
            'sort'      => 550,
            'url'       => '/alex_newmodule/',
            'more_url'  => ['/alex_newmodule/'],
            'icon'      => 'alex_newmodule_menu_icon',
            'page_icon' => 'alex_newmodule_menu_icon',
            'items'     => [
                [
                    'parent_menu' => 'global_menu_alex_newmodule',
                    'sort'        => 10,
                    'text'        => 'Список записей',
                    'title'       => 'Список записей',
                    'url'         => '/alex_newmodule/',
                    'items_id'    => 'menu_alex_newmodule_list',
                ],
            ],
        ];
    }


    /**
     * @param $entityID
     * @param $entityTypeID
     * @param $guid
     * @param $tabs
     * @return EventResult
     */
    public static function onEntityDetailsTabsInitialized($entityID = null, $entityTypeID = null, $guid = null, $tabs = null)
    {
        if (!is_array($tabs)) {
            foreach (func_get_args() as $arg) {
                if (is_array($arg)) {
                    $tabs = $arg;
                    break;
                }
            }
        }

        $tabs   = is_array($tabs) ? $tabs : [];
        $tabs[] = [
            'id'   => 'alex_newmodule_tab',
            'name' => 'Alex NewModule',
            'loader' => [
                'serviceUrl' => '/alex_newmodule/ajax_tab.php',
                'componentData' => [
                    'template' => '',
                    'params' => [
                        'ENTITY_ID'   => $entityID,
                        'ENTITY_TYPE' => $entityTypeID,
                    ],
                ],
            ],
        ];

        return new EventResult(EventResult::SUCCESS, ['tabs' => $tabs]);
    }
}
