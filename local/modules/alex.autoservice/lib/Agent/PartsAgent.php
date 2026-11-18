<?php

namespace Alex\Autoservice\Agent;

use Alex\Autoservice\Service\Log;
use Alex\Autoservice\Service\PartsSync;
use Bitrix\Main\Loader;

/**
 * Ежедневный агент синхронизации остатков.
 *
 * На боевом портале агенты стоит переводить на крон: иначе запуск
 * зависит от посещаемости. Для учебного стенда hit-режима достаточно.
 */
class PartsAgent
{
    /** Строка запуска агента — её же возвращаем для следующего цикла */
    public const NAME = '\Alex\Autoservice\Agent\PartsAgent::run();';

    /**
     * @return string
     */
    public static function run(): string
    {
        if (!Loader::includeModule('alex.autoservice')) {
            return self::NAME;
        }

        try {
            $report = PartsSync::run();

            Log::add(['stage' => 'PARTS_AGENT', 'report' => $report]);
        } catch (\Throwable $e) {
            Log::add(['stage' => 'PARTS_AGENT_ERROR', 'error' => $e->getMessage()]);
        }

        return self::NAME;
    }
}
