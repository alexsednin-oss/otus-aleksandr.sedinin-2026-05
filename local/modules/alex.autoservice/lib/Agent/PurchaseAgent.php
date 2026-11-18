<?php

namespace Alex\Autoservice\Agent;

use Alex\Autoservice\Service\Log;
use Alex\Autoservice\Service\PurchaseFlow;
use Bitrix\Main\Loader;

/**
 * Агент обработки заявок на закупку.
 */
class PurchaseAgent
{
    public const NAME = '\Alex\Autoservice\Agent\PurchaseAgent::run();';

    /**
     * @return string
     */
    public static function run(): string
    {
        if (!Loader::includeModule('alex.autoservice')) {
            return self::NAME;
        }

        try {
            PurchaseFlow::process();
        } catch (\Throwable $e) {
            Log::add(['stage' => 'PURCHASE_AGENT_ERROR', 'error' => $e->getMessage()]);
        }

        return self::NAME;
    }
}
