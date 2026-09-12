<?php

use Bitrix\Main\Page\Asset;

require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/header.php"); ?>
<?php
$APPLICATION->SetTitle("ДЗ #7: Создание кастомных полей и встраивание их в систему");

Asset::getInstance()->addCss('//cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css');


?>
<h1 class="mb-3"><?php $APPLICATION->ShowTitle() ?></h1>

<h4 class="mb-3">Пояснительная записка</h4>
<div>
    Реализован кастомный тип поля для инфоблока "Доктора".
    Поле для содания бронирований - по нажатию по процедуре открывается попап в который можно ввести фамилию и дату/время приема. Сохраняется в списке "Бронирования"

    Установка решения через установку модуля
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
                <a href="http://localhost/services/lists/26/view/0/?list_section_id="
                   class="d-flex justify-content-between align-items-center">
                <span>
                    Ссылка на список бронирование
                </span>
                    <span class="badge bg-warning">
                    Ссылка на просмотр
                </span>
                </a>
            </li>

            <li class="list-group-item list-group-item-action">
                <a href="http://localhost/services/lists/16/view/0/?list_section_id="
                   class="d-flex justify-content-between align-items-center">
                <span>
                    Ссылка на список врачей
                </span>
                    <span class="badge bg-warning">
                    Ссылка на просмотр
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

<pre><span class="folder">local/</span>
└── modules/
    └── alex.newmodule/
        │
        ├── lib/
        │   ├── <span class="file"><a href="/bitrix/admin/fileman_file_view.php?path=%2Flocal%2Fmodules%2Falex.newmodule%2Flib%2FBookingInstaller.php&full_src=Y&site=s1&lang=ru&&filter=Y&set_filter=Y">BookingInstaller.php</a></span>
        │   │
        │   ├── <span class="file"><a href="/bitrix/admin/fileman_file_view.php?path=%2Flocal%2Fmodules%2Falex.newmodule%2Flib%2FProcedurePickerPropertyType.php&full_src=Y&site=s1&lang=ru&&filter=Y&set_filter=Y">ProcedurePickerPropertyType.php</a></span>
        │   │
        │   └── <span class="file"><a href="/bitrix/admin/fileman_file_view.php?path=%2Flocal%2Fmodules%2Falex.newmodule%2Flib%2FProcedureSyncHandler.php&full_src=Y&site=s1&lang=ru&&filter=Y&set_filter=Y">ProcedureSyncHandler.php</a></span>
        │
        └── install/
            ├── <span class="file"><a href="/bitrix/admin/fileman_file_view.php?path=%2Flocal%2Fmodules%2Falex.newmodule%2Finstall%2Findex.php&site=s1&lang=ru">index.php</a></span>
            │
            ├── <span class="file"><a href="/bitrix/admin/fileman_file_view.php?path=%2Flocal%2Fmodules%2Falex.newmodule%2Finstall%2Fstep2.php&site=s1&lang=ru">step2.php</a></span>
            └── public/alex_newmodule/
                └── <span class="file"><a href="/bitrix/admin/fileman_file_view.php?path=%2Flocal%2Fmodules%2Falex.newmodule%2Finstall%2Fpublic%2Falex_newmodule%2Fbooking_create.php&full_src=Y&site=s1&lang=ru&&filter=Y&set_filter=Y">booking_create.php</a></span>

</pre>

<?php require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/footer.php"); ?>
