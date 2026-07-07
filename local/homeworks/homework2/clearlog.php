<?php
require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/header.php");
// ТУТ ДОБАВИТЬ СВОЮ ФУНКЦИЮ ОЧИСТКИ ЛОГА

use App\Debug\Log;
$logFile = 'custom_' . date("d.m.Y");
Log::cleanLog($logFile);

LocalRedirect('/local/homeworks/homework2/');
