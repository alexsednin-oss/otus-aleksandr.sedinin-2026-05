<?php

require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php');

$APPLICATION->SetTitle('Примеры отладки');


//print_r('<pre>');
//print_r('$_SERVER: ');
//print_r($_SERVER);
//print_r('</pre>');


//print_r('<pre>');
//print_r('var_dump($_SERVER): ');
//print_r(var_dump($_SERVER));
//print_r('</pre>');


//dump($_SERVER);

//Bitrix\Main\Diag\Debug::dumpToFile($_SERVER, 'SERVER');


//Bitrix\Main\Diag\Debug::startTimeLabel('Server1');
//sleep(2);
//Bitrix\Main\Diag\Debug::endTimeLabel('Server1');
//Bitrix\Main\Diag\Debug::startTimeLabel('Server2');
//sleep(3);
//Bitrix\Main\Diag\Debug::endTimeLabel('Server2');
//$TimeLabels = Bitrix\Main\Diag\Debug::getTimeLabels();
//print_r('<pre>');
//print_r('$TimeLabels: ');
//print_r($TimeLabels);
//print_r('</pre>');

//Bitrix\Main\Diag\Helper::getBackTrace();

// Region Отладка SQL
/*
use Bitrix\Main\Loader;
use Bitrix\Main\Application;

if(!Loader::includeModule('iblock')){
    return false;
}

$IBLOCK_ID = 7; // Фотогалерея пользователей

// Параметры выборки
$arEntityDataParams = [
    'select' => ['ID', 'NAME'],
    'filter' => ['IBLOCK_ID' => $IBLOCK_ID, 'ACTIVE' => 'Y'],
    'limit' => 5
];

// Включаем трекинг SQL
$connection = Application::getConnection();
$connection->startTracker();

// Выполняем запрос

$query = \Bitrix\Iblock\ElementTable::getList($arEntityDataParams);
$result = $query->fetchAll(); // Можно или fetch() в цикле

// Отключаем трекер
$connection->stopTracker();

// Получаем SQL-запрос
$sql = $query->getTrackerQuery()->getSql();

// Вывод
echo "<pre>";
print_r($result);
echo "SQL запрос:\n" . $sql;
echo "</pre>";

//*/
//endregion Отладка SQL

//$hh = 1/0;
//================== часть 1 ДЗ
/* Запись ошибки в файл exceptions.log
$hh = 1/0;
*/

/* Очистка файла кастомного логгирования
use App\Debug\Log;
Log::cleanLog('exceptions');
*/

//================== часть 2 ДЗ

/* запись в кастомный файл лога
use App\Debug\Log;
Log::addLog('тестовое сообщение');
*/

/* Удаление из кастомного файла
use App\Debug\Log;
$logFile = 'custom_' . date("d.m.Y");
Log::cleanLog($logFile);
*/

////Log::cleanLog('custom_07.07.2026');
//$result = new Log();
//$result->write($logFile);


require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
