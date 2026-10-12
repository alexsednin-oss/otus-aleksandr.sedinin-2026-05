<?php

use Bitrix\Main\Page\Asset;

require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/header.php"); ?>
<?php
$APPLICATION->SetTitle("ДЗ #9: Написание своих активити для БП");

Asset::getInstance()->addCss('//cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css');


?>
<h1 class="mb-3"><?php $APPLICATION->ShowTitle() ?></h1>

<h4 class="mb-3">Пояснительная записка</h4>
<div>
    Написано кастомное активити для БП которое ходит в сервис DaData и по ИНН возвращает наименование Юр лица
    Если такой компании в системе нет, то создается новая карточка компании, и цепляется в соответствующее поле элемента инфоблока "Заявки".
</div>
<br>
<br>
<hr>


    <div style="color: red;font-style: italic;">
        &darr;&darr;&darr; ссылки ниже заменить на свои &darr;&darr;&darr;
    </div>

    <div class="card shadow-sm mt-4">
        <div class="card-header bg-success text-white">
            Файлы проекта
        </div>
        <ul class="list-group list-group-flush">

            <li class="list-group-item list-group-item-action">
                <a href="/services/lists/29/bp_edit/"
                   class="d-flex justify-content-between align-items-center">
                <span>
                    Ссылка на БП
                </span>
                    <span class="badge bg-primary">
                    Ссылка на просмотр
                </span>
                </a>
            </li>

            <li class="list-group-item list-group-item-action">
                <a href="/services/lists/29/view/0/?list_section_id="
                   class="d-flex justify-content-between align-items-center">
                <span>
                    Ссылка на ИБ
                </span>
                    <span class="badge bg-success">
                    Ссылка на просмотр
                </span>
                </a>
            </li>

            <li class="list-group-item list-group-item-action">
                <a href="/crm/company/list/"
                   class="d-flex justify-content-between align-items-center">
                <span>
                    Компании
                </span>
                    <span class="badge bg-success">
                    Ссылка на просмотр
                </span>
                </a>
            </li>
        </ul>
    </div>
<div class="card shadow-sm mt-4">
    <div class="card-header bg-success text-white">
        Файлы проекта
    </div>
</div>
<pre><span class="folder">local/modules/alex.newmodule/</span>
├── lib/
│   ├── <span class="file"><a href="/bitrix/admin/fileman_file_view.php?path=%2Flocal%2Fmodules%2Falex.newmodule%2Flib%2FDaDataClient.php&site=s1&lang=ru">DaDataClient.php</a></span>
│   ├── <span class="file"><a href="/bitrix/admin/fileman_file_view.php?path=%2Flocal%2Fmodules%2Falex.newmodule%2Flib%2FCrmCompanyBuilder.php&site=s1&lang=ru">CrmCompanyBuilder.php</a></span>
│   └── <span class="file"><a href="/bitrix/admin/fileman_file_view.php?path=%2Flocal%2Fmodules%2Falex.newmodule%2Flib%2FOrderIblockInstaller.php&site=s1&lang=ru">OrderIblockInstaller.php</a></span>
└── install/
    ├── <span class="file"><a href="/bitrix/admin/fileman_file_view.php?path=%2Flocal%2Fmodules%2Falex.newmodule%2Finstall%2Findex.php&site=s1&lang=ru">index.php</a></span>
    └── activities/
        └── alexdadatacompany/
            ├── <span class="file"><a href="/bitrix/admin/fileman_file_view.php?path=%2Flocal%2Fmodules%2Falex.newmodule%2Finstall%2Factivities%2Falexdadatacompany%2F.description.php&site=s1&lang=ru">.description.php</a></span>
            ├── <span class="file"><a href="/bitrix/admin/fileman_file_view.php?path=%2Flocal%2Fmodules%2Falex.newmodule%2Finstall%2Factivities%2Falexdadatacompany%2Falexdadatacompany.php&site=s1&lang=ru">alexdadatacompany.php</a></span>
            └── <span class="file"><a href="/bitrix/admin/fileman_file_view.php?path=%2Flocal%2Fmodules%2Falex.newmodule%2Finstall%2Factivities%2Falexdadatacompany%2Fproperties_dialog.php&site=s1&lang=ru">properties_dialog.php</a></span>
</pre>

<?php require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/footer.php"); ?>
