<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

use Alex\Newmodule\CrmCompanyBuilder;
use Alex\Newmodule\DaDataClient;
use Alex\Newmodule\OrderIblockInstaller;
use Bitrix\Main\Loader;

/**
 * Действие бизнес-процесса: заказчик из DaData.
 *
 * По ИНН получает данные организации, создаёт компанию в CRM с реквизитами
 * и записывает её в поле документа «Заказчик».
 */
class CBPAlexDadataCompany extends CBPActivity
{
    public function __construct($name)
    {
        parent::__construct($name);

        $this->arProperties = [
            'Title' => '',

            // входные
            'Inn' => '',
            'CustomerFieldCode' => '',
            'CompanyType' => 'CUSTOMER',

            // возвращаемые
            'CompanyId' => 0,
            'CompanyTitle' => '',
            'ErrorMessage' => '',
        ];
    }

    public function Execute()
    {
        $this->CompanyId = 0;
        $this->CompanyTitle = '';
        $this->ErrorMessage = '';

        if (!Loader::includeModule('alex.newmodule')) {
            return $this->fail('Модуль alex.newmodule не установлен');
        }

        $inn = trim((string)$this->Inn);

        if ($inn === '') {
            return $this->fail('ИНН заказчика не заполнен — компания не создавалась');
        }

        try {
            $suggestion = DaDataClient::findByInn($inn);
        } catch (\Throwable $e) {
            return $this->fail('DaData: ' . $e->getMessage());
        }

        if (!$suggestion) {
            return $this->fail('DaData: организация с ИНН ' . $inn . ' не найдена');
        }

        try {
            $company = CrmCompanyBuilder::createFromDaData(
                $suggestion,
                (string)($this->CompanyType ?: 'CUSTOMER')
            );
        } catch (\Throwable $e) {
            return $this->fail('CRM: ' . $e->getMessage());
        }

        $this->CompanyId = $company['ID'];
        $this->CompanyTitle = $company['TITLE'];

        foreach ($company['MESSAGES'] as $message) {
            $this->WriteToTrackingService($message);
        }

        $this->WriteToTrackingService(
            ($company['EXISTED'] ? 'Найдена компания: ' : 'Создана компания: ')
            . $company['TITLE'] . ' (ID ' . $company['ID'] . ', ИНН ' . $company['INN'] . ')'
        );

        $this->updateCustomerField($company);

        return CBPActivityExecutionStatus::Closed;
    }

    /**
     * Запись компании в поле документа «Заказчик».
     */
    private function updateCustomerField(array $company): void
    {
        $fieldCode = trim((string) $this->CustomerFieldCode);

        if ($fieldCode === '') {
            $this->WriteToTrackingService('Поле «Заказчик» не указано в настройках действия — обновление пропущено');

            return;
        }

        $documentId = $this->GetDocumentId();
        $elementId  = (int) ($documentId[2] ?? 0);
        $iblockId   = OrderIblockInstaller::getIblockId();

        if (!$elementId || !$iblockId) {
            $this->WriteToTrackingService('Не удалось определить элемент для обновления поля «Заказчик»');

            return;
        }

        // Привязка к CRM хранится как CO_<id>, обычная строка — как название
        $value = $company['TITLE'];

        if (OrderIblockInstaller::isCustomerCrmBound($iblockId)) {
            $value = \CCrmOwnerTypeAbbr::ResolveByTypeName('COMPANY') . '_' . $company['ID'];
        }

        // SetPropertyValuesEx принимает и код свойства, и его ID — снимаем префикс PROPERTY_
        $propertyKey = preg_replace('/^PROPERTY_/', '', $fieldCode);

        try {
            \CIBlockElement::SetPropertyValuesEx($elementId, $iblockId, [$propertyKey => $value]);
        } catch (\Throwable $e) {
            $this->WriteToTrackingService('Не удалось обновить поле «Заказчик»: ' . $e->getMessage());

            return;
        }

        $this->WriteToTrackingService('Поле «Заказчик» обновлено: ' . $company['TITLE'] . ' (' . $value . ')');
    }

    private function fail(string $message): int
    {
        $this->ErrorMessage = $message;
        $this->WriteToTrackingService($message);

        return CBPActivityExecutionStatus::Closed;
    }

    // ------------------------------------------------- диалог настроек в дизайнере

    public static function GetPropertiesDialog(
        $documentType,
        $activityName,
        $arWorkflowTemplate,
        $arWorkflowParameters,
        $arWorkflowVariables,
        $arCurrentValues = null,
        $formName = ''
    )
    {
        $runtime = CBPRuntime::GetRuntime();

        if (!is_array($arCurrentValues)) {
            $arCurrentActivity = &CBPWorkflowTemplateLoader::FindActivityByName($arWorkflowTemplate, $activityName);

            $arCurrentValues = [
                'inn_field' => $arCurrentActivity['Properties']['Inn'] ?? '',
                'customer_field' => $arCurrentActivity['Properties']['CustomerFieldCode'] ?? '',
                'company_type' => $arCurrentActivity['Properties']['CompanyType'] ?? 'CUSTOMER',
            ];
        }

        return $runtime->ExecuteResourceFile(
            __FILE__,
            'properties_dialog.php',
            [
                'arCurrentValues' => $arCurrentValues,
                'formName' => $formName,
            ]
        );
    }

    public static function GetPropertiesDialogValues(
        $documentType,
        $activityName,
        &$arWorkflowTemplate,
        &$arWorkflowParameters,
        &$arWorkflowVariables,
        $arCurrentValues,
        &$arErrors
    )
    {
        $arErrors = [];

        $properties = [
            'Inn' => trim((string)($arCurrentValues['inn_field'] ?? '')),
            'CustomerFieldCode' => trim((string)($arCurrentValues['customer_field'] ?? '')),
            'CompanyType' => trim((string)($arCurrentValues['company_type'] ?? 'CUSTOMER')),
        ];

        if ($properties['Inn'] === '') {
            $arErrors[] = [
                'code' => 'NotExist',
                'parameter' => 'inn_field',
                'message' => 'Не указан ИНН заказчика',
            ];

            return false;
        }

        $arCurrentActivity = &CBPWorkflowTemplateLoader::FindActivityByName($arWorkflowTemplate, $activityName);
        $arCurrentActivity['Properties'] = $properties;

        return true;
    }
}
