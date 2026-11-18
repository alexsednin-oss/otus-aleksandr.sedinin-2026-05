<?php
/**
 * Тест: Сквозной сценарий сервисного центра всеми ролями
 *
 * Проходит карту процесса от записи клиента до закрытия заказ-наряда:
 * менеджер заводит автомобиль, механик работает по наряду, запчасть
 * заканчивается, закупщик согласует заявку, история попадает в гараж.
 *
 * Запускается через tools/tests/run.php или страницу администрирования.
 * Счётчики и вывод берутся из bootstrap.php: check() и report().
 */

CModule::IncludeModule('crm');
CModule::IncludeModule('catalog');
CModule::IncludeModule('alex.autoservice');

use Alex\Autoservice\Service\Access;
use Alex\Autoservice\Service\CarCrudService;
use Alex\Autoservice\Service\Garage;
use Alex\Autoservice\Service\PartsCatalog;
use Alex\Autoservice\Service\Purchase;
use Alex\Autoservice\Service\PurchaseFlow;
use Bitrix\Main\Config\Option;

/** Выполняет замыкание от лица указанного сотрудника */
$asUser = static function (int $id, callable $fn) {
    global $USER;

    $saved = $USER;

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

$suffix     = date('dHis');
$categoryId = (int) Option::get('alex.autoservice', 'service_category_id', 0);
$stage      = static fn (string $code): string => 'C' . $categoryId . ':' . $code;

echo "=== [1] роли и окружение ===\n";

$director = Access::getUsersByRole(Access::ROLE_DIRECTOR)[0] ?? 0;
$manager  = Access::getUsersByRole(Access::ROLE_MANAGER)[0] ?? 0;
$mechanic = Access::getUsersByRole(Access::ROLE_MECHANIC)[0] ?? 0;
$buyer    = Access::getUsersByRole(Access::ROLE_BUYER)[0] ?? 0;
$head     = Access::getUsersByRole(Access::ROLE_PURCH_HEAD)[0] ?? 0;

check('воронка настроена', $categoryId > 0, 'CATEGORY_ID = ' . $categoryId);
check('роли заполнены', $director && $manager && $mechanic && $buyer && $head,
    'директор ' . $director . ', менеджер ' . $manager . ', механик ' . $mechanic
    . ', закупщик ' . $buyer . ', начальник ' . $head);

$parts = PartsCatalog::getParts();

check('каталог запчастей наполнен', count($parts) >= 2, 'позиций: ' . count($parts));

if (!$categoryId || !$mechanic || count($parts) < 2) {
    echo "  окружение не готово, сценарий пропущен\n";

    return;
}

echo "\n=== [2] клиент записывается ===\n";

$contact = new \CCrmContact(false);

$contactFields = [
    'NAME'           => 'Пётр',
    'LAST_NAME'      => 'Путешествие ' . $suffix,
    'ASSIGNED_BY_ID' => $mechanic,
    'OPENED'         => 'N',
];

$contactId = (int) $contact->Add($contactFields, true,
    ['DISABLE_USER_FIELD_CHECK' => true, 'REGISTER_SONET_EVENT' => false]);

check('менеджер завёл клиента', $contactId > 0, 'ID = ' . $contactId);

echo "\n=== [3] менеджер заводит автомобиль ===\n";

$number = 'С' . substr($suffix, -3) . 'МР777';

$car = $asUser($manager, static fn() => CarCrudService::create([
    'CONTACT_ID' => $contactId,
    'BRAND'      => 'Toyota',
    'MODEL'      => 'Camry',
    'NUMBER'     => $number,
    'YEAR'       => 2021,
    'COLOR'      => 'Синий',
    'MILEAGE'    => 42000,
]));

check('автомобиль в гараже', ($car['ID'] ?? 0) > 0, 'ID = ' . ($car['ID'] ?? 0));

$stored = $asUser($manager, static fn() => CarCrudService::read(['ID' => $car['ID']]));

check('  марка разрешена в справочник', (int) ($stored['BRAND_ID'] ?? 0) > 0);
check('  модель разрешена в справочник', (int) ($stored['MODEL_ID'] ?? 0) > 0);

$mechanicSees = $asUser($mechanic, static fn() => CarCrudService::getByContact($contactId));

check('механик видит машину своего клиента', count($mechanicSees) === 1);

$class = $expectDenied(static fn() => $asUser($buyer, static fn() => CarCrudService::getByContact($contactId)));

check('закупщику гараж закрыт', $class !== '', $class ?: 'исключения не было');

echo "\n=== [4] менеджер открывает заказ-наряд ===\n";

$deal = new \CCrmDeal(false);

$dealFields = [
    'TITLE'          => 'Заказ-наряд ' . $suffix,
    'CATEGORY_ID'    => $categoryId,
    'STAGE_ID'       => $stage('NEW'),
    'CONTACT_ID'     => $contactId,
    'ASSIGNED_BY_ID' => $mechanic,
    'OPPORTUNITY'    => 0,
    'CURRENCY_ID'    => \CCrmCurrency::GetBaseCurrencyID(),
    'OPENED'         => 'N',
    CarCrudService::DEAL_CAR_FIELD => $car['ID'],
];

$dealId = (int) $deal->Add($dealFields, true,
    ['DISABLE_USER_FIELD_CHECK' => true, 'REGISTER_SONET_EVENT' => false]);

check('наряд создан', $dealId > 0, 'ID = ' . $dealId);
check('  автомобиль привязан', (int) ($dealFields[CarCrudService::DEAL_CAR_FIELD] ?? 0) === $car['ID']);

echo "\n=== [5] второй наряд по тому же автомобилю ===\n";

$secondFields = $dealFields;

$secondFields['TITLE'] = 'Повторный наряд ' . $suffix;

unset($secondFields['ID']);

$secondId = (int) $deal->Add($secondFields, true,
    ['DISABLE_USER_FIELD_CHECK' => true, 'REGISTER_SONET_EVENT' => false]);

global $APPLICATION;

$guardMessage = is_object($APPLICATION) && $APPLICATION->GetException()
    ? $APPLICATION->GetException()->GetString()
    : '';

check('повторный наряд запрещён', $secondId === 0, $guardMessage ?: 'сообщение обработчика пустое');

echo "\n=== [6] механик работает по наряду ===\n";

$perms = \CCrmPerms::GetUserPermissions($mechanic);

check('механик видит свой наряд', \CCrmDeal::CheckReadPermission($dealId, $perms));

$buyerPerms = \CCrmPerms::GetUserPermissions($buyer);

check('закупщик наряда не видит', !\CCrmDeal::CheckReadPermission($dealId, $buyerPerms));

foreach (['DIAGNOSTICS', 'WAITING_PARTS'] as $code) {
    $move = ['STAGE_ID' => $stage($code)];

    $deal->Update($dealId, $move, true, true, ['DISABLE_USER_FIELD_CHECK' => true]);
}

$current = \CCrmDeal::GetByID($dealId, false);

check('наряд дошёл до ожидания запчастей', $current['STAGE_ID'] === $stage('WAITING_PARTS'),
    (string) $current['STAGE_ID']);

echo "\n=== [7] запчасть закончилась ===\n";

// Автозакупка защищена от повторов: по запчасти, на которую заявку уже
// создавали в последние часы, она сознательно ничего не делает. Для
// сценария берём позицию, по которой защита не сработает
$part    = null;
$before  = 0;
$autoId  = 0;
$factory = Purchase::getFactory();

foreach ($parts as $candidate) {
    if (!Purchase::hasRecentAuto((int) $candidate['ID'])) {
        $part = $candidate;

        break;
    }
}

if ($part === null) {
    echo "  по всем запчастям уже есть свежие автозаявки — блок пропущен\n";
    echo "  (защиту от повторов проверяет тест 08)\n";
} else {
    $before = PartsCatalog::getQuantity((int) $part['ID']);

    PartsCatalog::setQuantity((int) $part['ID'], 0);

    // createAuto() отдаёт отчёт об операции, а не идентификатор
    $auto   = Purchase::createAuto($part);
    $autoId = (int) ($auto['ID'] ?? 0);

    $autoItem = $autoId ? $factory->getItem($autoId) : null;

    check('автозаявка создана', $autoId > 0, 'ID = ' . $autoId . ', запчасть: ' . $part['NAME']);
    check('  сразу выполнена',
        $autoItem && $autoItem->get('STAGE_ID') === Purchase::stageId('SUCCESS'),
        $autoItem ? (string) $autoItem->get('STAGE_ID') : 'элемент не получен');
    check('  остаток пополнен до 10', PartsCatalog::getQuantity((int) $part['ID']) === 10,
        'остаток: ' . PartsCatalog::getQuantity((int) $part['ID']));
}

echo "\n=== [8] механик подаёт ручную заявку ===\n";

// Берём позицию с другого конца списка, чтобы не пересечься с той,
// на которой проверялась автозакупка
$wanted = $parts[count($parts) - 1];
$stock  = PartsCatalog::getQuantity((int) $wanted['ID']);

$requestId = $asUser($mechanic, static fn() => Purchase::createManual([
    [
        'ID'       => $wanted['ID'],
        'NAME'     => $wanted['NAME'],
        'PRICE'    => $wanted['PRICE'],
        'QUANTITY' => 3,
    ],
], $mechanic, 'Заявка механика ' . $suffix));

check('заявка подана', $requestId > 0, 'ID = ' . $requestId);

$item = $factory->getItem($requestId);

check('  стадия «Новая»', $item->get('STAGE_ID') === Purchase::stageId('NEW'));
check('  инициатор записан', (int) $item->get('UF_PURCHASE_INITIATOR') === $mechanic);

echo "\n=== [9] согласование ===\n";

report(PurchaseFlow::process());

$item     = $factory->getItem($requestId);
$approver = (int) $item->get('UF_PURCHASE_APPROVER');

check('заявка ушла на одобрение', $item->get('STAGE_ID') === Purchase::stageId('APPROVAL'),
    (string) $item->get('STAGE_ID'));
check('  назначен закупщик', in_array($approver, Access::getUsersByRole(Access::ROLE_BUYER), true),
    'согласующий: ' . $approver);

echo "\n=== [10] закупщик молчит, срабатывает эскалация ===\n";

$timeout = PurchaseFlow::getTimeout();

$item = $factory->getItem($requestId);

$item->set('UF_PURCHASE_APPROVAL_AT',
    \Bitrix\Main\Type\DateTime::createFromTimestamp(time() - ($timeout + 10) * 60));

$operation = $factory->getUpdateOperation($item);

if (method_exists($operation, 'disableCheckAccess')) {
    $operation->disableCheckAccess();
}

$operation->launch();

report(PurchaseFlow::process());

$item = $factory->getItem($requestId);

check('заявка эскалирована на начальника', (int) $item->get('UF_PURCHASE_APPROVER') === $head,
    'согласующий: ' . $item->get('UF_PURCHASE_APPROVER'));
check('  отметка эскалации', (bool) $item->get('UF_PURCHASE_ESCALATED'));

echo "\n=== [11] начальник одобряет ===\n";

$item = $factory->getItem($requestId);

$item->set('STAGE_ID', Purchase::stageId('SUCCESS'));

$operation = $factory->getUpdateOperation($item);

if (method_exists($operation, 'disableCheckAccess')) {
    $operation->disableCheckAccess();
}

$operation->launch();

report(PurchaseFlow::process());

check('остаток пополнен на заявленное',
    PartsCatalog::getQuantity((int) $wanted['ID']) === $stock + 3,
    'было ' . $stock . ', стало ' . PartsCatalog::getQuantity((int) $wanted['ID']));

$item = $factory->getItem($requestId);

check('  заявка помечена обработанной', (bool) $item->get('UF_PURCHASE_PROCESSED'));

echo "\n=== [12] ремонт и закрытие ===\n";

foreach (['REPAIR', 'CHECK'] as $code) {
    $move = ['STAGE_ID' => $stage($code)];

    $deal->Update($dealId, $move, true, true, ['DISABLE_USER_FIELD_CHECK' => true]);
}

$close = [
    'STAGE_ID'    => $stage('WON'),
    'OPPORTUNITY' => 8600,
];

$deal->Update($dealId, $close, true, true, ['DISABLE_USER_FIELD_CHECK' => true]);

$current = \CCrmDeal::GetByID($dealId, false);

check('наряд закрыт', $current['STAGE_ID'] === $stage('WON'), (string) $current['STAGE_ID']);

echo "\n=== [13] история в гараже ===\n";

$history = Garage::getHistory($car['ID']);

check('история содержит наряд', count($history) === 1, 'записей: ' . count($history));

if ($history) {
    $record = $history[0];

    check('  стадия названием', $record['STAGE_NAME'] !== $record['STAGE_ID'], (string) $record['STAGE_NAME']);
    check('  сумма заполнена', !empty($record['SUM']), (string) $record['SUM']);
    check('  ответственный со ссылкой',
        mb_strpos((string) $record['ASSIGNED_URL'], '/company/personal/user/') === 0);
}

check('заголовок попапа по формату ТЗ',
    (bool) preg_match('/^.+ - .+ \(.+\)$/u', Garage::getCaption($stored)),
    Garage::getCaption($stored));

echo "\n=== [14] новый наряд после закрытия ===\n";

$againFields = $dealFields;

$againFields['TITLE']    = 'Новое обращение ' . $suffix;
$againFields['STAGE_ID'] = $stage('NEW');

unset($againFields['ID']);

$againId = (int) $deal->Add($againFields, true,
    ['DISABLE_USER_FIELD_CHECK' => true, 'REGISTER_SONET_EVENT' => false]);

check('по закрытому автомобилю наряд открывается', $againId > 0, 'ID = ' . $againId);

echo "\n=== [15] уборка ===\n";

foreach ([$dealId, $againId] as $id) {
    if ($id) {
        $deal->Delete($id);
    }
}

foreach ([$autoId, $requestId] as $id) {
    if (!$id) {
        continue;
    }

    $operation = $factory->getDeleteOperation($factory->getItem($id));

    foreach (['disableRecycleBin', 'disableBizProc', 'disableCheckAccess'] as $method) {
        if (method_exists($operation, $method)) {
            $operation->$method();
        }
    }

    $operation->launch();
}

\Alex\Autoservice\Model\CarTable::delete($car['ID']);

$contact->Delete($contactId);

if ($part !== null) {
    PartsCatalog::setQuantity((int) $part['ID'], $before);
}

PartsCatalog::setQuantity((int) $wanted['ID'], $stock);

echo "    сделки, заявки, автомобиль и клиент удалены, остатки возвращены\n";
