<?php

namespace Alex\Newmodule\Rest;

use Bitrix\Main\Config\Option;

/**
 * Файловый лог CRUD мясной лавки.
 */
class Log
{
    public const OPTION_MODULE = 'alex.newmodule';
    public const OPTION_NAME   = 'meatshop_log';
    public const FILE_NAME     = 'meatshop';
    private const MAX_VALUE_LENGTH = 2000;

    /** Ключи, значения которых в лог попадать не должны */
    private const SECRET_KEYS = ['AUTH', 'APPLICATION_TOKEN', 'ACCESS_TOKEN', 'PASSWORD'];

    /**
     * @return bool
     */
    public static function isEnabled(): bool
    {
        return Option::get(self::OPTION_MODULE, self::OPTION_NAME, 'Y') === 'Y';
    }

    /**
     * @param mixed $message
     */
    public static function addLog(
        $message,
        bool $clear = false,
        string $fileName = self::FILE_NAME,
        bool $timeVersion = true
    ): void {
        $logFile = $_SERVER['DOCUMENT_ROOT'] . '/local/logs/' . $fileName;

        if ($timeVersion) {
            $logFile .= '_' . date('d.m.Y');
        }

        $logFile .= '.log';

        // Каталог может отсутствовать на свежем портале
        if (function_exists('CheckDirPath')) {
            \CheckDirPath($logFile);
        }

        $record = date('d.m.Y H:i:s') . "\n"
            . print_r($message, true) . "\n"
            . "---\n";

        file_put_contents($logFile, $record, $clear ? 0 : FILE_APPEND);
    }

    /**
     * @param string $fileName
     * @param bool $timeVersion
     * @return void
     */
    public static function cleanLog(string $fileName = self::FILE_NAME, bool $timeVersion = true): void
    {
        $logFile = $_SERVER['DOCUMENT_ROOT'] . '/local/logs/' . $fileName;

        if ($timeVersion) {
            $logFile .= '_' . date('d.m.Y');
        }

        file_put_contents($logFile . '.log', '');
    }

    /**
     * @param string $method
     * @param $params
     * @return string
     */
    public static function request(string $method, $params): string
    {
        $requestId = substr(md5(uniqid('', true)), 0, 8);

        if (!self::isEnabled()) {
            return $requestId;
        }

        global $USER;

        self::addLog([
            'id'     => $requestId,
            'stage'  => 'REQUEST',
            'method' => $method,
            'ip'     => $_SERVER['REMOTE_ADDR'] ?? 'cli',
            'user'   => is_object($USER) && $USER->IsAuthorized() ? (int) $USER->GetID() : 0,
            'params' => self::prepare($params),
        ]);

        return $requestId;
    }

    /**
     * @param mixed $result
     */
    public static function response(string $requestId, string $method, $result): void
    {
        if (!self::isEnabled()) {
            return;
        }

        self::addLog([
            'id'     => $requestId,
            'stage'  => 'RESPONSE',
            'method' => $method,
            'result' => self::prepare($result),
        ]);
    }

    /**
     * @param string $requestId
     * @param string $method
     * @param string $errorCode
     * @param \Throwable $e
     * @return void
     */
    public static function failure(string $requestId, string $method, string $errorCode, \Throwable $e): void
    {
        if (!self::isEnabled()) {
            return;
        }

        self::addLog([
            'id'        => $requestId,
            'stage'     => 'ERROR',
            'method'    => $method,
            'error'     => $errorCode,
            'message'   => $e->getMessage(),
            'exception' => get_class($e),
            'at'        => $e->getFile() . ':' . $e->getLine(),
        ]);
    }

    /**
     * @param mixed $value
     *
     * @return mixed
     */
    private static function prepare($value)
    {
        if (is_array($value)) {
            $prepared = [];

            foreach ($value as $key => $item) {
                $prepared[$key] = in_array(mb_strtoupper((string) $key), self::SECRET_KEYS, true)
                    ? '***'
                    : self::prepare($item);
            }

            return $prepared;
        }

        if (is_string($value) && mb_strlen($value) > self::MAX_VALUE_LENGTH) {
            return mb_substr($value, 0, self::MAX_VALUE_LENGTH) . '… (обрезано)';
        }

        return $value;
    }
}
