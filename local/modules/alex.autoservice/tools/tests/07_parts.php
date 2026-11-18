<?php
/**
 * Тест: Каталог запчастей
 *
 * Запускается через tools/tests/run.php или страницу администрирования.
 * Счётчики и вывод берутся из bootstrap.php: check() и report().
 */

CModule::IncludeModule('crm');
CModule::IncludeModule('iblock');
CModule::IncludeModule('catalog');
CModule::IncludeModule('alex.autoservice');

echo "=== [1] миграция ===\n";

report(\Alex\Autoservice\Migration\Manager::up());

$sectionId = \Alex\Autoservice\Service\PartsCatalog::getSectionId();
$catalogId = \Alex\Autoservice\Service\PartsCatalog::getCatalogId();

check('каталог найден', $catalogId > 0, 'IBLOCK_ID = ' . $catalogId);
check('раздел «Запчасти» создан', $sectionId > 0, 'ID = ' . $sectionId);

echo "\n=== [2] номенклатура ===\n";

foreach (\Alex\Autoservice\Service\DemoData::seed() as $line) {
    if (mb_strpos($line, 'апчаст') !== false) {
        echo '    ' . htmlspecialcharsbx($line) . "\n";
    }
}

$parts = \Alex\Autoservice\Service\PartsCatalog::getParts();

check('запчасти созданы', count($parts) >= 10, 'найдено: ' . count($parts));

foreach ($parts as $part) {
    echo '  ' . str_pad((string) $part['ID'], 6)
        . str_pad($part['NAME'], 32)
        . 'остаток ' . str_pad((string) $part['QUANTITY'], 5)
        . 'цена ' . number_format($part['PRICE'], 0, ',', ' ') . "\n";
}

check('у всех есть цена', !array_filter($parts, static fn (array $p): bool => $p['PRICE'] <= 0));
check('у всех заполнен XML_ID', !array_filter($parts, static fn (array $p): bool => $p['XML_ID'] === ''));

echo "\n=== [3] остатки ===\n";

$sample = $parts[0] ?? null;

if ($sample) {
    $before = \Alex\Autoservice\Service\PartsCatalog::getQuantity($sample['ID']);

    check('остаток читается', $before === $sample['QUANTITY'], 'было ' . $before);

    \Alex\Autoservice\Service\PartsCatalog::setQuantity($sample['ID'], 0);

    check('остаток обнуляется',
        \Alex\Autoservice\Service\PartsCatalog::getQuantity($sample['ID']) === 0);

    \Alex\Autoservice\Service\PartsCatalog::setQuantity($sample['ID'], 10);

    check('остаток устанавливается в 10',
        \Alex\Autoservice\Service\PartsCatalog::getQuantity($sample['ID']) === 10);

    \Alex\Autoservice\Service\PartsCatalog::setQuantity($sample['ID'], $before);

    check('остаток возвращён', \Alex\Autoservice\Service\PartsCatalog::getQuantity($sample['ID']) === $before);
}

echo "\n=== [4] повторное разворачивание ===\n";

$countBefore = count(\Alex\Autoservice\Service\PartsCatalog::getParts());

\Alex\Autoservice\Service\DemoData::seed();

check('дублей не появилось',
    count(\Alex\Autoservice\Service\PartsCatalog::getParts()) === $countBefore,
    'было ' . $countBefore . ', стало ' . count(\Alex\Autoservice\Service\PartsCatalog::getParts()));

echo "\n=== [5] внешний сервис ===\n";

$numbers = \Alex\Autoservice\Service\PartsSync::fetchQuantities(count($parts));

if ($numbers === null) {
    echo "  сервис недоступен — проверка пропущена;\n"
        . "  устойчивость к его отказу проверяет тест 08\n";
} else {
    echo '  чисел получено: ' . count($numbers) . ' — ' . implode(', ', $numbers) . "\n";

    check('чисел столько же, сколько запчастей', count($numbers) === count($parts),
        'это основа синхронизации остатков');
    check('все числа в диапазоне 0..10',
        !array_filter($numbers, static fn (int $n): bool => $n < 0 || $n > 10));
}

echo "\n=== [6] откат ===\n";

$journalBefore = count(\Alex\Autoservice\Migration\Manager::getApplied());

// Manager::down() без аргументов откатывает последнюю применённую
// миграцию, а последняя давно не эта. Зовём свою по версии: журнал
// остаётся нетронутым, а следующий блок возвращает всё на место
$migration = \Alex\Autoservice\Migration\Manager::get('20261113090000');

$migration->down();

report($migration->getReport());

check('раздел удалён', \Alex\Autoservice\Service\PartsCatalog::getSectionId() === 0);

echo "\n=== [7] повторный накат ===\n";

$migration = \Alex\Autoservice\Migration\Manager::get('20261113090000');

$migration->up();

report($migration->getReport());

check('раздел создан заново', \Alex\Autoservice\Service\PartsCatalog::getSectionId() > 0);

foreach (\Alex\Autoservice\Service\DemoData::seed() as $line) {
    if (mb_strpos($line, 'апчаст') !== false) {
        echo '    ' . htmlspecialcharsbx($line) . "\n";
    }
}

check('номенклатура восстановлена',
    count(\Alex\Autoservice\Service\PartsCatalog::getParts()) >= 10);
