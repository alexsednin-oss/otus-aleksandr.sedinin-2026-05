<?php

use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

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
            $this->UnInstallDB();
            $this->UnInstallFiles();

            UnRegisterModule($this->MODULE_ID);

            $APPLICATION->IncludeAdminFile(
                Loc::getMessage('ALEX_NEWMODULE_UNINSTALL_TITLE'),
                $this->GetPath().'/install/unstep2.php'
            );
        }
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

        $publicFile = $_SERVER['DOCUMENT_ROOT'].'/alex_newmodule/index.php';
        if (file_exists($publicFile)) {
            unlink($publicFile);
            @rmdir($_SERVER['DOCUMENT_ROOT'].'/alex_newmodule');
        }
    }

    public function GetPath()
    {
        return $_SERVER['DOCUMENT_ROOT'].'/local/modules/'.$this->MODULE_ID;
    }
}
