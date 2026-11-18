<?php

namespace Alex\Autoservice\Service;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Web\HttpClient;

Loc::loadMessages(__FILE__);

/**
 * Ежедневная синхронизация остатков запчастей с внешним сервисом.
 *
 * Сервис отдаёт текущее количество на складах — по одному числу на
 * позицию, одним запросом. При нулевом остатке запускается автозакупка.
 *
 * Главное правило: если сервис не ответил или вернул неполный набор,
 * остатки не трогаются вовсе. Иначе сетевой сбой обнулил бы склад
 * и автозакупка выстрелила бы по всему каталогу.
 */
class PartsSync
{
    private const MODULE_ID = 'alex.autoservice';

    public const OPTION_URL = 'parts_sync_url';

    private const DEFAULT_URL = 'https://www.random.org/integers/?num=#COUNT#&min=0&max=10&col=1&base=10&format=plain&rnd=new';

    /**
     * @return string[] отчёт
     */
    public static function run(): array
    {
        $report = [];
        $parts  = PartsCatalog::getParts();

        if (!$parts) {
            $report[] = Loc::getMessage('ALEX_AUTOSERVICE_SYNC_NO_PARTS');

            Log::add(['stage' => 'PARTS_SYNC', 'result' => 'no_parts']);

            return $report;
        }

        $quantities = self::fetchQuantities(count($parts));

        if ($quantities === null) {
            $report[] = Loc::getMessage('ALEX_AUTOSERVICE_SYNC_SERVICE_FAILED');

            Log::add(['stage' => 'PARTS_SYNC', 'result' => 'service_failed']);

            return $report;
        }

        if (count($quantities) < count($parts)) {
            $report[] = Loc::getMessage('ALEX_AUTOSERVICE_SYNC_SHORT_ANSWER', [
                '#GOT#'      => count($quantities),
                '#EXPECTED#' => count($parts),
            ]);

            Log::add([
                'stage'  => 'PARTS_SYNC',
                'result' => 'short_answer',
                'got'    => count($quantities),
            ]);

            return $report;
        }

        $zero = 0;

        foreach ($parts as $index => $part) {
            $quantity = (int) $quantities[$index];
            $previous = (int) $part['QUANTITY'];

            PartsCatalog::setQuantity((int) $part['ID'], $quantity);

            $line = Loc::getMessage('ALEX_AUTOSERVICE_SYNC_PART', [
                '#NAME#' => $part['NAME'],
                '#FROM#' => $previous,
                '#TO#'   => $quantity,
            ]);

            if ($quantity === 0) {
                $zero++;

                try {
                    $auto = Purchase::createAuto($part);

                    $line .= ' — ' . ($auto['SKIPPED']
                            ? Loc::getMessage('ALEX_AUTOSERVICE_SYNC_AUTO_SKIPPED')
                            : Loc::getMessage('ALEX_AUTOSERVICE_SYNC_AUTO_CREATED', [
                                '#ID#'       => $auto['ID'],
                                '#QUANTITY#' => $auto['QUANTITY'],
                            ]));
                } catch (\Throwable $e) {
                    $line .= ' — ' . Loc::getMessage('ALEX_AUTOSERVICE_SYNC_AUTO_ERROR', [
                            '#ERROR#' => $e->getMessage(),
                        ]);
                }
            }

            $report[] = $line;
        }

        $report[] = Loc::getMessage('ALEX_AUTOSERVICE_SYNC_DONE', [
            '#COUNT#' => count($parts),
            '#ZERO#'  => $zero,
        ]);

        Log::add([
            'stage'  => 'PARTS_SYNC',
            'result' => 'ok',
            'parts'  => count($parts),
            'zero'   => $zero,
        ]);

        return $report;
    }

    /**
     * Количества из внешнего сервиса.
     *
     * @param int $count сколько чисел нужно
     *
     * @return int[]|null null — сервис недоступен или ответ не распознан
     */
    public static function fetchQuantities(int $count): ?array
    {
        $url = str_replace('#COUNT#', (string) $count, self::getUrl());

        $http = new HttpClient([
            'socketTimeout' => 10,
            'streamTimeout' => 20,
            'waitResponse'  => true,
        ]);

        $body = $http->get($url);

        if ($http->getStatus() !== 200 || $body === false) {
            Log::add([
                'stage'  => 'PARTS_SYNC_FETCH',
                'url'    => $url,
                'status' => $http->getStatus(),
                'error'  => implode(' ', $http->getError()),
            ]);

            return null;
        }

        $lines      = array_filter(array_map('trim', explode("\n", (string) $body)), 'strlen');
        $quantities = [];

        foreach ($lines as $line) {
            if (!is_numeric($line)) {
                Log::add(['stage' => 'PARTS_SYNC_FETCH', 'unexpected' => mb_substr($line, 0, 100)]);

                return null;
            }

            $quantities[] = (int) $line;
        }

        return $quantities;
    }

    /**
     * @return string шаблон URL с подстановкой #COUNT#
     */
    public static function getUrl(): string
    {
        $url = (string) Option::get(self::MODULE_ID, self::OPTION_URL, '');

        return $url !== '' ? $url : self::DEFAULT_URL;
    }
}
