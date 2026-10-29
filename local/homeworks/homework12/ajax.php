<?php
/**
 * Серверная часть демо-стенда ДЗ #12.
 *
 * Страница демо не знает токенов вебхуков: она просит этот скрипт выполнить
 * вызов, а он берёт токен из опций модуля и делает HTTP-запрос сам. Так
 * секреты не уходят в HTML, а в браузер возвращается всё нужное для показа:
 * отправленный пакет, URL с замаскированным токеном, статус и ответ.
 *
 * Доступ — только администратору и только с валидным sessid.
 */

define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_CHECK', true);
define('BX_NO_ACCELERATOR_RESET', true);

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

const DEMO_OPTION_MODULE = 'alex.newmodule';
const DEMO_SCOPE         = 'meatshop';

/**
 * @return never
 */
function demoRespond(array $data)
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);

    die();
}

global $USER;

if (!is_object($USER) || !$USER->IsAdmin()) {
    demoRespond(['ok' => false, 'error' => 'Демо-стенд доступен только администратору портала']);
}

if (!check_bitrix_sessid()) {
    demoRespond(['ok' => false, 'error' => 'Неверный sessid — обновите страницу']);
}

if (!\Bitrix\Main\Loader::includeModule('alex.newmodule')) {
    demoRespond(['ok' => false, 'error' => 'Модуль alex.newmodule не установлен']);
}

function demoToken(string $op): string
{
    return (string) \Bitrix\Main\Config\Option::get(DEMO_OPTION_MODULE, 'meatshop_webhook_' . $op, '');
}

function demoMask(string $token): string
{
    return mb_strlen($token) > 6
        ? mb_substr($token, 0, 4) . str_repeat('*', mb_strlen($token) - 6) . mb_substr($token, -2)
        : '***';
}

/**
 * Один HTTP-вызов REST-метода через вебхук.
 */
function demoHttp(string $base, string $host, string $token, string $method, array $params): array
{
    $http = new \Bitrix\Main\Web\HttpClient([
        'socketTimeout'          => 5,
        'streamTimeout'          => 15,
        'waitResponse'           => true,
        'disableSslVerification' => true,
    ]);

    // В docker-стенде идём по IP контейнера, поэтому vhost выбираем заголовком
    $http->setHeader('Host', $host);

    $url = $base . '/rest/1/' . $token . '/' . $method . '.json';
    $raw = (string) $http->post($url, $params);

    $decoded = null;

    try {
        $decoded = \Bitrix\Main\Web\Json::decode($raw);
    } catch (\Throwable $e) {
        $decoded = null;
    }

    return [
        'status'   => (int) $http->getStatus(),
        'raw'      => $raw,
        'decoded'  => $decoded,
        'error'    => count($http->getError()) ? implode(' ', $http->getError()) : '',
        'url'      => $base . '/rest/1/' . demoMask($token) . '/' . $method . '.json',
    ];
}

/**
 * Адрес портала, по которому он доступен сам себе. Результат кешируется
 * в опции: в docker-стенде подбор требует нескольких проб.
 */
function demoResolveBase(string $probeToken): string
{
    $host   = $_SERVER['HTTP_HOST'];
    $cached = (string) \Bitrix\Main\Config\Option::get(DEMO_OPTION_MODULE, 'meatshop_demo_base', '');

    if ($cached !== '') {
        return $cached;
    }

    $candidates = [
        ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') . '://' . $host,
    ];

    foreach (['web_server', 'bitrixdock-web_server-1', 'nginx', 'apache'] as $name) {
        $ip = gethostbyname($name);

        if ($ip !== $name) {
            $candidates[] = 'http://' . $ip;
        }
    }

    foreach (array_unique($candidates) as $base) {
        $probe = demoHttp($base, $host, $probeToken, 'methods', ['scope' => DEMO_SCOPE]);

        if ($probe['status'] === 200) {
            \Bitrix\Main\Config\Option::set(DEMO_OPTION_MODULE, 'meatshop_demo_base', $base);

            return $base;
        }
    }

    return '';
}

$action = (string) ($_REQUEST['action'] ?? 'call');

// ------------------------------------------------- состояние стенда

if ($action === 'status') {
    $tokens = [];

    foreach (['create', 'read', 'update', 'delete'] as $op) {
        $token          = demoToken($op);
        $tokens[$op]    = $token !== '' ? demoMask($token) : '';
    }

    $description = (new \CRestProvider())->getDescription();

    demoRespond([
        'ok'      => true,
        'tokens'  => $tokens,
        'methods' => array_keys($description[DEMO_SCOPE] ?? []),
        'logFile' => '/local/logs/meatshop_' . date('d.m.Y') . '.log',
    ]);
}

// ------------------------------------------- полные URL по явному запросу

if ($action === 'urls') {
    $urls = [];

    foreach (['create', 'read', 'update', 'delete'] as $op) {
        $token = demoToken($op);

        $urls[$op] = $token === ''
            ? ''
            : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http')
            . '://' . $_SERVER['HTTP_HOST'] . '/rest/1/' . $token
            . '/meatshop_product.' . $op . '.json';
    }

    demoRespond(['ok' => true, 'urls' => $urls]);
}

// -------------------------------------------------------- вызов метода

$operations = [
    // update принимает MANUFACTURER и PRODUCT_TYPE намеренно: кнопка
    // негативного сценария должна показать отказ сервиса, а не фильтр стенда
    'create' => ['NAME', 'PRICE', 'MANUFACTURER', 'PRODUCT_TYPE'],
    'read'   => ['ID', 'LIMIT', 'OFFSET'],
    'update' => ['ID', 'NAME', 'PRICE'],
    'delete' => ['ID'],
];

$op = (string) ($_REQUEST['op'] ?? '');

if (!isset($operations[$op])) {
    demoRespond(['ok' => false, 'error' => 'Неизвестная операция: ' . $op]);
}

$token = demoToken($op);

if ($token === '') {
    demoRespond([
        'ok'    => false,
        'error' => 'Вебхук операции «' . $op . '» не создан — прогоните скрипт создания вебхуков',
    ]);
}

$base = demoResolveBase(demoToken('read') ?: $token);

if ($base === '') {
    demoRespond([
        'ok'    => false,
        'error' => 'Портал недоступен сам себе по HTTP (ограничение стенда). '
            . 'Вызовите ручку снаружи — curl или Bruno.',
    ]);
}

// Пропускаем только разрешённые для операции поля и только непустые
$params = [];

foreach ($operations[$op] as $field) {
    if (isset($_REQUEST[$field]) && $_REQUEST[$field] !== '') {
        $params[$field] = (string) $_REQUEST[$field];
    }
}

$result = demoHttp($base, $_SERVER['HTTP_HOST'], $token, 'meatshop_product.' . $op, $params);

$body = $result['decoded'];

demoRespond([
    'ok'       => $result['status'] === 200 && is_array($body) && !isset($body['error']),
    'op'       => $op,
    'method'   => 'meatshop_product.' . $op,
    'url'      => $result['url'],
    'request'  => $params,
    'status'   => $result['status'],
    'response' => $body ?? $result['raw'],
    'error'    => $result['error'],
]);
