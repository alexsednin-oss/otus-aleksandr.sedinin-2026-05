<?php
/**
 * История обращений по автомобилю — JSON для попапа вкладки «Гараж».
 */

define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_CHECK', true);
define('BX_NO_ACCELERATOR_RESET', true);

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

/**
 * @param array $data
 *
 * @return never
 */
function alexGarageRespond(array $data)
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
    alexGarageRespond(['ok' => false, 'error' => Loc::getMessage('ALEX_AUTOSERVICE_HISTORY_AUTH_REQUIRED')]);
}

if (!check_bitrix_sessid()) {
    alexGarageRespond(['ok' => false, 'error' => Loc::getMessage('ALEX_AUTOSERVICE_HISTORY_BAD_SESSID')]);
}

if (!\Bitrix\Main\Loader::includeModule('alex.autoservice')) {
    alexGarageRespond(['ok' => false, 'error' => Loc::getMessage('ALEX_AUTOSERVICE_HISTORY_NO_MODULE')]);
}

$carId = (int) ($_REQUEST['carId'] ?? 0);

if ($carId <= 0) {
    alexGarageRespond(['ok' => false, 'error' => Loc::getMessage('ALEX_AUTOSERVICE_HISTORY_NO_CAR')]);
}

try {
    $car = \Alex\Autoservice\Service\CarCrudService::read(['ID' => $carId]);
} catch (\Throwable $e) {
    alexGarageRespond(['ok' => false, 'error' => $e->getMessage()]);
}

alexGarageRespond([
    'ok'      => true,
    'caption' => \Alex\Autoservice\Service\Garage::getCaption($car),
    'deals'   => \Alex\Autoservice\Service\Garage::getHistory($carId),
]);
