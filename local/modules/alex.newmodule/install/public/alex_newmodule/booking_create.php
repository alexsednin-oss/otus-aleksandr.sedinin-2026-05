<?php
/**
 * AJAX: создание элемента в списке "Бронирование" с проверкой занятого времени.
 *
 * Важно: подключаем prolog_before.php, а НЕ header.php — header.php печатает
 * пролог страницы, и в ответ попадает HTML вместо чистого JSON.
 */

define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_CHECK', true);
define('DisableEventsCheck', true);

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

use Bitrix\Main\Loader;

/**
 * @param array $data
 * @return never
 */
function alexNmJsonResponse(array $data)
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
    alexNmJsonResponse(['success' => false, 'error' => 'Требуется авторизация']);
}

if (!check_bitrix_sessid()) {
    alexNmJsonResponse(['success' => false, 'error' => 'Сессия истекла, обновите страницу']);
}

if (!Loader::includeModule('iblock')) {
    alexNmJsonResponse(['success' => false, 'error' => 'Модуль iblock не установлен']);
}

if (!Loader::includeModule('alex.newmodule')) {
    alexNmJsonResponse(['success' => false, 'error' => 'Модуль alex.newmodule не установлен']);
}

$procedureId     = (int) ($_POST['PROCEDURE_ID'] ?? 0);
$patientName     = trim((string) ($_POST['PATIENT_NAME'] ?? ''));
$appointmentTime = trim((string) ($_POST['APPOINTMENT_TIME'] ?? ''));

if (!$procedureId || $patientName === '' || $appointmentTime === '') {
    alexNmJsonResponse(['success' => false, 'error' => 'Заполните все поля']);
}

// Приводим "2026-09-13T10:30" из <input type="datetime-local"> к "13.09.2026 10:30",
// чтобы одинаково писать в свойство и одинаково искать конфликт.
$timestamp = strtotime(str_replace('T', ' ', $appointmentTime));

if (!$timestamp) {
    alexNmJsonResponse(['success' => false, 'error' => 'Некорректное время записи']);
}

$appointmentTime = date('d.m.Y H:i', $timestamp);

$bookingIblockId = \Alex\Newmodule\BookingInstaller::getBookingIblockId();

if (!$bookingIblockId) {
    alexNmJsonResponse(['success' => false, 'error' => 'Список «Бронирование» не установлен']);
}

$procedure = \CIBlockElement::GetList([], ['ID' => $procedureId], false, false, ['ID', 'NAME'])->Fetch();

if (!$procedure) {
    alexNmJsonResponse(['success' => false, 'error' => 'Процедура не найдена']);
}

// Проверка занятого времени: та же процедура + то же время
$conflict = \CIBlockElement::GetList(
    [],
    [
        'IBLOCK_ID'                 => $bookingIblockId,
        'PROPERTY_PROCEDURE'        => $procedureId,
        'PROPERTY_APPOINTMENT_TIME' => $appointmentTime,
    ],
    false,
    false,
    ['ID']
)->Fetch();

if ($conflict) {
    alexNmJsonResponse([
        'success' => false,
        'error'   => 'На это время уже запланирована процедура',
    ]);
}

$element = new \CIBlockElement();

$newId = $element->Add([
    'IBLOCK_ID'       => $bookingIblockId,
    'ACTIVE'          => 'Y',
    'NAME'            => $patientName . ' — ' . $procedure['NAME'] . ' — ' . $appointmentTime,
    'PROPERTY_VALUES' => [
        'PATIENT_NAME'     => $patientName,
        'APPOINTMENT_TIME' => $appointmentTime,
        'PROCEDURE'        => $procedureId,
    ],
]);

if (!$newId) {
    alexNmJsonResponse([
        'success' => false,
        'error'   => 'Не удалось создать бронирование: ' . $element->LAST_ERROR,
    ]);
}

alexNmJsonResponse([
    'success' => true,
    'id'      => (int) $newId,
    'time'    => $appointmentTime,
]);
