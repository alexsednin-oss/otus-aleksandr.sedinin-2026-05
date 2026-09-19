<?php
/**
 * AJAX: подтверждение начала рабочего дня.
 *
 * Сам день НЕ открывает: старым CTimeMan::Open() в современных версиях модуля
 * уже нет, а сервисы Bitrix\Timeman\Service\Worktime\* от версии к версии меняются.
 * Вместо этого ставим в сессию одноразовую отметку «пользователь подтвердил»
 * и отдаём ok — дальше клиентский скрипт пропускает исходный клик к штатному
 * виджету, который стартует день своим родным кодом.
 *
 * Отметку читает обработчик OnBeforeTMDayStart: без неё день не откроется.
 */

define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_CHECK', true);
define('DisableEventsCheck', true);

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

use Alex\Newmodule\WorkdayStartHandler;
use Bitrix\Main\Loader;

/**
 * @param array $data
 * @return never
 */
function alexWorkdayJson(array $data)
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);

    die();
}

global $USER;

if (!is_object($USER) || !$USER->IsAuthorized()) {
    alexWorkdayJson(['success' => false, 'error' => 'Требуется авторизация']);
}

if (!check_bitrix_sessid()) {
    alexWorkdayJson(['success' => false, 'error' => 'Сессия истекла, обновите страницу']);
}

if (!Loader::includeModule('timeman')) {
    alexWorkdayJson(['success' => false, 'error' => 'Модуль «Учёт рабочего времени» не установлен']);
}

if (!Loader::includeModule('alex.newmodule')) {
    alexWorkdayJson(['success' => false, 'error' => 'Модуль alex.newmodule не установлен']);
}

$userId = (int) $USER->GetID();

if (WorkdayStartHandler::getState($userId) === 'OPENED') {
    alexWorkdayJson(['success' => false, 'error' => 'Рабочий день уже начат']);
}

WorkdayStartHandler::allowStart();

$response = ['success' => true];

if (WorkdayStartHandler::isDebug()) {
    $response['api'] = WorkdayStartHandler::getTimemanApiInfo();
}

alexWorkdayJson($response);
