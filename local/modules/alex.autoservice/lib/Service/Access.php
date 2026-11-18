<?php

namespace Alex\Autoservice\Service;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Web\Json;

Loc::loadMessages(__FILE__);

/**
 * Права доступа проекта.
 *
 * Карту «должность → сотрудники» оставляет миграция оргструктуры в опции
 * модуля, роли CRM по этой же карте раздаёт миграция прав. Класс —
 * единственное место, которое эту карту читает.
 *
 * Сделки, контакты и заявки на закупку закрыты штатными ролями CRM.
 * Автомобили живут в собственной ORM-таблице, механизма прав у неё нет,
 * поэтому проверки для них собраны здесь и вызываются из CarCrudService.
 */
class Access
{
    public const MODULE_ID = 'alex.autoservice';

    /** Должности из оргструктуры (ключи опции structure_users) */
    public const ROLE_DIRECTOR   = 'DIRECTOR';
    public const ROLE_MANAGER    = 'MANAGER';
    public const ROLE_MECHANIC   = 'MECHANIC';
    public const ROLE_ACCOUNTANT = 'ACCOUNTANT';
    public const ROLE_PURCH_HEAD = 'PURCH_HEAD';
    public const ROLE_BUYER      = 'BUYER';

    /**
     * Уровни доступа CRM. Повторяют константы CCrmPerms, но не ссылаются
     * на них: класс должен читаться и без загруженного модуля crm.
     * Соответствие проверяется тестом.
     */
    public const PERM_NONE = '';
    public const PERM_SELF = 'A';
    public const PERM_ALL  = 'X';

    private const OPTION_USERS = 'structure_users';

    /** Кто вообще видит автомобили — по ТЗ механик, менеджер и директор */
    private const CAR_READ = [self::ROLE_DIRECTOR, self::ROLE_MANAGER, self::ROLE_MECHANIC];

    /** Кто заводит и правит автомобили — «доступ к гаражу» у менеджера и директора */
    private const CAR_WRITE = [self::ROLE_DIRECTOR, self::ROLE_MANAGER];

    /** Удаление — те же, кто ведёт гараж: директор и менеджер */
    private const CAR_DELETE = [self::ROLE_DIRECTOR, self::ROLE_MANAGER];

    /** Роли, которым гараж виден только по своим клиентам */
    private const CAR_OWN_ONLY = [self::ROLE_MECHANIC];

    /**
     * Сотрудники указанной должности.
     *
     * @param string $role одна из констант ROLE_*
     *
     * @return int[]
     */
    public static function getUsersByRole(string $role): array
    {
        return array_map('intval', (array) (self::getStructure()[$role] ?? []));
    }

    /**
     * Должность сотрудника.
     *
     * @param int $userId
     *
     * @return string одна из констант ROLE_* либо пустая строка
     */
    public static function getRole(int $userId): string
    {
        foreach (self::getStructure() as $role => $ids) {
            if (in_array($userId, array_map('intval', (array) $ids), true)) {
                return (string) $role;
            }
        }

        return '';
    }

    /**
     * Текущий пользователь или 0, если кода исполняется вне сессии
     * (агент, консоль, установка модуля).
     *
     * @return int
     */
    public static function getCurrentUserId(): int
    {
        global $USER;

        return is_object($USER) && $USER->IsAuthorized() ? (int) $USER->GetID() : 0;
    }

    /**
     * Системный контекст: агент синхронизации остатков, агент согласования,
     * миграции и тесты работают без пользователя и проверкам не подлежат.
     *
     * @return bool
     */
    public static function isSystemContext(): bool
    {
        return self::getCurrentUserId() === 0;
    }

    /**
     * @param int $userId
     *
     * @return bool
     */
    public static function isAdmin(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        $groups = array_map('intval', (array) \CUser::GetUserGroup($userId));

        return in_array(1, $groups, true);
    }

    // ------------------------------------------------------------ автомобили

    /**
     * Доступ к справочнику автомобилей как таковому.
     *
     * Нужен отдельно от canReadCar(): выборка списка не привязана к
     * конкретному владельцу, и без этой проверки закупщик и бухгалтер
     * получили бы весь гараж портала.
     *
     * @param int|null $userId
     *
     * @return bool
     */
    public static function canViewCars(?int $userId = null): bool
    {
        $userId = $userId ?? self::getCurrentUserId();

        return self::isUnrestricted($userId)
            || in_array(self::getRole($userId), self::CAR_READ, true);
    }

    /**
     * @param int|null $userId
     *
     * @throws AccessDeniedException
     *
     * @return void
     */
    public static function assertCanViewCars(?int $userId = null): void
    {
        if (!self::canViewCars($userId)) {
            throw new AccessDeniedException(Loc::getMessage('ALEX_AUTOSERVICE_ACCESS_CAR_READ'));
        }
    }

    /**
     * @param int      $contactId владелец автомобиля
     * @param int|null $userId    по умолчанию текущий пользователь
     *
     * @return bool
     */
    public static function canReadCar(int $contactId, ?int $userId = null): bool
    {
        $userId = $userId ?? self::getCurrentUserId();

        if (!self::canViewCars($userId)) {
            return false;
        }

        if (self::isUnrestricted($userId)) {
            return true;
        }

        $role = self::getRole($userId);

        if (!in_array($role, self::CAR_OWN_ONLY, true)) {
            return true;
        }

        return self::isOwnContact($contactId, $userId);
    }

    /**
     * @param int|null $userId
     *
     * @return bool
     */
    public static function canWriteCar(?int $userId = null): bool
    {
        $userId = $userId ?? self::getCurrentUserId();

        return self::isUnrestricted($userId)
            || in_array(self::getRole($userId), self::CAR_WRITE, true);
    }

    /**
     * @param int|null $userId
     *
     * @return bool
     */
    public static function canDeleteCar(?int $userId = null): bool
    {
        $userId = $userId ?? self::getCurrentUserId();

        return self::isUnrestricted($userId)
            || in_array(self::getRole($userId), self::CAR_DELETE, true);
    }

    /**
     * Ограничен ли сотрудник автомобилями своих клиентов.
     *
     * Выборка в CarCrudService по этому признаку добавляет условие на
     * ответственного за контакт — фильтровать после выборки нельзя,
     * иначе ломается постраничная навигация.
     *
     * @param int|null $userId
     *
     * @return bool
     */
    public static function restrictedToOwnContacts(?int $userId = null): bool
    {
        $userId = $userId ?? self::getCurrentUserId();

        if (self::isUnrestricted($userId)) {
            return false;
        }

        return in_array(self::getRole($userId), self::CAR_OWN_ONLY, true);
    }

    /**
     * @param int      $contactId
     * @param int|null $userId
     *
     * @throws AccessDeniedException
     *
     * @return void
     */
    public static function assertCanReadCar(int $contactId, ?int $userId = null): void
    {
        if (!self::canReadCar($contactId, $userId)) {
            throw new AccessDeniedException(Loc::getMessage('ALEX_AUTOSERVICE_ACCESS_CAR_READ'));
        }
    }

    /**
     * @param int|null $userId
     *
     * @throws AccessDeniedException
     *
     * @return void
     */
    public static function assertCanWriteCar(?int $userId = null): void
    {
        if (!self::canWriteCar($userId)) {
            throw new AccessDeniedException(Loc::getMessage('ALEX_AUTOSERVICE_ACCESS_CAR_WRITE'));
        }
    }

    /**
     * @param int|null $userId
     *
     * @throws AccessDeniedException
     *
     * @return void
     */
    public static function assertCanDeleteCar(?int $userId = null): void
    {
        if (!self::canDeleteCar($userId)) {
            throw new AccessDeniedException(Loc::getMessage('ALEX_AUTOSERVICE_ACCESS_CAR_DELETE'));
        }
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Админ портала и системный контекст проверкам не подлежат.
     *
     * @param int $userId
     *
     * @return bool
     */
    private static function isUnrestricted(int $userId): bool
    {
        return $userId <= 0 || self::isAdmin($userId);
    }

    /**
     * Ответственный за контакт — он же «свой клиент».
     *
     * @param int $contactId
     * @param int $userId
     *
     * @return bool
     */
    public static function isOwnContact(int $contactId, int $userId): bool
    {
        if ($contactId <= 0 || !Loader::includeModule('crm')) {
            return false;
        }

        $contact = \Bitrix\Crm\ContactTable::query()
            ->setSelect(['ASSIGNED_BY_ID'])
            ->where('ID', $contactId)
            ->setLimit(1)
            ->fetch();

        return $contact && (int) $contact['ASSIGNED_BY_ID'] === $userId;
    }

    /**
     * @return array<string, int[]>
     */
    private static function getStructure(): array
    {
        $raw = (string) Option::get(self::MODULE_ID, self::OPTION_USERS, '');

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
