<?php

namespace Alex\Autoservice\Migrations;

use Alex\Autoservice\Agent\PurchaseAgent;
use Alex\Autoservice\Migration\AbstractMigration;
use Alex\Autoservice\Service\PurchaseFlow;
use Alex\Autoservice\Service\SmartProcess;
use Alex\Autoservice\Service\UserField;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

/**
 * Согласование заявок: поля процесса и агент-обработчик.
 */
class Migration20261115090000PurchaseApproval extends AbstractMigration
{
    private const MODULE_ID = 'alex.autoservice';

    /** Как часто агент просматривает заявки, секунд */
    private const INTERVAL = 300;

    /** @var array<int, array{FIELD_NAME:string, USER_TYPE_ID:string, LABEL_CODE:string, SORT:int}> */
    private const FIELDS = [
        [
            'FIELD_NAME'   => 'UF_PURCHASE_APPROVER',
            'USER_TYPE_ID' => 'employee',
            'LABEL_CODE'   => 'ALEX_AUTOSERVICE_APPROVAL_FIELD_APPROVER',
            'SORT'         => 500,
        ],
        [
            'FIELD_NAME'   => 'UF_PURCHASE_APPROVAL_AT',
            'USER_TYPE_ID' => 'datetime',
            'LABEL_CODE'   => 'ALEX_AUTOSERVICE_APPROVAL_FIELD_AT',
            'SORT'         => 600,
        ],
        [
            'FIELD_NAME'   => 'UF_PURCHASE_ESCALATED',
            'USER_TYPE_ID' => 'boolean',
            'LABEL_CODE'   => 'ALEX_AUTOSERVICE_APPROVAL_FIELD_ESCALATED',
            'SORT'         => 700,
        ],
        [
            'FIELD_NAME'   => 'UF_PURCHASE_PROCESSED',
            'USER_TYPE_ID' => 'boolean',
            'LABEL_CODE'   => 'ALEX_AUTOSERVICE_APPROVAL_FIELD_PROCESSED',
            'SORT'         => 800,
        ],
    ];

    /**
     * @return string
     */
    public function getDescription(): string
    {
        return Loc::getMessage('ALEX_AUTOSERVICE_MIGRATION_APPROVAL_DESCRIPTION');
    }

    /**
     * @return void
     */
    public function up(): void
    {
        $this->requireModules('crm', 'catalog');

        $entityTypeId = (int) Option::get(self::MODULE_ID, 'purchase_type_id', 0);

        if (!$entityTypeId) {
            $this->fail(Loc::getMessage('ALEX_AUTOSERVICE_APPROVAL_NO_TYPE'));
        }

        $userFieldEntityId = SmartProcess::getUserFieldEntityId($entityTypeId);

        foreach (self::FIELDS as $field) {
            $field['LABEL'] = Loc::getMessage($field['LABEL_CODE']);

            $result = UserField::ensure($userFieldEntityId, $field);

            $this->note(Loc::getMessage(
                $result['CREATED'] ? 'ALEX_AUTOSERVICE_APPROVAL_FIELD_CREATED' : 'ALEX_AUTOSERVICE_APPROVAL_FIELD_EXISTS',
                ['#FIELD#' => $field['FIELD_NAME'], '#ID#' => $result['ID']]
            ));
        }

        // Срок ожидания согласования. В минутах, чтобы на демонстрации
        // можно было поставить пару минут и показать эскалацию в кадре
        if (Option::get(self::MODULE_ID, PurchaseFlow::OPTION_TIMEOUT, '') === '') {
            Option::set(self::MODULE_ID, PurchaseFlow::OPTION_TIMEOUT, PurchaseFlow::DEFAULT_TIMEOUT);
        }

        $this->note(Loc::getMessage('ALEX_AUTOSERVICE_APPROVAL_TIMEOUT', [
            '#MINUTES#' => Option::get(self::MODULE_ID, PurchaseFlow::OPTION_TIMEOUT),
        ]));

        if (\CAgent::GetList([], ['NAME' => PurchaseAgent::NAME, 'MODULE_ID' => self::MODULE_ID])->Fetch()) {
            $this->note(Loc::getMessage('ALEX_AUTOSERVICE_APPROVAL_AGENT_EXISTS'));

            return;
        }

        $agentId = \CAgent::AddAgent(
            PurchaseAgent::NAME,
            self::MODULE_ID,
            'N',
            self::INTERVAL,
            '',
            'Y',
            \ConvertTimeStamp(time() + self::INTERVAL, 'FULL'),
            100
        );

        $this->note($agentId
            ? Loc::getMessage('ALEX_AUTOSERVICE_APPROVAL_AGENT_CREATED', ['#ID#' => $agentId])
            : Loc::getMessage('ALEX_AUTOSERVICE_APPROVAL_AGENT_ERROR'));
    }

    /**
     * @return void
     */
    public function down(): void
    {
        $this->requireModules('crm');

        \CAgent::RemoveAgent(PurchaseAgent::NAME, self::MODULE_ID);

        $this->note(Loc::getMessage('ALEX_AUTOSERVICE_APPROVAL_AGENT_REMOVED'));

        $entityTypeId = (int) Option::get(self::MODULE_ID, 'purchase_type_id', 0);

        if ($entityTypeId) {
            try {
                $userFieldEntityId = SmartProcess::getUserFieldEntityId($entityTypeId);
                $removed           = 0;

                foreach (self::FIELDS as $field) {
                    if (UserField::remove($userFieldEntityId, $field['FIELD_NAME'])) {
                        $removed++;
                    }
                }

                $this->note(Loc::getMessage('ALEX_AUTOSERVICE_APPROVAL_FIELDS_REMOVED', ['#COUNT#' => $removed]));
            } catch (\Throwable $e) {
                $this->note($e->getMessage());
            }
        }

        Option::delete(self::MODULE_ID, ['name' => PurchaseFlow::OPTION_TIMEOUT]);
    }
}
