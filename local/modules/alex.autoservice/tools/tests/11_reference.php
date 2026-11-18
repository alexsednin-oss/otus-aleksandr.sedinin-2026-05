<?php
/**
 * Тест: Справочники марок и моделей, связь с карточкой автомобиля
 *
 * Запускается через tools/tests/run.php или страницу администрирования.
 * Счётчики и вывод берутся из bootstrap.php: check() и report().
 */

CModule::IncludeModule('crm');
CModule::IncludeModule('highloadblock');
CModule::IncludeModule('alex.autoservice');

use Alex\Autoservice\Service\CarCrudService;
use Alex\Autoservice\Service\CarReference;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;

echo "=== [1] миграция ===\n";

report(\Alex\Autoservice\Migration\Manager::up());

$brandBlock = (int) Option::get('alex.autoservice', CarReference::OPTION_BRAND_HL, 0);
$modelBlock = (int) Option::get('alex.autoservice', CarReference::OPTION_MODEL_HL, 0);

check('блок марок создан', $brandBlock > 0, 'ID = ' . $brandBlock);
check('блок моделей создан', $modelBlock > 0, 'ID = ' . $modelBlock);

echo "\n=== [2] наполнение ===\n";

$brands = CarReference::getBrands();

check('марок не меньше десяти', count($brands) >= 10, 'найдено: ' . count($brands));

$models = CarReference::getModels();

check('моделей не меньше тридцати', count($models) >= 30, 'найдено: ' . count($models));

foreach (array_slice($brands, 0, 3) as $brand) {
    $brandModels = CarReference::getModels((int) $brand['ID']);

    check('  у марки ' . $brand['NAME'] . ' есть модели', count($brandModels) >= 3,
        'моделей: ' . count($brandModels));
}

$toyota = CarReference::findBrand('Toyota');

check('марка ищется по названию', $toyota > 0, 'ID = ' . $toyota);
check('название возвращается по ID', CarReference::getBrandName($toyota) === 'Toyota');
check('модель ищется в пределах марки', CarReference::findModel($toyota, 'Camry') > 0);
check('чужая модель у марки не находится', CarReference::findModel($toyota, 'Logan') === 0,
    'Logan принадлежит Renault');

echo "\n=== [3] колонки таблицы ===\n";

$columns = array_column(
    Application::getConnection()
        ->query('SHOW COLUMNS FROM ' . \Alex\Autoservice\Model\CarTable::getTableName())
        ->fetchAll(),
    'Field'
);

check('колонка BRAND_ID', in_array('BRAND_ID', $columns, true));
check('колонка MODEL_ID', in_array('MODEL_ID', $columns, true));

echo "\n=== [4] автомобиль по названиям ===\n";

// Прогон мог оборваться до уборки — снимаем хвосты прошлого запуска
foreach (['С777ПР777', 'Х777УУ777'] as $staleNumber) {
    $staleId = CarCrudService::findIdByNumber($staleNumber);

    if (!$staleId) {
        continue;
    }

    $stale = \Alex\Autoservice\Model\CarTable::getById($staleId)->fetch();

    \Alex\Autoservice\Model\CarTable::delete($staleId);

    if (!empty($stale['CONTACT_ID'])) {
        $cleaner = new \CCrmContact(false);
        $cleaner->Delete((int) $stale['CONTACT_ID']);
    }

    echo '    удалён хвост прошлого прогона: ' . $staleNumber . "\n";
}

$contact = new \CCrmContact(false);

$contactFields = [
    'NAME'      => 'Тест',
    'LAST_NAME' => 'Справочник ' . date('dHis'),
    'OPENED'    => 'Y',
];

$contactId = (int) $contact->Add($contactFields, true,
    ['DISABLE_USER_FIELD_CHECK' => true, 'REGISTER_SONET_EVENT' => false]);

check('контакт создан', $contactId > 0, 'ID = ' . $contactId);

$car = CarCrudService::create([
    'CONTACT_ID' => $contactId,
    'BRAND'      => 'Toyota',
    'MODEL'      => 'Camry',
    'NUMBER'     => 'С777ПР777',
]);

$created = CarCrudService::read(['ID' => $car['ID']]);

check('автомобиль создан', $car['ID'] > 0, 'ID = ' . $car['ID']);
check('марка разрешена в справочник', (int) $created['BRAND_ID'] === $toyota,
    'BRAND_ID = ' . $created['BRAND_ID']);
check('модель разрешена в справочник', (int) $created['MODEL_ID'] > 0,
    'MODEL_ID = ' . $created['MODEL_ID']);
check('название сохранено для внешних систем', $created['BRAND'] === 'Toyota');

echo "\n=== [5] неизвестные значения отвергаются ===\n";

foreach ([
             ['BRAND' => 'Трабант', 'MODEL' => 'Camry', 'NUMBER' => 'Х777УУ777', 'что' => 'неизвестная марка'],
             ['BRAND' => 'Toyota',  'MODEL' => 'Logan', 'NUMBER' => 'Х777УУ777', 'что' => 'модель чужой марки'],
         ] as $case) {
    try {
        CarCrudService::create([
            'CONTACT_ID' => $contactId,
            'BRAND'      => $case['BRAND'],
            'MODEL'      => $case['MODEL'],
            'NUMBER'     => $case['NUMBER'],
        ]);

        check($case['что'] . ' отвергнута', false, 'автомобиль создался');
    } catch (\InvalidArgumentException $e) {
        check($case['что'] . ' отвергнута', true, $e->getMessage());
    }
}

echo "\n=== [6] обновление через справочник ===\n";

CarCrudService::update($car['ID'], ['MODEL' => 'Corolla']);

$updated = CarCrudService::read(['ID' => $car['ID']]);

check('модель сменилась', $updated['MODEL'] === 'Corolla');
check('ссылка обновилась', (int) $updated['MODEL_ID'] === CarReference::findModel($toyota, 'Corolla'));
check('марка не пострадала', (int) $updated['BRAND_ID'] === $toyota);

$kia = CarReference::findBrand('Kia');

CarCrudService::update($car['ID'], ['BRAND' => 'Kia', 'MODEL' => 'Rio']);

$switched = CarCrudService::read(['ID' => $car['ID']]);

check('марка сменилась вместе с моделью', (int) $switched['BRAND_ID'] === $kia
    && $switched['MODEL'] === 'Rio');

try {
    CarCrudService::update($car['ID'], ['BRAND' => 'Toyota']);

    check('смена марки без модели отвергнута', false, 'обновление прошло');
} catch (\InvalidArgumentException $e) {
    check('смена марки без модели отвергнута', true, $e->getMessage());
}

echo "\n=== [7] уборка ===\n";

\Alex\Autoservice\Model\CarTable::delete($car['ID']);

$contact->Delete($contactId);

echo "    тестовые данные удалены\n";
