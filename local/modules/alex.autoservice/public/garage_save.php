<?php
/**
 * Заведение, правка и удаление автомобилей из вкладки «Гараж».
 *
 * Отдаёт JSON. Права не проверяет сам: за них отвечает CarCrudService,
 * который зовут и REST, и миграции, — дублировать проверку здесь значило бы
 * завести второй источник правды.
 */

define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_CHECK', true);
define('BX_NO_ACCELERATOR_RESET', true);

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

use Alex\Autoservice\Service\CarCrudService;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

/**
 * @param array $data
 *
 * @return never
 */
function alexGarageSaveRespond(array $data)
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
    alexGarageSaveRespond(['ok' => false, 'error' => Loc::getMessage('ALEX_AUTOSERVICE_SAVE_AUTH_REQUIRED')]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !check_bitrix_sessid()) {
    alexGarageSaveRespond(['ok' => false, 'error' => Loc::getMessage('ALEX_AUTOSERVICE_SAVE_BAD_REQUEST')]);
}

if (!Loader::includeModule('alex.autoservice')) {
    alexGarageSaveRespond(['ok' => false, 'error' => Loc::getMessage('ALEX_AUTOSERVICE_SAVE_NO_MODULE')]);
}

$action = (string) ($_POST['action'] ?? '');
$carId  = (int) ($_POST['id'] ?? 0);

$fields = [
    'BRAND_ID' => (int) ($_POST['brandId'] ?? 0),
    'MODEL_ID' => (int) ($_POST['modelId'] ?? 0),
    'NUMBER'   => (string) ($_POST['number'] ?? ''),
    'YEAR'     => (string) ($_POST['year'] ?? ''),
    'COLOR'    => (string) ($_POST['color'] ?? ''),
    'MILEAGE'  => (string) ($_POST['mileage'] ?? ''),
];

try {
    switch ($action) {
        case 'create':
            $fields['CONTACT_ID'] = (int) ($_POST['contactId'] ?? 0);

            $created = CarCrudService::create($fields);

            alexGarageSaveRespond([
                'ok'      => true,
                'id'      => $created['ID'],
                'message' => Loc::getMessage('ALEX_AUTOSERVICE_SAVE_CREATED'),
            ]);

            break;

        case 'update':
            CarCrudService::update($carId, $fields);

            alexGarageSaveRespond([
                'ok'      => true,
                'id'      => $carId,
                'message' => Loc::getMessage('ALEX_AUTOSERVICE_SAVE_UPDATED'),
            ]);

            break;

        case 'delete':
            CarCrudService::delete($carId);

            alexGarageSaveRespond([
                'ok'      => true,
                'id'      => $carId,
                'message' => Loc::getMessage('ALEX_AUTOSERVICE_SAVE_DELETED'),
            ]);

            break;

        default:
            alexGarageSaveRespond([
                'ok'    => false,
                'error' => Loc::getMessage('ALEX_AUTOSERVICE_SAVE_UNKNOWN_ACTION', ['#ACTION#' => $action]),
            ]);
    }
} catch (\Throwable $e) {
    // Сообщения сервиса уже человекочитаемы и локализованы:
    // «Марка не найдена в справочнике», «Недостаточно прав» и прочие
    alexGarageSaveRespond(['ok' => false, 'error' => $e->getMessage()]);
}
