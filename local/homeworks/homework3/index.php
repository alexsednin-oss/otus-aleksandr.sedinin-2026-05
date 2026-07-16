<?php

use Bitrix\Main\Page\Asset;

require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/header.php"); ?>
<?php

/** @global $APPLICATION   */

$APPLICATION->SetTitle("ДЗ #3: Связывание моделей");

Asset::getInstance()->addCss('//cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css');

?>
    <h1 class="mb-3"><?php $APPLICATION->ShowTitle() ?></h1>

    <h4 class="mb-3">Пояснительная записка</h4>
    <div style="color: black;font-style: italic;">
        <h5>Были реализованы</h5>
        - Отображение всех врачей<br>
        - Отображение выбранного врача, его реквизитов и связанных процедур<br>
        - Добавление нового врача<br>
        - Добавление новой процедуры<br>
    </div>
    <br>
    <br>
    <hr>

    <div style="color: red;font-style: italic;">

    </div>


    <div class="card shadow-sm mt-4">
        <div class="card-header bg-success text-white">
            Файлы проекта
        </div>
        <ul class="list-group list-group-flush">
            <li class="list-group-item list-group-item-action">
                <a href="../../../services/lists/16/view/0/"
                   class="d-flex justify-content-between align-items-center">
                <span>
                    Список врачей
                </span>
                    <span class="badge bg-primary">
                   Ссылка на просмотр
                </span>
                </a>
            </li>
            <li class="list-group-item list-group-item-action">
                <a href="../../../services/lists/17/view/0/"
                   class="d-flex justify-content-between align-items-center">
                <span>
                    Список процедур
                </span>
                    <span class="badge bg-success">
                   Ссылка на просмотр
                </span>
                </a>
            </li>
            <li class="list-group-item list-group-item-action">
                <a href="Doctor/"
                   class="d-flex justify-content-between align-items-center">
                <span>
                    Врачи и процедуры
                </span>
                    <span class="badge bg-secondary">
                   Ссылка на просмотр
                </span>
                </a>
            </li>
            <li class="list-group-item list-group-item-action">
                <a href="/bitrix/admin/fileman_file_view.php?path=%2Flocal%2Fhomeworks%2Fhomework3%2FDoctor%2Findex.php&site=s1&lang=ru"
                   class="d-flex justify-content-between align-items-center">
                <span>
                    Ссылки на просмотр кода основных файлов ДЗ (связь таблиц и ORM)
                </span>
                    <span class="badge bg-warning">
                    файл в админке
                </span>
                </a>
            </li>
        </ul>
    </div>


<?php require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/footer.php"); ?>
