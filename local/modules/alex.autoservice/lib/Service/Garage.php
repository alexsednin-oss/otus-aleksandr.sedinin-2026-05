<?php

namespace Alex\Autoservice\Service;

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Type\DateTime;

Loc::loadMessages(__FILE__);

/**
 * Данные для вкладки «Гараж» в карточке контакта.
 *
 * Список автомобилей берётся из таблицы модуля, история обращений — из сделок,
 * привязанных к автомобилю полем UF_CRM_CAR_ID. Выборка сделок идёт с учётом
 * прав текущего пользователя: механик по ТЗ видит только свои.
 */
class Garage
{
    /**
     * Автомобили клиента.
     *
     * @param int $contactId
     *
     * @return array
     */
    public static function getCars(int $contactId): array
    {
        if ($contactId <= 0) {
            return [];
        }

        return CarCrudService::getByContact($contactId);
    }

    /**
     * История обращений по автомобилю.
     *
     * @param int $carId
     *
     * @return array<int, array>
     */
    public static function getHistory(int $carId): array
    {
        if ($carId <= 0 || !Loader::includeModule('crm')) {
            return [];
        }

        $res = \CCrmDeal::GetListEx(
            ['DATE_CREATE' => 'DESC'],
            [CarCrudService::DEAL_CAR_FIELD => $carId],
            false,
            false,
            [
                'ID',
                'TITLE',
                'DATE_CREATE',
                'STAGE_ID',
                'CATEGORY_ID',
                'OPPORTUNITY',
                'CURRENCY_ID',
                'ASSIGNED_BY_ID',
                'ASSIGNED_BY_NAME',
                'ASSIGNED_BY_LAST_NAME',
                'ASSIGNED_BY_SECOND_NAME',
            ]
        );

        $deals      = [];
        $stageNames = [];

        while ($row = $res->Fetch()) {
            $categoryId = (int) $row['CATEGORY_ID'];

            if (!isset($stageNames[$categoryId])) {
                $stageNames[$categoryId] = self::getStageNames($categoryId);
            }

            $dateCreate = $row['DATE_CREATE'];

            $deals[] = [
                'ID'          => (int) $row['ID'],
                'TITLE'       => (string) $row['TITLE'],
                'DATE_CREATE' => $dateCreate instanceof DateTime
                    ? $dateCreate->format('d.m.Y H:i')
                    : (string) $dateCreate,
                'STAGE_ID'    => (string) $row['STAGE_ID'],
                'STAGE_NAME'  => $stageNames[$categoryId][$row['STAGE_ID']]['NAME']
                    ?? (string) $row['STAGE_ID'],
                'STAGE_COLOR' => $stageNames[$categoryId][$row['STAGE_ID']]['COLOR'] ?? '',
                'SUM'         => self::formatMoney($row['OPPORTUNITY'] ?? 0, (string) ($row['CURRENCY_ID'] ?? '')),
                'ASSIGNED_ID' => (int) $row['ASSIGNED_BY_ID'],
                'ASSIGNED'    => self::formatUserName($row),
                'ASSIGNED_URL' => '/company/personal/user/' . (int) $row['ASSIGNED_BY_ID'] . '/',
                'URL'          => '/crm/deal/details/' . (int) $row['ID'] . '/',
                'PRODUCTS'     => self::getProducts((int) $row['ID']),
            ];
        }

        return $deals;
    }

    /**
     * Заголовок попапа по ТЗ: «Марка Модель - Номер (Имя клиента)».
     *
     * @param array $car строка из CarCrudService::read()
     *
     * @return string
     */
    public static function getCaption(array $car): string
    {
        $title = trim((string) ($car['BRAND'] ?? '') . ' ' . (string) ($car['MODEL'] ?? ''));
        $owner = (string) ($car['OWNER'] ?? '');

        return $title . ' - ' . (string) ($car['NUMBER'] ?? '')
            . ($owner !== '' ? ' (' . $owner . ')' : '');
    }

    /**
     * Запчасти заказ-наряда — товарные позиции сделки.
     *
     * @param int $dealId
     *
     * @return array<int, array{NAME:string, QUANTITY:float}>
     */
    private static function getProducts(int $dealId): array
    {
        if (!class_exists('\CCrmProductRow')) {
            return [];
        }

        $rows     = \CCrmProductRow::LoadRows('D', $dealId);
        $products = [];

        foreach ((array) $rows as $row) {
            $products[] = [
                'NAME'     => (string) ($row['PRODUCT_NAME'] ?? ''),
                'QUANTITY' => (float) ($row['QUANTITY'] ?? 0),
            ];
        }

        return $products;
    }

    /**
     * Стадии воронки: идентификатор → название и цвет.
     *
     * @param int $categoryId
     *
     * @return array<string, array{NAME:string, COLOR:string}>
     */
    private static function getStageNames(int $categoryId): array
    {
        try {
            $entityId = DealFunnel::getStagesEntityId($categoryId);
        } catch (\Throwable $e) {
            return [];
        }

        $stages = [];

        foreach (Stages::getExisting($entityId) as $statusId => $row) {
            $stages[$statusId] = [
                'NAME'  => (string) $row['NAME'],
                'COLOR' => (string) ($row['COLOR'] ?? ''),
            ];
        }

        return $stages;
    }

    /**
     * @param array $row строка сделки
     *
     * @return string
     */
    private static function formatUserName(array $row): string
    {
        $name = trim(implode(' ', array_filter([
            (string) ($row['ASSIGNED_BY_LAST_NAME'] ?? ''),
            (string) ($row['ASSIGNED_BY_NAME'] ?? ''),
            (string) ($row['ASSIGNED_BY_SECOND_NAME'] ?? ''),
        ])));

        return $name !== '' ? $name : Loc::getMessage('ALEX_AUTOSERVICE_GARAGE_NO_ASSIGNED');
    }

    /**
     * Сумма обычным текстом.
     *
     * MoneyToString() отдаёт HTML с мнемониками (&nbsp;, &#8381;), а значение
     * уходит в JSON и экранируется на клиенте — мнемоники стали бы видны
     * как текст. Поэтому разворачиваем их здесь.
     *
     * @param mixed  $sum
     * @param string $currency
     *
     * @return string
     */
    private static function formatMoney($sum, string $currency): string
    {
        if (!class_exists('\CCrmCurrency')) {
            return number_format((float) $sum, 2, ',', ' ');
        }

        $formatted = (string) \CCrmCurrency::MoneyToString(
            (float) $sum,
            $currency ?: \CCrmCurrency::GetBaseCurrencyID()
        );

        return trim(html_entity_decode(strip_tags($formatted), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }


}
