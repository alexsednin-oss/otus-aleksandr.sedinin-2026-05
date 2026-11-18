<?php

namespace Alex\Autoservice\Service;

use Bitrix\Crm\Model\Dynamic\TypeTable;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Application;

Loc::loadMessages(__FILE__);

/**
 * Работа со смарт-процессами (динамическими типами CRM).
 *
 * Фабрики типов в этой сборке нет, поэтому тип заводится напрямую через
 * TypeTable::add(). Идентификатор типа выделяется getNextAvailableEntityTypeId(),
 * а служебную часть — таблицы элементов, индексы, категорию по умолчанию —
 * берёт на себя обработчик onAfterAdd самой таблицы типов.
 */
class SmartProcess
{
    /**
     * Создаёт смарт-процесс, если типа с таким кодом ещё нет.
     *
     * @param string $code   символьный код типа, по нему же ищем существующий
     * @param string $title  заголовок в интерфейсе
     * @param string $name   латинское имя сущности (CamelCase)
     * @param array  $flags  переопределения флагов IS_*
     *
     * @throws \RuntimeException
     *
     * @return array{ENTITY_TYPE_ID:int, CREATED:bool}
     */
    public static function ensure(string $code, string $title, string $name, array $flags = []): array
    {
        self::requireCrm();

        $existing = self::findByCode($code);

        if ($existing) {
            return [
                'ENTITY_TYPE_ID' => $existing,
                'CREATED'        => false,
                'TABLES'         => self::ensureServiceTables(self::getTypeRowId($code)),
            ];
        }

        $entityTypeId = (int) TypeTable::getNextAvailableEntityTypeId();

        if (!$entityTypeId) {
            throw new \RuntimeException(Loc::getMessage('ALEX_AUTOSERVICE_SP_NO_FREE_ID'));
        }

        $fields = array_merge(
            [
                'ENTITY_TYPE_ID'                => $entityTypeId,
                'NAME'                          => $name,
                'TITLE'                         => $title,
                'CODE'                          => $code,
                'CREATED_BY'                    => self::getCurrentUserId(),
                'IS_CATEGORIES_ENABLED'         => false,
                'IS_STAGES_ENABLED'             => false,
                'IS_BEGIN_CLOSE_DATES_ENABLED'  => false,
                'IS_CLIENT_ENABLED'             => true,
                'IS_USE_IN_USERFIELD_ENABLED'   => true,
                'IS_LINK_WITH_PRODUCTS_ENABLED' => false,
                'IS_RECYCLEBIN_ENABLED'         => true,
                'IS_AUTOMATION_ENABLED'         => true,
                'IS_BIZ_PROC_ENABLED'           => true,
                'IS_SET_OPEN_PERMISSIONS'       => false,
                'IS_COUNTERS_ENABLED'           => true,
                'IS_OBSERVERS_ENABLED'          => false,
                'IS_SOURCE_ENABLED'             => false,
                'IS_MYCOMPANY_ENABLED'          => false,
                'IS_DOCUMENTS_ENABLED'          => false,
                'IS_CRM_TRACKING_ENABLED'       => false,
                'IS_RECURRING_ENABLED'          => false,
                'IS_PAYMENTS_ENABLED'           => false,
            ],
            $flags
        );

        $result = TypeTable::add($fields);

        if (!$result->isSuccess()) {
            throw new \RuntimeException(Loc::getMessage('ALEX_AUTOSERVICE_SP_ADD_ERROR', [
                '#CODE#'  => $code,
                '#ERROR#' => implode('; ', $result->getErrorMessages()),
            ]));
        }

        Container::getInstance()->getDynamicTypesMap()->invalidateTypesCollectionCache();

        // Идентификатор берём из результата добавления: повторный поиск
        // по CODE сразу после add() может ничего не вернуть, и тогда
        // служебные таблицы молча не создаются
        $tables = self::ensureServiceTables((int) $result->getId());

        return ['ENTITY_TYPE_ID' => $entityTypeId, 'CREATED' => true, 'TABLES' => $tables];
    }

    /**
     * Создаёт служебные таблицы типа — индекс поиска и контекст полей.
     *
     * Их заводит обработчик onAfterAdd, но результат там не проверяется,
     * а штатные методы создания в части сборок срабатывают не всегда.
     * Поэтому после каждого вызова проверяем таблицу и при необходимости
     * собираем сущность напрямую.
     *
     * @param int $typeId идентификатор строки в таблице типов
     *
     * @return bool готовы ли обе служебные таблицы
     */
    private static function ensureServiceTables(int $typeId): bool
    {
        if ($typeId <= 0) {
            return false;
        }

        $row = TypeTable::query()
            ->setSelect(['ENTITY_TYPE_ID'])
            ->where('ID', $typeId)
            ->setLimit(1)
            ->fetch();

        if (!$row) {
            return false;
        }

        $prefix     = 'b_crm_dynamic_items_' . (int) $row['ENTITY_TYPE_ID'];
        $connection = Application::getConnection();

        $tables = [
            '_index'          => ['create' => 'createItemIndexTable',         'compile' => 'compileItemIndexEntity'],
            '_fields_context' => ['create' => 'createItemFieldsContextTable', 'compile' => 'compileItemFieldsContextEntity'],
        ];

        $ready = true;

        foreach ($tables as $suffix => $methods) {
            $table = $prefix . $suffix;

            if ($connection->isTableExists($table)) {
                continue;
            }

            self::callQuietly($methods['create'], $typeId, $table);

            if ($connection->isTableExists($table)) {
                continue;
            }

            // Запасной путь: собрать сущность и создать таблицу напрямую
            try {
                if (method_exists(TypeTable::class, $methods['compile'])) {
                    $entity = TypeTable::{$methods['compile']}($typeId);

                    if (is_object($entity) && method_exists($entity, 'createDbTable')) {
                        $entity->createDbTable();
                    }
                }
            } catch (\Throwable $e) {
                Log::add([
                    'stage'  => 'SMART_PROCESS_SERVICE_TABLE',
                    'table'  => $table,
                    'method' => $methods['compile'],
                    'error'  => $e->getMessage(),
                ]);
            }

            if (!$connection->isTableExists($table)) {
                Log::add(['stage' => 'SMART_PROCESS_TABLE_MISSING', 'table' => $table]);

                $ready = false;
            }
        }

        return $ready;
    }

    /**
     * Вызов метода создания таблицы с подавлением ошибки: повторный вызов
     * для уже существующей таблицы — штатная ситуация, валить миграцию
     * из-за него нельзя.
     *
     * @param string $method
     * @param int    $typeId
     * @param string $table
     *
     * @return void
     */
    private static function callQuietly(string $method, int $typeId, string $table): void
    {
        if (!method_exists(TypeTable::class, $method)) {
            return;
        }

        try {
            TypeTable::$method($typeId);
        } catch (\Throwable $e) {
            Log::add([
                'stage'  => 'SMART_PROCESS_SERVICE_TABLE',
                'table'  => $table,
                'method' => $method,
                'error'  => $e->getMessage(),
            ]);
        }
    }

    /**
     * Удаляет смарт-процесс вместе с его таблицами.
     *
     * Служебный код CRM может бросить исключение уже после удаления строки
     * типа — например, пытаясь разобрать имя сущности, которой больше нет.
     * Поэтому результат проверяем по факту, а не по отсутствию ошибки.
     *
     * @param string $code
     *
     * @return bool удалён ли тип
     */
    public static function remove(string $code): bool
    {
        self::requireCrm();

        $type = TypeTable::query()
            ->setSelect(['ID'])
            ->where('CODE', $code)
            ->setLimit(1)
            ->fetch();

        if (!$type) {
            return false;
        }

        $error = '';

        try {
            $result = TypeTable::delete((int) $type['ID']);

            if (!$result->isSuccess()) {
                $error = implode('; ', $result->getErrorMessages());
            }
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        if ($error !== '') {
            Log::add([
                'stage' => 'SMART_PROCESS_REMOVE_EXCEPTION',
                'code'  => $code,
                'error' => $error,
            ]);
        }

        Container::getInstance()->getDynamicTypesMap()->invalidateTypesCollectionCache();

        if (self::findByCode($code) !== 0) {
            throw new \RuntimeException(Loc::getMessage('ALEX_AUTOSERVICE_SP_DELETE_ERROR', [
                '#CODE#'  => $code,
                '#ERROR#' => $error !== '' ? $error : Loc::getMessage('ALEX_AUTOSERVICE_SP_DELETE_SILENT'),
            ]));
        }

        return true;
    }

    /**
     * Идентификатор строки в таблице типов.
     *
     * Отличается от ENTITY_TYPE_ID: служебные методы создания таблиц
     * принимают именно его.
     *
     * @param string $code
     *
     * @return int ID строки или 0
     */
    private static function getTypeRowId(string $code): int
    {
        $row = TypeTable::query()
            ->setSelect(['ID'])
            ->where('CODE', $code)
            ->setLimit(1)
            ->fetch();

        return $row ? (int) $row['ID'] : 0;
    }

    /**
     * @param string $code
     *
     * @return int ENTITY_TYPE_ID или 0
     */
    public static function findByCode(string $code): int
    {
        self::requireCrm();

        $row = TypeTable::query()
            ->setSelect(['ENTITY_TYPE_ID'])
            ->where('CODE', $code)
            ->setLimit(1)
            ->fetch();

        return $row ? (int) $row['ENTITY_TYPE_ID'] : 0;
    }

    /**
     * Сущность пользовательских полей смарт-процесса, например CRM_DYNAMIC_40.
     *
     * Читается у фабрики: зашивать такие идентификаторы нельзя, они зависят
     * от выделенного ENTITY_TYPE_ID.
     *
     * @param int $entityTypeId
     *
     * @throws \RuntimeException
     *
     * @return string
     */
    public static function getUserFieldEntityId(int $entityTypeId): string
    {
        $factory = self::getFactory($entityTypeId);

        return (string) $factory->getUserFieldEntityId();
    }

    /**
     * Идентификатор набора стадий категории смарт-процесса.
     *
     * @param int      $entityTypeId
     * @param int|null $categoryId null — категория по умолчанию
     *
     * @return string
     */
    public static function getStagesEntityId(int $entityTypeId, ?int $categoryId = null): string
    {
        $factory = self::getFactory($entityTypeId);

        if ($categoryId === null) {
            $category   = $factory->getDefaultCategory();
            $categoryId = $category ? (int) $category->getId() : 0;
        }

        return (string) $factory->getStagesEntityId($categoryId);
    }

    /**
     * @param int $entityTypeId
     *
     * @throws \RuntimeException
     *
     * @return \Bitrix\Crm\Service\Factory
     */
    public static function getFactory(int $entityTypeId)
    {
        self::requireCrm();

        $factory = Container::getInstance()->getFactory($entityTypeId);

        if (!$factory) {
            throw new \RuntimeException(Loc::getMessage(
                'ALEX_AUTOSERVICE_SP_NO_FACTORY',
                ['#ID#' => $entityTypeId]
            ));
        }

        return $factory;
    }

    /**
     * @return int
     */
    private static function getCurrentUserId(): int
    {
        global $USER;

        return is_object($USER) && $USER->IsAuthorized() ? (int) $USER->GetID() : 1;
    }

    /**
     * @throws \RuntimeException
     *
     * @return void
     */
    private static function requireCrm(): void
    {
        if (!\Bitrix\Main\Loader::includeModule('crm')) {
            throw new \RuntimeException(Loc::getMessage('ALEX_AUTOSERVICE_SP_NO_CRM'));
        }
    }
}
