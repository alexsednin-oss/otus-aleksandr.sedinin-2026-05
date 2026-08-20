<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__DIR__.'/index.php');

?>

<div class="adm-info-message-wrap">
    <div class="adm-info-message green">
        <?= Loc::getMessage('ALEX_NEWMODULE_UNINSTALL_STEP2_SUCCESS') ?>
    </div>
</div>

<form action="<?= $APPLICATION->GetCurPage() ?>">
    <input type="hidden" name="lang" value="<?= LANGUAGE_ID ?>">
    <input type="submit" value="<?= Loc::getMessage('ALEX_NEWMODULE_UNINSTALL_STEP2_BACK') ?>" onclick="top.BX.closeAdminModal ? top.BX.closeAdminModal() : window.close(); return false;">
</form>
