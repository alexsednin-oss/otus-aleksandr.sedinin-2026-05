<?php
/**
 * Тест: Таблица автомобилей, CRUD и REST-методы
 *
 * Запускается через tools/tests/run.php или страницу администрирования.
 * Счётчики и вывод берутся из bootstrap.php: check() и report().
 */

CModule::IncludeModule('crm');
CModule::IncludeModule('rest');
CModule::IncludeModule('alex.autoservice');

// =============================================================== окружение

echo "=== [0] окружение ===\n";

foreach ([
    '\Alex\Autoservice\Model\CarTable',
    '\Alex\Autoservice\Service\CarCrudService',
    '\Alex\Autoservice\Rest\RestService',
] as $class) {
    check('автозагрузка ' . $class, class_exists($class));
}

echo "\n=== [1] миграция таблицы ===\n";

report(\Alex\Autoservice\Migration\Manager::up());

$connection = \Bitrix\Main\Application::getConnection();
$tableName  = \Alex\Autoservice\Model\CarTable::getTableName();

check('таблица ' . $tableName, $connection->isTableExists($tableName));
check('уникальный индекс по NUMBER', $connection->isIndexExists($tableName, ['NUMBER']));

echo "\n=== [2] регистрация в REST ===\n";

$scope        = \Alex\Autoservice\Rest\RestService::SCOPE;
$scopeMethods = (new \CRestProvider())->getDescription()[$scope] ?? [];

check('scope «' . $scope . '» объявлен', !empty($scopeMethods));

foreach (['create', 'read', 'update', 'delete'] as $op) {
    check('  метод autoservice_car.' . $op, isset($scopeMethods['autoservice_car.' . $op]));
}

// ----------------------------------------------------- подопытный контакт

echo "\n=== [3] тестовый контакт ===\n";

$suffix  = date('dHis');
$contact = new \CCrmContact(false);

// CCrmContact::Add() принимает массив по ссылке, литерал туда не передать
$contactFields = [
    'NAME'      => 'Тест',
    'LAST_NAME' => 'Владелец ' . $suffix,
    'OPENED'    => 'Y',
];

$contactId = (int) $contact->Add($contactFields, true, [
    'DISABLE_USER_FIELD_CHECK' => true,
    'REGISTER_SONET_EVENT'     => false,
]);

check('контакт создан', $contactId > 0, 'ID = ' . $contactId . ($contactId ? '' : ' ' . $contact->LAST_ERROR));

if (!$contactId) {
        return;
}

// ===================================================================== CRUD

echo "\n=== [A] создание ===\n";

$number  = 'а' . substr($suffix, -3) . 'вс777';
$created = \Alex\Autoservice\Rest\RestService::carCreate([
    'contact_id'   => $contactId,       // регистр ключей не важен
    'BRAND'        => 'Toyota',
    'MODEL'        => 'Camry',
    'NUMBER'       => $number,
    'YEAR'         => 2019,
    'COLOR'        => 'Чёрный',
    'MILEAGE'      => 84000,
]);

print_r($created);

$carId = (int) ($created['ID'] ?? 0);

check('автомобиль добавлен', $carId > 0, 'ID = ' . $carId);
check('госномер нормализован', ($created['NUMBER'] ?? '') === mb_strtoupper(preg_replace('/\s+/u', '', $number)),
    $created['NUMBER'] ?? '');

echo "\n=== [B] чтение ===\n";

$row = \Alex\Autoservice\Rest\RestService::carRead(['ID' => $carId]);
print_r($row);

check('владелец отдан именем', !empty($row['OWNER']), (string) ($row['OWNER'] ?? ''));
check('собран заголовок', ($row['TITLE'] ?? '') === 'Toyota Camry (' . $row['NUMBER'] . ')');
check('пробег на месте', ($row['MILEAGE'] ?? null) === 84000);

$garage = \Alex\Autoservice\Service\CarCrudService::getByContact($contactId);

check('гараж клиента содержит автомобиль', count($garage) === 1);

echo "\n=== [C] дубль госномера ===\n";

try {
    \Alex\Autoservice\Rest\RestService::carCreate([
        'CONTACT_ID' => $contactId,
        'BRAND'      => 'Kia',
        'MODEL'      => 'Rio',
        'NUMBER'     => mb_strtolower($number) . ' ',   // тот же номер в другом виде
    ]);

    check('дубль госномера отклонён', false, 'ошибки не было');
} catch (\Throwable $e) {
    check('дубль госномера отклонён', true,
        '[' . (method_exists($e, 'getErrorCode') ? $e->getErrorCode() : $e->getCode()) . '] ' . $e->getMessage());
}

echo "\n=== [D] обновление ===\n";

print_r(\Alex\Autoservice\Rest\RestService::carUpdate([
    'ID'      => $carId,
    'MILEAGE' => 91000,
    'COLOR'   => 'Синий',
]));

$afterUpdate = \Alex\Autoservice\Rest\RestService::carRead(['ID' => $carId]);

check('пробег обновлён', ($afterUpdate['MILEAGE'] ?? null) === 91000);
check('цвет обновлён', ($afterUpdate['COLOR'] ?? '') === 'Синий');
check('владелец не изменился', (int) ($afterUpdate['CONTACT_ID'] ?? 0) === $contactId);
check('UPDATED_AT заполнен', !empty($afterUpdate['UPDATED_AT']));

echo "\n=== [E] валидация ===\n";

$negative = [
    'смена владельца запрещена'  => ['carUpdate', ['ID' => $carId, 'CONTACT_ID' => 1]],
    'пустое обновление отклонено' => ['carUpdate', ['ID' => $carId]],
    'создание без владельца'      => ['carCreate', ['BRAND' => 'Kia', 'MODEL' => 'Rio', 'NUMBER' => 'Х001ХХ99']],
    'создание с чужим контактом'  => ['carCreate', ['CONTACT_ID' => 999999, 'BRAND' => 'Kia', 'MODEL' => 'Rio', 'NUMBER' => 'Х002ХХ99']],
    'создание без номера'         => ['carCreate', ['CONTACT_ID' => $contactId, 'BRAND' => 'Kia', 'MODEL' => 'Rio']],
    'отрицательный пробег'        => ['carCreate', ['CONTACT_ID' => $contactId, 'BRAND' => 'Kia', 'MODEL' => 'Rio', 'NUMBER' => 'Х003ХХ99', 'MILEAGE' => -1]],
    'чтение чужого ID'            => ['carRead', ['ID' => 999999]],
    'удаление чужого ID'          => ['carDelete', ['ID' => 999999]],
];

foreach ($negative as $title => $call) {
    [$method, $args] = $call;

    try {
        call_user_func(['\Alex\Autoservice\Rest\RestService', $method], $args);
        check($title, false, 'ошибки не было');
    } catch (\Throwable $e) {
        check($title, true, '[' . (method_exists($e, 'getErrorCode') ? $e->getErrorCode() : $e->getCode()) . ']');
    }
}

echo "\n=== [F] удаление ===\n";

print_r(\Alex\Autoservice\Rest\RestService::carDelete(['ID' => $carId]));

try {
    \Alex\Autoservice\Rest\RestService::carRead(['ID' => $carId]);
    check('автомобиль удалён', false, 'запись всё ещё читается');
} catch (\Throwable $e) {
    check('автомобиль удалён', true);
}

check('контакт не пострадал',
    (bool) \Bitrix\Crm\ContactTable::query()->setSelect(['ID'])->where('ID', $contactId)->fetch());

echo "\n=== [G] откат миграции ===\n";

/**
 * Проверка цикла down/up удаляет таблицу вместе со всеми автомобилями
 * стенда. false — пропустить её и сохранить демо-данные.
 */
$checkMigrationCycle = true;

$cars = \Alex\Autoservice\Model\CarTable::getCount();

if ($cars > 0 && !$checkMigrationCycle) {
    echo '  в таблице ' . $cars . " автомобилей — цикл down/up пропущен\n";

    return;
}

// Manager::down() без аргументов откатывает последнюю применённую
// миграцию, а это давно не таблица автомобилей. Поэтому зовём свою
// миграцию напрямую: журнал остаётся нетронутым
$journalBefore = count(\Alex\Autoservice\Migration\Manager::getApplied());

$migration = \Alex\Autoservice\Migration\Manager::get('20261109090000');

$migration->down();

report($migration->getReport());

check('таблица удалена', !$connection->isTableExists($tableName));
check('журнал не тронут',
    count(\Alex\Autoservice\Migration\Manager::getApplied()) === $journalBefore,
    'записей: ' . $journalBefore);

echo "\n=== [H] повторный накат ===\n";

$migration = \Alex\Autoservice\Migration\Manager::get('20261109090000');

$migration->up();

report($migration->getReport());

check('таблица создана заново', $connection->isTableExists($tableName));
check('индекс создан заново', $connection->isIndexExists($tableName, ['NUMBER']));

// ------------------------------------------------------------------- уборка

$contact->Delete($contactId);

echo "\n  тестовый контакт удалён\n";
