<?php

namespace Alex\Newmodule\Rest;

use Bitrix\Iblock\ElementTable;
use Bitrix\Main\Loader;

/**
 * Поиск элемента инфоблока по наименованию с созданием при отсутствии.
 */
class IblockElementResolver
{
    /**
     * @return array{ID:int, NAME:string, CREATED:bool}
     */
    public static function resolve(int $iblockId, string $name, string $title): array
    {
        if (!Loader::includeModule('iblock')) {
            throw new \RuntimeException('не установлен модуль iblock');
        }

        $name = trim($name);

        if ($name === '') {
            throw new \InvalidArgumentException('пустое наименование: ' . $title);
        }

        if (!$iblockId) {
            throw new \RuntimeException(
                'инфоблок «' . $title . '» не найден — переустановите модуль alex.newmodule'
            );
        }

        $existing = self::findByName($iblockId, $name);

        if ($existing) {
            return ['ID' => $existing, 'NAME' => $name, 'CREATED' => false];
        }

        return ['ID' => self::create($iblockId, $name), 'NAME' => $name, 'CREATED' => true];
    }

    private static function findByName(int $iblockId, string $name): int
    {
        // Неактивные тоже учитываем, иначе при повторном создании получим дубль
        $row = ElementTable::query()
            ->setSelect(['ID'])
            ->where('IBLOCK_ID', $iblockId)
            ->where('NAME', $name)
            ->setOrder(['ID' => 'ASC'])
            ->setLimit(1)
            ->fetch();

        return $row ? (int) $row['ID'] : 0;
    }

    private static function create(int $iblockId, string $name): int
    {
        $element = new \CIBlockElement();

        $id = (int) $element->Add([
            'IBLOCK_ID'         => $iblockId,
            'NAME'              => $name,
            'CODE'              => \CUtil::translit($name, 'ru', [
                'replace_space' => '-',
                'replace_other' => '-',
                'change_case'   => 'L',
            ]),
            'ACTIVE'            => 'Y',
            'SORT'              => 500,
            'IBLOCK_SECTION_ID' => false,
        ]);

        if (!$id) {
            throw new \RuntimeException(
                'не удалось создать элемент «' . $name . '» в инфоблоке ' . $iblockId . ': ' . $element->LAST_ERROR
            );
        }

        return $id;
    }
}
