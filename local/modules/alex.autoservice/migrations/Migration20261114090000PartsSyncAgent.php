<?php

namespace Alex\Autoservice\Migrations;

use Alex\Autoservice\Agent\PartsAgent;
use Alex\Autoservice\Migration\AbstractMigration;
use Alex\Autoservice\Service\SmartProcess;
use Alex\Autoservice\Service\UserField;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

/**
 * Автозакупка: поле связи заявки с запчастью и ежедневный агент.
 *
 * Поле UF_PURCHASE_PRODUCT добавляется отдельной миграцией, а не правкой
 * предыдущей: применённые миграции не переписывают, иначе на портале,
 * где они уже прошли, изменение просто не появится.
 */
class Migration20261114090000PartsSyncAgent extends AbstractMigration
{
    private const MODULE_ID = 'alex.autoservice';

    private const FIELD_NAME = 'UF_PURCHASE_PRODUCT';

    /** Раз в сутки */
    private const INTERVAL = 86400;

    /**
     * @return string
     */
    public function getDescription(): string
    {
        return Loc::getMessage('ALEX_AUTOSERVICE_MIGRATION_SYNC_DESCRIPTION');
    }

    /**
     * @return void
     */
    public function up(): void
    {
        $this->requireModules('crm', 'catalog');

        // ------------------------------------------- поле связи с запчастью

        $entityTypeId = (int) Option::get(self::MODULE_ID, 'purchase_type_id', 0);

        if (!$entityTypeId) {
            $this->fail(Loc::getMessage('ALEX_AUTOSERVICE_SYNC_NO_PURCHASE_TYPE'));
        }

        $field = UserField::ensure(SmartProcess::getUserFieldEntityId($entityTypeId), [
            'FIELD_NAME'   => self::FIELD_NAME,
            'USER_TYPE_ID' => 'integer',
            'LABEL'        => Loc::getMessage('ALEX_AUTOSERVICE_SYNC_FIELD_PRODUCT'),
            'SORT'         => 400,
            'SHOW_FILTER'  => 'E',
        ]);

        $this->note(Loc::getMessage(
            $field['CREATED'] ? 'ALEX_AUTOSERVICE_SYNC_FIELD_CREATED' : 'ALEX_AUTOSERVICE_SYNC_FIELD_EXISTS',
            ['#FIELD#' => self::FIELD_NAME, '#ID#' => $field['ID']]
        ));

        // ------------------------------------------------------------ агент

        if (self::findAgent()) {
            $this->note(Loc::getMessage('ALEX_AUTOSERVICE_SYNC_AGENT_EXISTS'));

            return;
        }

        $agentId = \CAgent::AddAgent(
            PartsAgent::NAME,
            self::MODULE_ID,
            'N',
            self::INTERVAL,
            '',
            'Y',
            \ConvertTimeStamp(time() + self::INTERVAL, 'FULL'),
            100
        );

        $this->note($agentId
            ? Loc::getMessage('ALEX_AUTOSERVICE_SYNC_AGENT_CREATED', ['#ID#' => $agentId])
            : Loc::getMessage('ALEX_AUTOSERVICE_SYNC_AGENT_ERROR'));
    }

    /**
     * @return void
     */
    public function down(): void
    {
        $this->requireModules('crm');

        \CAgent::RemoveAgent(PartsAgent::NAME, self::MODULE_ID);

        $this->note(Loc::getMessage('ALEX_AUTOSERVICE_SYNC_AGENT_REMOVED'));

        $entityTypeId = (int) Option::get(self::MODULE_ID, 'purchase_type_id', 0);

        if ($entityTypeId) {
            try {
                $removed = UserField::remove(
                    SmartProcess::getUserFieldEntityId($entityTypeId),
                    self::FIELD_NAME
                );

                $this->note($removed
                    ? Loc::getMessage('ALEX_AUTOSERVICE_SYNC_FIELD_REMOVED', ['#FIELD#' => self::FIELD_NAME])
                    : Loc::getMessage('ALEX_AUTOSERVICE_SYNC_FIELD_MISSING', ['#FIELD#' => self::FIELD_NAME]));
            } catch (\Throwable $e) {
                $this->note($e->getMessage());
            }
        }

        Option::delete(self::MODULE_ID, ['name' => \Alex\Autoservice\Service\PartsSync::OPTION_URL]);
    }

    /**
     * @return array|null
     */
    private static function findAgent(): ?array
    {
        $row = \CAgent::GetList([], ['NAME' => PartsAgent::NAME, 'MODULE_ID' => self::MODULE_ID])->Fetch();

        return $row ?: null;
    }
}
