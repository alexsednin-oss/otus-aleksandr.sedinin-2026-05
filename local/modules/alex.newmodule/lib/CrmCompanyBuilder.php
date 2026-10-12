<?php

namespace Alex\Newmodule;

use Bitrix\Main\Loader;

/**
 * Создание компании в CRM по данным DaData вместе с реквизитами.
 *
 * API реквизитов (EntityRequisite/EntityPreset) в разных версиях CRM отличается,
 * поэтому каждый необязательный шаг обёрнут проверками: если реквизиты заполнить
 * не удалось, компания всё равно создаётся, а причина возвращается в MESSAGES
 * и попадает в журнал бизнес-процесса.
 */
class CrmCompanyBuilder
{
    /**
     * @param array $suggestion подсказка DaData: ['value' => ..., 'data' => [...]]
     *
     * @return array{ID:int, TITLE:string, INN:string, REQUISITE_ID:int, EXISTED:bool, MESSAGES:string[]}
     *
     * @throws \RuntimeException
     */
    public static function createFromDaData(array $suggestion, string $companyType = 'CUSTOMER'): array
    {
        if (!Loader::includeModule('crm')) {
            throw new \RuntimeException('Модуль CRM не установлен');
        }

        $data = $suggestion['data'] ?? [];
        $inn  = (string) ($data['inn'] ?? '');

        if ($inn === '') {
            throw new \RuntimeException('В ответе DaData нет ИНН');
        }

        $messages = [];
        $title    = self::resolveTitle($suggestion);

        $existingId = self::findCompanyByInn($inn);

        if ($existingId > 0) {
            return [
                'ID'           => $existingId,
                'TITLE'        => self::getCompanyTitle($existingId) ?: $title,
                'INN'          => $inn,
                'REQUISITE_ID' => 0,
                'EXISTED'      => true,
                'MESSAGES'     => ['Компания с ИНН ' . $inn . ' уже есть в CRM (ID ' . $existingId . '), создание пропущено'],
            ];
        }

        $companyId = self::addCompany($title, $data, $companyType);

        $requisiteId = 0;

        try {
            $requisiteId = self::addRequisite($companyId, $suggestion);

            if (!$requisiteId) {
                $messages[] = 'Реквизиты не созданы: не найден шаблон (пресет) реквизитов';
            }
        } catch (\Throwable $e) {
            $messages[] = 'Реквизиты не созданы: ' . $e->getMessage();
        }

        return [
            'ID'           => $companyId,
            'TITLE'        => $title,
            'INN'          => $inn,
            'REQUISITE_ID' => $requisiteId,
            'EXISTED'      => false,
            'MESSAGES'     => $messages,
        ];
    }

    // ---------------------------------------------------------------- компания

    private static function resolveTitle(array $suggestion): string
    {
        $data = $suggestion['data'] ?? [];

        $title = (string) ($data['name']['short_with_opf'] ?? '');

        if ($title === '') {
            $title = (string) ($data['name']['full_with_opf'] ?? '');
        }

        if ($title === '') {
            $title = (string) ($suggestion['value'] ?? '');
        }

        if ($title === '') {
            $title = 'Компания ИНН ' . ($data['inn'] ?? '');
        }

        return $title;
    }

    private static function addCompany(string $title, array $data, string $companyType): int
    {
        global $USER;

        $fields = [
            'TITLE'        => $title,
            'COMPANY_TYPE' => $companyType,
            'OPENED'       => 'Y',
            'COMMENTS'     => self::buildComments($data),
        ];

        if (is_object($USER) && $USER->IsAuthorized()) {
            $fields['ASSIGNED_BY_ID'] = (int) $USER->GetID();
        }

        $address = (string) ($data['address']['unrestricted_value'] ?? $data['address']['value'] ?? '');

        if ($address !== '') {
            $fields['ADDRESS']        = $address;
            $fields['ADDRESS_LEGAL']  = $address;
        }

        $entity = new \CCrmCompany(false);

        $companyId = (int) $entity->Add(
            $fields,
            true,
            [
                'DISABLE_USER_FIELD_CHECK' => true,
                'REGISTER_SONET_EVENT'     => false,
            ]
        );

        if (!$companyId) {
            throw new \RuntimeException('Не удалось создать компанию: ' . $entity->LAST_ERROR);
        }

        return $companyId;
    }

    private static function buildComments(array $data): string
    {
        $lines = [];

        $map = [
            'ОГРН'         => $data['ogrn'] ?? '',
            'ОКПО'         => $data['okpo'] ?? '',
            'ОКВЭД'        => $data['okved'] ?? '',
            'Руководитель' => $data['management']['name'] ?? '',
            'Должность'    => $data['management']['post'] ?? '',
            'Статус'       => $data['state']['status'] ?? '',
        ];

        foreach ($map as $label => $value) {
            $value = trim((string) $value);

            if ($value !== '') {
                $lines[] = $label . ': ' . $value;
            }
        }

        if ($lines) {
            array_unshift($lines, 'Данные получены из DaData:');
        }

        return implode("\n", $lines);
    }

    private static function getCompanyTitle(int $companyId): string
    {
        $row = \CCrmCompany::GetListEx(
            [],
            ['=ID' => $companyId, 'CHECK_PERMISSIONS' => 'N'],
            false,
            false,
            ['ID', 'TITLE']
        )->Fetch();

        return $row ? (string) $row['TITLE'] : '';
    }

    // --------------------------------------------------------------- реквизиты

    /**
     * Поиск компании по ИНН среди реквизитов — чтобы не плодить дубли.
     */
    public static function findCompanyByInn(string $inn): int
    {
        if ($inn === '' || !class_exists('\Bitrix\Crm\EntityRequisite')) {
            return 0;
        }

        try {
            $requisite = new \Bitrix\Crm\EntityRequisite();

            $row = $requisite->getList([
                'select' => ['ENTITY_ID'],
                'filter' => [
                    '=ENTITY_TYPE_ID' => \CCrmOwnerType::Company,
                    '=RQ_INN'         => $inn,
                ],
                'limit'  => 1,
            ])->fetch();
        } catch (\Throwable $e) {
            return 0;
        }

        return $row ? (int) $row['ENTITY_ID'] : 0;
    }

    private static function addRequisite(int $companyId, array $suggestion): int
    {
        if (!class_exists('\Bitrix\Crm\EntityRequisite')) {
            throw new \RuntimeException('класс EntityRequisite недоступен в этой версии CRM');
        }

        $presetId = self::getPresetId();

        if (!$presetId) {
            return 0;
        }

        $data         = $suggestion['data'] ?? [];
        $isIndividual = (string) ($data['type'] ?? '') === 'INDIVIDUAL';
        $title        = self::resolveTitle($suggestion);

        $fields = [
            'ENTITY_TYPE_ID' => \CCrmOwnerType::Company,
            'ENTITY_ID'      => $companyId,
            'PRESET_ID'      => $presetId,
            'NAME'           => $title,
            'ACTIVE'         => 'Y',
            'SORT'           => 500,
            'RQ_INN'         => (string) ($data['inn'] ?? ''),
        ];

        if ($isIndividual) {
            $fio = preg_split('/\s+/', trim((string) ($data['name']['full'] ?? $title)));

            $fields['RQ_LAST_NAME']    = $fio[0] ?? '';
            $fields['RQ_FIRST_NAME']   = $fio[1] ?? '';
            $fields['RQ_SECOND_NAME']  = $fio[2] ?? '';
            $fields['RQ_OGRNIP']       = (string) ($data['ogrn'] ?? '');
        } else {
            $fields['RQ_COMPANY_NAME']      = (string) ($data['name']['short_with_opf'] ?? $title);
            $fields['RQ_COMPANY_FULL_NAME'] = (string) ($data['name']['full_with_opf'] ?? '');
            $fields['RQ_KPP']               = (string) ($data['kpp'] ?? '');
            $fields['RQ_OGRN']              = (string) ($data['ogrn'] ?? '');
            $fields['RQ_DIRECTOR']          = (string) ($data['management']['name'] ?? '');
        }

        $fields['RQ_OKPO'] = (string) ($data['okpo'] ?? '');

        $fields = array_filter($fields, static function ($value) {
            return $value !== '' && $value !== null;
        });

        $requisite = new \Bitrix\Crm\EntityRequisite();
        $result    = $requisite->add($fields);

        if (!$result->isSuccess()) {
            throw new \RuntimeException(implode('; ', $result->getErrorMessages()));
        }

        $requisiteId = (int) $result->getId();

        self::addRequisiteAddress($requisiteId, $data);

        return $requisiteId;
    }

    private static function getPresetId(): int
    {
        if (!class_exists('\Bitrix\Crm\EntityPreset')) {
            return 0;
        }

        try {
            $preset = new \Bitrix\Crm\EntityPreset();

            $row = $preset->getList([
                'select' => ['ID'],
                'filter' => [
                    '=ENTITY_TYPE_ID' => \Bitrix\Crm\EntityPreset::Requisite,
                    '=ACTIVE'         => 'Y',
                    '=COUNTRY_ID'     => 1,
                ],
                'order'  => ['SORT' => 'ASC', 'ID' => 'ASC'],
                'limit'  => 1,
            ])->fetch();
        } catch (\Throwable $e) {
            return 0;
        }

        return $row ? (int) $row['ID'] : 0;
    }

    /**
     * Юридический адрес реквизита. Класс адреса переезжал между namespace,
     * поэтому пробуем оба и молча пропускаем, если ни одного нет.
     */
    private static function addRequisiteAddress(int $requisiteId, array $data): void
    {
        $address = (string) ($data['address']['unrestricted_value'] ?? $data['address']['value'] ?? '');

        if ($requisiteId <= 0 || $address === '') {
            return;
        }

        $addressClass = null;

        foreach (['\Bitrix\Crm\EntityAddress', '\Bitrix\Crm\Requisite\EntityAddress'] as $class) {
            if (class_exists($class) && method_exists($class, 'register')) {
                $addressClass = $class;
                break;
            }
        }

        if ($addressClass === null) {
            return;
        }

        $typeId = 1; // Registered / юридический

        foreach (['\Bitrix\Crm\EntityAddressType', '\Bitrix\Crm\Requisite\EntityAddressType'] as $class) {
            if (class_exists($class) && defined($class . '::Registered')) {
                $typeId = constant($class . '::Registered');
                break;
            }
        }

        $fields = [
            'ADDRESS_1'   => $address,
            'CITY'        => (string) ($data['address']['data']['city'] ?? ''),
            'POSTAL_CODE' => (string) ($data['address']['data']['postal_code'] ?? ''),
            'COUNTRY'     => 'Россия',
        ];

        try {
            $addressClass::register(
                \CCrmOwnerType::Requisite,
                $requisiteId,
                $typeId,
                array_filter($fields)
            );
        } catch (\Throwable $e) {
            // адрес — необязательная часть, молча пропускаем
        }
    }
}
