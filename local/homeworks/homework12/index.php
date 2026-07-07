<?php

use Bitrix\Main\Page\Asset;

require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/header.php"); ?>
<?php
$APPLICATION->SetTitle("ДЗ #12: Собственные обработчики REST");

Asset::getInstance()->addCss('//cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css');


?>
<h1 class="mb-3"><?php $APPLICATION->ShowTitle() ?></h1>

<h4 class="mb-3">Пояснительная записка</h4>
<div>
    Тут добавить описание того что и как было реализовано.
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
                <a href="#"
                   class="d-flex justify-content-between align-items-center">
                <span>
                    Ссылка на тестовую страницу
                </span>
                    <span class="badge bg-primary">
                    Ссылка на просмотр
                </span>
                </a>
            </li>

            <li class="list-group-item list-group-item-action">
                <a href="#"
                   class="d-flex justify-content-between align-items-center">
                <span>
                    Ссылка на веб-хук: получения списка
                </span>
                    <span class="badge bg-warning">
                    Ссылка на просмотр
                </span>
                </a>
            </li>

            <li class="list-group-item list-group-item-action">
                <a href="#"
                   class="d-flex justify-content-between align-items-center">
                <span>
                    Ссылка на веб-хук: добавления элемента
                </span>
                    <span class="badge bg-warning">
                    Ссылка на просмотр
                </span>
                </a>
            </li>

            <li class="list-group-item list-group-item-action">
                <a href="#"
                   class="d-flex justify-content-between align-items-center">
                <span>
                    Ссылка на веб-хук: обновления элемента
                </span>
                    <span class="badge bg-warning">
                    Ссылка на просмотр
                </span>
                </a>
            </li>

            <li class="list-group-item list-group-item-action">
                <a href="#"
                   class="d-flex justify-content-between align-items-center">
                <span>
                    Ссылка на веб-хук: удаления элемента
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


<?php require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/footer.php"); ?>
