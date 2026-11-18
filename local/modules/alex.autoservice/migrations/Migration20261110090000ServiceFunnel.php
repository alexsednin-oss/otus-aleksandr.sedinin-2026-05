<?php

namespace Alex\Autoservice\Migrations;

use Alex\Autoservice\Migration\AbstractMigration;
use Alex\Autoservice\Service\DealFunnel;
use Alex\Autoservice\Service\Stages;
use Alex\Autoservice\Service\UserField;
use Alex\Autoservice\Service\CarCrudService;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Localization\Loc;
use Alex\Autoservice\UserField\CarType;

Loc::loadMessages(__FILE__);

/**
 * Воронка «Сервисное обслуживание», её стадии и привязка сделки к автомобилю.
 *
 * Поле UF_CRM_CAR_ID хранит ID строки из таблицы автомобилей. Тип «целое
 * число», а не «привязка к CRM»: автомобили живут в собственной таблице,
 * а не в сущности CRM. Выбор из списка добавим отдельным типом поля —
 * на этом этапе достаточно хранения и программной привязки.
 */
class Migration20261110090000ServiceFunnel extends AbstractMigration
{
    private const MODULE_ID = 'alex.autoservice';

    public const OPTION_CATEGORY_ID = 'service_category_id';

    /** Стадии по ТЗ: Приемка → Диагностика → Ожидание запчастей → Ремонт → Проверка → Завершено */
    private const STAGES = [
        ['CODE' => 'NEW',           'NAME_CODE' => 'ALEX_AUTOSERVICE_STAGE_ACCEPTANCE',    'SORT' => 10, 'COLOR' => '#39a8ef'],
        ['CODE' => 'DIAGNOSTICS',   'NAME_CODE' => 'ALEX_AUTOSERVICE_STAGE_DIAGNOSTICS',   'SORT' => 20, 'COLOR' => '#2fc6f6'],
        ['CODE' => 'WAITING_PARTS', 'NAME_CODE' => 'ALEX_AUTOSERVICE_STAGE_WAITING_PARTS', 'SORT' => 30, 'COLOR' => '#ffa900'],
        ['CODE' => 'REPAIR',        'NAME_CODE' => 'ALEX_AUTOSERVICE_STAGE_REPAIR',        'SORT' => 40, 'COLOR' => '#47d1e2'],
        ['CODE' => 'CHECK',         'NAME_CODE' => 'ALEX_AUTOSERVICE_STAGE_CHECK',         'SORT' => 50, 'COLOR' => '#a6d13a'],
        ['CODE' => 'WON',           'NAME_CODE' => 'ALEX_AUTOSERVICE_STAGE_DONE',          'SORT' => 60, 'COLOR' => '#7bd500', 'SEMANTICS' => 'S'],
        ['CODE' => 'LOSE',          'NAME_CODE' => 'ALEX_AUTOSERVICE_STAGE_REJECTED',      'SORT' => 70, 'COLOR' => '#ff5752', 'SEMANTICS' => 'F'],
    ];

    /**
     * @return string
     */
    public function getDescription(): string
    {
        return Loc::getMessage('ALEX_AUTOSERVICE_MIGRATION_FUNNEL_DESCRIPTION');
    }

    /**
     * @return void
     */
    public function up(): void
    {
        $this->requireModules('crm');

        $funnel = DealFunnel::ensure(Loc::getMessage('ALEX_AUTOSERVICE_FUNNEL_NAME'));

        $this->note(Loc::getMessage(
            $funnel['CREATED'] ? 'ALEX_AUTOSERVICE_FUNNEL_CREATED' : 'ALEX_AUTOSERVICE_FUNNEL_EXISTS',
            ['#ID#' => $funnel['ID']]
        ));

        Option::set(self::MODULE_ID, self::OPTION_CATEGORY_ID, $funnel['ID']);

        $stagesEntityId = DealFunnel::getStagesEntityId($funnel['ID']);

        $definitions = [];

        foreach (self::STAGES as $stage) {
            $definitions[] = [
                'CODE'      => $stage['CODE'],
                'NAME'      => Loc::getMessage($stage['NAME_CODE']),
                'SORT'      => $stage['SORT'],
                'COLOR'     => $stage['COLOR'],
                'SEMANTICS' => $stage['SEMANTICS'] ?? null,
            ];
        }

        foreach (Stages::sync($stagesEntityId, $definitions, $funnel['ID']) as $line) {
            $this->note($line);
        }

        $field = UserField::ensure('CRM_DEAL', [
            'FIELD_NAME'   => CarCrudService::DEAL_CAR_FIELD,
            'USER_TYPE_ID' => CarType::USER_TYPE_ID,
            'LABEL'        => Loc::getMessage('ALEX_AUTOSERVICE_DEAL_CAR_LABEL'),
            'SORT'         => 100,
            'SHOW_FILTER'  => 'E',
        ]);

        $this->note(Loc::getMessage(
            $field['CREATED'] ? 'ALEX_AUTOSERVICE_DEAL_CAR_CREATED' : 'ALEX_AUTOSERVICE_DEAL_CAR_EXISTS',
            ['#FIELD#' => CarCrudService::DEAL_CAR_FIELD, '#ID#' => $field['ID']]
        ));
    }

    /**
     * @return void
     */
    public function down(): void
    {
        $this->requireModules('crm');

        $categoryId = (int) Option::get(self::MODULE_ID, self::OPTION_CATEGORY_ID, 0);

        // Воронку удаляем до поля сделки. CRM откажет, если в ней есть
        // сделки, и тогда откат обязан прерваться целиком, а не снять
        // поле и уйти, оставив воронку жить без записи в журнале
        $this->note($categoryId && DealFunnel::remove($categoryId)
            ? Loc::getMessage('ALEX_AUTOSERVICE_FUNNEL_REMOVED', ['#ID#' => $categoryId])
            : Loc::getMessage('ALEX_AUTOSERVICE_FUNNEL_NOT_REMOVED', ['#ID#' => $categoryId]));

        if (UserField::remove('CRM_DEAL', CarCrudService::DEAL_CAR_FIELD)) {
            $this->note(Loc::getMessage('ALEX_AUTOSERVICE_DEAL_CAR_REMOVED', [
                '#FIELD#' => CarCrudService::DEAL_CAR_FIELD,
            ]));
        }

        Option::delete(self::MODULE_ID, ['name' => self::OPTION_CATEGORY_ID]);
    }
}
