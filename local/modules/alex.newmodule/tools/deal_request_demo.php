<?php
/**
 * Скрипт развёртывания задачи «Заявки ↔ Сделки».
 *
 * Делает всё с нуля и за один проход:
 *   1. создаёт инфоблок «Заявки» со свойствами Сделка / Сумма / Ответственный;
 *   2. регистрирует обработчики событий iblock и crm;
 *   3. создаёт 5 сделок;
 *   4. создаёт 5 элементов инфоблока с привязкой к этим сделкам;
 *   5. показывает итоговую таблицу и подсказку, как проверить синхронизацию.
 *
 * Запуск из консоли контейнера:
 *   php -f /var/www/bitrix/local/modules/alex.newmodule/tools/deal_request_demo.php
 *
 * Запуск из браузера (только администратору):
 *   /local/modules/alex.newmodule/tools/deal_request_demo.php
 */

use Alex\Newmodule\DealRequestSync;
use Alex\Newmodule\RequestIblockInstaller;
use Bitrix\Main\Loader;

$isCli = PHP_SAPI === 'cli';

if ($isCli) {
    $_SERVER['DOCUMENT_ROOT'] = realpath(__DIR__ . '/../../../..');

    if (!$_SERVER['DOCUMENT_ROOT'] || !is_file($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php')) {
        fwrite(STDERR, "Не найден корень сайта. Запускайте скрипт из local/modules/alex.newmodule/tools/\n");
        exit(1);
    }

    $_SERVER['HTTP_HOST']   = 'localhost';
    $_SERVER['REQUEST_URI'] = '/';

    define('NO_KEEP_STATISTIC', true);
    define('NOT_CHECK_PERMISSIONS', true);
    define('BX_NO_ACCELERATOR_RESET', true);
}

define('STOP_STATISTICS', true);
define('NO_AGENT_CHECK', true);

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

/** Количество демонстрационных пар «сделка + заявка» */
const DEMO_COUNT = 5;

// ---------------------------------------------------------------- окружение

global $USER;

if ($isCli) {
    if (!is_object($USER)) {
        $USER = new CUser();
    }

    $USER->Authorize(1); // от имени администратора — иначе CRM откажет в правах
} elseif (!is_object($USER) || !$USER->IsAdmin()) {
    die('Доступно только администратору');
}

if (!$isCli) {
    header('Content-Type: text/plain; charset=UTF-8');
}

function out(string $line = ''): void
{
    echo $line . PHP_EOL;

    if (ob_get_level() > 0) {
        @ob_flush();
    }

    flush();
}

function fail(string $message): void
{
    out('ОШИБКА: ' . $message);
    die(1);
}

foreach (['iblock', 'crm', 'alex.newmodule'] as $module) {
    if (!Loader::includeModule($module)) {
        fail('не установлен модуль ' . $module);
    }
}

out('=== Заявки ↔ Сделки: развёртывание ===');
out();

// ------------------------------------------------- 1. инфоблок и свойства

out('[1/4] Инфоблок «' . RequestIblockInstaller::IBLOCK_NAME . '»');

try {
    foreach (RequestIblockInstaller::install() as $line) {
        out('      ' . $line);
    }
} catch (\Throwable $e) {
    fail($e->getMessage());
}

$iblockId = RequestIblockInstaller::getIblockId();

if (!$iblockId) {
    fail('инфоблок не создан');
}

out();

// --------------------------------------------------- 2. обработчики событий

out('[2/4] Обработчики событий');

DealRequestSync::registerEvents();

foreach (DealRequestSync::getEvents() as [$module, $event, $class, $method]) {
    out('      ' . str_pad($module, 7) . ' ' . str_pad($event, 28) . ' → ' . $class . '::' . $method);
}

out();

// ------------------------------------------------------------- 3. сделки

out('[3/4] Создание сделок: ' . DEMO_COUNT);

$assignedBy = (int) $USER->GetID() ?: 1;
$deals      = [];
$stamp      = date('d.m.Y H:i');

for ($i = 1; $i <= DEMO_COUNT; $i++) {
    $fields = [
        'TITLE'          => 'Демо-сделка №' . $i . ' от ' . $stamp,
        'OPPORTUNITY'    => 1000 * $i,
        'CURRENCY_ID'    => \CCrmCurrency::GetBaseCurrencyID(),
        'ASSIGNED_BY_ID' => $assignedBy,
        'OPENED'         => 'Y',
    ];

    $entity = new \CCrmDeal(false);

    $dealId = (int) $entity->Add(
        $fields,
        true,
        [
            'DISABLE_USER_FIELD_CHECK' => true,
            'REGISTER_SONET_EVENT'     => false,
        ]
    );

    if (!$dealId) {
        fail('не удалось создать сделку №' . $i . ': ' . $entity->LAST_ERROR);
    }

    $deals[] = [
        'ID'          => $dealId,
        'TITLE'       => $fields['TITLE'],
        'OPPORTUNITY' => $fields['OPPORTUNITY'],
    ];

    out('      сделка ID ' . $dealId . ' — ' . $fields['OPPORTUNITY']);
}

out();

// ------------------------------------------------------------- 4. заявки

out('[4/4] Создание заявок с привязкой к сделкам');

$isCrmBound = \CIBlockProperty::GetList(
    [],
    ['IBLOCK_ID' => $iblockId, 'CODE' => RequestIblockInstaller::PROP_DEAL]
)->Fetch();

$dealPropertyIsCrm = $isCrmBound && (string) $isCrmBound['USER_TYPE'] === 'ECrm';   // было 'crm'

$elements = [];

foreach ($deals as $index => $deal) {
    $number = $index + 1;

    $dealPrefix = \CCrmOwnerTypeAbbr::ResolveByTypeName('DEAL');   // 'D'
    $dealValue  = $dealPropertyIsCrm ? $dealPrefix . '_' . $deal['ID'] : $deal['ID'];

    $fields = [
        'IBLOCK_ID'       => $iblockId,
        'ACTIVE'          => 'Y',
        'NAME'            => 'Заявка №' . $number . ' от ' . $stamp,
        'PROPERTY_VALUES' => [
            RequestIblockInstaller::PROP_DEAL        => $dealValue,
            RequestIblockInstaller::PROP_AMOUNT      => $deal['OPPORTUNITY'],
            RequestIblockInstaller::PROP_ASSIGNED_BY => $assignedBy,
        ],
    ];

    $element   = new \CIBlockElement();
    $elementId = (int) $element->Add($fields);

    if (!$elementId) {
        fail('не удалось создать заявку №' . $number . ': ' . $element->LAST_ERROR);
    }

    $elements[] = [
        'ID'     => $elementId,
        'NAME'   => $fields['NAME'],
        'DEAL'   => $deal['ID'],
        'AMOUNT' => $deal['OPPORTUNITY'],
    ];

    out('      заявка ID ' . $elementId . ' → сделка ' . $deal['ID']);
}

out();

// ------------------------------------------------------------- результат

out('=== Готово ===');
out();
out(str_pad('Заявка', 10) . str_pad('Сделка', 10) . str_pad('Сумма', 12) . 'Ответственный');
out(str_repeat('-', 46));

foreach ($elements as $row) {
    out(
        str_pad((string) $row['ID'], 10)
        . str_pad((string) $row['DEAL'], 10)
        . str_pad((string) $row['AMOUNT'], 12)
        . $assignedBy
    );
}

out();
out('Инфоблок «Заявки»: IBLOCK_ID = ' . $iblockId);
out('Свойство «Сделка» хранит ' . ($dealPropertyIsCrm ? 'привязку к CRM (D_<id>)' : 'ID сделки числом'));
out();
out('Как проверить синхронизацию:');
out('  1. Поменяйте Сумму или Ответственного в заявке → значения изменятся в сделке.');
out('  2. Поменяйте сумму или ответственного в сделке → значения изменятся в заявке.');
out('  3. Обратного «эха» не будет: встречный обработчик блокируется флагом,');
out('     а при совпадении значений запись вообще не выполняется.');
out();
out('Журнал синхронизации (по желанию):');
out("  \\Bitrix\\Main\\Config\\Option::set('alex.newmodule', 'sync_debug', 'Y');");
out('  записи появятся в /bitrix/admin/event_log.php');

if ($isCli) {
    exit(0);
}
