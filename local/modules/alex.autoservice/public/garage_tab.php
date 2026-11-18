<?php
/**
 * Содержимое вкладки «Гараж»: список автомобилей клиента.
 *
 * Отдаёт голый фрагмент HTML. Подключать header.php и footer.php нельзя:
 * загрузчик вкладок вставляет ответ как есть, и хвост страницы портала
 * с непарными тегами ломает разметку карточки — содержимое вкладки при
 * этом просто исчезает, без ошибок в консоли.
 */

define('STOP_STATISTICS', true);
define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_CHECK', true);
define('BX_NO_ACCELERATOR_RESET', true);

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

use Alex\Autoservice\Service\Access;
use Alex\Autoservice\Service\CarReference;
use Alex\Autoservice\Service\Garage;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

global $USER, $APPLICATION;

// Фрагмент собираем сами, буфер страницы портала нам не нужен
$APPLICATION->RestartBuffer();

if (!is_object($USER) || !$USER->IsAuthorized()) {
    echo '<div class="alex-garage-empty">'
        . Loc::getMessage('ALEX_AUTOSERVICE_GARAGE_AUTH_REQUIRED') . '</div>';

    die();
}

if (!Loader::includeModule('alex.autoservice')) {
    echo '<div class="alex-garage-empty">'
        . Loc::getMessage('ALEX_AUTOSERVICE_GARAGE_NO_MODULE') . '</div>';

    die();
}

$contactId = (int) ($_REQUEST['contactId'] ?? $_REQUEST['ENTITY_ID'] ?? 0);
$cars      = Garage::getCars($contactId);

// Кнопки показываем по правам, но решение дублируется на сервере:
// спрятанная кнопка — удобство, а не защита
$canWrite  = Access::canWriteCar();
$canDelete = Access::canDeleteCar();

$reference = [
    'brands' => CarReference::getBrands(),
    'models' => CarReference::getModels(),
];

?>
    <div class="alex-garage" data-sessid="<?= bitrix_sessid() ?>">
        <style>
            .alex-garage { padding: 12px 0; font: 13px/1.5 "Helvetica Neue", Arial, sans-serif; }
            .alex-garage table { width: 100%; border-collapse: collapse; }
            .alex-garage th { text-align: left; padding: 8px 10px; background: #f3f5f7;
                color: #6a737b; font-weight: 600; border-bottom: 1px solid #e6e8ea; }
            .alex-garage td { padding: 9px 10px; border-bottom: 1px solid #f0f1f2; }
            .alex-garage tr.alex-garage-row { cursor: pointer; }
            .alex-garage tr.alex-garage-row:hover td { background: #f7fafd; }
            .alex-garage .alex-garage-number { font-weight: 600; letter-spacing: .4px; }
            .alex-garage-empty { padding: 16px; color: #828b95; }
            .alex-garage-toolbar { padding: 0 0 12px; }
            .alex-garage-btn {
                display: inline-block;
                padding: 7px 16px;
                border: 1px solid #c6cdd3;
                border-radius: 3px;
                background: #fff;
                color: #2067b0;
                font: 13px/1.2 "Helvetica Neue", Arial, sans-serif;
                cursor: pointer;
            }
            .alex-garage-btn:hover { background: #f4f8fb; }
            .alex-garage-btn-primary { background: #2067b0; border-color: #2067b0; color: #fff; }
            .alex-garage-btn-primary:hover { background: #1a5695; }
            .alex-garage td.alex-garage-actions { text-align: right; white-space: nowrap; }
            .alex-garage-action {
                border: 0;
                background: none;
                color: #798795;
                cursor: pointer;
                font-size: 13px;
                padding: 2px 6px;
            }
            .alex-garage-action:hover { color: #2067b0; text-decoration: underline; }
            .alex-garage-form { padding: 20px 22px; min-width: 460px;
                font: 14px/1.5 "Helvetica Neue", Arial, sans-serif; }
            .alex-garage-form label { display: block; margin-bottom: 12px; color: #55606b; }
            .alex-garage-form select,
            .alex-garage-form input {
                display: block;
                width: 100%;
                margin-top: 4px;
                padding: 7px 9px;
                border: 1px solid #c6cdd3;
                border-radius: 2px;
                font: 14px/1.3 "Helvetica Neue", Arial, sans-serif;
                box-sizing: border-box;
            }
            .alex-garage-form .error { color: #d64646; margin-bottom: 12px; min-height: 18px; }
            .alex-garage-history {
                padding: 20px 22px;
                min-width: 580px;
                font: 14px/1.6 "Helvetica Neue", Arial, sans-serif;
                color: #1f2b3a;
            }
            .alex-garage-history .deal {
                border: 1px solid #dde2e7;
                border-radius: 3px;
                background: #fff;
                padding: 16px 18px;
                margin-bottom: 14px;
            }
            .alex-garage-history .deal:last-child { margin-bottom: 0; }
            .alex-garage-history .deal-title {
                font-size: 16px;
                font-weight: 600;
                margin-bottom: 12px;
                padding-bottom: 10px;
                border-bottom: 1px solid #f0f2f4;
            }
            .alex-garage-history .deal-title a { color: #2067b0; text-decoration: none; }
            .alex-garage-history .deal-title a:hover { text-decoration: underline; }
            .alex-garage-history .deal-grid {
                display: grid;
                grid-template-columns: 140px 1fr;
                row-gap: 8px;
                column-gap: 14px;
                align-items: start;
            }
            .alex-garage-history .deal-label { color: #798795; }
            .alex-garage-history .deal-value a { color: #2067b0; text-decoration: none; }
            .alex-garage-history .deal-value a:hover { text-decoration: underline; }
            .alex-garage-history .stage {
                display: inline-block;
                padding: 3px 10px;
                border-radius: 3px;
                background: #8a94a0;
                color: #fff;
                font-size: 13px;
                line-height: 1.3;
            }
            .alex-garage-history .parts { margin: 0; padding: 0; list-style: none; }
            .alex-garage-history .parts li { margin-bottom: 4px; }
            .alex-garage-history .parts li:last-child { margin-bottom: 0; }
            .alex-garage-history .parts .qty { color: #798795; }
            .alex-garage-history .empty {
                padding: 26px 22px;
                color: #798795;
                font-size: 14px;
                text-align: center;
            }
        </style>

        <script>
            BX.message({
                ALEX_GARAGE_NO_DEALS: '<?= CUtil::JSEscape(Loc::getMessage('ALEX_AUTOSERVICE_GARAGE_NO_DEALS')) ?>',
                ALEX_GARAGE_DATE: '<?= CUtil::JSEscape(Loc::getMessage('ALEX_AUTOSERVICE_GARAGE_DEAL_DATE')) ?>',
                ALEX_GARAGE_STAGE: '<?= CUtil::JSEscape(Loc::getMessage('ALEX_AUTOSERVICE_GARAGE_DEAL_STAGE')) ?>',
                ALEX_GARAGE_SUM: '<?= CUtil::JSEscape(Loc::getMessage('ALEX_AUTOSERVICE_GARAGE_DEAL_SUM')) ?>',
                ALEX_GARAGE_ASSIGNED: '<?= CUtil::JSEscape(Loc::getMessage('ALEX_AUTOSERVICE_GARAGE_DEAL_ASSIGNED')) ?>',
                ALEX_GARAGE_PARTS: '<?= CUtil::JSEscape(Loc::getMessage('ALEX_AUTOSERVICE_GARAGE_DEAL_PARTS')) ?>',
                ALEX_GARAGE_CLOSE: '<?= CUtil::JSEscape(Loc::getMessage('ALEX_AUTOSERVICE_GARAGE_CLOSE')) ?>',
                ALEX_GARAGE_LOADING: '<?= CUtil::JSEscape(Loc::getMessage('ALEX_AUTOSERVICE_GARAGE_LOADING')) ?>',
                ALEX_GARAGE_LOAD_ERROR: '<?= CUtil::JSEscape(Loc::getMessage('ALEX_AUTOSERVICE_GARAGE_LOAD_ERROR')) ?>',
                ALEX_GARAGE_NO_POPUP: '<?= CUtil::JSEscape(Loc::getMessage('ALEX_AUTOSERVICE_GARAGE_NO_POPUP')) ?>',
                ALEX_GARAGE_ADD: '<?= CUtil::JSEscape(Loc::getMessage('ALEX_AUTOSERVICE_GARAGE_ADD')) ?>',
                ALEX_GARAGE_EDIT: '<?= CUtil::JSEscape(Loc::getMessage('ALEX_AUTOSERVICE_GARAGE_EDIT')) ?>',
                ALEX_GARAGE_DELETE: '<?= CUtil::JSEscape(Loc::getMessage('ALEX_AUTOSERVICE_GARAGE_DELETE')) ?>',
                ALEX_GARAGE_DELETE_CONFIRM: '<?= CUtil::JSEscape(Loc::getMessage('ALEX_AUTOSERVICE_GARAGE_DELETE_CONFIRM')) ?>',
                ALEX_GARAGE_SAVE: '<?= CUtil::JSEscape(Loc::getMessage('ALEX_AUTOSERVICE_GARAGE_SAVE')) ?>',
                ALEX_GARAGE_CANCEL: '<?= CUtil::JSEscape(Loc::getMessage('ALEX_AUTOSERVICE_GARAGE_CANCEL')) ?>',
                ALEX_GARAGE_FORM_TITLE_ADD: '<?= CUtil::JSEscape(Loc::getMessage('ALEX_AUTOSERVICE_GARAGE_FORM_TITLE_ADD')) ?>',
                ALEX_GARAGE_FORM_TITLE_EDIT: '<?= CUtil::JSEscape(Loc::getMessage('ALEX_AUTOSERVICE_GARAGE_FORM_TITLE_EDIT')) ?>',
                ALEX_GARAGE_CHOOSE: '<?= CUtil::JSEscape(Loc::getMessage('ALEX_AUTOSERVICE_GARAGE_CHOOSE')) ?>'
            });

            window.AlexGarageData = <?= CUtil::PhpToJSObject([
                'contactId' => $contactId,
                'sessid'    => bitrix_sessid(),
                'saveUrl'   => '/alex_autoservice/garage_save.php',
                'tabUrl'    => '/alex_autoservice/garage_tab.php',
                'canWrite'  => $canWrite,
                'canDelete' => $canDelete,
                'brands'    => $reference['brands'],
                'models'    => $reference['models'],
                'columns'   => [
                    'BRAND'   => Loc::getMessage('ALEX_AUTOSERVICE_GARAGE_COL_BRAND'),
                    'MODEL'   => Loc::getMessage('ALEX_AUTOSERVICE_GARAGE_COL_MODEL'),
                    'NUMBER'  => Loc::getMessage('ALEX_AUTOSERVICE_GARAGE_COL_NUMBER'),
                    'YEAR'    => Loc::getMessage('ALEX_AUTOSERVICE_GARAGE_COL_YEAR'),
                    'COLOR'   => Loc::getMessage('ALEX_AUTOSERVICE_GARAGE_COL_COLOR'),
                    'MILEAGE' => Loc::getMessage('ALEX_AUTOSERVICE_GARAGE_COL_MILEAGE'),
                ],
            ]) ?>;
            window.AlexGarageRun = function (action, node) {
                var run = function () { window.AlexGarage[action](node); };

                if (window.AlexGarage) {
                    run();

                    return;
                }

                // Скрипт грузится по первому действию, а не вместе с вкладкой:
                // пока клиент не нажал ни одной кнопки, он и не нужен
                window.AlexGarageQueue = window.AlexGarageQueue || [];
                window.AlexGarageQueue.push(run);

                if (window.AlexGarageLoading) {
                    return;
                }

                window.AlexGarageLoading = true;

                var script = document.createElement('script');

                script.src = '/bitrix/js/alex.autoservice/garage.js';

                script.onload = function () {
                    window.AlexGarageLoading = false;

                    (window.AlexGarageQueue || []).forEach(function (fn) { fn(); });

                    window.AlexGarageQueue = [];
                };

                document.head.appendChild(script);
            };
        </script>

        <?php if ($canWrite): ?>
            <div class="alex-garage-toolbar">
                <button type="button" class="alex-garage-btn alex-garage-btn-primary"
                        onclick="AlexGarageRun('add')">
                    <?= Loc::getMessage('ALEX_AUTOSERVICE_GARAGE_ADD') ?>
                </button>
            </div>
        <?php endif; ?>

        <?php if (!$cars): ?>
            <div class="alex-garage-empty"><?= Loc::getMessage('ALEX_AUTOSERVICE_GARAGE_EMPTY') ?></div>
        <?php else: ?>
            <table>
                <thead>
                <tr>
                    <th><?= Loc::getMessage('ALEX_AUTOSERVICE_GARAGE_COL_BRAND') ?></th>
                    <th><?= Loc::getMessage('ALEX_AUTOSERVICE_GARAGE_COL_MODEL') ?></th>
                    <th><?= Loc::getMessage('ALEX_AUTOSERVICE_GARAGE_COL_NUMBER') ?></th>
                    <th><?= Loc::getMessage('ALEX_AUTOSERVICE_GARAGE_COL_YEAR') ?></th>
                    <th><?= Loc::getMessage('ALEX_AUTOSERVICE_GARAGE_COL_COLOR') ?></th>
                    <th><?= Loc::getMessage('ALEX_AUTOSERVICE_GARAGE_COL_MILEAGE') ?></th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($cars as $car): ?>
                    <tr class="alex-garage-row"
                        data-car-id="<?= (int) $car['ID'] ?>"
                        data-caption="<?= htmlspecialcharsbx(Garage::getCaption($car)) ?>"
                        data-car="<?= htmlspecialcharsbx(json_encode([
                            'ID'       => (int) $car['ID'],
                            'BRAND_ID' => (int) ($car['BRAND_ID'] ?? 0),
                            'MODEL_ID' => (int) ($car['MODEL_ID'] ?? 0),
                            'NUMBER'   => (string) $car['NUMBER'],
                            'YEAR'     => $car['YEAR'],
                            'COLOR'    => $car['COLOR'],
                            'MILEAGE'  => $car['MILEAGE'],
                        ], JSON_UNESCAPED_UNICODE)) ?>"
                        onclick="AlexGarageRun('open', this)">
                        <td><?= htmlspecialcharsbx((string) $car['BRAND']) ?></td>
                        <td><?= htmlspecialcharsbx((string) $car['MODEL']) ?></td>
                        <td class="alex-garage-number"><?= htmlspecialcharsbx((string) $car['NUMBER']) ?></td>
                        <td><?= $car['YEAR'] !== null ? (int) $car['YEAR'] : '—' ?></td>
                        <td><?= $car['COLOR'] !== null ? htmlspecialcharsbx((string) $car['COLOR']) : '—' ?></td>
                        <td><?= $car['MILEAGE'] !== null
                                ? number_format((int) $car['MILEAGE'], 0, ',', ' ')
                                . ' ' . Loc::getMessage('ALEX_AUTOSERVICE_GARAGE_KM')
                                : '—' ?></td>
                        <td class="alex-garage-actions">
                            <?php if ($canWrite): ?>
                                <button type="button" class="alex-garage-action"
                                        onclick="event.stopPropagation(); AlexGarageRun('edit', this)">
                                    <?= Loc::getMessage('ALEX_AUTOSERVICE_GARAGE_EDIT') ?>
                                </button>
                            <?php endif; ?>
                            <?php if ($canDelete): ?>
                                <button type="button" class="alex-garage-action"
                                        onclick="event.stopPropagation(); AlexGarageRun('remove', this)">
                                    <?= Loc::getMessage('ALEX_AUTOSERVICE_GARAGE_DELETE') ?>
                                </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
<?php

die();
