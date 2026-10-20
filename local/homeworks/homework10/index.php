<?php

use Bitrix\Main\Page\Asset;

require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/header.php"); ?>
<?php
$APPLICATION->SetTitle("ДЗ #10: Обработка событий");

Asset::getInstance()->addCss('//cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css');


?>
<h1 class="mb-3"><?php $APPLICATION->ShowTitle() ?></h1>

<h4 class="mb-3">Пояснительная записка</h4>
<div>
    Реализованы события обновления элемента в списке "Заявки" и сущности сделка.
    При обновлении ответственного или суммы, данные синхронизируются на связанный объект
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
                <a href="services/lists/28/view/0/"
                   class="d-flex justify-content-between align-items-center">
                <span>
                    Ссылка на ИБ Заявки
                </span>
                    <span class="badge bg-primary">
                    Ссылка на просмотр
                </span>
                </a>
            </li>

            <li class="list-group-item list-group-item-action">
                <a href="/crm/deal/category/0/"
                   class="d-flex justify-content-between align-items-center">
                <span>
                    Сделки
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
├── lib/
│   ├── <span class="file"><a href="/bitrix/admin/fileman_file_edit.php?path=%2Flocal%2Fmodules%2Falex.newmodule%2Flib%2FRequestIblockInstaller.php&full_src=Y&site=s1&lang=ru&&filter=Y&set_filter=Y">RequestIblockInstaller.php</a>       Установщик списка</span>
│   └── <span class="file"><a href="/bitrix/admin/fileman_file_edit.php?path=%2Flocal%2Fmodules%2Falex.newmodule%2Flib%2FDealRequestSync.php&full_src=Y&site=s1&lang=ru&&filter=Y&set_filter=Y">DealRequestSync.php</a>              Обработчик событий</span>
└── install/
    └── <span class="file"><a href="/bitrix/admin/fileman_file_edit.php?path=%2Flocal%2Fmodules%2Falex.newmodule%2Finstall%2Findex.php&full_src=Y&site=s1&lang=ru&&filter=Y&set_filter=Y">index.php</a></span>
</pre>

<?php require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/footer.php"); ?>
