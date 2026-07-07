<?php

namespace App\Debug;

use Bitrix\Main\Diag\ExceptionHandlerFormatter;
use Bitrix\Main\Diag\FileExceptionHandlerLog;

/**
 * Класс для записи в кастомный лог и реализация кастомного логгирования ошибок
 */
class Log extends FileExceptionHandlerLog
{
    /**
     * @var int $level
     */
    private $level;

    /**
     * @param $message
     * @param bool $clear
     * @param string $fileName
     * @param bool $timeVersion
     * @return void
     */
    public static function addLog($message, bool $clear = false, string $fileName = 'custom', bool $timeVersion = true): void
    {
        $logFile = $_SERVER['DOCUMENT_ROOT'] . '/local/logs/' . $fileName;

        if ($timeVersion) {
            $logFile .= '_' . date("d.m.Y");
        }
        $logFile .= '.log';

        $_message = date('d.m.Y H:i:s');
        $_message .= "\n";
        $_message .= print_r($message, true);
        $_message .= "\n";
        $_message .= "---";
        $_message .= "\n";

        if ($clear) {
            file_put_contents($logFile, $_message);
        } else {
            file_put_contents($logFile, $_message, FILE_APPEND);
        }

        $result = file_put_contents($logFile, $_message, $clear ? 0 : FILE_APPEND);
        var_dump($result, $logFile);

    }

    /**
     * @param string $fileName
     * @return void
     */
    public static function cleanLog(string $fileName = 'custom'): void
    {
        $logFile = $_SERVER['DOCUMENT_ROOT'] . '/local/logs/' . $fileName;
        $logFile .= '.log';
        file_put_contents($logFile, '');
    }

    /**
     * @param $exception
     * @param $logType
     * @return void
     */
    public function write($exception, $logType): void
    {
        $text = ExceptionHandlerFormatter::format($exception, false, $this->level);

        $context = [
            'type' => static::logTypeToString($logType),
        ];

        $logLevel = static::logTypeToLevel($logType);

        $message = "Otus - " . "{date} - Host: {host} - {type} - {$text}\n";

        $this->logger->log($logLevel, $message, $context);
    }
}
