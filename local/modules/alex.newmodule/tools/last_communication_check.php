<?php
/**
 * Диагностика задачи «Дата последней коммуникации».
 *
 * Показывает: создано ли поле, стоит ли оно первым в карточке, сохранён ли
 * токен приложения, какие контакты уже получили дату. С ключом --touch=<ID дела>
 * прогоняет обработчик вручную — удобно проверить логику без события от Б24.
 *
 * Запуск из консоли контейнера:
 *   php -f /var/www/bitrix/local/modules/alex.newmodule/tools/last_communication_check.php
 *   php -f ... last_communication_check.php --touch=123
 */

use Alex\Newmodule\LastCommunicationHandler;
use Alex\Newmodule\LastCommunicationInstaller;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;

$isCli = PHP_SAPI === 'cli';

if ($isCli) {
    $_SERVER['DOCUMENT_ROOT'] = realpath(__DIR__ . '/../../../..');

    if (!$_SERVER['DOCUMENT_ROOT'] || !is_file($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php')) {
        fwrite(STDERR, "Не найден корень сайта.\n");
        exit(1);
    }

    $_SERVER['HTTP_HOST']   = 'localhost';
    $_SERVER['REQUEST_URI'] = '/';

    define('NO_KEEP_STATISTIC', true);
    define('NOT_CHECK_PERMISSIONS', true);
    define('BX_NO_ACCELERATOR_RESET', true);
}

define('NO_AGENT_CHECK', true);

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

global $USER;

if ($isCli) {
    if (!is_object($USER)) {
        $USER = new CUser();
    }

    $USER->Authorize(1);
} elseif (!is_object($USER) || !$USER->IsAdmin()) {
    die('Доступно только администратору');
}

if (!$isCli) {
    header('Content-Type: text/plain; charset=UTF-8');
}

function out(string $line = ''): void
{
    echo $line . PHP_EOL;
    flush();
}

foreach (['crm', 'alex.newmodule'] as $module) {
    if (!Loader::includeModule($module)) {
        out('ОШИБКА: не установлен модуль ' . $module);
        exit(1);
    }
}

out('=== Дата последней коммуникации: диагностика ===');
out();

// ------------------------------------------------------------------ поле

$fieldId = LastCommunicationInstaller::getFieldId();

out('[1] Пользовательское поле');
out('    ' . LastCommunicationInstaller::FIELD_NAME . ': ' . ($fieldId ? 'создано, ID = ' . $fieldId : 'НЕ СОЗДАНО'));

// -------------------------------------------------------------- карточка

out();
out('[2] Позиция в карточке контакта');

$configClass = '\Bitrix\Crm\Entity\EntityEditorConfig';
$scopeClass  = '\Bitrix\Crm\Entity\EntityEditorConfigScope';

if (class_exists($configClass) && class_exists($scopeClass)) {
    $config = new $configClass(\CCrmOwnerType::Contact, (int) $USER->GetID(), constant($scopeClass . '::COMMON'), []);
    $data   = $config->getConfiguration(true);

    $position = null;

    foreach ((array) $data as $sectionIndex => $section) {
        foreach ((array) ($section['elements'] ?? []) as $elementIndex => $element) {
            if ((string) ($element['name'] ?? '') === LastCommunicationInstaller::FIELD_NAME) {
                $position = ['section' => $sectionIndex, 'element' => $elementIndex,
                    'title' => (string) ($section['title'] ?? $section['name'] ?? '')];
                break 2;
            }
        }
    }

    if ($position === null) {
        out('    поля в конфигурации нет — оно попадёт в раздел «Дополнительно»');
    } else {
        out('    секция ' . $position['section'] . ' («' . $position['title'] . '»), позиция ' . $position['element']
            . ($position['section'] === 0 && $position['element'] === 0 ? '  ← первое' : ''));
    }
} else {
    out('    классы конфигурации карточки недоступны');
}

// ------------------------------------------------------------ приложение

out();
out('[3] Приложение');
out('    application_token: ' . (Option::get('alex.newmodule', 'app_token', '') !== '' ? 'сохранён' : 'НЕ СОХРАНЁН'));
out('    подписка на ONCRMACTIVITYADD: ' . (Option::get('alex.newmodule', 'app_event_bound', 'N') === 'Y' ? 'создана' : 'не отмечена'));
out('    обработчик: ' . (Option::get('alex.newmodule', 'app_handler_url', '—')));
out('    журнал (app_debug): ' . Option::get('alex.newmodule', 'app_debug', 'N'));

// ------------------------------------------------- ручной прогон по делу

$touchId = 0;

if ($isCli) {
    foreach ($argv as $arg) {
        if (preg_match('/^--touch=(\d+)$/', $arg, $m)) {
            $touchId = (int) $m[1];
        }
    }
} else {
    $touchId = (int) ($_GET['touch'] ?? 0);
}

if ($touchId) {
    out();
    out('[4] Ручной прогон по делу ' . $touchId);

    $report = LastCommunicationHandler::touchByActivity($touchId);

    out('    контакты дела: ' . ($report['CONTACTS'] ? implode(', ', $report['CONTACTS']) : 'нет'));
    out('    обновлены:     ' . ($report['UPDATED'] ? implode(', ', $report['UPDATED']) : 'нет'));

    foreach ($report['MESSAGES'] as $message) {
        out('    ' . $message);
    }
}

// --------------------------------------------- контакты с заполненной датой

out();
out('[5] Последние контакты с заполненной датой');

if ($fieldId) {
    $res = CCrmContact::GetListEx(
        ['ID' => 'DESC'],
        ['CHECK_PERMISSIONS' => 'N', '!' . LastCommunicationInstaller::FIELD_NAME => false],
        false,
        ['nTopCount' => 10],
        ['ID', 'FULL_NAME', LastCommunicationInstaller::FIELD_NAME]
    );

    $found = 0;

    while ($row = $res->Fetch()) {
        $found++;
        out('    ' . str_pad((string) $row['ID'], 6) . ' | '
            . str_pad((string) $row['FULL_NAME'], 30) . ' | '
            . $row[LastCommunicationInstaller::FIELD_NAME]);
    }

    if (!$found) {
        out('    ни одного — дата ещё ни разу не записывалась');
    }
} else {
    out('    поле не создано, проверять нечего');
}

out();
out('Готово.');

if ($isCli) {
    exit(0);
}
