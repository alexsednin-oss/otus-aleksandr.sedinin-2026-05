<?php

namespace Otus\Iblock;

use Bitrix\Iblock\IblockTable;
use Bitrix\Main\Loader;

class Helper
{
    public static function getIblockIdByCode(?string $code): ?int
    {
        if (!Loader::includeModule('iblock')) {
            return null;
            }

        return IblockTable::getList([
            'filter' => [
                '=CODE' => $code,
            ]
        ])->fetch()['ID'];
    }
}