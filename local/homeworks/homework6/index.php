<?php

use Bitrix\Main\Page\Asset;

require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/header.php"); ?>
<?php
$APPLICATION->SetTitle("ДЗ #6: Написание своего модуля");

Asset::getInstance()->addCss('//cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css');


?>
<h1 class="mb-3"><?php $APPLICATION->ShowTitle() ?></h1>

<h4 class="mb-3">Пояснительная записка</h4>
<div>
   Реализован новый модуль, в котором выводятся данные из ORM таблицы.
    Кроме этого добавлен таб на сущности сделка(на нем так же выводятся данные из кастомной таблицы)
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
                <a href="../../../alex_newmodule/"
                   class="d-flex justify-content-between align-items-center">
                <span>
                    Ссылка на тестовую страницу с компонентом
                </span>
                    <span class="badge bg-warning">
                    Ссылка на просмотр
                </span>
                </a>
            </li>

            <li class="list-group-item list-group-item-action">
                <a href="/bitrix/admin/settings.php?lang=ru&mid=alex.newmodule&mid_menu=1"
                   class="d-flex justify-content-between align-items-center">
                <span>
                    Ссылка на страницу с настройками модуля
                </span>
                    <span class="badge bg-warning">
                    Ссылка на просмотр в <админке></админке>
                </span>
                </a>
            </li>

            <li class="list-group-item list-group-item-action">
                <a href="/bitrix/admin/partner_modules.php?lang=ru"
                   class="d-flex justify-content-between align-items-center">
                <span>
                    Ссылка на установленные решения, модуль: НАЗВАНИЕ_МОДУЛЯ
                </span>
                    <span class="badge bg-warning">
                    Ссылка на просмотр в админке
                </span>
                </a>
            </li>

            <li class="list-group-item list-group-item-action">
                <a href="/bitrix/admin/perfmon_table.php?lang=ru&table_name=alex_newmodule_item"
                   class="d-flex justify-content-between align-items-center">
                <span>
                    Ссылка на таблицу
                </span>
                    <span class="badge bg-warning">
                    Ссылка на просмотр в админке
                </span>
                </a>
            </li>

            <li class="list-group-item list-group-item-action">
                <a href="/bitrix/admin/fileman_file_view.php?path=/local/App/Debug/Log.php"
                   class="d-flex justify-content-between align-items-center">
                <span>
                    Ссылки на просмотр кода основных файлов ДЗ (связь таблиц, ORM, классы  и т.д.)
                </span>
                    <span class="badge bg-warning">
                    файл в админке
                </span>
                </a>
            </li>
        </ul>
    </div>
<h2>Структура решения</h2>

<pre><span class="folder">local/</span>
└── modules/
   └── alex.newmodule/
       │
       ├── <span class="file"><a href="/bitrix/admin/fileman_file_view.php?path=%2Flocal%2Fmodules%2Falex.newmodule%2Finclude.php&site=s1&lang=ru">include.php</a></span>
       │
       ├── lib
       │   ├── <span class="file"><a href="/bitrix/admin/fileman_file_view.php?path=%2Flocal%2Fmodules%2Falex.newmodule%2Flib%2FItemTable.php&site=s1&lang=ru">ItemTable.php</a></span>
       │   └── <span class="file"><a href="/bitrix/admin/fileman_file_view.php?path=%2Flocal%2Fmodules%2Falex.newmodule%2Flib%2FEventHandler.php&site=s1&lang=ru">EventHandler.php</a></span>
       │
       ├── lang/
       │   └── ru/
       │       └── install/
       │           └── <span class="file"><a href="/bitrix/admin/fileman_file_view.php?path=%2Flocal%2Fmodules%2Falex.newmodule%2Flang%2Fru%2Finstall%2Findex.php&site=s1&lang=ru">index.php</a></span>
       │
       └── install/
           ├── <span class="file"><a href="/bitrix/admin/fileman_file_view.php?path=%2Flocal%2Fmodules%2Falex.newmodule%2Finstall%2Findex.php&site=s1&lang=ru">index.php</a></span>
           ├── <span class="file"><a href="/bitrix/admin/fileman_file_view.php?path=%2Flocal%2Fmodules%2Falex.newmodule%2Finstall%2Fversion.php&site=s1&lang=ru">version.php</a></span>
           ├── <span class="file"><a href="/bitrix/admin/fileman_file_view.php?path=%2Flocal%2Fmodules%2Falex.newmodule%2Finstall%2Fstep1.php&site=s1&lang=ru">step1.php</a></span>
           ├── <span class="file"><a href="/bitrix/admin/fileman_file_view.php?path=%2Flocal%2Fmodules%2Falex.newmodule%2Finstall%2Fstep2.php&site=s1&lang=ru">step2.php</a></span>
           ├── <span class="file"><a href="/bitrix/admin/fileman_file_view.php?path=%2Flocal%2Fmodules%2Falex.newmodule%2Finstall%2Funstep1.php&site=s1&lang=ru">unstep1.php</a></span>
           ├── <span class="file"><a href="/bitrix/admin/fileman_file_view.php?path=%2Flocal%2Fmodules%2Falex.newmodule%2Finstall%2Funstep2.php&site=s1&lang=ru">unstep2.php</a></span>
           │
           ├── admin/
           │   └── <span class="file"><a href="/bitrix/admin/fileman_file_view.php?path=/local/">alex_newmodule_list.php</a></span>
           │
           └── public/
               └── alex_newmodule/
                   ├── <span class="file"><a href="/bitrix/admin/fileman_file_view.php?path=/local/">index.php</a></span>
                   └── <span class="file"><a href="/bitrix/admin/fileman_file_view.php?path=/local/">ajax_tab.php</a></span>
</pre>
<?php require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/footer.php"); ?>
