<?php
/**
 * Тест: Права доступа: роли CRM и проверки гаража
 *
 * Запускается через tools/tests/run.php или страницу администрирования.
 * Счётчики и вывод берутся из bootstrap.php: check() и report().
 */

use Alex\Autoservice\Migration\Manager;
use Alex\Autoservice\Service\Access;
use Alex\Autoservice\Service\AccessDeniedException;
use Alex\Autoservice\Service\CarCrudService;
use Alex\Autoservice\Service\Purchase;
use Alex\Autoservice\Model\CarTable;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;

CModule::IncludeModule('crm');
CModule::IncludeModule('alex.autoservice');

$check = static function (bool $condition, string $label, string $detail = ''): bool {
    check($label, $condition, $detail);

    return $condition;
};

/** Выполняет замыкание от лица указанного сотрудника */
$asUser = static function (int $id, callable $fn) {
    global $USER;

    $saved = $USER;

    // Наследуемся от CUser: ядро обращается к глобальному $USER и за другими
    // методами (CTimeZone::GetOffset → GetParam), у простой заглушки их нет
    $USER = new class extends \CUser {
        public int $testUserId = 0;

        public function IsAuthorized()
        {
            return true;
        }

        public function GetID()
        {
            return $this->testUserId;
        }

        public function IsAdmin()
        {
            return false;
        }
    };

    $USER->testUserId = $id;

    try {
        return $fn();
    } finally {
        $USER = $saved;
    }
};

/** Ловит исключение и возвращает его класс либо '' */
$expectDenied = static function (callable $fn): string {
    try {
        $fn();
    } catch (\Throwable $e) {
        return get_class($e);
    }

    return '';
};

// ------------------------------------------------------------------ [1]

echo "=== [1] миграция ===\n";

foreach (Manager::up() as $line) {
    echo '    ' . htmlspecialcharsbx($line) . "\n";
}

$roles = (array) \Bitrix\Main\Web\Json::decode(
    (string) Option::get('alex.autoservice', 'crm_roles', '{}')
);

$check(count($roles) === 4, 'создано четыре роли', 'в опции: ' . count($roles));

// ------------------------------------------------------------------ [2]

echo "\n=== [2] роли в CRM ===\n";

$existing = [];
$res      = \CCrmRole::GetList(['ID' => 'ASC'], []);

while ($row = $res->Fetch()) {
    $existing[(int) $row['ID']] = (string) $row['NAME'];
}

foreach ($roles as $code => $roleId) {
    $check(
        isset($existing[(int) $roleId]),
        'роль ' . $code . ' существует',
        isset($existing[(int) $roleId]) ? $existing[(int) $roleId] . ', ID = ' . $roleId : 'ID = ' . $roleId
    );
}

// ------------------------------------------------------------------ [3]

echo "\n=== [3] права против матрицы ТЗ ===\n";

$serviceCategory  = (int) Option::get('alex.autoservice', 'service_category_id', 0);
$purchaseType     = Purchase::getEntityTypeId();
$purchaseCategory = Purchase::getCategoryId();

$deal     = 'DEAL_C' . $serviceCategory;
$purchase = 'DYNAMIC_' . $purchaseType . '_C' . $purchaseCategory;

echo '    воронка: ' . $deal . ', заявки: ' . $purchase . "\n";

/**
 * Ожидания записаны по тексту ТЗ, а не взяты из кода миграции:
 * механик — только свои, менеджер — все сделки и клиенты,
 * закупщик — только заявки, директор — полный доступ.
 */
$expected = [
    'MECHANIC' => [
        'DEAL'    => ['READ' => 'A', 'ADD' => 'A', 'WRITE' => 'A', 'DELETE' => null],
        $deal     => ['READ' => 'A', 'ADD' => 'A', 'WRITE' => 'A', 'DELETE' => null],
        'CONTACT' => ['READ' => 'A', 'ADD' => 'A', 'WRITE' => 'A', 'DELETE' => null],
        $purchase => ['READ' => 'A', 'ADD' => 'A', 'WRITE' => 'A', 'DELETE' => null],
    ],
    'MANAGER' => [
        'DEAL'    => ['READ' => 'X', 'ADD' => 'X', 'WRITE' => 'X', 'DELETE' => null],
        $deal     => ['READ' => 'X', 'ADD' => 'X', 'WRITE' => 'X', 'DELETE' => null],
        'CONTACT' => ['READ' => 'X', 'ADD' => 'X', 'WRITE' => 'X', 'DELETE' => null],
        $purchase => ['READ' => 'A', 'ADD' => 'A', 'WRITE' => 'A', 'DELETE' => null],
    ],
    'BUYER' => [
        'DEAL'    => ['READ' => null, 'ADD' => null, 'WRITE' => null, 'DELETE' => null],
        $deal     => ['READ' => null, 'ADD' => null, 'WRITE' => null, 'DELETE' => null],
        'CONTACT' => ['READ' => null, 'ADD' => null, 'WRITE' => null, 'DELETE' => null],
        $purchase => ['READ' => 'X', 'ADD' => 'X', 'WRITE' => 'X', 'DELETE' => null],
    ],
    'DIRECTOR' => [
        'DEAL'    => ['READ' => 'X', 'ADD' => 'X', 'WRITE' => 'X', 'DELETE' => 'X'],
        $deal     => ['READ' => 'X', 'ADD' => 'X', 'WRITE' => 'X', 'DELETE' => 'X'],
        'CONTACT' => ['READ' => 'X', 'ADD' => 'X', 'WRITE' => 'X', 'DELETE' => 'X'],
        $purchase => ['READ' => 'X', 'ADD' => 'X', 'WRITE' => 'X', 'DELETE' => 'X'],
    ],
];

$connection = Application::getConnection();

foreach ($expected as $code => $entities) {
    $roleId = (int) ($roles[$code] ?? 0);

    if (!$roleId) {
        $check(false, 'права роли ' . $code, 'роль не создана');

        continue;
    }

    $actual = [];

    $rows = $connection->query(
        'SELECT ENTITY, PERM_TYPE, ATTR FROM b_crm_role_perms WHERE ROLE_ID = ' . $roleId
    )->fetchAll();

    foreach ($rows as $row) {
        $actual[$row['ENTITY']][$row['PERM_TYPE']] = (string) $row['ATTR'];
    }

    $errors = [];

    foreach ($entities as $entity => $operations) {
        foreach ($operations as $operation => $attr) {
            $has = $actual[$entity][$operation] ?? null;

            if ($attr === null) {
                if ($has !== null && $has !== '') {
                    $errors[] = $entity . '.' . $operation . ' = ' . $has . ' (ждали запрет)';
                }

                continue;
            }

            if ($has !== $attr) {
                $errors[] = $entity . '.' . $operation . ' = ' . var_export($has, true) . ' (ждали ' . $attr . ')';
            }
        }
    }

    $check(!$errors, 'права роли ' . $code, $errors ? implode('; ', $errors) : 'совпадают с ТЗ');
}

// ------------------------------------------------------------------ [4]

echo "\n=== [4] уровни доступа совпадают с CCrmPerms ===\n";

$check(Access::PERM_SELF === \CCrmPerms::PERM_SELF, 'уровень «свои»', Access::PERM_SELF);
$check(Access::PERM_ALL === \CCrmPerms::PERM_ALL, 'уровень «все»', Access::PERM_ALL);

// ------------------------------------------------------------------ [5]

echo "\n=== [5] привязка сотрудников ===\n";

$relations = [];

foreach ($connection->query('SELECT RELATION, ROLE_ID FROM b_crm_role_relation')->fetchAll() as $row) {
    $relations[(string) $row['RELATION']][] = (int) $row['ROLE_ID'];
}

$expectRelation = [
    Access::ROLE_MECHANIC   => 'MECHANIC',
    Access::ROLE_MANAGER    => 'MANAGER',
    Access::ROLE_BUYER      => 'BUYER',
    Access::ROLE_PURCH_HEAD => 'BUYER',
    Access::ROLE_DIRECTOR   => 'DIRECTOR',
];

foreach ($expectRelation as $position => $roleCode) {
    $roleId  = (int) ($roles[$roleCode] ?? 0);
    $users   = Access::getUsersByRole($position);
    $missing = [];

    foreach ($users as $userId) {
        if (!in_array($roleId, $relations['U' . $userId] ?? [], true)) {
            $missing[] = $userId;
        }
    }

    $check(
        $users && !$missing,
        $position . ' → роль ' . $roleCode,
        $missing ? 'без роли: ' . implode(', ', $missing) : 'сотрудники: ' . implode(', ', $users)
    );
}

$ourRoleIds  = array_map('intval', array_values($roles));
$accountants = Access::getUsersByRole(Access::ROLE_ACCOUNTANT);
$bound       = [];

foreach ($accountants as $userId) {
    if (array_intersect($ourRoleIds, $relations['U' . $userId] ?? [])) {
        $bound[] = $userId;
    }
}

$check(!$bound, 'бухгалтер без ролей проекта', $bound ? 'привязан: ' . implode(', ', $bound) : 'в ТЗ не упомянут');

$foreign = 0;

foreach ($relations as $code => $ids) {
    $foreign += count(array_diff($ids, $ourRoleIds));
}

$check($foreign > 0, 'чужие назначения ролей целы', 'строк вне проекта: ' . $foreign);

// ------------------------------------------------------------------ [6]

echo "\n=== [6] тестовые данные ===\n";

// Прогон мог оборваться до уборки — подчищаем хвосты прошлого запуска
foreach (['Т001ЕСТ777', 'Т002ЕСТ777', 'Т003ЕСТ777'] as $number) {
    $staleId = CarCrudService::findIdByNumber($number);

    if (!$staleId) {
        continue;
    }

    $stale = CarTable::getById($staleId)->fetch();

    CarTable::delete($staleId);

    if (!empty($stale['CONTACT_ID'])) {
        $cleaner = new \CCrmContact(false);
        $cleaner->Delete((int) $stale['CONTACT_ID']);
    }

    echo '    удалён хвост прошлого прогона: ' . $number . "\n";
}

$mechanic   = Access::getUsersByRole(Access::ROLE_MECHANIC)[0] ?? 0;
$manager    = Access::getUsersByRole(Access::ROLE_MANAGER)[0] ?? 0;
$buyer      = Access::getUsersByRole(Access::ROLE_BUYER)[0] ?? 0;
$director   = Access::getUsersByRole(Access::ROLE_DIRECTOR)[0] ?? 0;
$accountant = Access::getUsersByRole(Access::ROLE_ACCOUNTANT)[0] ?? 0;

echo '    механик ' . $mechanic . ', менеджер ' . $manager
    . ', закупщик ' . $buyer . ', директор ' . $director . ', бухгалтер ' . $accountant . "\n";

$contact = new \CCrmContact(false);

$ownFields = [
    'NAME'           => 'Тест',
    'LAST_NAME'      => 'Свой клиент',
    'ASSIGNED_BY_ID' => $mechanic,
    'OPENED'         => 'N',
];

$foreignFields = [
    'NAME'           => 'Тест',
    'LAST_NAME'      => 'Чужой клиент',
    'ASSIGNED_BY_ID' => $manager,
    'OPENED'         => 'N',
];

$ownContact     = (int) $contact->Add($ownFields, false, ['DISABLE_USER_FIELD_CHECK' => true]);
$foreignContact = (int) $contact->Add($foreignFields, false, ['DISABLE_USER_FIELD_CHECK' => true]);

$check($ownContact > 0 && $foreignContact > 0, 'контакты созданы', $ownContact . ' и ' . $foreignContact);

$ownCar = CarCrudService::create([
    'CONTACT_ID' => $ownContact,
    'BRAND'      => 'Lada',
    'MODEL'      => 'Vesta',
    'NUMBER'     => 'Т001ЕСТ777',
]);

$foreignCar = CarCrudService::create([
    'CONTACT_ID' => $foreignContact,
    'BRAND'      => 'Kia',
    'MODEL'      => 'Rio',
    'NUMBER'     => 'Т002ЕСТ777',
]);

$check(
    $ownCar['ID'] > 0 && $foreignCar['ID'] > 0,
    'автомобили созданы',
    $ownCar['ID'] . ' и ' . $foreignCar['ID']
);

// ------------------------------------------------------------------ [7]

echo "\n=== [7] чтение гаража ===\n";

$list = $asUser($director, static fn() => CarCrudService::read(['LIMIT' => 50]));
$check(count($list) >= 2, 'директор видит весь гараж', 'записей: ' . count($list));

$list = $asUser($manager, static fn() => CarCrudService::read(['LIMIT' => 50]));
$check(count($list) >= 2, 'менеджер видит весь гараж', 'записей: ' . count($list));

$list    = $asUser($mechanic, static fn() => CarCrudService::read(['LIMIT' => 50]));
$numbers = array_column($list, 'NUMBER');

$check(
    in_array('Т001ЕСТ777', $numbers, true) && !in_array('Т002ЕСТ777', $numbers, true),
    'механик видит только своих клиентов',
    'номера: ' . (implode(', ', $numbers) ?: '—')
);

$class = $expectDenied(static fn() => $asUser($buyer, static fn() => CarCrudService::read(['LIMIT' => 50])));
$check($class === AccessDeniedException::class, 'закупщику гараж закрыт', $class ?: 'исключения не было');

$class = $expectDenied(static fn() => $asUser($accountant, static fn() => CarCrudService::read(['LIMIT' => 50])));
$check($class === AccessDeniedException::class, 'бухгалтеру гараж закрыт', $class ?: 'исключения не было');

$own = $asUser($mechanic, static fn() => CarCrudService::read(['ID' => $ownCar['ID']]));
$check(($own['ID'] ?? 0) === $ownCar['ID'], 'механик читает свой автомобиль по ID');

// Чужая машина для механика не «запрещена», а не существует: условие по
// ответственному уходит в запрос, и наружу не утекает даже факт её наличия
$class = $expectDenied(static fn() => $asUser($mechanic, static fn() => CarCrudService::read(['ID' => $foreignCar['ID']])));
$check($class === \OutOfBoundsException::class, 'чужой автомобиль механику не виден', $class ?: 'исключения не было');

// ------------------------------------------------------------------ [8]

echo "\n=== [8] изменение и удаление ===\n";

$updated = $asUser($manager, static fn() => CarCrudService::update($ownCar['ID'], ['MILEAGE' => 120000]));
$check(($updated['ID'] ?? 0) === $ownCar['ID'], 'менеджер правит карточку автомобиля');

$class = $expectDenied(static fn() => $asUser($mechanic, static fn() => CarCrudService::update($ownCar['ID'], ['MILEAGE' => 1])));
$check($class === AccessDeniedException::class, 'механик карточку не правит', $class ?: 'исключения не было');

$class = $expectDenied(static fn() => $asUser($buyer, static fn() => CarCrudService::create([
    'CONTACT_ID' => $ownContact,
    'BRAND'      => 'Audi',
    'MODEL'      => 'A4',
    'NUMBER'     => 'Т003ЕСТ777',
])));
$check($class === AccessDeniedException::class, 'закупщик автомобиль не заводит', $class ?: 'исключения не было');

$class = $expectDenied(static fn() => $asUser($mechanic, static fn() => CarCrudService::delete($foreignCar['ID'])));
$check($class === AccessDeniedException::class, 'механик автомобиль не удаляет', $class ?: 'исключения не было');

$deleted = $asUser($manager, static fn() => CarCrudService::delete($foreignCar['ID']));
$check(($deleted['DELETED'] ?? false) === true, 'менеджер удаляет автомобиль');

// Машину менеджер уже убрал, поэтому директору заводим свою:
// проверяем обе операции, а не только удаление
$directorCar = $asUser($director, static fn() => CarCrudService::create([
    'CONTACT_ID' => $ownContact,
    'BRAND'      => 'Audi',
    'MODEL'      => 'A4',
    'NUMBER'     => 'Т003ЕСТ777',
]));

$check(($directorCar['ID'] ?? 0) > 0, 'директор заводит автомобиль', 'ID = ' . ($directorCar['ID'] ?? 0));

$deleted = $asUser($director, static fn() => CarCrudService::delete($directorCar['ID']));
$check(($deleted['DELETED'] ?? false) === true, 'директор удаляет автомобиль');

// ------------------------------------------------------------------ [9]

echo "\n=== [9] уборка ===\n";

CarTable::delete($ownCar['ID']);

$cleaner = new \CCrmContact(false);

foreach ([$ownContact, $foreignContact] as $contactId) {
    $cleaner->Delete($contactId);
}

echo "    тестовые контакты и автомобили удалены\n";

// ------------------------------------------------------------------ [10]

echo "\n=== [10] откат и повторное применение ===\n";

foreach (Manager::down('20261116090000') as $line) {
    echo '    ' . htmlspecialcharsbx($line) . "\n";
}

$afterDown = (string) Option::get('alex.autoservice', 'crm_roles', '');
$check($afterDown === '', 'опция с ролями снята');

$left = 0;

foreach ($connection->query('SELECT ID FROM b_crm_role WHERE ID IN (' . implode(',', $ourRoleIds ?: [0]) . ')')->fetchAll() as $row) {
    $left++;
}

$check($left === 0, 'роли проекта удалены', 'осталось: ' . $left);

$survived = 0;

foreach ($connection->query('SELECT ROLE_ID FROM b_crm_role_relation')->fetchAll() as $row) {
    if (!in_array((int) $row['ROLE_ID'], $ourRoleIds, true)) {
        $survived++;
    }
}

$check($survived === $foreign, 'чужие назначения пережили откат', $survived . ' из ' . $foreign);

foreach (Manager::up() as $line) {
    echo '    ' . htmlspecialcharsbx($line) . "\n";
}

$roles = (array) \Bitrix\Main\Web\Json::decode(
    (string) Option::get('alex.autoservice', 'crm_roles', '{}')
);

$check(count($roles) === 4, 'роли восстановлены повторным применением', 'ролей: ' . count($roles));

\CCrmRole::ClearCache();
