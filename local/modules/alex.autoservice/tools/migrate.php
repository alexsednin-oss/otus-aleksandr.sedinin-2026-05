<?php
/**
 * Запуск миграций из командной строки контейнера:
 *
 *   php -f .../tools/migrate.php status
 *   php -f .../tools/migrate.php up
 *   php -f .../tools/migrate.php up 20261104093000
 *   php -f .../tools/migrate.php down
 *   php -f .../tools/migrate.php down --all
 */

$_SERVER['DOCUMENT_ROOT'] = realpath(__DIR__ . '/../../../..');

$_SERVER['HTTP_HOST']   = 'localhost';
$_SERVER['REQUEST_URI'] = '/';

define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
define('BX_NO_ACCELERATOR_RESET', true);
define('NO_AGENT_CHECK', true);

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

global $USER;

if (!is_object($USER)) {
    $USER = new CUser();
}

$USER->Authorize(1);

if (!\Bitrix\Main\Loader::includeModule('alex.autoservice')) {
    fwrite(STDERR, "Модуль alex.autoservice не установлен\n");
    exit(1);
}

$command = $argv[1] ?? 'status';
$target  = $argv[2] ?? null;

switch ($command) {
    case 'status':
        foreach (\Alex\Autoservice\Migration\Manager::getStatus() as $item) {
            echo ($item['applied'] ? '[x] ' : '[ ] ')
                . $item['version'] . '  '
                . str_pad($item['name'], 46)
                . $item['description']
                . ($item['appliedAt'] ? '  (' . $item['appliedAt'] . ')' : '')
                . PHP_EOL;
        }
        break;

    case 'up':
        foreach (\Alex\Autoservice\Migration\Manager::up($target) as $line) {
            echo $line . PHP_EOL;
        }
        break;

    case 'down':
        $all = $target === '--all';

        foreach (\Alex\Autoservice\Migration\Manager::down($all ? null : $target, $all) as $line) {
            echo $line . PHP_EOL;
        }
        break;

    default:
        fwrite(STDERR, "Неизвестная команда: " . $command . PHP_EOL);
        exit(1);
}

exit(0);
