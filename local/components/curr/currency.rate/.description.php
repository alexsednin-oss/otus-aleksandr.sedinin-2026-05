<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

$arComponentDescription = [
    'NAME'        => 'Вывести валюты 1',
    'DESCRIPTION' => 'Отображает актуальный курс выбранной валюты из справочника валют',
    'ICON'        => '/images/icon.gif',
    'SORT'        => 10,
    'PATH'        => [
        'ID'   => 'curr',
        'NAME' => 'Настройка компонента',
    ],
];
