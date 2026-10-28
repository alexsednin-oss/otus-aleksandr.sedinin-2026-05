<?php

use Bitrix\Main\Page\Asset;

require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/header.php"); ?>
<?php
$APPLICATION->SetTitle("ДЗ #11: Локальное REST приложение дата последней коммуникации");

Asset::getInstance()->addCss('//cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css');


?>
<h1 class="mb-3"><?php $APPLICATION->ShowTitle() ?></h1>

<h4 class="mb-3">Пояснительная записка</h4>
<div>
    Релизовано приложение, которое, при осздании дела, получает событие от Б24, далее записывает в поле UF_CRM_LAST_COMMUNICATION текущие дату и время
</div>
<br>
<br>
<hr>

    <div class="card shadow-sm mt-4">
        <div class="card-header bg-success text-white">
            Файлы проекта
        </div>
        <ul class="list-group list-group-flush">

            <li class="list-group-item list-group-item-action">
                <a href="/crm/contact/details/12/?login=yes"
                   class="d-flex justify-content-between align-items-center">
                <span>
                    Ссылка на карточку контакта для теста
                </span>
                    <span class="badge bg-primary">
                    Ссылка на просмотр
                </span>
                </a>
            </li>

            <li class="list-group-item list-group-item-action">
                <a href="/alex_newmodule/app_install.php"
                   class="d-flex justify-content-between align-items-center">
                <span>
                    Ссылка на приложение
                </span>
                    <span class="badge bg-warning">
                    Ссылка на просмотр
                </span>
                </a>
            </li>
        </ul>
    </div>
<br>
<pre><span class="folder">local/modules/alex.newmodule/</span>
├── install/
│   ├── <span class="file"><a href="/bitrix/admin/fileman_file_edit.php?path=%2Flocal%2Fmodules%2Falex.newmodule%2Finstall%2Findex.php&full_src=Y&site=s1&lang=ru&&filter=Y&set_filter=Y">index.php</a></span>                            установщик модуля
│   │
│   └── public/
│       └── alex_newmodule/                  → копируется в корень сайта как /alex_newmodule/
│           ├── <span class="file"><a href="/bitrix/admin/fileman_file_edit.php?path=%2Flocal%2Fmodules%2Falex.newmodule%2Finstall%2Fpublic%2Falex_newmodule%2Fapp_install.php&full_src=Y&site=s1&lang=ru&&filter=Y&set_filter=Y">app_install.php</a></span>              установщик приложения
│           └── <span class="file"><a href="/bitrix/admin/fileman_file_edit.php?path=%2Flocal%2Fmodules%2Falex.newmodule%2Finstall%2Fpublic%2Falex_newmodule%2Fapp_handler.php&full_src=Y&site=s1&lang=ru&&filter=Y&set_filter=Y">app_handler.php</a></span>              обработчик приложения
│
└── lib/
    ├── <span class="file"><a href="/bitrix/admin/fileman_file_edit.php?path=%2Flocal%2Fmodules%2Falex.newmodule%2Flib%2FLastCommunicationInstaller.php&full_src=Y&site=s1&lang=ru&&filter=Y&set_filter=Y">LastCommunicationInstaller.php</a></span>       поле и его место в карточке
    │
    ├── <span class="file"><a href="/bitrix/admin/fileman_file_edit.php?path=%2Flocal%2Fmodules%2Falex.newmodule%2Flib%2FLastCommunicationHandler.php&full_src=Y&site=s1&lang=ru&&filter=Y&set_filter=Y">LastCommunicationHandler.php</a></span>         бизнес-логика
    │
    └── <span class="file"><a href="/bitrix/admin/fileman_file_edit.php?path=%2Flocal%2Fmodules%2Falex.newmodule%2Flib%2FLocalEventProvider.php&full_src=Y&site=s1&lang=ru&&filter=Y&set_filter=Y">LocalEventProvider.php</a></span>               транспорт событий на коробке
</pre>

<?php require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/footer.php"); ?>
