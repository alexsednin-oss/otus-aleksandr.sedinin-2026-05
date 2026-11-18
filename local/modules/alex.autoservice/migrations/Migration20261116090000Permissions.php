<?php

namespace Alex\Autoservice\Migrations;

use Alex\Autoservice\Migration\AbstractMigration;
use Alex\Autoservice\Service\Access;
use Alex\Autoservice\Service\Purchase;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Web\Json;

Loc::loadMessages(__FILE__);

/**
 * Роли доступа CRM по ТЗ.
 *
 * ТЗ, раздел «Права доступа»:
 *   Механики — только свои сделки и свои клиенты;
 *   Менеджеры — все сделки и доступ к «гаражу» клиентов;
 *   Закупщики — видят заявки на закупку;
 *   Директор — полный доступ.
 *
 * Бухгалтер в разделе прав не упомянут и роли не получает. Начальник
 * отдела закупок получает роль закупщика: по разделу «Заявки в отдел
 * закупок» он одобряет заявки, когда закупщики недоступны.
 *
 * Права на автомобили здесь не раздаются — своя ORM-таблица ролями CRM
 * не управляется, её проверки живут в Service\Access.
 */
class Migration20261116090000Permissions extends AbstractMigration
{
    private const MODULE_ID = 'alex.autoservice';

    /** Созданные роли: код => ID роли CRM */
    private const OPTION_ROLES = 'crm_roles';

    /** Опция с идентификатором воронки сервисного обслуживания */
    private const OPTION_SERVICE_CATEGORY = 'service_category_id';

    /**
     * Матрица доступа. Группы сущностей раскрываются в коды прав CRM
     * в resolveEntities(): DEAL → общая воронка и воронка сервиса,
     * PURCHASE → смарт-процесс заявок в своей воронке.
     */
    private const MATRIX = [
        Access::ROLE_MECHANIC => [
            'DEAL'     => ['READ' => Access::PERM_SELF, 'ADD' => Access::PERM_SELF, 'WRITE' => Access::PERM_SELF],
            'CONTACT'  => ['READ' => Access::PERM_SELF, 'ADD' => Access::PERM_SELF, 'WRITE' => Access::PERM_SELF],
            'PURCHASE' => ['READ' => Access::PERM_SELF, 'ADD' => Access::PERM_SELF, 'WRITE' => Access::PERM_SELF],
        ],
        Access::ROLE_MANAGER => [
            'DEAL'     => ['READ' => Access::PERM_ALL,  'ADD' => Access::PERM_ALL,  'WRITE' => Access::PERM_ALL],
            'CONTACT'  => ['READ' => Access::PERM_ALL,  'ADD' => Access::PERM_ALL,  'WRITE' => Access::PERM_ALL],
            'PURCHASE' => ['READ' => Access::PERM_SELF, 'ADD' => Access::PERM_SELF, 'WRITE' => Access::PERM_SELF],
        ],
        Access::ROLE_BUYER => [
            'PURCHASE' => ['READ' => Access::PERM_ALL, 'ADD' => Access::PERM_ALL, 'WRITE' => Access::PERM_ALL],
        ],
        Access::ROLE_DIRECTOR => [
            'DEAL'     => ['READ' => Access::PERM_ALL, 'ADD' => Access::PERM_ALL, 'WRITE' => Access::PERM_ALL, 'DELETE' => Access::PERM_ALL],
            'CONTACT'  => ['READ' => Access::PERM_ALL, 'ADD' => Access::PERM_ALL, 'WRITE' => Access::PERM_ALL, 'DELETE' => Access::PERM_ALL],
            'PURCHASE' => ['READ' => Access::PERM_ALL, 'ADD' => Access::PERM_ALL, 'WRITE' => Access::PERM_ALL, 'DELETE' => Access::PERM_ALL],
        ],
    ];

    /** Кому какая роль CRM достаётся: роль => должности из оргструктуры */
    private const ROLE_USERS = [
        Access::ROLE_MECHANIC => [Access::ROLE_MECHANIC],
        Access::ROLE_MANAGER  => [Access::ROLE_MANAGER],
        Access::ROLE_BUYER    => [Access::ROLE_BUYER, Access::ROLE_PURCH_HEAD],
        Access::ROLE_DIRECTOR => [Access::ROLE_DIRECTOR],
    ];

    /**
     * @return string
     */
    public function getDescription(): string
    {
        return Loc::getMessage('ALEX_AUTOSERVICE_MIGRATION_PERMISSIONS_DESCRIPTION');
    }

    /**
     * @return void
     */
    public function up(): void
    {
        $this->requireModules('crm');

        $entities  = $this->resolveEntities();
        $roles     = $this->readOption(self::OPTION_ROLES);
        $existing  = $this->getExistingRoles();

        foreach (self::MATRIX as $code => $rules) {
            $permissions = $this->buildPermissions($rules, $entities);
            $roleId      = (int) ($roles[$code] ?? 0);

            // Массив уходит в CCrmRole по ссылке — передаём переменную
            $fields = [
                'NAME'        => Loc::getMessage('ALEX_AUTOSERVICE_ROLE_' . $code),
                'PERMISSIONS' => $permissions,
            ];

            $role = new \CCrmRole();

            if ($roleId && isset($existing[$roleId])) {
                if (!$role->Update($roleId, $fields)) {
                    $this->fail(Loc::getMessage('ALEX_AUTOSERVICE_PERMISSIONS_ROLE_ERROR', [
                        '#NAME#'  => $fields['NAME'],
                        '#ERROR#' => $role->GetLastError(),
                    ]));
                }

                $this->note(Loc::getMessage('ALEX_AUTOSERVICE_PERMISSIONS_ROLE_UPDATED', [
                    '#NAME#' => $fields['NAME'],
                    '#ID#'   => $roleId,
                ]));
            } else {
                $roleId = (int) $role->Add($fields);

                if (!$roleId) {
                    $this->fail(Loc::getMessage('ALEX_AUTOSERVICE_PERMISSIONS_ROLE_ERROR', [
                        '#NAME#'  => $fields['NAME'],
                        '#ERROR#' => $role->GetLastError(),
                    ]));
                }

                $this->note(Loc::getMessage('ALEX_AUTOSERVICE_PERMISSIONS_ROLE_CREATED', [
                    '#NAME#' => $fields['NAME'],
                    '#ID#'   => $roleId,
                ]));
            }

            $roles[$code] = $roleId;
        }

        Option::set(self::MODULE_ID, self::OPTION_ROLES, Json::encode($roles));

        $this->assignUsers($roles);

        \CCrmRole::ClearCache();
    }

    /**
     * Откат: сотрудники отвязываются от ролей проекта, роли удаляются.
     * Назначения всех прочих ролей портала сохраняются.
     *
     * @return void
     */
    public function down(): void
    {
        $this->requireModules('crm');

        $roles = $this->readOption(self::OPTION_ROLES);

        if (!$roles) {
            $this->note(Loc::getMessage('ALEX_AUTOSERVICE_PERMISSIONS_NOTHING'));

            return;
        }

        $ourIds = array_map('intval', array_values($roles));

        $relations = $this->stripRoles($this->readRelations(), $ourIds);

        $role = new \CCrmRole();
        $role->SetRelation($relations);

        $this->note(Loc::getMessage('ALEX_AUTOSERVICE_PERMISSIONS_RELATIONS_CLEARED'));

        foreach ($roles as $code => $roleId) {
            $role->Delete((int) $roleId);

            // CCrmRole::Delete() ничего не возвращает — проверяем результат фактом
            $stillExists = Application::getConnection()
                ->query('SELECT ID FROM b_crm_role WHERE ID = ' . (int) $roleId)
                ->fetch();

            if ($stillExists) {
                $this->fail(Loc::getMessage('ALEX_AUTOSERVICE_PERMISSIONS_ROLE_DELETE_ERROR', [
                    '#NAME#' => Loc::getMessage('ALEX_AUTOSERVICE_ROLE_' . $code),
                    '#ID#'   => $roleId,
                ]));
            }

            $this->note(Loc::getMessage('ALEX_AUTOSERVICE_PERMISSIONS_ROLE_DELETED', [
                '#NAME#' => Loc::getMessage('ALEX_AUTOSERVICE_ROLE_' . $code),
                '#ID#'   => $roleId,
            ]));
        }

        Option::delete(self::MODULE_ID, ['name' => self::OPTION_ROLES]);

        \CCrmRole::ClearCache();
    }

    /**
     * Коды сущностей, на которые ставятся права.
     *
     * Смарт-процессы и воронки сделок адресуются вместе с направлением:
     * DEAL_C6, DYNAMIC_1054_C12 — это видно в штатных ролях портала.
     *
     * @return array<string, string[]>
     */
    private function resolveEntities(): array
    {
        $serviceCategory  = (int) Option::get(self::MODULE_ID, self::OPTION_SERVICE_CATEGORY, 0);
        $purchaseType     = Purchase::getEntityTypeId();
        $purchaseCategory = Purchase::getCategoryId();

        if (!$serviceCategory || !$purchaseType || !$purchaseCategory) {
            $this->fail(Loc::getMessage('ALEX_AUTOSERVICE_PERMISSIONS_NO_ENTITIES'));
        }

        return [
            'DEAL'     => ['DEAL', 'DEAL_C' . $serviceCategory],
            'CONTACT'  => ['CONTACT'],
            'PURCHASE' => ['DYNAMIC_' . $purchaseType . '_C' . $purchaseCategory],
        ];
    }

    /**
     * @param array                   $rules
     * @param array<string, string[]> $entities
     *
     * @return array права в формате CCrmRole: [СУЩНОСТЬ][ОПЕРАЦИЯ]['-'] = уровень
     */
    private function buildPermissions(array $rules, array $entities): array
    {
        $permissions = [];

        foreach ($rules as $group => $operations) {
            foreach ($entities[$group] ?? [] as $entity) {
                foreach ($operations as $operation => $attr) {
                    // Запрет — это отсутствие строки, а не пустой уровень
                    if ($attr === Access::PERM_NONE) {
                        continue;
                    }

                    $permissions[$entity][$operation] = ['-' => $attr];
                }
            }
        }

        return $permissions;
    }

    /**
     * Привязка сотрудников к ролям.
     *
     * CCrmRole::SetRelation() не добавляет связи, а переписывает их целиком:
     * удаляет все связи несистемных ролей и вставляет переданные. Поэтому
     * сначала читаем текущую картину и подмешиваем в неё своё.
     *
     * @param array<string, int> $roles
     *
     * @return void
     */
    private function assignUsers(array $roles): void
    {
        $relations = $this->stripRoles(
            $this->readRelations(),
            array_map('intval', array_values($roles))
        );

        foreach (self::ROLE_USERS as $roleCode => $positions) {
            $roleId = (int) ($roles[$roleCode] ?? 0);

            if (!$roleId) {
                continue;
            }

            $assigned = [];

            foreach ($positions as $position) {
                foreach (Access::getUsersByRole($position) as $userId) {
                    $relations['U' . $userId][] = $roleId;
                    $assigned[]                 = $userId;
                }
            }

            $this->note(Loc::getMessage('ALEX_AUTOSERVICE_PERMISSIONS_ASSIGNED', [
                '#NAME#'  => Loc::getMessage('ALEX_AUTOSERVICE_ROLE_' . $roleCode),
                '#USERS#' => $assigned ? implode(', ', $assigned) : '—',
            ]));
        }

        $role = new \CCrmRole();
        $role->SetRelation($relations);
    }

    /**
     * Текущие связи несистемных ролей.
     *
     * Системные роли SetRelation() не трогает, поэтому их связи возвращать
     * нельзя — иначе продублируются.
     *
     * @return array<string, int[]>
     */
    private function readRelations(): array
    {
        $rows = Application::getConnection()->query(
            "SELECT rr.RELATION, rr.ROLE_ID FROM b_crm_role_relation rr "
            . "INNER JOIN b_crm_role r ON r.ID = rr.ROLE_ID "
            . "WHERE r.IS_SYSTEM != 'Y'"
        )->fetchAll();

        $relations = [];

        foreach ($rows as $row) {
            $relations[(string) $row['RELATION']][] = (int) $row['ROLE_ID'];
        }

        return $relations;
    }

    /**
     * @param array<string, int[]> $relations
     * @param int[]                $roleIds
     *
     * @return array<string, int[]>
     */
    private function stripRoles(array $relations, array $roleIds): array
    {
        foreach ($relations as $code => $ids) {
            $kept = array_values(array_diff(array_map('intval', $ids), $roleIds));

            if ($kept) {
                $relations[$code] = $kept;
            } else {
                unset($relations[$code]);
            }
        }

        return $relations;
    }

    /**
     * @return array<int, string> ID роли => название
     */
    private function getExistingRoles(): array
    {
        $result = [];
        $res    = \CCrmRole::GetList(['ID' => 'ASC'], []);

        while ($row = $res->Fetch()) {
            $result[(int) $row['ID']] = (string) $row['NAME'];
        }

        return $result;
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
