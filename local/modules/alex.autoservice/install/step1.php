<?php

use Bitrix\Main\Localization\Loc;

/** @var CMain $APPLICATION */

if (!check_bitrix_sessid()) {
    return;
}

Loc::loadMessages(__FILE__);

$report = (array) ($GLOBALS['ALEX_AUTOSERVICE_REPORT'] ?? []);

?>
<?php if ($report): ?>
    <ul>
        <?php foreach ($report as $line): ?>
            <li><?= htmlspecialcharsbx($line) ?></li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
<p><?= Loc::getMessage('ALEX_AUTOSERVICE_STEP_DONE') ?></p>
<form action="<?= htmlspecialcharsbx($APPLICATION->GetCurPage()) ?>">
    <input type="hidden" name="lang" value="<?= LANGUAGE_ID ?>">
    <input type="submit" name="back" value="<?= Loc::getMessage('ALEX_AUTOSERVICE_STEP_BACK') ?>">
</form>
