<?php

namespace Alex\Autoservice\Service;

use Bitrix\Main\Config\Option;

/**
 * Файловый лог проекта: /local/logs/autoservice_<дата>.log
 *
 * Тот же приём, что применялся в домашних работах: дописка в файл через
 * print_r с разделителем. Пишем сюда миграции, синхронизацию остатков,
 * решения по заявкам и отказы в создании заказ-нарядов.
 */
class Log
{
    public const MODULE_ID   = 'alex.autoservice';
    public const OPTION_NAME = 'log_enabled';
    public const FILE_NAME   = 'autoservice';

    private const MAX_VALUE_LENGTH = 2000;

    private const SECRET_KEYS = ['PASSWORD', 'CONFIRM_PASSWORD', 'AUTH', 'TOKEN'];

    /**
     * @return bool
     */
    public static function isEnabled(): bool
    {
        return Option::get(self::MODULE_ID, self::OPTION_NAME, 'Y') === 'Y';
    }

    /**
     * @param mixed  $message
     * @param bool   $clear
     * @param string $fileName
     *
     * @return void
     */
    public static function add($message, bool $clear = false, string $fileName = self::FILE_NAME): void
    {
        if (!self::isEnabled()) {
            return;
        }

        $logFile = $_SERVER['DOCUMENT_ROOT'] . '/local/logs/' . $fileName . '_' . date('d.m.Y') . '.log';

        if (function_exists('CheckDirPath')) {
            \CheckDirPath($logFile);
        }

        $record = date('d.m.Y H:i:s') . "\n"
            . print_r(self::prepare($message), true) . "\n"
            . "---\n";

        file_put_contents($logFile, $record, $clear ? 0 : FILE_APPEND);
    }

    /**
     * Прячет секреты и обрезает длинные значения.
     *
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
