<?php

use Bitrix\Main\Page\Asset;

require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/header.php"); ?>
<?php
$APPLICATION->SetTitle("ДЗ #5: Компонент списка таблицы БД");

Asset::getInstance()->addCss('//cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css');

?>
    <h1 class="mb-3"><?php $APPLICATION->ShowTitle() ?></h1>

    <h4 class="mb-3">Пояснительная записка</h4>
    <div>
        Был создан кастомный компонент, с опцией выбора нужной валюты
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
                <a href="/local/homeworks/homework5/curr/index.php"
                   class="d-flex justify-content-between align-items-center">
                <span>
                    Ссылка на тестовую страницу с компонентом
                </span>
                    <span class="badge bg-warning">
                    Ссылка на просмотр
                </span>
                </a>
            </li>
        </ul>

        <h2>Структура решения</h2>

<pre><span class="folder">local/</span>
└── components/
    └── curr/
        └── currency.rate/
            ├── <span class="file"><a href="/bitrix/admin/fileman_file_view.php?path=/local/components/curr/currency.rate/.description.php">.description.php</a></span>
            ├── <span class="file"><a href="/bitrix/admin/fileman_file_view.php?path=/local/components/curr/currency.rate/.parameters.php">.parameters.php</a></span>
            ├── <span class="file"><a href="/bitrix/admin/fileman_file_view.php?path=/local/components/curr/currency.rate/component.php">component.php</a></span>
            ├── lang/
            │   └── ru/
            └── templates/
                └── .default/
                    ├── <span class="file"><a href="/bitrix/admin/fileman_file_view.php?path=/local/components/curr/currency.rate/templates/.default/template.php">template.php</a></span>
                    └── <span class="file"><a href="/bitrix/admin/fileman_file_view.php?path=/local/components/curr/currency.rate/templates/.default/style.css">style.css</a></span>

</pre>

    </div>


<?php require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/footer.php"); ?>
