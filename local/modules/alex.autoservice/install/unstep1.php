<?php

use Bitrix\Main\Localization\Loc;

/** @var CMain $APPLICATION */

if (!check_bitrix_sessid()) {
    return;
}

Loc::loadMessages(__FILE__);

?>
<form action="<?= htmlspecialcharsbx($APPLICATION->GetCurPage()) ?>">
    <?= bitrix_sessid_post() ?>
    <input type="hidden" name="lang" value="<?= LANGUAGE_ID ?>">
    <input type="hidden" name="id" value="alex.autoservice">
    <input type="hidden" name="uninstall" value="Y">
    <input type="hidden" name="step" value="2">

    <p><?= Loc::getMessage('ALEX_AUTOSERVICE_UNSTEP_WARNING') ?></p>

    <p>
        <label>
            <input type="checkbox" name="save_data" value="Y" checked>
            <?= Loc::getMessage('ALEX_AUTOSERVICE_UNSTEP_SAVE_DATA') ?>
        </label>
    </p>

    <input type="submit" name="inst" value="<?= Loc::getMessage('ALEX_AUTOSERVICE_UNSTEP_SUBMIT') ?>">
</form>
