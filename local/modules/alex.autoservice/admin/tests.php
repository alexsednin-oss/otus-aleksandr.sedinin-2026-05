<?php
/**
 * Страница запуска тестов проекта.
 *
 * Показывает список тестов и прогоняет выбранные, печатая отчёт прямо
 * на странице. Нужна, чтобы проверку решения не приходилось собирать
 * из кусков в PHP-консоли.
 */

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
    $APPLICATION->SetTitle(Loc::getMessage('ALEX_AUTOSERVICE_TESTS_TITLE'));

    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';

    CAdminMessage::ShowMessage(Loc::getMessage('ALEX_AUTOSERVICE_TESTS_NO_MODULE'));

    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';

    die();
}

require_once __DIR__ . '/../tools/tests/run.php';

$tests    = alexAutoserviceTestList();
$selected = array_values(array_intersect((array) ($_REQUEST['tests'] ?? []), array_keys($tests)));
$output   = '';
$results  = [];

$nothingSelected = $_SERVER['REQUEST_METHOD'] === 'POST' && !$selected;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $selected && check_bitrix_sessid()) {
    // Тесты откатывают и применяют миграции заново — это долго
    @set_time_limit(600);

    ob_start();

    $results = alexAutoserviceRunTests($selected);

    $output = (string) ob_get_clean();
}

$APPLICATION->SetTitle(Loc::getMessage('ALEX_AUTOSERVICE_TESTS_TITLE'));

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';

if ($nothingSelected) {
    CAdminMessage::ShowMessage([
        'MESSAGE' => Loc::getMessage('ALEX_AUTOSERVICE_TESTS_NOTHING_SELECTED'),
        'TYPE'    => 'ERROR',
    ]);
}

if ($results) {
    $failed = 0;

    foreach ($results as $result) {
        $failed += $result['fail'];
    }

    CAdminMessage::ShowMessage([
        'MESSAGE' => $failed
            ? Loc::getMessage('ALEX_AUTOSERVICE_TESTS_FAILED', ['#COUNT#' => $failed])
            : Loc::getMessage('ALEX_AUTOSERVICE_TESTS_PASSED'),
        'TYPE' => $failed ? 'ERROR' : 'OK',
    ]);
}

?>
    <form method="post" action="<?= htmlspecialcharsbx($APPLICATION->GetCurPage()) ?>">
        <?= bitrix_sessid_post() ?>
        <input type="hidden" name="lang" value="<?= LANGUAGE_ID ?>">

        <table class="adm-list-table">
            <thead>
            <tr class="adm-list-table-header">
                <td class="adm-list-table-cell" style="width:40px"></td>
                <td class="adm-list-table-cell"><?= Loc::getMessage('ALEX_AUTOSERVICE_TESTS_COL_CODE') ?></td>
                <td class="adm-list-table-cell"><?= Loc::getMessage('ALEX_AUTOSERVICE_TESTS_COL_TITLE') ?></td>
                <td class="adm-list-table-cell"><?= Loc::getMessage('ALEX_AUTOSERVICE_TESTS_COL_RESULT') ?></td>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($tests as $code => $test): ?>
                <?php $result = $results[$code] ?? null; ?>
                <tr class="adm-list-table-row">
                    <td class="adm-list-table-cell">
                        <input type="checkbox" name="tests[]" value="<?= htmlspecialcharsbx($code) ?>"
                            <?= in_array($code, $selected, true) ? 'checked' : '' ?>>
                    </td>
                    <td class="adm-list-table-cell"><?= htmlspecialcharsbx($code) ?></td>
                    <td class="adm-list-table-cell"><?= htmlspecialcharsbx($test['title']) ?></td>
                    <td class="adm-list-table-cell">
                        <?php if ($result === null): ?>
                            <span style="color:#bbb">&mdash;</span>
                        <?php elseif ($result['fail']): ?>
                            <span style="color:#d64646;font-weight:bold">
                                FAIL <?= (int) $result['fail'] ?>
                            </span>
                            <span style="color:#777">
                                / OK <?= (int) $result['ok'] ?>, <?= $result['time'] ?> c
                            </span>
                        <?php else: ?>
                            <span style="color:#2fa84f;font-weight:bold">
                                OK <?= (int) $result['ok'] ?>
                            </span>
                            <span style="color:#777"><?= $result['time'] ?> c</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <p>
            <input type="submit" class="adm-btn-save"
                   value="<?= Loc::getMessage('ALEX_AUTOSERVICE_TESTS_RUN_SELECTED') ?>">

            <input type="button"
                   value="<?= Loc::getMessage('ALEX_AUTOSERVICE_TESTS_RUN_ALL') ?>"
                   onclick="this.form.querySelectorAll('input[name=\'tests[]\']').forEach(function (box) {
                       box.checked = true;
                   }); this.form.submit();">
        </p>

        <p style="color:#777">
            <?= Loc::getMessage('ALEX_AUTOSERVICE_TESTS_HINT') ?>
        </p>
    </form>

<?php if ($output !== ''): ?>
    <pre style="background:#fff;border:1px solid #dde2e7;padding:16px;overflow:auto;
                font:12px/1.5 Consolas, Menlo, monospace"><?= htmlspecialcharsbx($output) ?></pre>
<?php endif; ?>

<?php

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
