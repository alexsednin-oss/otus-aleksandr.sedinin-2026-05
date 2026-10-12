<?php

namespace Alex\Newmodule;

use Bitrix\Iblock\IblockTable;
use Bitrix\Iblock\PropertyTable;
use Bitrix\Main\Loader;

/**
 * Инфоблок «Заказы» (Сумма, Заказчик ИНН, Заказчик, Вид работ)
 * и шаблон бизнес-процесса «при создании элемента».
 */
class OrderIblockInstaller
{
    public const IBLOCK_CODE = 'alex_newmodule_orders';
    public const IBLOCK_NAME = 'Заказы';
    public const IBLOCK_TYPE = 'lists';

    public const TEMPLATE_NAME = 'Заказчик из DaData';
    public const ACTIVITY_TYPE = 'AlexDadataCompany';

    /** Свойства инфоблока: CODE => [NAME, PROPERTY_TYPE, SORT] */
    public const PROPERTIES = [
        'AMOUNT'       => ['Сумма', 'N', 100],
        'CUSTOMER_INN' => ['Заказчик ИНН', 'S', 200],
        'CUSTOMER'     => ['Заказчик', 'S', 300],
        'WORK_TYPE'    => ['Вид работ', 'S', 400],
    ];

    /**
     * @return string[] отчёт для страницы установки
     */
    public static function install(): array
    {
        Loader::includeModule('iblock');

        $report = [];

        $iblockId = self::createIblock();
        $report[] = 'Инфоблок «' . self::IBLOCK_NAME . '» готов, IBLOCK_ID = ' . $iblockId;

        $propertyIds = self::ensureProperties($iblockId);
        $report[]    = 'Свойства: ' . implode(', ', array_keys($propertyIds));

        try {
            $templateReport = self::createWorkflowTemplate($iblockId, $propertyIds);
            $report[]       = $templateReport;
        } catch (\Throwable $e) {
            $report[] = 'Шаблон бизнес-процесса не создан: ' . $e->getMessage()
                . '. Соберите его вручную в дизайнере (действие «' . self::TEMPLATE_NAME . '»).';
        }

        return $report;
    }

    public static function uninstall(): void
    {
        $iblockId = self::getIblockId();

        if (!$iblockId || !Loader::includeModule('bizproc')) {
            return;
        }

        $documentType = self::getDocumentType($iblockId);

        try {
            $res = \CBPWorkflowTemplateLoader::GetList(
                [],
                ['DOCUMENT_TYPE' => $documentType, '=NAME' => self::TEMPLATE_NAME],
                false,
                false,
                ['ID']
            );

            while ($row = $res->Fetch()) {
                \CBPWorkflowTemplateLoader::Delete((int) $row['ID']);
            }
        } catch (\Throwable $e) {
            // шаблон можно удалить вручную
        }
    }

    public static function getIblockId(): int
    {
        Loader::includeModule('iblock');

        $row = IblockTable::getList([
            'select' => ['ID'],
            'filter' => ['=CODE' => self::IBLOCK_CODE],
            'limit'  => 1,
        ])->fetch();

        return $row ? (int) $row['ID'] : 0;
    }

    // ------------------------------------------------------------- инфоблок

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
            'API_CODE'       => 'AlexOrders',
            'IBLOCK_TYPE_ID' => self::IBLOCK_TYPE,
            'SITE_ID'        => ['s1'],
            'GROUP_ID'       => ['2' => 'X'],
            'BIZPROC'        => 'Y', // без этого бизнес-процессы для инфоблока недоступны
            'WORKFLOW'       => 'N',
            'SORT'           => 600,
        ]);

        if (!$iblockId) {
            throw new \RuntimeException('Не удалось создать инфоблок «' . self::IBLOCK_NAME . '»: ' . $ib->LAST_ERROR);
        }

        return (int) $iblockId;
    }

    /**
     * @return array<string,int> CODE => PROPERTY_ID
     */
    private static function ensureProperties(int $iblockId): array
    {
        $result = [];

        foreach (self::PROPERTIES as $code => [$name, $type, $sort]) {
            $existing = PropertyTable::getList([
                'select' => ['ID'],
                'filter' => ['=IBLOCK_ID' => $iblockId, '=CODE' => $code],
                'limit'  => 1,
            ])->fetch();

            if ($existing) {
                $result[$code] = (int) $existing['ID'];

                continue;
            }

            $fields = [
                'IBLOCK_ID'     => $iblockId,
                'ACTIVE'        => 'Y',
                'NAME'          => $name,
                'CODE'          => $code,
                'PROPERTY_TYPE' => $type,
                'MULTIPLE'      => 'N',
                'SORT'          => $sort,
            ];

            // «Заказчик» — привязка к компании CRM, если такой тип свойства зарегистрирован
            if ($code === 'CUSTOMER' && self::isCrmPropertyTypeAvailable()) {
                $fields['USER_TYPE']          = 'ECrm';
                $fields['USER_TYPE_SETTINGS'] = [
                    'VISIBLE' => 'Y',
                    'LEAD'    => 'N',
                    'CONTACT' => 'N',
                    'COMPANY' => 'Y',
                    'DEAL'    => 'N',
                ];
            }

            $prop       = new \CIBlockProperty();
            $propertyId = (int) $prop->Add($fields);

            if (!$propertyId && isset($fields['USER_TYPE'])) {
                // тип «crm» не принял — создаём обычную строку
                unset($fields['USER_TYPE'], $fields['USER_TYPE_SETTINGS']);

                $prop       = new \CIBlockProperty();
                $propertyId = (int) $prop->Add($fields);
            }

            if (!$propertyId) {
                throw new \RuntimeException('Не удалось создать свойство ' . $code . ': ' . $prop->LAST_ERROR);
            }

            $result[$code] = $propertyId;
        }

        return $result;
    }

    public static function isCrmPropertyTypeAvailable(): bool
    {
        if (!Loader::includeModule('crm')) {
            return false;
        }

        $userType = \CIBlockProperty::GetUserType('ECrm');   // было 'crm'

        return is_array($userType) && !empty($userType);
    }

    /**
     * Свойство «Заказчик» хранит привязку к CRM (значение вида CO_12)?
     */
    public static function isCustomerCrmBound(int $iblockId): bool
    {
        Loader::includeModule('iblock');

        $row = PropertyTable::getList([
            'select' => ['USER_TYPE'],
            'filter' => ['=IBLOCK_ID' => $iblockId, '=CODE' => 'CUSTOMER'],
            'limit'  => 1,
        ])->fetch();

        return $row && (string) $row['USER_TYPE'] === 'ECrm';   // было 'crm'
    }

    // ------------------------------------------------------- бизнес-процесс

    /**
     * Тип документа для шаблона БП.
     * Списки (модуль lists) — BizprocDocument, обычные инфоблоки — CIBlockDocument.
     */
    public static function getDocumentType(int $iblockId): array
    {
        return ['iblock', 'CIBlockDocument', 'iblock_' . $iblockId];
    }

    private static function createWorkflowTemplate(int $iblockId, array $propertyIds): string
    {
        if (!Loader::includeModule('bizproc')) {
            throw new \RuntimeException('модуль bizproc не установлен');
        }

        $documentType = self::getDocumentType($iblockId);

        $existing = \CBPWorkflowTemplateLoader::GetList(
            [],
            ['DOCUMENT_TYPE' => $documentType, '=NAME' => self::TEMPLATE_NAME],
            false,
            false,
            ['ID']
        )->Fetch();

        if ($existing) {
            return 'Шаблон БП «' . self::TEMPLATE_NAME . '» уже существует, ID = ' . $existing['ID'];
        }

        $innField      = 'PROPERTY_' . $propertyIds['CUSTOMER_INN'];
        $customerField = 'PROPERTY_' . $propertyIds['CUSTOMER'];

        $template = [
            'Type'       => 'SequentialWorkflowActivity',
            'Name'       => 'Template',
            'Properties' => ['Title' => self::TEMPLATE_NAME],
            'Children'   => [
                [
                    'Type'       => self::ACTIVITY_TYPE,
                    'Name'       => 'AlexDadataCompany1',
                    'Properties' => [
                        'Title'             => 'Заполнить заказчика по ИНН',
                        'Inn'               => '{=Document:' . $innField . '}',
                        'CustomerFieldCode' => $customerField,
                        'CompanyType'       => 'CUSTOMER',
                    ],
                ],
            ],
        ];

        $templateId = \CBPWorkflowTemplateLoader::Add([
            'DOCUMENT_TYPE' => $documentType,
            'AUTO_EXECUTE'  => \CBPDocumentEventType::Create,
            'NAME'          => self::TEMPLATE_NAME,
            'DESCRIPTION'   => 'При создании элемента получает компанию по ИНН из DaData, '
                . 'создаёт её в CRM и записывает в поле «Заказчик»',
            'TEMPLATE'      => [$template],
            'PARAMETERS'    => [],
            'VARIABLES'     => [],
            'CONSTANTS'     => [],
            'ACTIVE'        => 'Y',
            'USER_ID'       => 1,
            'MODIFIER_USER' => new \CBPWorkflowTemplateUser(1),
        ]);

        if (!$templateId) {
            throw new \RuntimeException('CBPWorkflowTemplateLoader::Add вернул пустой ID');
        }

        return 'Шаблон БП «' . self::TEMPLATE_NAME . '» создан, ID = ' . $templateId
            . ' (запуск при создании элемента)';
    }
}
