<?php
/**
 * Тест: Контроль незакрытых заказ-нарядов
 *
 * Запускается через tools/tests/run.php или страницу администрирования.
 * Счётчики и вывод берутся из bootstrap.php: check() и report().
 */

CModule::IncludeModule('crm');
CModule::IncludeModule('alex.autoservice');

$carField = \Alex\Autoservice\Service\CarCrudService::DEAL_CAR_FIELD;

echo "=== [0] обработчик ===\n";

$registered = false;
$res        = GetModuleEvents('crm', 'OnBeforeCrmDealAdd');

while ($event = $res->Fetch()) {
    if ($event['TO_CLASS'] === '\Alex\Autoservice\Handler\DealGuard') {
        $registered = true;
    }
}

check('OnBeforeCrmDealAdd зарегистрирован', $registered);
check('класс Notifier', class_exists('\Alex\Autoservice\Service\Notifier'));

echo "\n=== [1] подготовка ===\n";

$categoryId = (int) \Bitrix\Main\Config\Option::get('alex.autoservice', 'service_category_id', 0);
$entityId   = \Alex\Autoservice\Service\DealFunnel::getStagesEntityId($categoryId);
$prefix     = '';

foreach (array_keys(\Alex\Autoservice\Service\Stages::getExisting($entityId)) as $statusId) {
    $position = mb_strpos($statusId, ':');

    if ($position !== false) {
        $prefix = mb_substr($statusId, 0, $position + 1);
        break;
    }
}

$suffix  = date('dHis');
$contact = new \CCrmContact(false);

$contactFields = ['NAME' => 'Тест', 'LAST_NAME' => 'Контроль ' . $suffix, 'OPENED' => 'Y'];

$contactId = (int) $contact->Add($contactFields, true,
    ['DISABLE_USER_FIELD_CHECK' => true, 'REGISTER_SONET_EVENT' => false]);

$car = \Alex\Autoservice\Service\CarCrudService::create([
    'CONTACT_ID' => $contactId,
    'BRAND'      => 'Ford',
    'MODEL'      => 'Focus',
    'NUMBER'     => 'у' . substr($suffix, -3) . 'кк161',
]);

$carId = (int) $car['ID'];

check('клиент и автомобиль созданы', $contactId > 0 && $carId > 0, 'авто ID = ' . $carId);

$deal = new \CCrmDeal(false);

/**
 * @return array{ID:int, ERROR:string}
 */
function addDeal(\CCrmDeal $deal, string $title, int $categoryId, int $contactId, int $carId, string $carField): array
{
    $fields = [
        'TITLE'          => $title,
        'CATEGORY_ID'    => $categoryId,
        'CONTACT_ID'     => $contactId,
        'ASSIGNED_BY_ID' => 1,
        'OPENED'         => 'Y',
        $carField        => $carId,
    ];

    $id = (int) $deal->Add($fields, true,
        ['DISABLE_USER_FIELD_CHECK' => true, 'REGISTER_SONET_EVENT' => false]);

    return ['ID' => $id, 'ERROR' => (string) $deal->LAST_ERROR];
}

echo "\n=== [2] первый заказ-наряд ===\n";

$first = addDeal($deal, 'Первый наряд ' . $suffix, $categoryId, $contactId, $carId, $carField);

check('первый наряд создан', $first['ID'] > 0, $first['ID'] ? 'ID = ' . $first['ID'] : $first['ERROR']);

$opened = \Alex\Autoservice\Handler\DealGuard::findOpenedDeal($carId);

check('наряд считается незакрытым', $opened !== null && (int) $opened['ID'] === $first['ID'],
    $opened ? $opened['STAGE_ID'] : 'не найден');

echo "\n=== [3] второй наряд по тому же авто ===\n";

$second = addDeal($deal, 'Второй наряд ' . $suffix, $categoryId, $contactId, $carId, $carField);

check('создание запрещено', $second['ID'] === 0);
check('сообщение понятное', mb_strpos($second['ERROR'], 'незакрытый заказ-наряд') !== false,
    $second['ERROR']);

echo "\n=== [4] наряд по другому автомобилю не блокируется ===\n";

$otherCar = \Alex\Autoservice\Service\CarCrudService::create([
    'CONTACT_ID' => $contactId,
    'BRAND'      => 'Ford',
    'MODEL'      => 'Mondeo',
    'NUMBER'     => 'х' . substr($suffix, -3) . 'мм161',
]);

$other = addDeal($deal, 'Наряд по другой машине ' . $suffix, $categoryId, $contactId,
    (int) $otherCar['ID'], $carField);

check('другой автомобиль — наряд создан', $other['ID'] > 0, $other['ERROR']);

echo "\n=== [5] сделка без автомобиля не блокируется ===\n";

$plainFields = [
    'TITLE'          => 'Сделка без авто ' . $suffix,
    'CATEGORY_ID'    => $categoryId,
    'CONTACT_ID'     => $contactId,
    'ASSIGNED_BY_ID' => 1,
    'OPENED'         => 'Y',
];

$plainId = (int) $deal->Add($plainFields, true,
    ['DISABLE_USER_FIELD_CHECK' => true, 'REGISTER_SONET_EVENT' => false]);

check('сделка без привязки создана', $plainId > 0, $plainId ? 'ID = ' . $plainId : $deal->LAST_ERROR);

echo "\n=== [6] после закрытия первого наряда ===\n";

$updateFields = ['STAGE_ID' => $prefix . 'WON'];

$deal->Update($first['ID'], $updateFields, true, true,
    ['DISABLE_USER_FIELD_CHECK' => true, 'REGISTER_SONET_EVENT' => false]);

check('незакрытых нарядов не осталось',
    \Alex\Autoservice\Handler\DealGuard::findOpenedDeal($carId) === null);

$third = addDeal($deal, 'Третий наряд ' . $suffix, $categoryId, $contactId, $carId, $carField);

check('новый наряд создаётся', $third['ID'] > 0, $third['ID'] ? 'ID = ' . $third['ID'] : $third['ERROR']);

echo "\n=== [7] служебное отключение ===\n";

\Alex\Autoservice\Handler\DealGuard::$disabled = true;

$bypass = addDeal($deal, 'Пакетный наряд ' . $suffix, $categoryId, $contactId, $carId, $carField);

\Alex\Autoservice\Handler\DealGuard::$disabled = false;

check('при отключённой проверке наряд создаётся', $bypass['ID'] > 0, $bypass['ERROR']);

echo "\n=== уборка ===\n";

foreach ([$first['ID'], $other['ID'], $plainId, $third['ID'], $bypass['ID']] as $id) {
    if ($id) {
        $deal->Delete($id, ['ENABLE_DEFERRED_MODE' => false]);
    }
}

\Alex\Autoservice\Service\CarCrudService::delete($carId);
\Alex\Autoservice\Service\CarCrudService::delete((int) $otherCar['ID']);
$contact->Delete($contactId);

echo "  тестовые данные удалены\n";
