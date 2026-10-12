<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

$arActivityDescription = [
    'NAME'        => 'Заказчик из DaData',
    'DESCRIPTION' => 'По ИНН получает данные организации из DaData, создаёт компанию в CRM '
        . 'с реквизитами и записывает её в поле «Заказчик»',
    'TYPE'        => 'activity',
    'CLASS'       => 'AlexDadataCompany',
    'JSCLASS'     => 'BizProcActivity',
    'CATEGORY'    => [
        'ID'       => 'document',
        'OWN_ID'   => 'alex_newmodule',
        'OWN_NAME' => 'Alex NewModule',
    ],
    'RETURN'      => [
        'CompanyId'    => [
            'NAME' => 'ID компании',
            'TYPE' => 'int',
        ],
        'CompanyTitle' => [
            'NAME' => 'Название компании',
            'TYPE' => 'string',
        ],
        'ErrorMessage' => [
            'NAME' => 'Текст ошибки',
            'TYPE' => 'string',
        ],
    ],
];
