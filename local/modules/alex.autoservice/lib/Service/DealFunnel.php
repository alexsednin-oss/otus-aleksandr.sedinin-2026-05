<?php

namespace Alex\Autoservice\Service;

use Bitrix\Crm\Service\Container;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

/**
 * Направление сделок «Сервисное обслуживание».
 *
 * Воронка заводится фабрикой сделок (D7), стадии после этого приводит
 * к виду из ТЗ сервис Stages.
 */
class DealFunnel
{
    /**
     * Создаёт воронку, если её ещё нет.
     *
     * @param string $name
     *
     * @throws \RuntimeException
     *
     * @return array{ID:int, CREATED:bool}
     */
    public static function ensure(string $name): array
    {
        $factory  = self::getFactory();
        $existing = self::findByName($name);

        if ($existing) {
            return ['ID' => $existing, 'CREATED' => false];
        }

        $category = $factory->createCategory(['NAME' => $name]);

        if (!$category) {
            throw new \RuntimeException(Loc::getMessage('ALEX_AUTOSERVICE_FUNNEL_NOT_CREATED', ['#NAME#' => $name]));
        }

        $result = $category->save();

        if (!$result->isSuccess()) {
            throw new \RuntimeException(Loc::getMessage('ALEX_AUTOSERVICE_FUNNEL_SAVE_ERROR', [
                '#NAME#'  => $name,
                '#ERROR#' => implode('; ', $result->getErrorMessages()),
            ]));
        }

        $factory->clearCategoriesCache();

        return ['ID' => (int) $category->getId(), 'CREATED' => true];
    }

    /**
     * @param string $name
     *
     * @return int ID воронки или 0
     */
    public static function findByName(string $name): int
    {
        foreach (self::getFactory()->getCategories() as $category) {
            if ((string) $category->getName() === $name) {
                return (int) $category->getId();
            }
        }

        return 0;
    }

    /**
     * @param int $categoryId
     *
     * @return bool
     */
    /**
     * @param int $categoryId
     *
     * @throws \RuntimeException если CRM отказалась удалять направление
     *
     * @return bool false — направление не найдено
     */
    /**
     * @param int $categoryId
     *
     * @throws \RuntimeException если CRM отказалась удалять направление
     *
     * @return bool false — направление не найдено
     */
    public static function remove(int $categoryId): bool
    {
        if (!$categoryId) {
            return false;
        }

        $factory  = self::getFactory();
        $category = $factory->getCategory($categoryId);

        if (!$category) {
            return false;
        }

        $result = $category->delete();

        $factory->clearCategoriesCache();

        if (!$result->isSuccess()) {
            throw new \RuntimeException(Loc::getMessage('ALEX_AUTOSERVICE_FUNNEL_DELETE_ERROR', [
                '#ID#'    => $categoryId,
                '#ERROR#' => implode('; ', $result->getErrorMessages()),
            ]));
        }

        return true;
    }

    /**
     * Идентификатор набора стадий воронки, например DEAL_STAGE_5.
     *
     * @param int $categoryId
     *
     * @return string
     */
    public static function getStagesEntityId(int $categoryId): string
    {
        return (string) self::getFactory()->getStagesEntityId($categoryId);
    }

    /**
     * @throws \RuntimeException
     *
     * @return \Bitrix\Crm\Service\Factory
     */
    public static function getFactory()
    {
        if (!Loader::includeModule('crm')) {
            throw new \RuntimeException(Loc::getMessage('ALEX_AUTOSERVICE_FUNNEL_NO_CRM'));
        }

        $factory = Container::getInstance()->getFactory(\CCrmOwnerType::Deal);

        if (!$factory) {
            throw new \RuntimeException(Loc::getMessage('ALEX_AUTOSERVICE_FUNNEL_NO_FACTORY'));
        }

        return $factory;
    }
}
