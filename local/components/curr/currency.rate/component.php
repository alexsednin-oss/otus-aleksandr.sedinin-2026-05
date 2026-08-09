<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

use Bitrix\Main\Loader;

if (!Loader::includeModule('currency')) {
    ShowError('Модуль "Валюты" не установлен');
    return;
}

$currencyId = trim((string) ($arParams['CURRENCY_ID'] ?? 'USD'));

if ($currencyId === '') {
    $currencyId = 'USD';
}

$arParams['CURRENCY_ID'] = $currencyId;

$this->initComponentTemplate();

if ($this->startResultCache(false, [$currencyId])) {

    $currencyFields = \CCurrency::GetByID($currencyId);

    if (!$currencyFields) {
        $this->abortResultCache();
        ShowError('Валюта "'.htmlspecialcharsbx($currencyId).'" не найдена');
        return;
    }

    $currencyLangFields = \CCurrencyLang::GetByID($currencyId, LANGUAGE_ID);

    $this->arResult = [
        'CURRENCY_ID' => $currencyId,
        'FULL_NAME'   => $currencyLangFields['FULL_NAME'] ?? $currencyId,
        'DECIMALS'    => $currencyFields['DECIMALS'] ?? 2,
        'AMOUNT'      => (float) $currencyFields['AMOUNT'],
        'AMOUNT_CNT'  => (int) $currencyFields['AMOUNT_CNT'] ?: 1,
    ];

    $this->setResultCacheKeys(['CURRENCY_ID', 'AMOUNT']);
    $this->includeComponentTemplate();
}
