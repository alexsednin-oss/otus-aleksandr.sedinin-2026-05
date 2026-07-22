<?php

namespace App\ORMDZ\Model;

use Bitrix\Main\Config\Option;
use Bitrix\Iblock\Iblock;

/**
 * Базовый класс для создания инфоблоков
 */

abstract class AbstractMeatShopElementTable
{
    /**
     * @var string
     */
    protected static string $optionCode = '';

    /**
     * @return int
     */
    public static function getIblockId(): int
    {
        return (int) Option::get('main', static::$optionCode, 0);
    }

    /**
     * @return string
     */
    public static function getEntityDataClass(): string
    {
        $iblockId = static::getIblockId();

        if(!$iblockId) {
            throw new \RuntimeException('Инфоблок ' . static::class . 'не найден');
        }

        return Iblock::wakeUp($iblockId)->getEntityDataClass();
    }

    /**
     * @param array $parameters
     *
     * @return mixed
     */
    public static function getList(array $parameters = []): mixed
    {
        $entityClass = static::getEntityDataClass();

        return $entityClass::getList($parameters);
    }
}
