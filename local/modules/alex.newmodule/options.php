<?php

/**
 * Bitrix Framework
 * @package bitrix
 * @subpackage alex.newmodule
 * @copyright 2001-2025 Bitrix
 */

use \Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);
Loc::loadMessages($_SERVER['DOCUMENT_ROOT'].BX_ROOT.'/modules/main/options.php');

$module_id = 'alex.newmodule';
CModule::IncludeModule($module_id);

$MOD_RIGHT = $APPLICATION->GetGroupRight($module_id);

if ($MOD_RIGHT >= 'R'):

    // Одна настройка типа "note" с текстом
    $arAllOptions = [
        ['note' => Loc::getMessage('ALEX_NEWMODULE_OPTIONS_NOTE')],
    ];

    $aTabs = [];
    $aTabs[] = [
        'DIV'   => 'set',
        'TAB'   => Loc::getMessage('MAIN_TAB_SET'),
        'ICON'  => 'wiki_settings',
        'TITLE' => Loc::getMessage('MAIN_TAB_TITLE_SET'),
    ];

    $tabControl = new CAdminTabControl('tabControl', $aTabs);
    ?>
    <style>
        table.edit-table td.field-name {
            width: 40% !important;
        }
    </style>
    <?
    $tabControl->Begin();
    ?>
    <form method="POST" action="<?echo $APPLICATION->GetCurPage()?>?mid=<?=htmlspecialcharsbx($mid)?>&lang=<?=LANGUAGE_ID?>" name="alex_newmodule_settings">
        <?$tabControl->BeginNextTab();?>
        <?__AdmSettingsDrawList($module_id, $arAllOptions);?>
        <?$tabControl->Buttons();?>
        <script>
            function RestoreDefaults()
            {
                if (confirm('<?echo AddSlashes(Loc::getMessage('MAIN_HINT_RESTORE_DEFAULTS_WARNING'))?>'))
                    window.location = "<?echo $APPLICATION->GetCurPage()?>?RestoreDefaults=Y&lang=<?echo LANG?>&mid=<?echo rawurlencode($mid)."&".bitrix_sessid_get();?>";
            }
        </script>
        <input type="submit" name="Update" <?if ($MOD_RIGHT<'W') echo "disabled" ?> value="<?echo Loc::getMessage('MAIN_SAVE')?>">
        <input type="reset" name="reset" value="<?echo Loc::getMessage('MAIN_RESET')?>">
        <input type="hidden" name="Update" value="Y">
        <?=bitrix_sessid_post();?>
        <input type="button" <?if ($MOD_RIGHT<'W') echo "disabled" ?> title="<?echo Loc::getMessage('MAIN_HINT_RESTORE_DEFAULTS')?>" OnClick="RestoreDefaults();" value="<?echo Loc::getMessage('MAIN_RESTORE_DEFAULTS')?>">
        <?$tabControl->End();?>
    </form>
<?endif;
