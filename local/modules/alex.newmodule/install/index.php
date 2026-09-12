<?php

use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

Loader::includeModule('iblock');
Loader::includeModule('main');

class alex_newmodule extends CModule
{
    public $MODULE_ID = 'alex.newmodule';
    public $MODULE_VERSION;
    public $MODULE_VERSION_DATE;
    public $MODULE_NAME;
    public $MODULE_DESCRIPTION;

    public function __construct()
    {
        $arModuleVersion = [];
        include __DIR__.'/version.php';

        $this->MODULE_VERSION      = $arModuleVersion['VERSION'];
        $this->MODULE_VERSION_DATE = $arModuleVersion['VERSION_DATE'];
        $this->MODULE_NAME         = Loc::getMessage('ALEX_NEWMODULE_MODULE_NAME');
        $this->MODULE_DESCRIPTION  = Loc::getMessage('ALEX_NEWMODULE_MODULE_DESCRIPTION');
    }

    public function DoInstall()
    {
        global $APPLICATION, $step;

        $step = (int) ($step ?: 1);

        if ($step < 2) {
            $APPLICATION->IncludeAdminFile(
                Loc::getMessage('ALEX_NEWMODULE_INSTALL_TITLE'),
                $this->GetPath().'/install/step1.php'
            );
        } else {
            $this->InstallFiles();
            $this->InstallDB();

            RegisterModule($this->MODULE_ID);

            $this->InstallEvents();

            // Автозагрузчик классов модуля (\Alex\Newmodule\*).
            // Loader::includeModule здесь не подходит: модуль только что зарегистрирован.
            require_once $this->GetPath().'/include.php';

            try {
                $GLOBALS['ALEX_NEWMODULE_INSTALL_REPORT'] = \Alex\Newmodule\BookingInstaller::install();
            } catch (\Throwable $e) {
                $GLOBALS['ALEX_NEWMODULE_INSTALL_REPORT'] = [
                    'ОШИБКА при создании списка «Бронирование»: '.$e->getMessage(),
                ];
            }

            $APPLICATION->IncludeAdminFile(
                Loc::getMessage('ALEX_NEWMODULE_INSTALL_TITLE'),
                $this->GetPath().'/install/step2.php'
            );
        }
    }

    public function DoUninstall()
    {
        global $APPLICATION, $step;

        $step = (int) ($step ?: 1);

        if ($step < 2) {
            $APPLICATION->IncludeAdminFile(
                Loc::getMessage('ALEX_NEWMODULE_UNINSTALL_TITLE'),
                $this->GetPath().'/install/unstep1.php'
            );
        } else {
            require_once $this->GetPath().'/include.php';

            try {
                \Alex\Newmodule\BookingInstaller::uninstall();
            } catch (\Throwable $e) {
                // удаление служебного свойства не должно ломать деинсталляцию
            }

            $this->UnInstallEvents();
            $this->UnInstallDB();
            $this->UnInstallFiles();

            UnRegisterModule($this->MODULE_ID);

            $APPLICATION->IncludeAdminFile(
                Loc::getMessage('ALEX_NEWMODULE_UNINSTALL_TITLE'),
                $this->GetPath().'/install/unstep2.php'
            );
        }
    }

    /**
     * Обработчики регистрируются ДО создания свойства-виджета:
     * CIBlockProperty::Add проверяет USER_TYPE по списку из OnIBlockPropertyBuildList.
     */
    public function InstallEvents()
    {
        foreach ($this->getEventList() as $event) {
            RegisterModuleDependences(
                $event[0],
                $event[1],
                $this->MODULE_ID,
                $event[2],
                $event[3]
            );
        }
    }

    public function UnInstallEvents()
    {
        foreach ($this->getEventList() as $event) {
            UnRegisterModuleDependences(
                $event[0],
                $event[1],
                $this->MODULE_ID,
                $event[2],
                $event[3]
            );
        }
    }

    /**
     * [модуль-источник, событие, класс-обработчик, метод]
     */
    private function getEventList(): array
    {
        return [
            ['iblock', 'OnIBlockPropertyBuildList', '\Alex\Newmodule\ProcedurePickerPropertyType', 'GetUserTypeDescription'],
            ['iblock', 'OnAfterIBlockElementAdd', '\Alex\Newmodule\ProcedureSyncHandler', 'onAfterAdd'],
            ['iblock', 'OnAfterIBlockElementUpdate', '\Alex\Newmodule\ProcedureSyncHandler', 'onAfterUpdate'],
            ['main', 'OnBuildGlobalMenu', '\Alex\Newmodule\EventHandler', 'onBuildGlobalMenu'],
            ['crm', 'onEntityDetailsTabsInitialized', '\Alex\Newmodule\EventHandler', 'onEntityDetailsTabsInitialized'],
        ];
    }

    public function InstallDB()
    {
        require_once $this->GetPath().'/include.php';

        $connection = Application::getConnection();

        if (!$connection->isTableExists(\Alex\Newmodule\ItemTable::getTableName())) {
            \Alex\Newmodule\ItemTable::getEntity()->createDbTable();
        }

        if (\Alex\Newmodule\ItemTable::getList(['limit' => 1])->fetch() === false) {
            \Alex\Newmodule\ItemTable::add(['NAME' => 'Первая запись', 'VALUE' => 100]);
            \Alex\Newmodule\ItemTable::add(['NAME' => 'Вторая запись', 'VALUE' => 250]);
            \Alex\Newmodule\ItemTable::add(['NAME' => 'Третья запись', 'VALUE' => 75]);
        }
    }

    public function UnInstallDB()
    {
        require_once $this->GetPath().'/include.php';

        $connection = Application::getConnection();
        $tableName  = \Alex\Newmodule\ItemTable::getTableName();

        if ($connection->isTableExists($tableName)) {
            $connection->dropTable($tableName);
        }
    }

    public function InstallFiles()
    {
        CopyDirFiles(
            $this->GetPath().'/install/admin',
            $_SERVER['DOCUMENT_ROOT'].'/bitrix/admin',
            true,
            true
        );

        CopyDirFiles(
            $this->GetPath().'/install/public',
            $_SERVER['DOCUMENT_ROOT'],
            true,
            true
        );
    }

    public function UnInstallFiles()
    {
        $adminFile = $_SERVER['DOCUMENT_ROOT'].'/bitrix/admin/alex_newmodule_list.php';

        if (file_exists($adminFile)) {
            unlink($adminFile);
        }

        $publicDir = $_SERVER['DOCUMENT_ROOT'].'/alex_newmodule';

        foreach (['index.php', 'ajax_tab.php', 'booking_create.php'] as $fileName) {
            if (file_exists($publicDir.'/'.$fileName)) {
                unlink($publicDir.'/'.$fileName);
            }
        }

        @rmdir($publicDir);
    }

    public function GetPath()
    {
        return $_SERVER['DOCUMENT_ROOT'].'/local/modules/'.$this->MODULE_ID;
    }
}
