<?php

use Bitrix\Main\Page\Asset;

require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/header.php"); ?>
<?php
$APPLICATION->SetTitle("ДЗ #8: Учимся подключать свои скрипты, взаимодействовать с компонентами из фронтенда");

Asset::getInstance()->addCss('//cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css');


?>
<h1 class="mb-3"><?php $APPLICATION->ShowTitle() ?></h1>

<h4 class="mb-3">Пояснительная записка</h4>
<div>
    Реализован перехват нажатия на кнопку "Начать рабочий день" + "Продолжить". Всплывает модальное окно с подтверждением.
</div>
<br>
<br>
<hr>

    <div class="card shadow-sm mt-4">
        <div class="card-header bg-success text-white">
            Файлы проекта
        </div>
    </div>

<pre><span class="folder">local/</span>
│
├── lib/
│   └── <span class="file"><a href="/bitrix/admin/fileman_file_view.php?path=%2Flocal%2Fmodules%2Falex.newmodule%2Flib%2FWorkdayStartHandler.php&site=s1&lang=ru">WorkdayStartHandler.php</a></span>
│
└── install/
    ├── <span class="file"><a href="/bitrix/admin/fileman_file_view.php?path=%2Flocal%2Fmodules%2Falex.newmodule%2Finstall%2Findex.php&site=s1&lang=ru">index.php</a></span>
    │
    └── public/alex_newmodule/
        ├── <span class="file"><a href="/bitrix/admin/fileman_file_view.php?path=%2Flocal%2Fmodules%2Falex.newmodule%2Finstall%2Fpublic%2Falex_newmodule%2Fworkday_start.js&site=s1&lang=ru">workday_start.js</a></span>
        │
        └── <span class="file"><a href="/bitrix/admin/fileman_file_view.php?path=%2Flocal%2Fmodules%2Falex.newmodule%2Finstall%2Fpublic%2Falex_newmodule%2Fworkday_confirm.php&site=s1&lang=ru">workday_confirm.php</a></span>
</pre>

<?php require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/footer.php"); ?>
