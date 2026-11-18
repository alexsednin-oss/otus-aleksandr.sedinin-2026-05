<?php

namespace Alex\Autoservice\Migration;

use Alex\Autoservice\Service\Log;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Type\DateTime;

Loc::loadMessages(__FILE__);

/**
 * Менеджер миграций: находит классы, применяет и откатывает их,
 * ведёт журнал применённых версий.
 */
class Manager
{
    public const MODULE_ID = 'alex.autoservice';

    private const CLASS_NAMESPACE = '\\Alex\\Autoservice\\Migrations\\';

    /**
     * @return string
     */
    public static function getDirectory(): string
    {
        return dirname(__DIR__, 2) . '/migrations';
    }

    /**
     * Все миграции из каталога, отсортированные по версии.
     *
     * @return array<string, array{version:string, class:string, file:string}>
     */
    public static function getAvailable(): array
    {
        $available = [];

        foreach ((array) glob(self::getDirectory() . '/Migration*.php') as $file) {
            $shortName = basename($file, '.php');

            if (!preg_match('/^Migration(\d{14})/', $shortName, $matches)) {
                continue;
            }

            $available[$matches[1]] = [
                'version' => $matches[1],
                'class'   => self::CLASS_NAMESPACE . $shortName,
                'file'    => $file,
            ];
        }

        ksort($available);

        return $available;
    }

    /**
     * Миграция по версии: нужна тестам и точечным операциям,
     * когда применять менеджер целиком не требуется.
     *
     * @param string $version
     *
     * @throws \RuntimeException если файла с такой версией нет
     *
     * @return AbstractMigration
     */
    public static function get(string $version): AbstractMigration
    {
        $available = self::getAvailable();

        if (!isset($available[$version])) {
            throw new \RuntimeException(Loc::getMessage(
                'ALEX_AUTOSERVICE_MIGRATION_NOT_FOUND',
                ['#VERSION#' => $version]
            ));
        }

        return self::instantiate($available[$version]);
    }

    /**
     * Применённые версии: версия => строка журнала.
     *
     * @return array<string, array>
     */
    public static function getApplied(): array
    {
        $applied = [];

        foreach (MigrationTable::query()->setSelect(['*'])->fetchAll() as $row) {
            $applied[(string) $row['VERSION']] = $row;
        }

        ksort($applied);

        return $applied;
    }

    /**
     * Сводка состояния для вывода пользователю.
     *
     * @return array<int, array{version:string, name:string, description:string, applied:bool, appliedAt:?string}>
     */
    public static function getStatus(): array
    {
        $applied = self::getApplied();
        $status  = [];

        foreach (self::getAvailable() as $version => $item) {
            $migration = self::instantiate($item);

            $appliedAt = $applied[$version]['APPLIED_AT'] ?? null;

            $status[] = [
                'version'     => $version,
                'name'        => $migration->getName(),
                'description' => $migration->getDescription(),
                'applied'     => isset($applied[$version]),
                'appliedAt'   => $appliedAt instanceof DateTime ? $appliedAt->toString() : null,
            ];
        }

        return $status;
    }

    /**
     * Применяет все неприменённые миграции по возрастанию версии.
     *
     * @param string|null $target применить включительно до этой версии
     *
     * @return string[] отчёт
     */
    public static function up(?string $target = null): array
    {
        $report  = [];
        $applied = self::getApplied();
        $count   = 0;

        foreach (self::getAvailable() as $version => $item) {
            if (isset($applied[$version])) {
                continue;
            }

            if ($target !== null && $version > $target) {
                break;
            }

            $migration = self::instantiate($item);

            try {
                $migration->up();
            } catch (\Throwable $e) {
                $report[] = Loc::getMessage('ALEX_AUTOSERVICE_MIGRATION_FAILED', [
                    '#VERSION#' => $version,
                    '#NAME#'    => $migration->getName(),
                    '#ERROR#'   => $e->getMessage(),
                ]);

                $report = array_merge($report, $migration->getReport());

                Log::add(['stage' => 'MIGRATION_FAILED', 'version' => $version, 'error' => $e->getMessage()]);

                // Дальше идти нельзя: следующие миграции опираются на эту
                return $report;
            }

            $registration = MigrationTable::add([
                'VERSION'    => $version,
                'NAME'       => $migration->getName(),
                'APPLIED_AT' => new DateTime(),
            ]);

            if (!$registration->isSuccess()) {
                $report[] = Loc::getMessage('ALEX_AUTOSERVICE_MIGRATION_NOT_REGISTERED', [
                    '#VERSION#' => $version,
                    '#ERROR#'   => implode('; ', $registration->getErrorMessages()),
                ]);

                return $report;
            }

            $count++;

            $report[] = Loc::getMessage('ALEX_AUTOSERVICE_MIGRATION_APPLIED', [
                '#VERSION#'     => $version,
                '#DESCRIPTION#' => $migration->getDescription(),
            ]);

            $report = array_merge($report, array_map(
                static fn (string $line): string => '    ' . $line,
                $migration->getReport()
            ));

            Log::add(['stage' => 'MIGRATION_APPLIED', 'version' => $version, 'report' => $migration->getReport()]);
        }

        if ($count === 0) {
            $report[] = Loc::getMessage('ALEX_AUTOSERVICE_MIGRATION_NOTHING_TO_APPLY');
        }

        return $report;
    }

    /**
     * Откатывает миграции по убыванию версии.
     *
     * @param string|null $target откатить включительно до этой версии;
     *                            null — только последнюю применённую
     * @param bool        $all    откатить все применённые
     *
     * @return string[] отчёт
     */
    public static function down(?string $target = null, bool $all = false): array
    {
        $report    = [];
        $applied   = self::getApplied();
        $available = self::getAvailable();
        $versions  = array_keys($applied);

        rsort($versions);

        if (!$versions) {
            return [Loc::getMessage('ALEX_AUTOSERVICE_MIGRATION_NOTHING_TO_ROLLBACK')];
        }

        if (!$all && $target === null) {
            $versions = [reset($versions)];
        }

        foreach ($versions as $version) {
            if (!$all && $target !== null && $version < $target) {
                break;
            }

            if (!isset($available[$version])) {
                $report[] = Loc::getMessage('ALEX_AUTOSERVICE_MIGRATION_FILE_MISSING', ['#VERSION#' => $version]);
                continue;
            }

            $migration = self::instantiate($available[$version]);

            try {
                $migration->down();
            } catch (\Throwable $e) {
                $report[] = Loc::getMessage('ALEX_AUTOSERVICE_MIGRATION_ROLLBACK_FAILED', [
                    '#VERSION#' => $version,
                    '#ERROR#'   => $e->getMessage(),
                ]);

                return $report;
            }

            MigrationTable::delete((int) $applied[$version]['ID']);

            $report[] = Loc::getMessage('ALEX_AUTOSERVICE_MIGRATION_ROLLED_BACK', [
                '#VERSION#'     => $version,
                '#DESCRIPTION#' => $migration->getDescription(),
            ]);

            $report = array_merge($report, array_map(
                static fn (string $line): string => '    ' . $line,
                $migration->getReport()
            ));

            Log::add(['stage' => 'MIGRATION_ROLLED_BACK', 'version' => $version]);
        }

        return $report;
    }

    /**
     * @param array{class:string, file:string} $item
     *
     * @return AbstractMigration
     */
    private static function instantiate(array $item): AbstractMigration
    {
        require_once $item['file'];

        $class = $item['class'];

        if (!class_exists($class)) {
            throw new \RuntimeException(Loc::getMessage(
                'ALEX_AUTOSERVICE_MIGRATION_CLASS_MISSING',
                ['#CLASS#' => $class, '#FILE#' => $item['file']]
            ));
        }

        return new $class();
    }
}
