<?php
/**
 * Тест: Воронка «Сервисное обслуживание», стадии и поле сделки
 *
 * Запускается через tools/tests/run.php или страницу администрирования.
 * Счётчики и вывод берутся из bootstrap.php: check() и report().
 */

CModule::IncludeModule('crm');
CModule::IncludeModule('alex.autoservice');

// ======================================================= схема таблицы авто

echo "=== [0] схема таблицы автомобилей ===\n";

$connection = \Bitrix\Main\Application::getConnection();
$carTable   = \Alex\Autoservice\Model\CarTable::getTableName();

check('таблица ' . $carTable, $connection->isTableExists($carTable));

$nullable = [];

if ($connection->isTableExists($carTable)) {
    $res = $connection->query('SHOW COLUMNS FROM ' . $carTable);

    while ($row = $res->fetch()) {
        $nullable[$row['Field']] = $row['Null'];

        echo '  ' . str_pad($row['Field'], 14) . str_pad($row['Type'], 16) . 'NULL=' . $row['Null'] . "\n";
    }
}

foreach (['YEAR', 'COLOR', 'MILEAGE', 'UPDATED_AT'] as $column) {
    check('  ' . str_pad($column, 12) . 'допускает NULL', ($nullable[$column] ?? 'NO') === 'YES');
}

// ============================================================ миграция воронки

echo "\n=== [1] применение миграции ===\n";

report(\Alex\Autoservice\Migration\Manager::up());

$categoryId = (int) \Bitrix\Main\Config\Option::get('alex.autoservice', 'service_category_id', 0);

check('ID воронки сохранён в опции', $categoryId > 0, 'ID = ' . $categoryId);

if (!$categoryId) {
        return;
}

echo "\n=== [2] воронка ===\n";

$factory  = \Alex\Autoservice\Service\DealFunnel::getFactory();
$category = $factory->getCategory($categoryId);

check('воронка найдена фабрикой', $category !== null, $category ? $category->getName() : '');

$stagesEntityId = \Alex\Autoservice\Service\DealFunnel::getStagesEntityId($categoryId);

echo '  набор стадий: ' . $stagesEntityId . "\n";

// ==================================================================== стадии

echo "\n=== [3] стадии ===\n";

$existing = \Alex\Autoservice\Service\Stages::getExisting($stagesEntityId);

foreach ($existing as $statusId => $row) {
    echo '  ' . str_pad($statusId, 22) . str_pad((string) $row['SORT'], 6)
        . str_pad((string) $row['NAME'], 22)
        . 'SYSTEM=' . ($row['SYSTEM'] ?? '—') . '  SEM=' . ($row['SEMANTICS'] ?? '—') . "\n";
}

$expected = [
    'NEW'           => 'Приемка',
    'DIAGNOSTICS'   => 'Диагностика',
    'WAITING_PARTS' => 'Ожидание запчастей',
    'REPAIR'        => 'Ремонт',
    'CHECK'         => 'Проверка',
    'WON'           => 'Завершено',
    'LOSE'          => 'Отказ клиента',
];

$prefix = '';

foreach (array_keys($existing) as $statusId) {
    $position = mb_strpos($statusId, ':');

    if ($position !== false) {
        $prefix = mb_substr($statusId, 0, $position + 1);
        break;
    }
}

echo "\n  префикс: " . ($prefix !== '' ? $prefix : '—') . "\n";

foreach ($expected as $code => $name) {
    $statusId = $prefix . $code;

    check('  стадия ' . str_pad($code, 14),
        isset($existing[$statusId]) && $existing[$statusId]['NAME'] === $name,
        $existing[$statusId]['NAME'] ?? 'нет');
}

check('успешная стадия помечена семантикой', ($existing[$prefix . 'WON']['SEMANTICS'] ?? '') === 'S');
check('провальная стадия помечена семантикой', ($existing[$prefix . 'LOSE']['SEMANTICS'] ?? '') === 'F');
check('лишних стадий не осталось', count($existing) === count($expected), 'всего: ' . count($existing));

// ============================================================== поле сделки

echo "\n=== [4] поле сделки ===\n";

$carField = \Alex\Autoservice\Service\CarCrudService::DEAL_CAR_FIELD;
$fieldId  = \Alex\Autoservice\Service\UserField::getId('CRM_DEAL', $carField);

check('поле ' . $carField . ' создано', $fieldId > 0, 'ID = ' . $fieldId);

// ====================================================== сквозной сценарий

echo "\n=== [5] сквозной сценарий ===\n";

$suffix  = date('dHis');
$contact = new \CCrmContact(false);

// Массив в переменной: CCrmContact::Add() принимает его по ссылке
$contactFields = ['NAME' => 'Тест', 'LAST_NAME' => 'Клиент ' . $suffix, 'OPENED' => 'Y'];

$contactId = (int) $contact->Add(
    $contactFields,
    true,
    ['DISABLE_USER_FIELD_CHECK' => true, 'REGISTER_SONET_EVENT' => false]
);

check('контакт создан', $contactId > 0, 'ID = ' . $contactId . ($contactId ? '' : ' ' . $contact->LAST_ERROR));

if (!$contactId) {
        return;
}

// Автомобиль без года, цвета и пробега — проверка nullable-схемы
$car = \Alex\Autoservice\Service\CarCrudService::create([
    'CONTACT_ID' => $contactId,
    'BRAND'      => 'Kia',
    'MODEL'      => 'Rio',
    'NUMBER'     => 'в' . substr($suffix, -3) . 'мн199',
]);

$carId = (int) ($car['ID'] ?? 0);

check('автомобиль создан без необязательных полей', $carId > 0, 'ID = ' . $carId);

$carRow = \Alex\Autoservice\Service\CarCrudService::read(['ID' => $carId]);

check('  год пустой', $carRow['YEAR'] === null);
check('  пробег пустой', $carRow['MILEAGE'] === null);
check('  заголовок собран', ($carRow['TITLE'] ?? '') !== '');

// Сделка в сервисной воронке с привязкой к автомобилю
$deal       = new \CCrmDeal(false);
$dealFields = [
    'TITLE'       => 'Заказ-наряд ' . $suffix,
    'CATEGORY_ID' => $categoryId,
    'CONTACT_ID'  => $contactId,
    'OPENED'      => 'Y',
    $carField     => $carId,
];

$dealId = (int) $deal->Add(
    $dealFields,
    true,
    ['DISABLE_USER_FIELD_CHECK' => true, 'REGISTER_SONET_EVENT' => false]
);

check('сделка создана в сервисной воронке', $dealId > 0, $dealId ? 'ID = ' . $dealId : $deal->LAST_ERROR);

if ($dealId) {
    $loaded = \CCrmDeal::GetListEx(
        [],
        ['ID' => $dealId, 'CHECK_PERMISSIONS' => 'N'],
        false,
        false,
        ['ID', 'CATEGORY_ID', 'STAGE_ID', $carField]
    )->Fetch();

    check('  сделка в нужной воронке', (int) $loaded['CATEGORY_ID'] === $categoryId,
        'CATEGORY_ID = ' . $loaded['CATEGORY_ID']);
    check('  стартовая стадия из нашего набора',
        $prefix !== '' && mb_strpos((string) $loaded['STAGE_ID'], $prefix) === 0,
        (string) $loaded['STAGE_ID']);
    check('  автомобиль привязан', (int) $loaded[$carField] === $carId);

    check('счётчик сделок по автомобилю',
        \Alex\Autoservice\Service\CarCrudService::countDeals($carId) === 1);

    try {
        \Alex\Autoservice\Service\CarCrudService::delete($carId);
        check('удаление автомобиля со сделкой запрещено', false, 'ошибки не было');
    } catch (\Throwable $e) {
        check('удаление автомобиля со сделкой запрещено', true, $e->getMessage());
    }

    $deal->Delete($dealId, ['ENABLE_DEFERRED_MODE' => false]);

    check('после удаления сделки счётчик обнулился',
        \Alex\Autoservice\Service\CarCrudService::countDeals($carId) === 0);

    $removed = \Alex\Autoservice\Service\CarCrudService::delete($carId);

    check('автомобиль удалён', ($removed['DELETED'] ?? false) === true);
}

$contact->Delete($contactId);

// ==================================================================== откат

echo "\n=== [6] откат ===\n";

$funnelDeals     = 0;
$serviceCategory = (int) \Bitrix\Main\Config\Option::get('alex.autoservice', 'service_category_id', 0);

if ($serviceCategory) {
    $res = \CCrmDeal::GetListEx([], ['CATEGORY_ID' => $serviceCategory, 'CHECK_PERMISSIONS' => 'N'], false, false, ['ID']);

    while ($res->Fetch()) {
        $funnelDeals++;
    }
}

if ($funnelDeals > 0) {
    echo '  в воронке ' . $funnelDeals . " сделок — CRM не даст её удалить, цикл отката пропущен\n";

    return;
}

$journalBefore = count(\Alex\Autoservice\Migration\Manager::getApplied());

// Manager::down() без аргументов откатывает последнюю применённую
// миграцию, а последняя давно не эта. Зовём свою по версии: журнал
// остаётся нетронутым, а следующий блок возвращает всё на место
$migration = \Alex\Autoservice\Migration\Manager::get('20261110090000');

$migration->down();

report($migration->getReport());

check('поле сделки снято',
    \Alex\Autoservice\Service\UserField::getId('CRM_DEAL', $carField) === 0);
check('воронка удалена',
    \Alex\Autoservice\Service\DealFunnel::findByName('Сервисное обслуживание') === 0);
check('опция очищена',
    \Bitrix\Main\Config\Option::get('alex.autoservice', 'service_category_id', '') === '');
check('журнал не тронут',
    count(\Alex\Autoservice\Migration\Manager::getApplied()) === $journalBefore,
    'записей: ' . $journalBefore);

// =========================================================== повторный накат

echo "\n=== [7] повторный накат ===\n";

$migration = \Alex\Autoservice\Migration\Manager::get('20261110090000');

$migration->up();

report($migration->getReport());

$again = (int) \Bitrix\Main\Config\Option::get('alex.autoservice', 'service_category_id', 0);

check('воронка создана заново', $again > 0, 'ID = ' . $again
    . ($again !== $categoryId ? ' (прежняя была ' . $categoryId . ')' : ''));
check('поле сделки создано заново',
    \Alex\Autoservice\Service\UserField::getId('CRM_DEAL', $carField) > 0);

$againStages = \Alex\Autoservice\Service\Stages::getExisting(
    \Alex\Autoservice\Service\DealFunnel::getStagesEntityId($again)
);

check('стадии восстановлены', count($againStages) === count($expected),
    'всего: ' . count($againStages));
