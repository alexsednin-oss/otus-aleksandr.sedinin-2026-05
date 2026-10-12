<?php

namespace Alex\Newmodule;

use Bitrix\Iblock\IblockTable;
use Bitrix\Iblock\PropertyTable;
use Bitrix\Main\Loader;

/**
 * Инфоблок «Заявки»: Сделка, Сумма, Ответственный.
 *
 * Свойства создаются с «красивыми» типами (привязка к сделке CRM, привязка
 * к пользователю), но если такой пользовательский тип в установке не
 * зарегистрирован — создаётся обычное число. Синхронизация умеет читать оба
 * варианта, см. DealRequestSync::toId().
 */
class RequestIblockInstaller
{
    public const IBLOCK_CODE = 'alex_newmodule_requests';
    public const IBLOCK_NAME = 'Заявки';
    public const IBLOCK_TYPE = 'lists';

    public const PROP_DEAL        = 'DEAL';
    public const PROP_AMOUNT      = 'AMOUNT';
    public const PROP_ASSIGNED_BY = 'ASSIGNED_BY';

    /** Кэш ID инфоблока в рамках запроса */
    private static ?int $iblockId = null;

    /**
     * @return string[] отчёт
     */
    public static function install(): array
    {
        Loader::includeModule('iblock');

        $report = [];

        $iblockId = self::createIblock();
        $report[] = 'Инфоблок «' . self::IBLOCK_NAME . '» готов, IBLOCK_ID = ' . $iblockId;

        $properties = self::ensureProperties($iblockId);

        foreach ($properties as $code => $info) {
            $report[] = 'Свойство ' . $code . ' (ID ' . $info['ID'] . ', тип ' . $info['TYPE'] . ')';
        }

        self::$iblockId = $iblockId;

        return $report;
    }

    public static function getIblockId(): int
    {
        if (self::$iblockId !== null) {
            return self::$iblockId;
        }

        Loader::includeModule('iblock');

        $row = IblockTable::getList([
            'select' => ['ID'],
            'filter' => ['=CODE' => self::IBLOCK_CODE],
            'limit'  => 1,
        ])->fetch();

        self::$iblockId = $row ? (int) $row['ID'] : 0;

        return self::$iblockId;
    }

    private static function createIblock(): int
    {
        $existingId = self::getIblockId();

        if ($existingId) {
            return $existingId;
        }

        $type = \CIBlockType::GetByID(self::IBLOCK_TYPE)->Fetch();

        if (!$type) {
            throw new \RuntimeException('Тип инфоблока "' . self::IBLOCK_TYPE . '" не найден');
        }

        $ib = new \CIBlock();

        $iblockId = $ib->Add([
            'ACTIVE'         => 'Y',
            'NAME'           => self::IBLOCK_NAME,
            'CODE'           => self::IBLOCK_CODE,
            'API_CODE'       => 'AlexRequests',
            'IBLOCK_TYPE_ID' => self::IBLOCK_TYPE,
            'SITE_ID'        => ['s1'],
            'GROUP_ID'       => ['2' => 'X'],
            'SORT'           => 700,
        ]);

        if (!$iblockId) {
            throw new \RuntimeException('Не удалось создать инфоблок «' . self::IBLOCK_NAME . '»: ' . $ib->LAST_ERROR);
        }

        self::$iblockId = (int) $iblockId;

        return self::$iblockId;
    }

    /**
     * @return array<string,array{ID:int,TYPE:string}>
     */
    private static function ensureProperties(int $iblockId): array
    {
        $definitions = [
            self::PROP_DEAL => [
                'NAME'      => 'Сделка',
                'TYPE'      => 'N',
                'SORT'      => 100,
                'USER_TYPE' => self::isUserTypeAvailable('ECrm') ? 'ECrm' : '',
                'SETTINGS'  => [
                    'VISIBLE' => 'Y',
                    'LEAD'    => 'N',
                    'CONTACT' => 'N',
                    'COMPANY' => 'N',
                    'DEAL'    => 'Y',
                ],
            ],
            self::PROP_AMOUNT => [
                'NAME' => 'Сумма',
                'TYPE' => 'N',
                'SORT' => 200,
            ],
            self::PROP_ASSIGNED_BY => [
                'NAME'      => 'Ответственный',
                'TYPE'      => 'N',
                'SORT'      => 300,
                'USER_TYPE' => self::isUserTypeAvailable('UserID') ? 'UserID' : '',
            ],
        ];

        $result = [];

        foreach ($definitions as $code => $definition) {
            $existing = PropertyTable::getList([
                'select' => ['ID', 'PROPERTY_TYPE', 'USER_TYPE'],
                'filter' => ['=IBLOCK_ID' => $iblockId, '=CODE' => $code],
                'limit'  => 1,
            ])->fetch();

            if ($existing) {
                $result[$code] = [
                    'ID'   => (int) $existing['ID'],
                    'TYPE' => $existing['USER_TYPE'] ?: $existing['PROPERTY_TYPE'],
                ];

                continue;
            }

            $userType = (string) ($definition['USER_TYPE'] ?? '');

            // Привязка к сделке — строковое свойство с пользовательским типом,
            // обычный фолбэк — число с ID сделки
            $propertyType = $userType !== '' ? 'S' : $definition['TYPE'];

            $fields = [
                'IBLOCK_ID'     => $iblockId,
                'ACTIVE'        => 'Y',
                'NAME'          => $definition['NAME'],
                'CODE'          => $code,
                'PROPERTY_TYPE' => $propertyType,
                'MULTIPLE'      => 'N',
                'SORT'          => $definition['SORT'],
            ];

            if ($userType !== '') {
                $fields['USER_TYPE'] = $userType;

                if (!empty($definition['SETTINGS'])) {
                    $fields['USER_TYPE_SETTINGS'] = $definition['SETTINGS'];
                }
            }

            $prop       = new \CIBlockProperty();
            $propertyId = (int) $prop->Add($fields);

            if (!$propertyId && $userType !== '') {
                // пользовательский тип не принят — создаём простое число
                unset($fields['USER_TYPE'], $fields['USER_TYPE_SETTINGS']);
                $fields['PROPERTY_TYPE'] = $definition['TYPE'];
                $userType                = '';

                $prop       = new \CIBlockProperty();
                $propertyId = (int) $prop->Add($fields);
            }

            if (!$propertyId) {
                throw new \RuntimeException('Не удалось создать свойство ' . $code . ': ' . $prop->LAST_ERROR);
            }

            $result[$code] = [
                'ID'   => $propertyId,
                'TYPE' => $userType !== '' ? $userType : $definition['TYPE'],
            ];
        }

        return $result;
    }

    private static function isUserTypeAvailable(string $userType): bool
    {
        if ($userType === 'ECrm' && !Loader::includeModule('crm')) {   // было 'crm'
            return false;
        }

        $description = \CIBlockProperty::GetUserType($userType);

        return is_array($description) && !empty($description);
    }
}
