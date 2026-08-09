<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

use Bitrix\Main\Loader;

Loader::includeModule('currency');

$currencyItems = [];

$currencyList = \Bitrix\Currency\CurrencyManager::getCurrencyList();

foreach ($currencyList as $code => $label) {
    $currencyItems[$code] = $label;
}

$arComponentParameters = [
    'GROUPS' => [
        'TEST_GROUP' => [
            'NAME' => 'Тестовая группа',
        ],
    ],
    'PARAMETERS' => [
        'CURRENCY_ID' => [
            'PARENT'   => 'TEST_GROUP',
            'NAME'     => 'Выбрать валюту',
            'TYPE'     => 'LIST',
            'VALUES'   => $currencyItems,
            'DEFAULT'  => 'USD',
            'MULTIPLE' => 'N',
            'REFRESH'  => 'N',
        ],
    ],
];
