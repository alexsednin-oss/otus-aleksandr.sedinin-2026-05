<?php
/**
 * Тест: Смарт-процесс «Заявка на закупку»
 *
 * Запускается через tools/tests/run.php или страницу администрирования.
 * Счётчики и вывод берутся из bootstrap.php: check() и report().
 */

CModule::IncludeModule('crm');
CModule::IncludeModule('alex.autoservice');

echo "=== [1] применение миграции ===\n";

report(\Alex\Autoservice\Migration\Manager::up());

$entityTypeId = (int) \Bitrix\Main\Config\Option::get('alex.autoservice', 'purchase_type_id', 0);
$categoryId   = (int) \Bitrix\Main\Config\Option::get('alex.autoservice', 'purchase_category_id', 0);

check('ENTITY_TYPE_ID в опции', $entityTypeId > 0, 'ID = ' . $entityTypeId);
check('категория в опции', $categoryId > 0, 'ID = ' . $categoryId);

if (!$entityTypeId) {
        return;
}

echo "\n=== [2] тип ===\n";

$type = \Bitrix\Crm\Model\Dynamic\TypeTable::query()
    ->setSelect(['*'])
    ->where('CODE', 'AUTOSERVICE_PURCHASE')
    ->setLimit(1)
    ->fetch();

check('тип найден по коду', (bool) $type);

if ($type) {
    echo '  TITLE = ' . $type['TITLE'] . ', TABLE = ' . $type['TABLE_NAME'] . "\n";

    check('инициализирован', !empty($type['IS_INITIALIZED']));
    check('стадии включены', !empty($type['IS_STAGES_ENABLED']));
    check('товарные позиции включены', !empty($type['IS_LINK_WITH_PRODUCTS_ENABLED']),
        'без них в заявке не будет списка запчастей');
    check('автоматизация и БП включены',
        !empty($type['IS_AUTOMATION_ENABLED']) && !empty($type['IS_BIZ_PROC_ENABLED']),
        'нужны для согласования');
}

echo "\n=== [3] стадии ===\n";

$factory        = \Alex\Autoservice\Service\SmartProcess::getFactory($entityTypeId);
$stagesEntityId = $factory->getStagesEntityId($categoryId);

echo '  набор стадий: ' . $stagesEntityId . "\n\n";

$existing = \Alex\Autoservice\Service\Stages::getExisting($stagesEntityId);

foreach ($existing as $statusId => $row) {
    echo '  ' . str_pad($statusId, 26) . str_pad((string) $row['SORT'], 6)
        . str_pad((string) $row['NAME'], 16)
        . 'SYSTEM=' . ($row['SYSTEM'] ?? '—') . '  SEM=' . ($row['SEMANTICS'] ?? '—') . "\n";
}

$prefix = '';

foreach (array_keys($existing) as $statusId) {
    $position = mb_strpos($statusId, ':');

    if ($position !== false) {
        $prefix = mb_substr($statusId, 0, $position + 1);
        break;
    }
}

echo "\n  префикс: " . ($prefix !== '' ? $prefix : '—') . "\n";

foreach (['NEW' => 'Новая', 'APPROVAL' => 'На одобрении', 'SUCCESS' => 'Выполнено', 'FAIL' => 'Отклонено'] as $code => $name) {
    check('  стадия ' . str_pad($code, 9),
        isset($existing[$prefix . $code]) && $existing[$prefix . $code]['NAME'] === $name,
        $existing[$prefix . $code]['NAME'] ?? 'нет');
}

echo "\n=== [4] поля ===\n";

$userFieldEntityId = $factory->getUserFieldEntityId();

echo '  сущность полей: ' . $userFieldEntityId . "\n";

foreach (['UF_PURCHASE_INITIATOR', 'UF_PURCHASE_REASON', 'UF_PURCHASE_AUTO'] as $fieldName) {
    $id = \Alex\Autoservice\Service\UserField::getId($userFieldEntityId, $fieldName);

    check('  поле ' . str_pad($fieldName, 24), $id > 0, 'ID = ' . $id);
}

echo "\n=== [5] создание заявки через API ===\n";

$item = $factory->createItem();
$item->setTitle('Тестовая заявка ' . date('d.m.Y H:i:s'));
$item->set('STAGE_ID', $prefix . 'NEW');
$item->set('UF_PURCHASE_AUTO', 0);

$result = $factory->getAddOperation($item)->launch();

check('заявка создана', $result->isSuccess(),
    $result->isSuccess() ? 'ID = ' . $item->getId() : implode('; ', $result->getErrorMessages()));

if ($result->isSuccess()) {
    $loaded = $factory->getItem($item->getId());

    check('  стадия сохранилась', $loaded && (string) $loaded->get('STAGE_ID') === $prefix . 'NEW',
        $loaded ? (string) $loaded->get('STAGE_ID') : '');

    $deleteResult = $factory->getDeleteOperation($loaded)->launch();

    check('  тестовая заявка удалена', $deleteResult->isSuccess());
}

echo "\n=== [6] откат ===\n";

$purchaseType = \Alex\Autoservice\Service\Purchase::getEntityTypeId();
$liveItems    = 0;

if ($purchaseType) {
    foreach (\Alex\Autoservice\Service\SmartProcess::getFactory($purchaseType)
        ->getItems(['select' => ['ID']]) as $ignored) {
        $liveItems++;
    }
}

if ($liveItems > 0) {
    echo '  в процессе ' . $liveItems . " заявок — тип не удалить, цикл отката пропущен\n";

    return;
}

$journalBefore = count(\Alex\Autoservice\Migration\Manager::getApplied());

// Manager::down() без аргументов откатывает последнюю применённую
// миграцию, а последняя давно не эта. Зовём свою по версии: журнал
// остаётся нетронутым, а следующий блок возвращает всё на место
$migration = \Alex\Autoservice\Migration\Manager::get('20261112090000');

$migration->down();

report($migration->getReport());

check('тип удалён', \Alex\Autoservice\Service\SmartProcess::findByCode('AUTOSERVICE_PURCHASE') === 0,
    'главная проверка: раньше удаление падало с исключением');
check('опции очищены',
    \Bitrix\Main\Config\Option::get('alex.autoservice', 'purchase_type_id', '') === ''
    && \Bitrix\Main\Config\Option::get('alex.autoservice', 'purchase_category_id', '') === '');
check('журнал не тронут',
    count(\Alex\Autoservice\Migration\Manager::getApplied()) === $journalBefore,
    'записей: ' . $journalBefore);

echo "\n=== [7] повторный накат ===\n";

$migration = \Alex\Autoservice\Migration\Manager::get('20261112090000');

$migration->up();

report($migration->getReport());

check('тип создан заново',
    \Alex\Autoservice\Service\SmartProcess::findByCode('AUTOSERVICE_PURCHASE') > 0);
