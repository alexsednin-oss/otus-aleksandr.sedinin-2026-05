<?php
/**
 * Тест: Вкладка «Гараж» и история обращений по автомобилю
 *
 * Запускается через tools/tests/run.php или страницу администрирования.
 * Счётчики и вывод берутся из bootstrap.php: check() и report().
 */

CModule::IncludeModule('crm');
CModule::IncludeModule('alex.autoservice');

echo "=== [0] файлы и обработчик ===\n";

foreach ([
             '/alex_autoservice/garage_tab.php',
             '/alex_autoservice/garage_history.php',
             '/alex_autoservice/garage_save.php',
             '/bitrix/js/alex.autoservice/garage.js',
         ] as $path) {
    check('файл ' . $path, is_file($_SERVER['DOCUMENT_ROOT'] . $path));
}

check('класс Garage', class_exists('\Alex\Autoservice\Service\Garage'));
check('класс CarReference', class_exists('\Alex\Autoservice\Service\CarReference'));
check('класс ContactTab', class_exists('\Alex\Autoservice\Handler\ContactTab'));

$found = false;
$res   = GetModuleEvents('crm', 'onEntityDetailsTabsInitialized');

while ($event = $res->Fetch()) {
    if ($event['TO_CLASS'] === '\Alex\Autoservice\Handler\ContactTab') {
        $found = true;
    }
}

check('обработчик вкладки зарегистрирован', $found);

echo "\n=== [1] вкладка добавляется только контактам ===\n";

$tabs = \Alex\Autoservice\Handler\ContactTab::onEntityDetailsTabsInitialized(
    1, \CCrmOwnerType::Contact, 'guid', []
);

$tabList = $tabs ? ($tabs->getParameters()['tabs'] ?? []) : [];

check('для контакта вкладка добавлена', count($tabList) === 1,
    $tabList ? $tabList[0]['name'] . ' → ' . $tabList[0]['loader']['serviceUrl'] : '');

$dealTabs = \Alex\Autoservice\Handler\ContactTab::onEntityDetailsTabsInitialized(
    1, \CCrmOwnerType::Deal, 'guid', []
);

check('для сделки вкладка не добавляется', $dealTabs === null);

echo "\n=== [2] данные гаража ===\n";

$suffix  = date('dHis');
$contact = new \CCrmContact(false);

$contactFields = ['NAME' => 'Тест', 'LAST_NAME' => 'Гараж ' . $suffix, 'OPENED' => 'Y'];

$contactId = (int) $contact->Add($contactFields, true,
    ['DISABLE_USER_FIELD_CHECK' => true, 'REGISTER_SONET_EVENT' => false]);

check('контакт создан', $contactId > 0, 'ID = ' . $contactId);

$carIds = [];

foreach ([['Toyota', 'Camry', 2019, 'Чёрный', 84000], ['Kia', 'Rio', null, null, null]] as $index => $data) {
    [$brand, $model, $year, $color, $mileage] = $data;

    $car = \Alex\Autoservice\Service\CarCrudService::create([
        'CONTACT_ID' => $contactId,
        'BRAND'      => $brand,
        'MODEL'      => $model,
        'NUMBER'     => 'г' . substr($suffix, -3) . 'оо' . (770 + $index),
        'YEAR'       => $year,
        'COLOR'      => $color,
        'MILEAGE'    => $mileage,
    ]);

    $carIds[] = (int) $car['ID'];
}

$cars = \Alex\Autoservice\Service\Garage::getCars($contactId);

check('в гараже два автомобиля', count($cars) === 2, 'найдено: ' . count($cars));
check('поля для таблицы на месте',
    isset($cars[0]['BRAND'], $cars[0]['MODEL'], $cars[0]['NUMBER'])
    && array_key_exists('YEAR', $cars[0])
    && array_key_exists('MILEAGE', $cars[0]));

$caption = \Alex\Autoservice\Service\Garage::getCaption($cars[0]);

echo '  заголовок попапа: ' . $caption . "\n";

check('заголовок по формату ТЗ',
    (bool) preg_match('/^.+ - .+ \(.+\)$/u', $caption));

echo "\n=== [3] история обращений ===\n";

$categoryId = (int) \Bitrix\Main\Config\Option::get('alex.autoservice', 'service_category_id', 0);
$deal       = new \CCrmDeal(false);
$dealIds    = [];

foreach (['Диагностика ходовой', 'Замена масла'] as $index => $title) {
    $dealFields = [
        'TITLE'          => $title . ' ' . $suffix,
        'CATEGORY_ID'    => $categoryId,
        'CONTACT_ID'     => $contactId,
        'OPPORTUNITY'    => 12500,
        'CURRENCY_ID'    => \CCrmCurrency::GetBaseCurrencyID(),
        'ASSIGNED_BY_ID' => 1,
        'OPENED'         => 'Y',
        \Alex\Autoservice\Service\CarCrudService::DEAL_CAR_FIELD => $carIds[0],
    ];

    $dealId = (int) $deal->Add($dealFields, true,
        ['DISABLE_USER_FIELD_CHECK' => true, 'REGISTER_SONET_EVENT' => false]);

    $dealIds[] = $dealId;

    if ($dealId && $index === 0) {
        $closeFields = ['STAGE_ID' => 'C' . $categoryId . ':WON'];

        $deal->Update($dealId, $closeFields, true, true, ['DISABLE_USER_FIELD_CHECK' => true]);
    }
}

check('две сделки созданы', count(array_filter($dealIds)) === 2, implode(', ', $dealIds));

$history = \Alex\Autoservice\Service\Garage::getHistory($carIds[0]);

print_r($history);

check('история содержит обе сделки', count($history) === 2, 'найдено: ' . count($history));

if ($history) {
    $first = $history[0];

    check('  есть название', !empty($first['TITLE']));
    check('  есть дата создания', !empty($first['DATE_CREATE']));
    check('  стадия названием, а не кодом', $first['STAGE_NAME'] !== $first['STAGE_ID'],
        $first['STAGE_NAME']);
    check('  есть сумма', !empty($first['SUM']), (string) $first['SUM']);
    check('  есть ответственный', !empty($first['ASSIGNED']), (string) $first['ASSIGNED']);
    check('  ссылка на профиль', mb_strpos((string) $first['ASSIGNED_URL'], '/company/personal/user/') === 0);
    check('  список запчастей присутствует', is_array($first['PRODUCTS']));
}

check('у второго автомобиля истории нет',
    count(\Alex\Autoservice\Service\Garage::getHistory($carIds[1])) === 0);

echo "\n=== уборка ===\n";

foreach ($dealIds as $id) {
    if ($id) {
        $deal->Delete($id, ['ENABLE_DEFERRED_MODE' => false]);
    }
}

foreach ($carIds as $id) {
    \Alex\Autoservice\Model\CarTable::delete($id);
}

$contact->Delete($contactId);

echo "  тестовые данные удалены\n";
