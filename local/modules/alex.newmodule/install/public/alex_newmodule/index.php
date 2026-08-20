<?php

require($_SERVER["DOCUMENT_ROOT"]."/bitrix/header.php");

use Bitrix\Main\Loader;
use Bitrix\Main\UI\Extension;

if (!Loader::includeModule('alex.newmodule')) {
    ShowError('Модуль alex.newmodule не установлен');
    require($_SERVER["DOCUMENT_ROOT"]."/bitrix/footer.php");
    exit;
}

$APPLICATION->SetTitle('Записи Alex NewModule');

Extension::load(['main.ui.grid']);

$gridId = 'alex_newmodule_grid';

$items = \Alex\Newmodule\ItemTable::getList([
    'select' => ['ID', 'NAME', 'VALUE', 'CREATED_AT'],
    'order'  => ['ID' => 'DESC'],
])->fetchAll();

$headers = [
    ['id' => 'ID',         'name' => 'ID',       'sort' => 'id',         'default' => true],
    ['id' => 'NAME',       'name' => 'Название', 'sort' => 'name',       'default' => true],
    ['id' => 'VALUE',      'name' => 'Значение', 'sort' => 'value',      'default' => true],
    ['id' => 'CREATED_AT', 'name' => 'Создано',  'sort' => 'created_at', 'default' => true],
];

$rows = [];

foreach ($items as $item) {
    $createdAt = $item['CREATED_AT'] instanceof \Bitrix\Main\Type\DateTime
        ? $item['CREATED_AT']->format('d.m.Y H:i')
        : (string) $item['CREATED_AT'];

    $rows[] = [
        'id'      => $item['ID'],
        'columns' => [
            'ID'         => $item['ID'],
            'NAME'       => htmlspecialcharsbx($item['NAME']),
            'VALUE'      => htmlspecialcharsbx((string) $item['VALUE']),
            'CREATED_AT' => htmlspecialcharsbx($createdAt),
        ],
    ];
}

$APPLICATION->IncludeComponent(
    'bitrix:main.ui.grid',
    '',
    [
        'GRID_ID'               => $gridId,
        'COLUMNS'               => $headers,
        'ROWS'                  => $rows,
        'SHOW_ROW_CHECKBOXES'   => false,
        'SHOW_SELECTED_COUNTER' => false,
        'SHOW_TOTAL_COUNTER'    => true,
        'ALLOW_COLUMN_SORT'     => true,
        'ALLOW_PIN_HEADER'      => true,
        'AJAX_MODE'             => 'N',
        'NAV_OBJECT'            => null,
        'NAV_PARAMS'            => null,
        'SHOW_PAGESIZE'         => false,
        'SHOW_PAGINATION'       => false,
    ],
    false
);

require($_SERVER["DOCUMENT_ROOT"]."/bitrix/footer.php");
