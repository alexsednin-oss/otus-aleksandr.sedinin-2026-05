<?php
/**
 * Тест: Согласование ручных заявок и эскалация
 *
 * Запускается через tools/tests/run.php или страницу администрирования.
 * Счётчики и вывод берутся из bootstrap.php: check() и report().
 */

CModule::IncludeModule('crm');
CModule::IncludeModule('catalog');
CModule::IncludeModule('alex.autoservice');

echo "=== [1] миграция ===\n";

report(\Alex\Autoservice\Migration\Manager::up());

$entityTypeId = \Alex\Autoservice\Service\Purchase::getEntityTypeId();
$ufEntity     = \Alex\Autoservice\Service\SmartProcess::getUserFieldEntityId($entityTypeId);

foreach (['UF_PURCHASE_APPROVER', 'UF_PURCHASE_APPROVAL_AT', 'UF_PURCHASE_ESCALATED', 'UF_PURCHASE_PROCESSED'] as $field) {
    check('поле ' . str_pad($field, 26),
        \Alex\Autoservice\Service\UserField::getId($ufEntity, $field) > 0);
}

check('агент согласования зарегистрирован',
    (bool) \CAgent::GetList([], ['NAME' => \Alex\Autoservice\Agent\PurchaseAgent::NAME])->Fetch());

echo "\n=== [2] уборка прошлых прогонов ===\n";

$factory = \Alex\Autoservice\Service\Purchase::getFactory();
$removed = 0;

foreach ($factory->getItems(['select' => ['ID']]) as $leftover) {
    $operation = $factory->getDeleteOperation($leftover);

    foreach (['disableRecycleBin', 'disableBizProc', 'disableCheckAccess'] as $method) {
        if (method_exists($operation, $method)) {
            $operation->$method();
        }
    }

    if ($operation->launch()->isSuccess()) {
        $removed++;
    }
}

echo '  удалено заявок: ' . $removed . "\n";

echo "\n=== [3] ручная заявка ===\n";

$parts = \Alex\Autoservice\Service\PartsCatalog::getParts();
$part  = $parts[0];
$users = (array) \Bitrix\Main\Web\Json::decode(
    (string) \Bitrix\Main\Config\Option::get('alex.autoservice', 'structure_users', '{}')
);

$initiator = (int) ($users['MECHANIC'][0] ?? 1);
$buyers    = array_map('intval', (array) ($users['BUYER'] ?? []));
$head      = (int) ($users['PURCH_HEAD'][0] ?? 0);

echo '  инициатор: ' . $initiator . ', закупщики: ' . implode(', ', $buyers) . ', начальник: ' . $head . "\n";

$requestId = \Alex\Autoservice\Service\Purchase::createManual(
    [['ID' => $part['ID'], 'NAME' => $part['NAME'], 'PRICE' => $part['PRICE'], 'QUANTITY' => 4]],
    $initiator,
    'Тестовая заявка ' . date('H:i:s')
);

check('заявка создана', $requestId > 0, 'ID = ' . $requestId);

$item = $factory->getItem($requestId);

check('  стадия «Новая»',
    (string) $item->get('STAGE_ID') === \Alex\Autoservice\Service\Purchase::stageId('NEW'));
check('  инициатор записан', (int) $item->get('UF_PURCHASE_INITIATOR') === $initiator);

echo "\n=== [4] назначение согласующего ===\n";

report(\Alex\Autoservice\Service\PurchaseFlow::process());

$item = $factory->getItem($requestId);

check('стадия «На одобрении»',
    (string) $item->get('STAGE_ID') === \Alex\Autoservice\Service\Purchase::stageId('APPROVAL'),
    (string) $item->get('STAGE_ID'));
check('назначен закупщик', in_array((int) $item->get('UF_PURCHASE_APPROVER'), $buyers, true),
    'назначен ' . $item->get('UF_PURCHASE_APPROVER'));
check('отметка времени проставлена', !empty($item->get('UF_PURCHASE_APPROVAL_AT')));

echo "\n=== [5] эскалация по таймауту ===\n";

// Отматываем время постановки на согласование за пределы срока
$timeout = \Alex\Autoservice\Service\PurchaseFlow::getTimeout();

$item->set('UF_PURCHASE_APPROVAL_AT',
    (new \Bitrix\Main\Type\DateTime())->add('-' . ($timeout + 5) . ' minutes'));

$factory->getUpdateOperation($item)->launch();

echo '  срок согласования: ' . $timeout . " мин., время отмотано назад\n";

report(\Alex\Autoservice\Service\PurchaseFlow::process());

$item = $factory->getItem($requestId);

check('согласующий сменился на начальника',
    (int) $item->get('UF_PURCHASE_APPROVER') === $head,
    'стало ' . $item->get('UF_PURCHASE_APPROVER'));
check('отметка эскалации', (int) $item->get('UF_PURCHASE_ESCALATED') === 1);

report(\Alex\Autoservice\Service\PurchaseFlow::process());

$item = $factory->getItem($requestId);

check('повторная эскалация не происходит', (int) $item->get('UF_PURCHASE_ESCALATED') === 1);

echo "\n=== [6] отказ без причины ===\n";

$item->set('STAGE_ID', \Alex\Autoservice\Service\Purchase::stageId('FAIL'));
$factory->getUpdateOperation($item)->launch();

report(\Alex\Autoservice\Service\PurchaseFlow::process());

$item = $factory->getItem($requestId);

check('заявка не считается обработанной', (int) $item->get('UF_PURCHASE_PROCESSED') !== 1,
    'ждём заполнения причины');

echo "\n=== [7] отказ с причиной ===\n";

$item->set('UF_PURCHASE_REASON', 'Закупка отложена до следующего квартала');
$factory->getUpdateOperation($item)->launch();

$quantityBefore = \Alex\Autoservice\Service\PartsCatalog::getQuantity((int) $part['ID']);

report(\Alex\Autoservice\Service\PurchaseFlow::process());

$item = $factory->getItem($requestId);

check('заявка обработана', (int) $item->get('UF_PURCHASE_PROCESSED') === 1);
check('остаток не изменился',
    \Alex\Autoservice\Service\PartsCatalog::getQuantity((int) $part['ID']) === $quantityBefore);

echo "\n=== [8] одобрение ===\n";

$secondId = \Alex\Autoservice\Service\Purchase::createManual(
    [['ID' => $part['ID'], 'NAME' => $part['NAME'], 'PRICE' => $part['PRICE'], 'QUANTITY' => 7]],
    $initiator,
    'Тестовая заявка на одобрение ' . date('H:i:s')
);

\Alex\Autoservice\Service\PurchaseFlow::process();

$second = $factory->getItem($secondId);
$second->set('STAGE_ID', \Alex\Autoservice\Service\Purchase::stageId('SUCCESS'));
$factory->getUpdateOperation($second)->launch();

$before = \Alex\Autoservice\Service\PartsCatalog::getQuantity((int) $part['ID']);

report(\Alex\Autoservice\Service\PurchaseFlow::process());

$after = \Alex\Autoservice\Service\PartsCatalog::getQuantity((int) $part['ID']);

check('остаток пополнен на 7', $after === $before + 7, $before . ' → ' . $after);
check('заявка помечена обработанной',
    (int) $factory->getItem($secondId)->get('UF_PURCHASE_PROCESSED') === 1);

echo "\n=== [9] повторный проход ничего не меняет ===\n";

report(\Alex\Autoservice\Service\PurchaseFlow::process());

check('остаток не пополнился повторно',
    \Alex\Autoservice\Service\PartsCatalog::getQuantity((int) $part['ID']) === $after,
    'идемпотентность обработки');

echo "\n=== [10] откат ===\n";

$journalBefore = count(\Alex\Autoservice\Migration\Manager::getApplied());

// Manager::down() без аргументов откатывает последнюю применённую
// миграцию, а последняя давно не эта. Зовём свою по версии: журнал
// остаётся нетронутым, а следующий блок возвращает всё на место
$migration = \Alex\Autoservice\Migration\Manager::get('20261115090000');

$migration->down();

report($migration->getReport());

check('агент снят',
    !\CAgent::GetList([], ['NAME' => \Alex\Autoservice\Agent\PurchaseAgent::NAME])->Fetch());
check('поля сняты',
    \Alex\Autoservice\Service\UserField::getId($ufEntity, 'UF_PURCHASE_APPROVER') === 0);

$migration = \Alex\Autoservice\Migration\Manager::get('20261115090000');

$migration->up();

report($migration->getReport());
