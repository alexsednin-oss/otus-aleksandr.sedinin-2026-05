<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

/** @var array $arResult */
/** @var CBitrixComponentTemplate $this */
/** @var string $templateFolder */

$this->addExternalCss($templateFolder.'/style.css');
?>

<div class="curr-currency-rate">

    <div class="curr-currency-rate__name"><?= htmlspecialcharsbx($arResult['FULL_NAME']) ?></div>
    <div class="curr-currency-rate__value">
        <?= htmlspecialcharsbx($arResult['AMOUNT_CNT']) ?> <?= htmlspecialcharsbx($arResult['CURRENCY_ID']) ?>
        =
        <?= htmlspecialcharsbx(number_format((float) $arResult['AMOUNT'], (int) $arResult['DECIMALS'], '.', ' ')) ?> RUB
    </div>
</div>
