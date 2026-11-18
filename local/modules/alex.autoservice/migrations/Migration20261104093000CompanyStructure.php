<?php

namespace Alex\Autoservice\Migrations;

use Alex\Autoservice\Migration\AbstractMigration;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Web\Json;

Loc::loadMessages(__FILE__);

/**
 * Оргструктура сервисного центра и сотрудники.
 *
 * Создаёт два подразделения и двенадцать пользователей по ТЗ. Пароли
 * генерируются при первом применении и выводятся в отчёт один раз —
 * в код и репозиторий они не попадают.
 *
 * Соответствие «сотрудник → роль» сохраняется в опции модуля: следующая
 * миграция раздаёт по ней права доступа CRM.
 */
class Migration20261104093000CompanyStructure extends AbstractMigration
{
    private const MODULE_ID = 'alex.autoservice';

    /** Опции, в которых миграция оставляет созданные идентификаторы */
    private const OPTION_DEPARTMENTS = 'structure_departments';
    private const OPTION_USERS       = 'structure_users';

    /** Подразделения: код => [название, код родителя] */
    private const DEPARTMENTS = [
        'SERVICE'    => ['TITLE_CODE' => 'ALEX_AUTOSERVICE_DEPARTMENT_SERVICE', 'PARENT' => null],
        'PURCHASING' => ['TITLE_CODE' => 'ALEX_AUTOSERVICE_DEPARTMENT_PURCHASING', 'PARENT' => 'SERVICE'],
    ];

    /**
     * Сотрудники по ТЗ: директор, 2 менеджера, 5 механиков, бухгалтер,
     * начальник отдела закупок и 2 закупщика.
     */
    private const USERS = [
        ['LOGIN' => 'as.director',  'NAME' => 'Игорь',   'LAST_NAME' => 'Орлов',     'ROLE' => 'DIRECTOR',   'DEPARTMENT' => 'SERVICE',    'HEAD' => true],
        ['LOGIN' => 'as.manager1',  'NAME' => 'Анна',    'LAST_NAME' => 'Белова',    'ROLE' => 'MANAGER',    'DEPARTMENT' => 'SERVICE',    'HEAD' => false],
        ['LOGIN' => 'as.manager2',  'NAME' => 'Павел',   'LAST_NAME' => 'Гущин',     'ROLE' => 'MANAGER',    'DEPARTMENT' => 'SERVICE',    'HEAD' => false],
        ['LOGIN' => 'as.mechanic1', 'NAME' => 'Виктор',  'LAST_NAME' => 'Зайцев',    'ROLE' => 'MECHANIC',   'DEPARTMENT' => 'SERVICE',    'HEAD' => false],
        ['LOGIN' => 'as.mechanic2', 'NAME' => 'Руслан',  'LAST_NAME' => 'Ковалёв',   'ROLE' => 'MECHANIC',   'DEPARTMENT' => 'SERVICE',    'HEAD' => false],
        ['LOGIN' => 'as.mechanic3', 'NAME' => 'Денис',   'LAST_NAME' => 'Лапин',     'ROLE' => 'MECHANIC',   'DEPARTMENT' => 'SERVICE',    'HEAD' => false],
        ['LOGIN' => 'as.mechanic4', 'NAME' => 'Тимур',   'LAST_NAME' => 'Нестеров',  'ROLE' => 'MECHANIC',   'DEPARTMENT' => 'SERVICE',    'HEAD' => false],
        ['LOGIN' => 'as.mechanic5', 'NAME' => 'Артём',   'LAST_NAME' => 'Пряхин',    'ROLE' => 'MECHANIC',   'DEPARTMENT' => 'SERVICE',    'HEAD' => false],
        ['LOGIN' => 'as.accountant','NAME' => 'Ольга',   'LAST_NAME' => 'Кузьмина',  'ROLE' => 'ACCOUNTANT', 'DEPARTMENT' => 'SERVICE',    'HEAD' => false],
        ['LOGIN' => 'as.purchead',  'NAME' => 'Сергей',  'LAST_NAME' => 'Муратов',   'ROLE' => 'PURCH_HEAD', 'DEPARTMENT' => 'PURCHASING', 'HEAD' => true],
        ['LOGIN' => 'as.buyer1',    'NAME' => 'Марина',  'LAST_NAME' => 'Соколова',  'ROLE' => 'BUYER',      'DEPARTMENT' => 'PURCHASING', 'HEAD' => false],
        ['LOGIN' => 'as.buyer2',    'NAME' => 'Егор',    'LAST_NAME' => 'Фомин',     'ROLE' => 'BUYER',      'DEPARTMENT' => 'PURCHASING', 'HEAD' => false],
    ];

    /**
     * @return string
     */
    public function getDescription(): string
    {
        return Loc::getMessage('ALEX_AUTOSERVICE_MIGRATION_STRUCTURE_DESCRIPTION');
    }

    /**
     * @return void
     */
    public function up(): void
    {
        $this->requireModules('iblock', 'intranet');

        $departments = $this->createDepartments();
        $users       = $this->createUsers($departments);

        $this->assignHeads($departments, $users);

        Option::set(self::MODULE_ID, self::OPTION_DEPARTMENTS, Json::encode($departments));
        Option::set(self::MODULE_ID, self::OPTION_USERS, Json::encode($users));
    }

    /**
     * Откат: сотрудники увольняются (деактивируются), подразделения удаляются.
     *
     * Полное удаление пользователей в Битрикс24 рвёт связи в истории CRM,
     * поэтому откат делает то же, что делает кадровик — увольнение.
     *
     * @return void
     */
    public function down(): void
    {
        $this->requireModules('iblock', 'intranet');

        $users = $this->readOption(self::OPTION_USERS);

        $user = new \CUser();

        foreach ($users as $role => $ids) {
            foreach ((array) $ids as $id) {
                $user->Update((int) $id, ['ACTIVE' => 'N', 'UF_DEPARTMENT' => []]);

                $this->note(Loc::getMessage('ALEX_AUTOSERVICE_STRUCTURE_USER_DISABLED', ['#ID#' => $id]));
            }
        }

        $departments = $this->readOption(self::OPTION_DEPARTMENTS);

        // Сначала дочерние, затем родительские
        foreach (array_reverse($departments) as $code => $sectionId) {
            if (\CIBlockSection::Delete((int) $sectionId)) {
                $this->note(Loc::getMessage('ALEX_AUTOSERVICE_STRUCTURE_DEPARTMENT_DELETED', ['#CODE#' => $code]));
            }
        }

        Option::delete(self::MODULE_ID, ['name' => self::OPTION_DEPARTMENTS]);
        Option::delete(self::MODULE_ID, ['name' => self::OPTION_USERS]);
    }

    /**
     * @return array<string, int> код подразделения => ID раздела
     */
    private function createDepartments(): array
    {
        $iblockId = (int) Option::get('intranet', 'iblock_structure', 0);

        if (!$iblockId) {
            $this->fail(Loc::getMessage('ALEX_AUTOSERVICE_STRUCTURE_NO_IBLOCK'));
        }

        $rootId = $this->getRootSectionId($iblockId);
        $result = [];

        foreach (self::DEPARTMENTS as $code => $department) {
            $xmlId    = self::MODULE_ID . '.' . $code;
            $existing = \CIBlockSection::GetList(
                [],
                ['IBLOCK_ID' => $iblockId, 'XML_ID' => $xmlId, 'CHECK_PERMISSIONS' => 'N'],
                false,
                ['ID']
            )->Fetch();

            if ($existing) {
                $result[$code] = (int) $existing['ID'];

                $this->note(Loc::getMessage('ALEX_AUTOSERVICE_STRUCTURE_DEPARTMENT_EXISTS', [
                    '#CODE#' => $code,
                    '#ID#'   => $existing['ID'],
                ]));

                continue;
            }

            $parentId = $department['PARENT'] === null
                ? $rootId
                : ($result[$department['PARENT']] ?? $rootId);

            $section   = new \CIBlockSection();
            $sectionId = (int) $section->Add([
                'IBLOCK_ID'         => $iblockId,
                'IBLOCK_SECTION_ID' => $parentId,
                'NAME'              => Loc::getMessage($department['TITLE_CODE']),
                'XML_ID'            => $xmlId,
                'ACTIVE'            => 'Y',
                'SORT'              => 500,
            ]);

            if (!$sectionId) {
                $this->fail(Loc::getMessage('ALEX_AUTOSERVICE_STRUCTURE_DEPARTMENT_ERROR', [
                    '#CODE#'  => $code,
                    '#ERROR#' => $section->LAST_ERROR,
                ]));
            }

            $result[$code] = $sectionId;

            $this->note(Loc::getMessage('ALEX_AUTOSERVICE_STRUCTURE_DEPARTMENT_CREATED', [
                '#CODE#' => $code,
                '#ID#'   => $sectionId,
            ]));
        }

        return $result;
    }

    /**
     * @param int $iblockId
     *
     * @return int
     */
    private function getRootSectionId(int $iblockId): int
    {
        $root = \CIBlockSection::GetList(
            ['LEFT_MARGIN' => 'ASC'],
            ['IBLOCK_ID' => $iblockId, 'DEPTH_LEVEL' => 1, 'CHECK_PERMISSIONS' => 'N'],
            false,
            ['ID']
        )->Fetch();

        if (!$root) {
            $this->fail(Loc::getMessage('ALEX_AUTOSERVICE_STRUCTURE_NO_ROOT'));
        }

        return (int) $root['ID'];
    }

    /**
     * @param array $departments
     * @return array
     * @throws \Bitrix\Main\ArgumentException
     * @throws \Bitrix\Main\ObjectPropertyException
     * @throws \Bitrix\Main\SystemException
     */
    private function createUsers(array $departments): array
    {
        $user       = new \CUser();
        $groupId    = $this->getEmployeesGroupId();
        $byRole     = [];
        $credentials = [];

        foreach (self::USERS as $employee) {
            $existing = \Bitrix\Main\UserTable::query()
                ->setSelect(['ID'])
                ->where('LOGIN', $employee['LOGIN'])
                ->setLimit(1)
                ->fetch();

            if ($existing) {
                $userId = (int) $existing['ID'];

                // После отката сотрудники остаются уволенными и без подразделения —
                // повторное применение миграции должно возвращать их в строй
                $updated = $user->Update($userId, [
                    'ACTIVE'        => 'Y',
                    'UF_DEPARTMENT' => [$departments[$employee['DEPARTMENT']]],
                    'WORK_POSITION' => Loc::getMessage('ALEX_AUTOSERVICE_POSITION_' . $employee['ROLE']),
                ]);

                if (!$updated) {
                    $this->fail(Loc::getMessage('ALEX_AUTOSERVICE_STRUCTURE_USER_ERROR', [
                        '#LOGIN#' => $employee['LOGIN'],
                        '#ERROR#' => $user->LAST_ERROR,
                    ]));
                }

                $byRole[$employee['ROLE']][] = $userId;

                $this->note(Loc::getMessage('ALEX_AUTOSERVICE_STRUCTURE_USER_RESTORED', [
                    '#LOGIN#' => $employee['LOGIN'],
                    '#ID#'    => $userId,
                ]));

                continue;
            }

            $password = $this->generatePassword();

            $fields = [
                'LOGIN'            => $employee['LOGIN'],
                'EMAIL'            => $employee['LOGIN'] . '@autoservice.local',
                'NAME'             => $employee['NAME'],
                'LAST_NAME'        => $employee['LAST_NAME'],
                'PASSWORD'         => $password,
                'CONFIRM_PASSWORD' => $password,
                'ACTIVE'           => 'Y',
                'WORK_POSITION'    => Loc::getMessage('ALEX_AUTOSERVICE_POSITION_' . $employee['ROLE']),
                'UF_DEPARTMENT'    => [$departments[$employee['DEPARTMENT']]],
                'XML_ID'           => self::MODULE_ID . '.' . $employee['LOGIN'],
            ];

            if ($groupId) {
                $fields['GROUP_ID'] = [$groupId];
            }

            $userId = (int) $user->Add($fields);

            if (!$userId) {
                $this->fail(Loc::getMessage('ALEX_AUTOSERVICE_STRUCTURE_USER_ERROR', [
                    '#LOGIN#' => $employee['LOGIN'],
                    '#ERROR#' => $user->LAST_ERROR,
                ]));
            }

            $byRole[$employee['ROLE']][] = $userId;
            $credentials[]               = $employee['LOGIN'] . ' / ' . $password;

            $this->note(Loc::getMessage('ALEX_AUTOSERVICE_STRUCTURE_USER_CREATED', [
                '#LOGIN#' => $employee['LOGIN'],
                '#ID#'    => $userId,
            ]));
        }

        if ($credentials) {
            $this->note(Loc::getMessage('ALEX_AUTOSERVICE_STRUCTURE_CREDENTIALS'));

            foreach ($credentials as $line) {
                $this->note('    ' . $line);
            }
        }

        return $byRole;
    }

    /**
     * Назначает руководителей подразделений.
     *
     * @param array<string, int>   $departments
     * @param array<string, int[]> $users
     *
     * @return void
     */
    private function assignHeads(array $departments, array $users): void
    {
        $heads = [
            'SERVICE'    => $users['DIRECTOR'][0] ?? 0,
            'PURCHASING' => $users['PURCH_HEAD'][0] ?? 0,
        ];

        $section = new \CIBlockSection();

        foreach ($heads as $code => $headId) {
            if (!$headId || !isset($departments[$code])) {
                continue;
            }

            $section->Update($departments[$code], ['UF_HEAD' => $headId]);

            $this->note(Loc::getMessage('ALEX_AUTOSERVICE_STRUCTURE_HEAD_SET', [
                '#CODE#' => $code,
                '#ID#'   => $headId,
            ]));
        }
    }

    /**
     * @return int
     * @throws \Bitrix\Main\ArgumentException
     * @throws \Bitrix\Main\ObjectPropertyException
     * @throws \Bitrix\Main\SystemException
     */
    private function getEmployeesGroupId(): int
    {
        $group = \Bitrix\Main\GroupTable::query()
            ->setSelect(['ID'])
            ->where('STRING_ID', 'employees')
            ->setLimit(1)
            ->fetch();

        return $group ? (int) $group['ID'] : 0;
    }

    /**
     * Пароль, удовлетворяющий обычной политике портала.
     *
     * @return string
     */
    private function generatePassword(): string
    {
        return \Bitrix\Main\Security\Random::getString(12, true) . 'aA1!';
    }

    /**
     * @param string $option
     *
     * @return array
     */
    private function readOption(string $option): array
    {
        $raw = (string) Option::get(self::MODULE_ID, $option, '');

        if ($raw === '') {
            return [];
        }

        try {
            return (array) Json::decode($raw);
        } catch (\Throwable $e) {
            return [];
        }
    }
}
