<?php
/**
 * Тест: Синхронизация остатков и автозакупка
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

check('поле UF_PURCHASE_PRODUCT создано',
    \Alex\Autoservice\Service\UserField::getId($ufEntity, 'UF_PURCHASE_PRODUCT') > 0);

$agent = \CAgent::GetList([], ['NAME' => \Alex\Autoservice\Agent\PartsAgent::NAME])->Fetch();

check('агент зарегистрирован', (bool) $agent,
    $agent ? 'ID = ' . $agent['ID'] . ', интервал ' . $agent['AGENT_INTERVAL'] . ' с' : '');

echo "\n=== [2] стадии заявки ===\n";

foreach (['NEW', 'APPROVAL', 'SUCCESS', 'FAIL'] as $code) {
    echo '  ' . str_pad($code, 10) . \Alex\Autoservice\Service\Purchase::stageId($code) . "\n";
}

check('идентификатор стадии с префиксом',
    mb_strpos(\Alex\Autoservice\Service\Purchase::stageId('SUCCESS'), ':') !== false);

echo "\n=== [3] автозакупка ===\n";

$parts = \Alex\Autoservice\Service\PartsCatalog::getParts();

check('запчасти в каталоге есть', count($parts) > 0, 'найдено: ' . count($parts));

if (!$parts) {
    echo "\n  нет запчастей в каталоге — разверните демо-данные\n";

    return;
}

$part = $parts[0];

\Alex\Autoservice\Service\PartsCatalog::setQuantity((int) $part['ID'], 0);
$part['QUANTITY'] = 0;

echo '  обнулили «' . $part['NAME'] . "»\n";

$auto = \Alex\Autoservice\Service\Purchase::createAuto($part);

check('автозаявка создана', $auto['ID'] > 0 && !$auto['SKIPPED'], 'ID = ' . $auto['ID']);
check('остаток стал 10',
    \Alex\Autoservice\Service\PartsCatalog::getQuantity((int) $part['ID']) === 10);

$factory = \Alex\Autoservice\Service\Purchase::getFactory();
$item    = $auto['ID'] ? $factory->getItem($auto['ID']) : null;

if ($item) {
    check('  стадия «Выполнено»',
        (string) $item->get('STAGE_ID') === \Alex\Autoservice\Service\Purchase::stageId('SUCCESS'),
        (string) $item->get('STAGE_ID'));
    check('  помечена автоматической', (int) $item->get('UF_PURCHASE_AUTO') === 1);
    check('  связана с запчастью', (int) $item->get('UF_PURCHASE_PRODUCT') === (int) $part['ID']);

    echo '  заголовок: ' . $item->getTitle() . "\n";

    $rows = \CCrmProductRow::LoadRows(
        \CCrmOwnerTypeAbbr::ResolveByTypeID($entityTypeId),
        $auto['ID']
    );

    echo '  товарных позиций: ' . count((array) $rows) . "\n";

    foreach ((array) $rows as $row) {
        echo '    ' . $row['PRODUCT_NAME'] . ' × ' . $row['QUANTITY'] . "\n";
    }
}

echo "\n=== [4] защита от повторов ===\n";

\Alex\Autoservice\Service\PartsCatalog::setQuantity((int) $part['ID'], 0);
$part['QUANTITY'] = 0;

$again = \Alex\Autoservice\Service\Purchase::createAuto($part);

check('повторная автозаявка не создаётся', $again['SKIPPED'] === true);

echo "\n=== [5] устойчивость к недоступному сервису ===\n";

$before = array_column(\Alex\Autoservice\Service\PartsCatalog::getParts(), 'QUANTITY', 'ID');

\Bitrix\Main\Config\Option::set('alex.autoservice',
    \Alex\Autoservice\Service\PartsSync::OPTION_URL,
    'https://localhost:9/none?num=#COUNT#');

report(\Alex\Autoservice\Service\PartsSync::run());

$after = array_column(\Alex\Autoservice\Service\PartsCatalog::getParts(), 'QUANTITY', 'ID');

check('остатки не тронуты при сбое сервиса', $before === $after,
    'это защита от обнуления склада сетевой ошибкой');

\Bitrix\Main\Config\Option::delete('alex.autoservice',
    ['name' => \Alex\Autoservice\Service\PartsSync::OPTION_URL]);

echo "\n=== [6] синхронизация с настоящим сервисом ===\n";

report(\Alex\Autoservice\Service\PartsSync::run());

$synced = \Alex\Autoservice\Service\PartsCatalog::getParts();

check('остатки обновились', array_column($synced, 'QUANTITY', 'ID') !== $before,
    'значения случайные, совпадение маловероятно');
check('ни один остаток не отрицательный',
    !array_filter($synced, static fn (array $p): bool => $p['QUANTITY'] < 0));

echo "\n=== [7] откат ===\n";

$journalBefore = count(\Alex\Autoservice\Migration\Manager::getApplied());

// Manager::down() без аргументов откатывает последнюю применённую
// миграцию, а последняя давно не эта. Зовём свою по версии: журнал
// остаётся нетронутым, а следующий блок возвращает всё на место
$migration = \Alex\Autoservice\Migration\Manager::get('20261114090000');

$migration->down();

report($migration->getReport());

check('агент снят',
    !\CAgent::GetList([], ['NAME' => \Alex\Autoservice\Agent\PartsAgent::NAME])->Fetch());
check('поле снято',
    \Alex\Autoservice\Service\UserField::getId($ufEntity, 'UF_PURCHASE_PRODUCT') === 0);

echo "\n=== [8] повторный накат ===\n";

$migration = \Alex\Autoservice\Migration\Manager::get('20261114090000');

$migration->up();

report($migration->getReport());

check('агент создан заново',
    (bool) \CAgent::GetList([], ['NAME' => \Alex\Autoservice\Agent\PartsAgent::NAME])->Fetch());
