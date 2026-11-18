<?php

use Alex\Autoservice\Migration\Manager;
use Alex\Autoservice\Migration\MigrationTable;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ModuleManager;

Loc::loadMessages(__FILE__);

/**
 * Установщик модуля автоматизации сервисного центра.
 *
 * Установка создаёт таблицу версий, регистрирует обработчики, копирует
 * публичные файлы и применяет все миграции. Снятие спрашивает, удалять ли
 * данные: по умолчанию воронка, автомобили и сотрудники остаются на месте.
 */
class alex_autoservice extends CModule
{
    /** @var string */
    public $MODULE_ID = 'alex.autoservice';

    /** @var string */
    public $MODULE_VERSION;

    /** @var string */
    public $MODULE_VERSION_DATE;

    /** @var string */
    public $MODULE_NAME;

    /** @var string */
    public $MODULE_DESCRIPTION;

    /** @var string */
    public $PARTNER_NAME;

    /** @var string */
    public $PARTNER_URI;

    /**
     * Читает версию модуля из version.php.
     */
    public function __construct()
    {
        $arModuleVersion = [];

        include __DIR__ . '/version.php';

        $this->MODULE_VERSION      = $arModuleVersion['VERSION'];
        $this->MODULE_VERSION_DATE = $arModuleVersion['VERSION_DATE'];
        $this->MODULE_NAME         = Loc::getMessage('ALEX_AUTOSERVICE_MODULE_NAME');
        $this->MODULE_DESCRIPTION  = Loc::getMessage('ALEX_AUTOSERVICE_MODULE_DESCRIPTION');
        $this->PARTNER_NAME        = Loc::getMessage('ALEX_AUTOSERVICE_PARTNER_NAME');
        $this->PARTNER_URI         = '';
    }

    /**
     * Регистрирует модуль, разворачивает инфраструктуру и применяет миграции.
     *
     * @return void
     */
    public function DoInstall(): void
    {
        global $APPLICATION;

        $report = [];

        ModuleManager::registerModule($this->MODULE_ID);

        if (!Loader::includeModule($this->MODULE_ID)) {
            $this->finish(Loc::getMessage('ALEX_AUTOSERVICE_INSTALL_TITLE'), [
                Loc::getMessage('ALEX_AUTOSERVICE_INSTALL_MODULE_ERROR'),
            ]);

            return;
        }

        try {
            $report[] = MigrationTable::ensureTable();
        } catch (\Throwable $e) {
            $report[] = Loc::getMessage('ALEX_AUTOSERVICE_INSTALL_TABLE_ERROR', ['#ERROR#' => $e->getMessage()]);
        }

        $this->InstallEvents();
        $this->InstallFiles();

        $report[] = Loc::getMessage('ALEX_AUTOSERVICE_INSTALL_FILES_COPIED');

        // Миграции применяются здесь: решение должно разворачиваться
        // одной установкой, без обращения к командной строке
        $report[] = Loc::getMessage('ALEX_AUTOSERVICE_INSTALL_MIGRATIONS');

        try {
            foreach (Manager::up() as $line) {
                $report[] = '— ' . $line;
            }
        } catch (\Throwable $e) {
            $report[] = Loc::getMessage('ALEX_AUTOSERVICE_INSTALL_MIGRATIONS_ERROR', ['#ERROR#' => $e->getMessage()]);
        }

        $report[] = Loc::getMessage('ALEX_AUTOSERVICE_INSTALL_ADMIN_PAGE');

        $this->finish(Loc::getMessage('ALEX_AUTOSERVICE_INSTALL_TITLE'), $report);
    }

    /**
     * Снимает модуль. Данные удаляются только по явному согласию.
     *
     * @return void
     */
    public function DoUninstall(): void
    {
        global $APPLICATION;

        $step = (int) ($_REQUEST['step'] ?? 1);

        if ($step < 2) {
            $APPLICATION->IncludeAdminFile(
                Loc::getMessage('ALEX_AUTOSERVICE_UNINSTALL_TITLE'),
                __DIR__ . '/unstep1.php'
            );

            return;
        }

        $report   = [];
        $withData = ($_REQUEST['save_data'] ?? 'Y') !== 'Y';

        if ($withData && Loader::includeModule($this->MODULE_ID)) {
            $report[] = Loc::getMessage('ALEX_AUTOSERVICE_UNINSTALL_ROLLBACK');

            try {
                foreach (Manager::down(null, true) as $line) {
                    $report[] = '— ' . $line;
                }
            } catch (\Throwable $e) {
                $report[] = Loc::getMessage('ALEX_AUTOSERVICE_UNINSTALL_ERROR', ['#ERROR#' => $e->getMessage()]);
            }

            MigrationTable::dropTable();

            $report[] = Loc::getMessage('ALEX_AUTOSERVICE_UNINSTALL_TABLE_DROPPED');
        } else {
            $report[] = Loc::getMessage('ALEX_AUTOSERVICE_UNINSTALL_DATA_KEPT');
        }

        $this->UnInstallEvents();
        $this->UnInstallFiles();

        ModuleManager::unRegisterModule($this->MODULE_ID);

        $this->finish(Loc::getMessage('ALEX_AUTOSERVICE_UNINSTALL_TITLE'), $report);
    }

    /**
     * @return void
     */
    public function InstallEvents(): void
    {
        foreach ($this->getEvents() as $event) {
            RegisterModuleDependences($event[0], $event[1], $this->MODULE_ID, $event[2], $event[3]);
        }
    }

    /**
     * @return void
     */
    public function UnInstallEvents(): void
    {
        foreach ($this->getEvents() as $event) {
            UnRegisterModuleDependences($event[0], $event[1], $this->MODULE_ID, $event[2], $event[3]);
        }
    }

    /**
     * Публичные файлы и страница администрирования.
     *
     * @return bool
     */
    public function InstallFiles(): bool
    {
        CopyDirFiles(__DIR__ . '/public', $_SERVER['DOCUMENT_ROOT'], true, true);
        CopyDirFiles(__DIR__ . '/admin', $_SERVER['DOCUMENT_ROOT'] . '/bitrix/admin', true, true);
        CopyDirFiles(__DIR__ . '/js', $_SERVER['DOCUMENT_ROOT'] . '/bitrix/js', true, true);

        return true;
    }

    /**
     * @return bool
     */
    public function UnInstallFiles(): bool
    {
        DeleteDirFilesEx('/alex_autoservice/');
        DeleteDirFilesEx('/bitrix/js/alex.autoservice/');
        DeleteDirFiles(__DIR__ . '/admin', $_SERVER['DOCUMENT_ROOT'] . '/bitrix/admin');

        return true;
    }

    /**
     * @return array<int, array{0:string, 1:string, 2:string, 3:string}>
     */
    private function getEvents(): array
    {
        return [
            ['rest', 'OnRestServiceBuildDescription', '\Alex\Autoservice\Rest\RestService', 'onRestServiceBuildDescription'],
            ['crm', 'onEntityDetailsTabsInitialized', '\Alex\Autoservice\Handler\ContactTab', 'onEntityDetailsTabsInitialized'],
            ['crm', 'OnBeforeCrmDealAdd', '\Alex\Autoservice\Handler\DealGuard', 'onBeforeAdd'],
            ['main', 'OnUserTypeBuildList', '\Alex\Autoservice\UserField\CarType', 'getUserTypeDescription'],
        ];
    }

    /**
     * Показывает отчёт о выполненной операции.
     *
     * @param string   $title
     * @param string[] $report
     *
     * @return void
     */
    private function finish(string $title, array $report): void
    {
        global $APPLICATION;

        $GLOBALS['ALEX_AUTOSERVICE_REPORT'] = $report;

        $APPLICATION->IncludeAdminFile($title, __DIR__ . '/step1.php');
    }
}
