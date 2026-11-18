<?php
/**
 * Тест: Оргструктура сервисного центра и механизм миграций
 *
 * Запускается через tools/tests/run.php или страницу администрирования.
 * Счётчики и вывод берутся из bootstrap.php: check() и report().
 */

CModule::IncludeModule('iblock');
CModule::IncludeModule('intranet');

/** Полный цикл с откатом. Поставь false, чтобы только посмотреть состояние. */
$runFullCycle = true;

/**
 * Откат сносит воронку и смарт-процесс, а CRM не даёт удалить их, пока
 * внутри есть сделки и заявки. true — удалить эти данные перед откатом
 * (демо-данные пропадут), false — пропустить разрушающую часть теста.
 */
$dropBlockingData = false;

function printReport(array $lines): void
{
    foreach ($lines as $line) {
        echo '    ' . htmlspecialcharsbx($line) . "\n";
    }
}

/** Подразделение по XML_ID */
function asDepartment(string $code): ?array
{
    $iblockId = (int) \Bitrix\Main\Config\Option::get('intranet', 'iblock_structure', 0);

    if (!$iblockId) {
        return null;
    }

    $row = \CIBlockSection::GetList(
        [],
        [
            'IBLOCK_ID'         => $iblockId,
            'XML_ID'            => 'alex.autoservice.' . $code,
            'CHECK_PERMISSIONS' => 'N',
        ],
        false,
        ['ID', 'NAME', 'XML_ID', 'IBLOCK_SECTION_ID', 'UF_HEAD']
    )->Fetch();

    return $row ?: null;
}

/** Сотрудники проекта по логинам */
function asUsers(array $logins): array
{
    if (!$logins) {
        return [];
    }

    $rows = \Bitrix\Main\UserTable::query()
        ->setSelect(['ID', 'LOGIN', 'ACTIVE', 'WORK_POSITION', 'UF_DEPARTMENT'])
        ->whereIn('LOGIN', $logins)
        ->fetchAll();

    $byLogin = [];

    foreach ($rows as $row) {
        $byLogin[$row['LOGIN']] = $row;
    }

    return $byLogin;
}

$expected = [
    'as.director'   => 'DIRECTOR',
    'as.manager1'   => 'MANAGER',
    'as.manager2'   => 'MANAGER',
    'as.mechanic1'  => 'MECHANIC',
    'as.mechanic2'  => 'MECHANIC',
    'as.mechanic3'  => 'MECHANIC',
    'as.mechanic4'  => 'MECHANIC',
    'as.mechanic5'  => 'MECHANIC',
    'as.accountant' => 'ACCOUNTANT',
    'as.purchead'   => 'PURCH_HEAD',
    'as.buyer1'     => 'BUYER',
    'as.buyer2'     => 'BUYER',
];

$expectedByRole = ['DIRECTOR' => 1, 'MANAGER' => 2, 'MECHANIC' => 5, 'ACCOUNTANT' => 1, 'PURCH_HEAD' => 1, 'BUYER' => 2];
$logins         = array_keys($expected);

// ================================================================ окружение

echo "=== [0] окружение ===\n";

$moduleReady = CModule::IncludeModule('alex.autoservice');

check('модуль alex.autoservice установлен', $moduleReady);

if (!$moduleReady) {
    echo "\n  модуль не установлен: поставьте его в «Marketplace → Установленные решения»\n";
    return;
}

foreach ([
    '\Alex\Autoservice\Migration\Manager',
    '\Alex\Autoservice\Migration\AbstractMigration',
    '\Alex\Autoservice\Migration\MigrationTable',
    '\Alex\Autoservice\Service\Log',
] as $class) {
    check('автозагрузка ' . $class, class_exists($class));
}

$tableName = \Alex\Autoservice\Migration\MigrationTable::getTableName();

check(
    'таблица ' . $tableName,
    \Bitrix\Main\Application::getConnection()->isTableExists($tableName)
);

$structureIblock = (int) \Bitrix\Main\Config\Option::get('intranet', 'iblock_structure', 0);

check('инфоблок структуры компании', $structureIblock > 0, 'ID = ' . $structureIblock);

// ===================================================== обнаружение миграций

echo "\n=== [1] обнаружение миграций ===\n";

echo '  каталог: ' . \Alex\Autoservice\Migration\Manager::getDirectory() . "\n";

check(
    'каталог миграций существует',
    is_dir(\Alex\Autoservice\Migration\Manager::getDirectory())
);

$available = \Alex\Autoservice\Migration\Manager::getAvailable();

check('найдена хотя бы одна миграция', count($available) > 0, 'всего: ' . count($available));

foreach (\Alex\Autoservice\Migration\Manager::getStatus() as $item) {
    echo '  ' . ($item['applied'] ? '[x] ' : '[ ] ') . $item['version']
        . '  ' . str_pad($item['name'], 44) . $item['description']
        . ($item['appliedAt'] ? '  (' . $item['appliedAt'] . ')' : '') . "\n";

    check('  версия извлечена из имени класса', preg_match('/^\d{14}$/', $item['version']) === 1);
    check('  описание непустое', $item['description'] !== '');
}

// ================================================= приведение к чистому виду

if (!$runFullCycle) {
    echo "\nПолный цикл отключён, дальше не идём.\n";
        return;
}

$blockers = 0;

// Тест оргструктуры сам по себе CRM не требует, но воронка и заявки,
// мешающие откату, живут именно там
CModule::IncludeModule('crm');

$serviceCategory = (int) \Bitrix\Main\Config\Option::get('alex.autoservice', 'service_category_id', 0);
$dealIds         = [];

if ($serviceCategory) {
    $res = \CCrmDeal::GetListEx([], ['CATEGORY_ID' => $serviceCategory, 'CHECK_PERMISSIONS' => 'N'], false, false, ['ID']);

    while ($row = $res->Fetch()) {
        $dealIds[] = (int) $row['ID'];
    }

    $blockers += count($dealIds);
}

$purchaseType = (int) \Bitrix\Main\Config\Option::get('alex.autoservice', 'purchase_type_id', 0);
$itemIds      = [];
$factory      = null;

if ($purchaseType) {
    $factory = \Alex\Autoservice\Service\SmartProcess::getFactory($purchaseType);

    foreach ($factory->getItems(['select' => ['ID']]) as $item) {
        $itemIds[] = (int) $item->getId();
    }

    $blockers += count($itemIds);
}

if ($blockers > 0 && !$dropBlockingData) {
    echo "\n  в воронке сделок: " . count($dealIds) . ', заявок в процессе: ' . count($itemIds)
        . "\n  CRM не даст их удалить — разрушающая часть теста пропущена."
        . "\n  Поставь \$dropBlockingData = true, чтобы снести эти данные.\n";

    return;
}

if ($blockers > 0) {
    $deal = new \CCrmDeal(false);

    foreach ($dealIds as $dealId) {
        $deal->Delete($dealId);
    }

    foreach ($itemIds as $itemId) {
        $operation = $factory->getDeleteOperation($factory->getItem($itemId));

        foreach (['disableRecycleBin', 'disableBizProc', 'disableCheckAccess'] as $method) {
            if (method_exists($operation, $method)) {
                $operation->$method();
            }
        }

        $operation->launch();
    }

    echo '    удалено сделок: ' . count($dealIds) . ', заявок: ' . count($itemIds) . "\n";
}

echo "\n=== [2] откат до чистого состояния ===\n";

if (\Alex\Autoservice\Migration\Manager::getApplied()) {
    printReport(\Alex\Autoservice\Migration\Manager::down(null, true));
} else {
    echo "  применённых миграций нет, откатывать нечего\n";
}

check('журнал миграций пуст', count(\Alex\Autoservice\Migration\Manager::getApplied()) === 0);
check('подразделение SERVICE удалено', asDepartment('SERVICE') === null);
check('подразделение PURCHASING удалено', asDepartment('PURCHASING') === null);

// ============================================================== применение

echo "\n=== [3] применение миграций ===\n";

printReport(\Alex\Autoservice\Migration\Manager::up());

echo "\n  ПАРОЛИ ВЫШЕ ПОКАЗАНЫ ОДИН РАЗ — сохраните их сейчас\n";

// --------------------------------------------------------------- журнал

echo "\n=== [4] журнал после применения ===\n";

$applied = \Alex\Autoservice\Migration\Manager::getApplied();

check('все миграции отмечены применёнными', count($applied) === count($available),
    count($applied) . ' из ' . count($available));

foreach (\Alex\Autoservice\Migration\Manager::getStatus() as $item) {
    check('  ' . $item['version'] . ' помечена применённой', $item['applied']);
    check('  ' . $item['version'] . ' имеет дату применения', !empty($item['appliedAt']),
        (string) $item['appliedAt']);
}

// -------------------------------------------------------------- структура

echo "\n=== [5] результат миграции оргструктуры ===\n";

$service    = asDepartment('SERVICE');
$purchasing = asDepartment('PURCHASING');

check('подразделение «Автосервис» создано', $service !== null,
    $service ? 'ID = ' . $service['ID'] . ', ' . $service['NAME'] : '');
check('подразделение «Отдел закупок» создано', $purchasing !== null,
    $purchasing ? 'ID = ' . $purchasing['ID'] . ', ' . $purchasing['NAME'] : '');

if ($service && $purchasing) {
    check('закупки подчинены автосервису',
        (int) $purchasing['IBLOCK_SECTION_ID'] === (int) $service['ID']);
}

$users = asUsers($logins);

check('создано 12 сотрудников', count($users) === 12, 'найдено: ' . count($users));

$inactive   = [];
$noDeptUser = [];
$byRole     = [];

foreach ($expected as $login => $role) {
    if (!isset($users[$login])) {
        continue;
    }

    $row = $users[$login];

    if ($row['ACTIVE'] !== 'Y') {
        $inactive[] = $login;
    }

    if (empty($row['UF_DEPARTMENT'])) {
        $noDeptUser[] = $login;
    }

    $byRole[$role][] = $row['ID'];
}

check('все сотрудники активны', !$inactive, $inactive ? implode(', ', $inactive) : '');
check('у всех заполнено подразделение', !$noDeptUser, $noDeptUser ? implode(', ', $noDeptUser) : '');

foreach ($expectedByRole as $role => $count) {
    check('  роль ' . str_pad($role, 11) . ' — ' . $count . ' чел.',
        count($byRole[$role] ?? []) === $count,
        'найдено: ' . count($byRole[$role] ?? []));
}

if ($service) {
    check('руководитель автосервиса назначен', (int) ($service['UF_HEAD'] ?? 0) > 0,
        'UF_HEAD = ' . ($service['UF_HEAD'] ?? '—'));
    check('  это директор',
        (int) ($service['UF_HEAD'] ?? 0) === (int) ($byRole['DIRECTOR'][0] ?? -1));
}

if ($purchasing) {
    check('руководитель отдела закупок назначен', (int) ($purchasing['UF_HEAD'] ?? 0) > 0,
        'UF_HEAD = ' . ($purchasing['UF_HEAD'] ?? '—'));
    check('  это начальник отдела закупок',
        (int) ($purchasing['UF_HEAD'] ?? 0) === (int) ($byRole['PURCH_HEAD'][0] ?? -1));
}

// ---------------------------------------------------- опции для след. миграций

echo "\n=== [6] карта ролей в опциях ===\n";

$rawUsers = (string) \Bitrix\Main\Config\Option::get('alex.autoservice', 'structure_users', '');
$rawDepts = (string) \Bitrix\Main\Config\Option::get('alex.autoservice', 'structure_departments', '');

check('опция structure_users заполнена', $rawUsers !== '');
check('опция structure_departments заполнена', $rawDepts !== '');

if ($rawUsers !== '') {
    $storedUsers = (array) \Bitrix\Main\Web\Json::decode($rawUsers);

    print_r($storedUsers);

    foreach ($expectedByRole as $role => $count) {
        check('  в опции роль ' . str_pad($role, 11), count((array) ($storedUsers[$role] ?? [])) === $count);
    }
}

// ------------------------------------------------------------ идемпотентность

echo "\n=== [7] повторное применение ===\n";

$again = \Alex\Autoservice\Migration\Manager::up();

printReport($again);

check('повторный up ничего не делает',
    count($again) === 1 && mb_strpos($again[0], 'уже применены') !== false);

check('сотрудников по-прежнему 12', count(asUsers($logins)) === 12);
check('подразделений по-прежнему два', asDepartment('SERVICE') !== null && asDepartment('PURCHASING') !== null);

// ===================================================================== откат

echo "\n=== [8] откат ===\n";

printReport(\Alex\Autoservice\Migration\Manager::down(null, true));

check('журнал миграций пуст', count(\Alex\Autoservice\Migration\Manager::getApplied()) === 0);
check('подразделения удалены', asDepartment('SERVICE') === null && asDepartment('PURCHASING') === null);

$afterDown = asUsers($logins);
$stillActive = [];

foreach ($afterDown as $login => $row) {
    if ($row['ACTIVE'] === 'Y') {
        $stillActive[] = $login;
    }
}

check('все сотрудники уволены', !$stillActive, $stillActive ? implode(', ', $stillActive) : '');
check('пользователи не удалены физически', count($afterDown) === 12,
    'найдено: ' . count($afterDown) . ' (увольнение, а не удаление — связи CRM целы)');

check('опции очищены',
    \Bitrix\Main\Config\Option::get('alex.autoservice', 'structure_users', '') === ''
    && \Bitrix\Main\Config\Option::get('alex.autoservice', 'structure_departments', '') === '');

// ======================================================= повторное применение

echo "\n=== [9] накат после отката ===\n";

printReport(\Alex\Autoservice\Migration\Manager::up());

$restored    = asUsers($logins);
$notRestored = [];
$noDept      = [];

foreach ($restored as $login => $row) {
    if ($row['ACTIVE'] !== 'Y') {
        $notRestored[] = $login;
    }

    if (empty($row['UF_DEPARTMENT'])) {
        $noDept[] = $login;
    }
}

check('подразделения восстановлены', asDepartment('SERVICE') !== null && asDepartment('PURCHASING') !== null);
check('все сотрудники снова активны', !$notRestored, $notRestored ? implode(', ', $notRestored) : '');
check('все снова в подразделениях', !$noDept, $noDept ? implode(', ', $noDept) : '');
check('дублей сотрудников не появилось', count($restored) === 12, 'найдено: ' . count($restored));
check('миграция снова в журнале', count(\Alex\Autoservice\Migration\Manager::getApplied()) === count($available));

// ====================================================================== лог

echo "\n=== [10] журнал проекта ===\n";

$logFile = $_SERVER['DOCUMENT_ROOT'] . '/local/logs/autoservice_' . date('d.m.Y') . '.log';

check('лог-файл создан', is_file($logFile), $logFile);

if (is_file($logFile)) {
    echo "  последние 1500 байт:\n";
    echo htmlspecialcharsbx(mb_substr((string) file_get_contents($logFile), -1500)) . "\n";
}
