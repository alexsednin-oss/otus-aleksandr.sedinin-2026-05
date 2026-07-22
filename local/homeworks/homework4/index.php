<?php

use Bitrix\Main\Page\Asset;

require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/header.php"); ?>
<?php

/**
 *  @var CMain $APPLICATION
 */
$APPLICATION->SetTitle("ДЗ #4: Создание своих таблиц БД и написание модели данных к ним");

Asset::getInstance()->addCss('//cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css');

?>
    <h1 class="mb-3"><?php $APPLICATION->ShowTitle() ?></h1>

    <h4 class="mb-3">Пояснительная записка</h4>
    <div>
        Было реализовано:
        <ul>
            <li>Созданы 2 инфоблока, устанавливаются через файл install.php</li>
            <li>Создана ORM таблица, описана в классе ProductTable.php</li>
            <li>Создан класс для вывода данных ProductRepository.php</li>
            <li>Созданы файлы создания и удаления таблиц(и единица данных в таблицах), для того чтобы было удобно переносить решение через GIT</li>
        </ul>
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
                <a href="/bitrix/admin/perfmon_table.php?lang=ru&table_name=meatshop_product"
                   class="d-flex justify-content-between align-items-center">
                <span>
                    Ссылка на таблицу
                </span>
                    <span class="badge bg-success">
                   Ссылка на просмотр в админке
                </span>
                </a>
            </li>
            <li class="list-group-item list-group-item-action">
                <a href="http://localhost/bitrix/admin/iblock_admin.php?type=lists&lang=ru&admin=N"
                   class="d-flex justify-content-between align-items-center">
                <span>
                    Список на ИБ 1
                </span>
                    <span class="badge bg-primary">
                   Ссылка на просмотр в админке
                </span>
                </a>
            </li>
            <li class="list-group-item list-group-item-action">
                <a href="http://localhost/bitrix/admin/iblock_admin.php?type=lists&lang=ru&admin=N"
                   class="d-flex justify-content-between align-items-center">
                <span>
                    Список на ИБ 2
                </span>
                    <span class="badge bg-primary">
                   Ссылка на просмотр в админке
                </span>
                </a>
            </li>
            <li class="list-group-item list-group-item-action">
                <a href="/local/homeworks/homework4/ormdz/index.php"
                   class="d-flex justify-content-between align-items-center">
                <span>
                    Ссылка на тестовую страницу
                </span>
                    <span class="badge bg-secondary">
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

        <h2>Структура решения</h2>

        <pre><span class="folder">local/</span>
├── <span class="folder">Install/</span>
│   └── <span class="folder">ORMDZ/</span>
│       ├── <span class="file"><a href="/bitrix/admin/fileman_file_view.php?path=/local/Install/ORMDZ/install.php">install.php</a></span>
│       └── <span class="file"><a href="/bitrix/admin/fileman_file_view.php?path=/local/Install/ORMDZ/uninstall.php">uninstall.php</a></span>
├── <span class="folder">App/</span>
│   └── <span class="folder">ORMDZ/</span>
│       ├── <span class="folder">Model/</span>
│       │   ├── <span class="file"><a href="/bitrix/admin/fileman_file_view.php?path=/local/App/ORMDZ/Model/ProductTable.php">ProductTable.php</a></span>
│       │   ├── <span class="file"><a href="/bitrix/admin/fileman_file_view.php?path=/local/App/ORMDZ/Model/AbstractMeatShopElementTable.php">AbstractOrmdzElementTable.php</a></span>
│       │   ├── <span class="file"><a href="/bitrix/admin/fileman_file_view.php?path=/local/App/ORMDZ/Model/ManufacturerElementTable.php">ManufacturerElementTable.php</a></span>
│       │   └── <span class="file"><a href="/bitrix/admin/fileman_file_view.php?path=/local/App/ORMDZ/Model/ProductTypeElementTable.php">ProductTypeElementTable.php</a></span>
│       └── <span class="folder">Repository/</span>
│           └── <span class="file"><a href="/bitrix/admin/fileman_file_view.php?path=/local/App/ORMDZ/Repository/ProductRepository.php">ProductRepository.php</a></span>
└── <span class="folder">homeworks/</span>
    └── <span class="folder">homework4/</span>
        └── <span class="folder">ormdz/</span>
            └── <span class="file"><a href="/bitrix/admin/fileman_file_view.php?path=/local/homeworks/homework4/ormdz/index.php&site=s1&lang=ru">index.php</a></span>
</pre>

    </div>


<?php require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/footer.php"); ?>
