<?php

declare(strict_types=1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php';
/**
 * @var CMain $APPLICATION  
 */

$APPLICATION->SetTitle('Демо библиотеки Var-Dumper');

dump([
    '21' => '32',
    '25' => [
        '324' => [
            '434' => 'fdsgs'
        ]
    ]
]);
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php';
