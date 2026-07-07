<?php
require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/header.php");

$APPLICATION->SetTitle("Очистка лога");
// ТУТ ДОБАВИТЬ СВОЮ ФУНКЦИЮ ОЧИСТКИ ЛОГА

use App\Debug\Log;
Log::cleanLog('exceptions');

echo

LocalRedirect('/local/homeworks/homework2/');
