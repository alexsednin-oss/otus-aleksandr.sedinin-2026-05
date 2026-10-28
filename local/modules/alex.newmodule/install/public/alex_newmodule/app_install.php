<?php
/**
 * Путь первоначальной установки локального приложения.
 *
 * Битрикс24 открывает эту страницу при установке приложения и передаёт POST
 * с AUTH_ID. По нему подписываемся на событие ONCRMACTIVITYADD, указывая
 * обработчиком app_handler.php.
 */

define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
define('NO_AGENT_CHECK', true);
define('BX_NO_ACCELERATOR_RESET', true);
define('DisableEventsCheck', true);

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

use Bitrix\Main\Config\Option;
use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\Json;

const MODULE_ID = 'alex.newmodule';
const EVENT_NAME = 'ONCRMACTIVITYADD';

$lines = [];

$accessToken = (string) ($_REQUEST['AUTH_ID'] ?? $_REQUEST['auth']['access_token'] ?? '');

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';

// Адрес портала для серверных вызовов. Пусто — берём хост текущего запроса.
$internalHost = trim((string) Option::get(MODULE_ID, 'app_internal_host', ''));

if ($internalHost === '') {
    $portalBase = $scheme . '://' . $_SERVER['HTTP_HOST'];
} elseif (strpos($internalHost, '://') !== false) {
    $portalBase = rtrim($internalHost, '/');
} else {
    $portalBase = 'http://' . rtrim($internalHost, '/');
}

$handlerUrl = $portalBase . '/alex_newmodule/app_handler.php';

Option::set(MODULE_ID, 'app_handler_url', $handlerUrl);

$lines[] = 'Адрес портала для серверных вызовов: ' . $portalBase;
$lines[] = 'Обработчик события: ' . $handlerUrl;

if ($accessToken === '') {
    $lines[] = 'Не передан токен авторизации (AUTH_ID). Страница открыта напрямую, а не из мастера установки.';
} else {
    $http = new HttpClient([
        'socketTimeout'          => 10,
        'streamTimeout'          => 15,
        'waitResponse'           => true,
        'disableSslVerification' => true,
    ]);

    $response = $http->post(
        $portalBase . '/rest/event.bind',
        [
            'auth'    => $accessToken,
            'event'   => EVENT_NAME,
            'handler' => $handlerUrl,
        ]
    );

    $decoded = null;

    if ($response !== false) {
        try {
            $decoded = Json::decode((string) $response);
        } catch (\Throwable $e) {
            $decoded = null;
        }
    }

    if (is_array($decoded) && !empty($decoded['result'])) {
        $lines[] = 'Подписка на ' . EVENT_NAME . ' создана.';
        Option::set(MODULE_ID, 'app_event_bound', 'Y');
    } elseif (is_array($decoded) && ($decoded['error'] ?? '') === 'ERROR_EVENT_BINDING_EXISTS') {
        $lines[] = 'Подписка на ' . EVENT_NAME . ' уже существует.';
        Option::set(MODULE_ID, 'app_event_bound', 'Y');
    } else {
        $lines[] = 'Не удалось создать подписку. Статус ' . $http->getStatus()
            . ', ответ: ' . ($response === false ? 'нет ответа' : (string) $response);
    }
}

?><!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Установка приложения «Дата последней коммуникации»</title>
    <style>
        body { font: 14px/1.5 Arial, sans-serif; padding: 24px; }
        li { margin-bottom: 6px; }
    </style>
</head>
<body>
<h3>Приложение «Дата последней коммуникации»</h3>
<ul>
    <?php foreach ($lines as $line): ?>
        <li><?= htmlspecialcharsbx($line) ?></li>
    <?php endforeach; ?>
</ul>
<script src="//api.bitrix24.com/api/v1/"></script>
<script>
    if (typeof BX24 !== 'undefined') {
        BX24.init(function () { BX24.installFinish(); });
    }
</script>
</body>
</html>
