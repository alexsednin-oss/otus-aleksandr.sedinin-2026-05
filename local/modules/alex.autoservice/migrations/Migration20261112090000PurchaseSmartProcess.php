<?php

namespace Alex\Autoservice\Migrations;

use Alex\Autoservice\Migration\AbstractMigration;
use Alex\Autoservice\Service\SmartProcess;
use Alex\Autoservice\Service\Stages;
use Alex\Autoservice\Service\UserField;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

/**
 * Смарт-процесс «Заявки на закупку».
 *
 * Здесь смарт-процесс уместен, в отличие от автомобилей: у заявки есть
 * жизненный цикл со стадиями, товарные позиции со списком запчастей,
 * согласование бизнес-процессом и права для отдела закупок — всё это
 * штатное и не требует собственной реализации.
 *
 * Стадии по ТЗ: Новая → На одобрении → Выполнено либо Отклонено.
 * Автоматические заявки при нулевом остатке согласования не проходят
 * и создаются сразу в стадии «Выполнено».
 */
class Migration20261112090000PurchaseSmartProcess extends AbstractMigration
{
    private const MODULE_ID = 'alex.autoservice';

    public const OPTION_ENTITY_TYPE_ID = 'purchase_type_id';
    public const OPTION_CATEGORY_ID    = 'purchase_category_id';

    private const TYPE_CODE = 'AUTOSERVICE_PURCHASE';
    private const TYPE_NAME = 'AutoservicePurchase';

    /** @var array<int, array{CODE:string, NAME_CODE:string, SORT:int, COLOR:string, SEMANTICS?:string}> */
    private const STAGES = [
        ['CODE' => 'NEW',      'NAME_CODE' => 'ALEX_AUTOSERVICE_PURCHASE_STAGE_NEW',      'SORT' => 10, 'COLOR' => '#39a8ef'],
        ['CODE' => 'APPROVAL', 'NAME_CODE' => 'ALEX_AUTOSERVICE_PURCHASE_STAGE_APPROVAL', 'SORT' => 20, 'COLOR' => '#ffa900'],
        ['CODE' => 'SUCCESS',  'NAME_CODE' => 'ALEX_AUTOSERVICE_PURCHASE_STAGE_DONE',     'SORT' => 30, 'COLOR' => '#7bd500', 'SEMANTICS' => 'S'],
        ['CODE' => 'FAIL',     'NAME_CODE' => 'ALEX_AUTOSERVICE_PURCHASE_STAGE_REJECTED', 'SORT' => 40, 'COLOR' => '#ff5752', 'SEMANTICS' => 'F'],
    ];

    /** @var array<int, array{FIELD_NAME:string, USER_TYPE_ID:string, LABEL_CODE:string, SORT:int}> */
    private const FIELDS = [
        [
            'FIELD_NAME'   => 'UF_PURCHASE_INITIATOR',
            'USER_TYPE_ID' => 'employee',
            'LABEL_CODE'   => 'ALEX_AUTOSERVICE_PURCHASE_FIELD_INITIATOR',
            'SORT'         => 100,
        ],
        [
            'FIELD_NAME'   => 'UF_PURCHASE_REASON',
            'USER_TYPE_ID' => 'string',
            'LABEL_CODE'   => 'ALEX_AUTOSERVICE_PURCHASE_FIELD_REASON',
            'SORT'         => 200,
        ],
        [
            'FIELD_NAME'   => 'UF_PURCHASE_AUTO',
            'USER_TYPE_ID' => 'boolean',
            'LABEL_CODE'   => 'ALEX_AUTOSERVICE_PURCHASE_FIELD_AUTO',
            'SORT'         => 300,
        ],
    ];

    /**
     * @return string
     */
    public function getDescription(): string
    {
        return Loc::getMessage('ALEX_AUTOSERVICE_MIGRATION_PURCHASE_DESCRIPTION');
    }

    /**
     * @return void
     */
    public function up(): void
    {
        $this->requireModules('crm');

        $type = SmartProcess::ensure(
            self::TYPE_CODE,
            Loc::getMessage('ALEX_AUTOSERVICE_PURCHASE_TYPE_TITLE'),
            self::TYPE_NAME,
            [
                // Стадии нужны для жизненного цикла заявки,
                // товарные позиции — для списка запчастей с количеством
                'IS_STAGES_ENABLED'             => true,
                'IS_LINK_WITH_PRODUCTS_ENABLED' => true,
                'IS_CLIENT_ENABLED'             => false,
                'IS_BEGIN_CLOSE_DATES_ENABLED'  => true,
            ]
        );

        $entityTypeId = $type['ENTITY_TYPE_ID'];

        $this->note(Loc::getMessage(
            $type['CREATED'] ? 'ALEX_AUTOSERVICE_PURCHASE_TYPE_CREATED' : 'ALEX_AUTOSERVICE_PURCHASE_TYPE_EXISTS',
            ['#ID#' => $entityTypeId]
        ));

        $this->note(($type['TABLES'] ?? false)
            ? Loc::getMessage('ALEX_AUTOSERVICE_PURCHASE_TABLES_READY')
            : Loc::getMessage('ALEX_AUTOSERVICE_PURCHASE_TABLES_MISSING'));

        Option::set(self::MODULE_ID, self::OPTION_ENTITY_TYPE_ID, $entityTypeId);

        // ----------------------------------------------------------- стадии

        $factory  = SmartProcess::getFactory($entityTypeId);
        $category = $factory->getDefaultCategory();

        if (!$category) {
            $this->fail(Loc::getMessage('ALEX_AUTOSERVICE_PURCHASE_NO_CATEGORY'));
        }

        $categoryId = (int) $category->getId();

        Option::set(self::MODULE_ID, self::OPTION_CATEGORY_ID, $categoryId);

        $this->note(Loc::getMessage('ALEX_AUTOSERVICE_PURCHASE_CATEGORY', ['#ID#' => $categoryId]));

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

        foreach (Stages::sync($factory->getStagesEntityId($categoryId), $definitions, $categoryId) as $line) {
            $this->note($line);
        }

        // ------------------------------------------------------------ поля

        $userFieldEntityId = SmartProcess::getUserFieldEntityId($entityTypeId);

        $this->note(Loc::getMessage('ALEX_AUTOSERVICE_PURCHASE_UF_ENTITY', ['#ENTITY#' => $userFieldEntityId]));

        foreach (self::FIELDS as $field) {
            $field['LABEL'] = Loc::getMessage($field['LABEL_CODE']);

            $result = UserField::ensure($userFieldEntityId, $field);

            $this->note(Loc::getMessage(
                $result['CREATED'] ? 'ALEX_AUTOSERVICE_PURCHASE_FIELD_CREATED' : 'ALEX_AUTOSERVICE_PURCHASE_FIELD_EXISTS',
                ['#FIELD#' => $field['FIELD_NAME'], '#ID#' => $result['ID']]
            ));
        }

        // Карта типов и сущность ORM кешируются: после добавления полей
        // кеш сбрасываем, иначе следующий запрос может собрать сущность
        // по устаревшему описанию
        \Bitrix\Crm\Service\Container::getInstance()->getDynamicTypesMap()->invalidateTypesCollectionCache();

        if (method_exists('\Bitrix\Main\ORM\Entity', 'destroy')) {
            \Bitrix\Main\ORM\Entity::destroy($factory->getDataClass());
        }
    }

    /**
     * @return void
     */
    public function down(): void
    {
        $this->requireModules('crm');

        $entityTypeId = (int) Option::get(self::MODULE_ID, self::OPTION_ENTITY_TYPE_ID, 0);

        // Сущность пользовательских полей вычисляем заранее:
        // после удаления типа её уже не получить
        $ufEntity = $entityTypeId ? SmartProcess::getUserFieldEntityId($entityTypeId) : '';

        // Тип удаляем первым. CRM откажет, если внутри остались заявки,
        // и откат прервётся до того, как что-то будет снято: менеджер
        // оставит запись в журнале, и состояние сойдётся с реальностью
        $removed = SmartProcess::remove(self::TYPE_CODE);

        $this->note($removed
            ? Loc::getMessage('ALEX_AUTOSERVICE_PURCHASE_TYPE_REMOVED', ['#ID#' => $entityTypeId])
            : Loc::getMessage('ALEX_AUTOSERVICE_PURCHASE_TYPE_NOT_FOUND'));

        if ($ufEntity !== '') {
            try {
                $removedFields = UserField::removeByPrefix($ufEntity, 'UF_PURCHASE_');

                $this->note(Loc::getMessage('ALEX_AUTOSERVICE_PURCHASE_FIELDS_REMOVED', [
                    '#COUNT#' => $removedFields,
                ]));
            } catch (\Throwable $e) {
                $this->note(Loc::getMessage('ALEX_AUTOSERVICE_PURCHASE_FIELDS_SKIPPED', [
                    '#ERROR#' => $e->getMessage(),
                ]));
            }
        }

        Option::delete(self::MODULE_ID, ['name' => self::OPTION_ENTITY_TYPE_ID]);
        Option::delete(self::MODULE_ID, ['name' => self::OPTION_CATEGORY_ID]);
    }
}
