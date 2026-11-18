<?php

namespace Alex\Autoservice\Migration;

use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

/**
 * Базовый класс миграции.
 *
 * Версия берётся из имени класса: Migration20261104093000CompanyStructure
 * даёт версию 20261104093000. Такой приём избавляет от ручного
 * проставления версий и даёт естественный порядок применения.
 */
abstract class AbstractMigration
{
    /** @var string[] */
    protected array $report = [];

    /**
     * @return string
     */
    abstract public function getDescription(): string;

    /**
     * @return void
     */
    abstract public function up(): void;

    /**
     * @return void
     */
    abstract public function down(): void;

    /**
     * @return string
     */
    public function getVersion(): string
    {
        $shortName = (new \ReflectionClass($this))->getShortName();

        return preg_match('/^Migration(\d{14})/', $shortName, $matches) ? $matches[1] : $shortName;
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return (new \ReflectionClass($this))->getShortName();
    }

    /**
     * @return string[]
     */
    public function getReport(): array
    {
        return $this->report;
    }

    /**
     * @param string $message
     *
     * @return void
     */
    protected function note(?string $message): void
    {
        // Loc::getMessage() возвращает null, если фразы нет в языковом файле.
        // Прерывать из-за этого миграцию нельзя: данные она уже изменила,
        // поэтому пропуск виден в отчёте, а работа продолжается.
        $this->report[] = $message
            ?? (string) Loc::getMessage('ALEX_AUTOSERVICE_MIGRATION_MISSING_PHRASE');
    }

    /**
     * @param string $message
     *
     * @throws \RuntimeException
     *
     * @return never
     */
    protected function fail(?string $message)
    {
        throw new \RuntimeException(
            $message ?? (string) Loc::getMessage('ALEX_AUTOSERVICE_MIGRATION_MISSING_PHRASE')
        );
    }

    /**
     * @param string ...$modules
     *
     * @return void
     */
    protected function requireModules(string ...$modules): void
    {
        foreach ($modules as $module) {
            if (!\Bitrix\Main\Loader::includeModule($module)) {
                $this->fail(Loc::getMessage(
                    'ALEX_AUTOSERVICE_MIGRATION_MODULE_REQUIRED',
                    ['#MODULE#' => $module]
                ));
            }
        }
    }
}
