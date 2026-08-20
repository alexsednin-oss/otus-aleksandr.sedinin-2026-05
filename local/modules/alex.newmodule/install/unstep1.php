<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__DIR__.'/index.php');

?>

<form action="<?= $APPLICATION->GetCurPage() ?>" method="post" name="alex_newmodule_uninstall_form">
    <?= bitrix_sessid_post() ?>
    <input type="hidden" name="lang" value="<?= LANGUAGE_ID ?>">
    <input type="hidden" name="id" value="alex.newmodule">
    <input type="hidden" name="uninstall" value="Y">
    <input type="hidden" name="step" value="2">

    <div class="adm-info-message-wrap">
        <div class="adm-info-message red">
            <?= Loc::getMessage('ALEX_NEWMODULE_UNINSTALL_STEP1_WARNING') ?>
        </div>
    </div>

    <ul>
        <li><?= Loc::getMessage('ALEX_NEWMODULE_UNINSTALL_STEP1_ITEM_DB') ?></li>
        <li><?= Loc::getMessage('ALEX_NEWMODULE_UNINSTALL_STEP1_ITEM_MENU') ?></li>
        <li><?= Loc::getMessage('ALEX_NEWMODULE_UNINSTALL_STEP1_ITEM_PAGE') ?></li>
    </ul>

    <input type="submit" name="save" value="<?= Loc::getMessage('ALEX_NEWMODULE_UNINSTALL_STEP1_BUTTON') ?>" class="adm-btn-save">
</form>
