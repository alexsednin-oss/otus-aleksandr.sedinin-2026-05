<?php
/**
 * Обработчик событий локального приложения.
 *
 * Принимает от Битрикс24:
 *   ONAPPINSTALL      — сохраняем application_token, им потом проверяем входящие
 *   ONCRMACTIVITYADD  — пишем текущие дату и время в поле контакта
 */

define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
define('NO_AGENT_CHECK', true);
define('BX_NO_ACCELERATOR_RESET', true);
define('DisableEventsCheck', true);

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

use Alex\Newmodule\LastCommunicationHandler;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;

const MODULE_ID = 'alex.newmodule';

/**
 * @param array $data
 * @return never
 */
function alexAppRespond(array $data)
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);

    die();
}

$event = (string) ($_REQUEST['event'] ?? '');
$auth  = (array) ($_REQUEST['auth'] ?? []);

$debug = Option::get(MODULE_ID, 'app_debug', 'N') === 'Y';

if ($debug) {
    AddMessage2Log('Входящее событие приложения: ' . $event . ' | ' . print_r($_REQUEST, true), MODULE_ID);
}

// ----------------------------------------------------- установка приложения

if ($event === 'ONAPPINSTALL') {
    $token = (string) ($auth['application_token'] ?? '');

    if ($token !== '') {
        Option::set(MODULE_ID, 'app_token', $token);
    }

    alexAppRespond(['result' => 'installed', 'token_saved' => $token !== '']);
}

// ------------------------------------------------------- проверка источника

$expectedToken = (string) Option::get(MODULE_ID, 'app_token', '');
$incomingToken = (string) ($auth['application_token'] ?? '');

if ($expectedToken === '') {
    alexAppRespond([
        'result' => 'error',
        'error'  => 'application_token не сохранён — переустановите приложение в Б24',
    ]);
}

if (!hash_equals($expectedToken, $incomingToken)) {
    alexAppRespond(['result' => 'error', 'error' => 'неверный application_token']);
}

// ------------------------------------------------------------ событие дела

if ($event !== 'ONCRMACTIVITYADD') {
    alexAppRespond(['result' => 'skipped', 'event' => $event]);
}

if (!Loader::includeModule(MODULE_ID)) {
    alexAppRespond(['result' => 'error', 'error' => 'модуль ' . MODULE_ID . ' не установлен']);
}

$activityId = (int) ($_REQUEST['data']['FIELDS']['ID'] ?? 0);

$report = LastCommunicationHandler::touchByActivity($activityId);

alexAppRespond([
    'result'   => $report['UPDATED'] ? 'ok' : 'nothing',
    'activity' => $report['ACTIVITY_ID'],
    'contacts' => $report['CONTACTS'],
    'updated'  => $report['UPDATED'],
    'messages' => $report['MESSAGES'],
]);
