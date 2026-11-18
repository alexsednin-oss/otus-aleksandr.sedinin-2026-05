<?php
/**
 * Страница управления миграциями модуля.
 *
 * Заменяет запуск tools/migrate.php из командной строки: состояние,
 * применение и откат доступны прямо из административного интерфейса.
 */

use Alex\Autoservice\Migration\Manager;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

/**
 * @var CMain $APPLICATION
 * @var CUser $USER
 */

Loc::loadMessages(__FILE__);

if (!$USER->IsAdmin()) {
    $APPLICATION->AuthForm(Loc::getMessage('ACCESS_DENIED'));
}

if (!Loader::includeModule('alex.autoservice')) {
    $APPLICATION->SetTitle(Loc::getMessage('ALEX_AUTOSERVICE_ADMIN_TITLE'));

    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';

    CAdminMessage::ShowMessage(Loc::getMessage('ALEX_AUTOSERVICE_ADMIN_NO_MODULE'));

    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';

    die();
}

$report = [];
$action = (string) ($_REQUEST['action'] ?? '');

if ($action !== '' && $_SERVER['REQUEST_METHOD'] === 'POST' && check_bitrix_sessid()) {
    try {
        switch ($action) {
            case 'up':
                $report = Manager::up();
                break;

            case 'down':
                $report = Manager::down();
                break;

            case 'down_all':
                $report = Manager::down(null, true);
                break;
        }
    } catch (\Throwable $e) {
        $report = [Loc::getMessage('ALEX_AUTOSERVICE_ADMIN_ERROR', ['#ERROR#' => $e->getMessage()])];
    }
}

$status  = Manager::getStatus();
$pending = 0;

foreach ($status as $item) {
    if (!$item['applied']) {
        $pending++;
    }
}

$APPLICATION->SetTitle(Loc::getMessage('ALEX_AUTOSERVICE_ADMIN_TITLE'));

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';

if ($report) {
    $html = '<ul style="margin:0;padding-left:18px">';

    foreach ($report as $line) {
        $html .= '<li>' . htmlspecialcharsbx($line) . '</li>';
    }

    $html .= '</ul>';

    CAdminMessage::ShowMessage([
        'MESSAGE' => Loc::getMessage('ALEX_AUTOSERVICE_ADMIN_REPORT'),
        'DETAILS' => $html,
        'HTML'    => true,
        'TYPE'    => 'OK',
    ]);
}

?>
    <form method="post" action="<?= htmlspecialcharsbx($APPLICATION->GetCurPage()) ?>">
        <?= bitrix_sessid_post() ?>
        <input type="hidden" name="lang" value="<?= LANGUAGE_ID ?>">

        <table class="adm-list-table">
            <thead>
            <tr class="adm-list-table-header">
                <td class="adm-list-table-cell"><?= Loc::getMessage('ALEX_AUTOSERVICE_ADMIN_COL_STATE') ?></td>
                <td class="adm-list-table-cell"><?= Loc::getMessage('ALEX_AUTOSERVICE_ADMIN_COL_VERSION') ?></td>
                <td class="adm-list-table-cell"><?= Loc::getMessage('ALEX_AUTOSERVICE_ADMIN_COL_DESCRIPTION') ?></td>
                <td class="adm-list-table-cell"><?= Loc::getMessage('ALEX_AUTOSERVICE_ADMIN_COL_APPLIED_AT') ?></td>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($status as $item): ?>
                <tr class="adm-list-table-row">
                    <td class="adm-list-table-cell">
                        <?php if ($item['applied']): ?>
                            <span style="color:#2fa84f;font-weight:bold">&#10003;</span>
                        <?php else: ?>
                            <span style="color:#bbb">&mdash;</span>
                        <?php endif; ?>
                    </td>
                    <td class="adm-list-table-cell"><?= htmlspecialcharsbx($item['version']) ?></td>
                    <td class="adm-list-table-cell"><?= htmlspecialcharsbx($item['description']) ?></td>
                    <td class="adm-list-table-cell"><?= htmlspecialcharsbx((string) $item['appliedAt']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <p>
            <input type="submit" name="action" value="up"
                   class="adm-btn-save"
                   title="<?= Loc::getMessage('ALEX_AUTOSERVICE_ADMIN_UP_HINT') ?>"
                <?= $pending === 0 ? 'disabled' : '' ?>>

            <input type="submit" name="action" value="down"
                   title="<?= Loc::getMessage('ALEX_AUTOSERVICE_ADMIN_DOWN_HINT') ?>"
                   onclick="return confirm('<?= Loc::getMessage('ALEX_AUTOSERVICE_ADMIN_DOWN_CONFIRM') ?>')">

            <input type="submit" name="action" value="down_all"
                   title="<?= Loc::getMessage('ALEX_AUTOSERVICE_ADMIN_DOWN_ALL_HINT') ?>"
                   onclick="return confirm('<?= Loc::getMessage('ALEX_AUTOSERVICE_ADMIN_DOWN_ALL_CONFIRM') ?>')">
        </p>

        <p style="color:#777">
            <?= Loc::getMessage('ALEX_AUTOSERVICE_ADMIN_PENDING', ['#COUNT#' => $pending]) ?>
        </p>
    </form>
<?php

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
