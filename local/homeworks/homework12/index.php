<?php

use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\Page\Asset;

require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/header.php"); ?>
<?php

/**
 * @var CMain $APPLICATION
 * @var CUser $USER
 */
$APPLICATION->SetTitle("ДЗ #12: Собственные обработчики REST");

Asset::getInstance()->addCss('//cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css');

$moduleReady = Loader::includeModule('alex.newmodule');
$isAdmin     = is_object($USER) && $USER->IsAdmin();

$webhooksReady = true;

foreach (['create', 'read', 'update', 'delete'] as $op) {
    if (Option::get('alex.newmodule', 'meatshop_webhook_' . $op, '') === '') {
        $webhooksReady = false;
    }
}

$iblockAdmin = '/bitrix/admin/iblock_element_admin.php?lang=ru&type=lists&IBLOCK_ID=';
$manufacturerIblock = $moduleReady ? \Alex\Newmodule\Rest\MeatShopIblock::manufacturerId() : 0;
$productTypeIblock  = $moduleReady ? \Alex\Newmodule\Rest\MeatShopIblock::productTypeId() : 0;

?>
    <style>
        .demo-log { max-height: 520px; overflow-y: auto; }
        .demo-log pre { margin: 0; font-size: 12px; white-space: pre-wrap; word-break: break-word; }
        .demo-entry { border-left: 4px solid #adb5bd; }
        .demo-entry.ok { border-left-color: #198754; }
        .demo-entry.err { border-left-color: #dc3545; }
        .folder { color: #0d6efd; font-weight: 600; }
        .file a { text-decoration: none; }
    </style>

    <h1 class="mb-3"><?php $APPLICATION->ShowTitle() ?></h1>

    <h4 class="mb-3">Пояснительная записка</h4>
    <div>
        Реализован CRUD над ORM-таблицей <code>meatshop_product</code> из ДЗ #4 в виде четырёх
        собственных REST-методов, каждый вызывается своим входящим вебхуком.
    </div>

    <hr>

    <!-- ======================================================== демо-стенд -->

    <div class="card shadow-sm mt-4">
        <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
            <span>Демо-стенд: вызов ручек через вебхуки</span>
            <span class="badge bg-light text-dark" id="demo-state">проверяю…</span>
        </div>

        <div class="card-body">

            <?php if (!$isAdmin): ?>
                <div class="alert alert-warning mb-0">
                    Демо-стенд работает только под администратором портала: вызовы идут через серверный
                    прокси, чтобы токены вебхуков не попадали в HTML страницы.
                </div>
            <?php else: ?>

                <?php if (!$webhooksReady): ?>
                    <div class="alert alert-danger">
                        Созданы не все вебхуки. Прогоните скрипт создания вебхуков в
                        <a href="/bitrix/admin/php_command_line.php?lang=ru">PHP-консоли</a> — он выпустит
                        по токену на каждую операцию.
                    </div>
                <?php endif; ?>

                <div class="row g-3 mb-3">
                    <div class="col-md-2">
                        <label class="form-label">ID строки</label>
                        <input type="text" class="form-control" id="f-id" placeholder="после create">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">NAME</label>
                        <input type="text" class="form-control" id="f-name" value="Грудинка">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">PRICE</label>
                        <input type="text" class="form-control" id="f-price" value="720">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">MANUFACTURER</label>
                        <input type="text" class="form-control" id="f-manufacturer" value="Мясной двор">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">PRODUCT_TYPE</label>
                        <input type="text" class="form-control" id="f-type" value="Свинина">
                    </div>
                </div>

                <div class="d-flex flex-wrap gap-2 mb-3">
                    <button class="btn btn-success" data-demo="create">
                        meatshop_product.<b>create</b>
                    </button>
                    <button class="btn btn-primary" data-demo="read-one">
                        meatshop_product.<b>read</b> по ID
                    </button>
                    <button class="btn btn-outline-primary" data-demo="read-list">
                        meatshop_product.<b>read</b> список
                    </button>
                    <button class="btn btn-warning" data-demo="update">
                        meatshop_product.<b>update</b>
                    </button>
                    <button class="btn btn-danger" data-demo="delete">
                        meatshop_product.<b>delete</b>
                    </button>
                </div>

                <div class="d-flex flex-wrap gap-2 mb-3 align-items-center">
                    <button class="btn btn-dark" data-demo="scenario">
                        ▶ Сценарий целиком: create → read → update → read → delete → read
                    </button>
                    <button class="btn btn-outline-dark btn-sm" data-demo="urls">
                        Показать URL вебхуков
                    </button>
                    <button class="btn btn-outline-secondary btn-sm" data-demo="clear">
                        Очистить журнал
                    </button>
                    <a class="btn btn-outline-secondary btn-sm"
                       href="/bitrix/admin/fileman_file_view.php?path=/local/logs/meatshop_<?= date('d.m.Y') ?>.log"
                       target="_blank">Лог за сегодня</a>
                </div>

                <div class="demo-log border rounded p-2 bg-light" id="demo-log">
                    <div class="text-muted small">Нажмите кнопку — здесь появятся отправленный пакет и ответ вебхука.</div>
                </div>

            <?php endif; ?>
        </div>
    </div>

<?php if ($isAdmin): ?>
    <script>
        (function () {
            const SESSID = '<?= bitrix_sessid() ?>';
            const AJAX   = '/local/homeworks/homework12/ajax.php';

            const log = document.getElementById('demo-log');
            const val = (id) => document.getElementById(id).value.trim();

            function entry(title, payload, response, ok, note) {
                const block = document.createElement('div');
                block.className = 'demo-entry bg-white rounded p-2 mb-2 ' + (ok ? 'ok' : 'err');
                block.innerHTML =
                    '<div class="d-flex justify-content-between align-items-center mb-1">'
                    + '<b>' + title + '</b>'
                    + '<span class="badge ' + (ok ? 'bg-success' : 'bg-danger') + '">'
                    + (ok ? 'успех' : 'отказ') + '</span></div>'
                    + (note ? '<div class="small text-muted mb-1">' + note + '</div>' : '')
                    + '<div class="small text-muted">отправлено</div>'
                    + '<pre>' + payload + '</pre>'
                    + '<div class="small text-muted mt-1">получено</div>'
                    + '<pre>' + response + '</pre>';

                if (log.firstChild && log.firstChild.className === 'text-muted small') {
                    log.innerHTML = '';
                }

                log.prepend(block);
            }

            async function call(op, params, title) {
                const form = new FormData();
                form.append('sessid', SESSID);
                form.append('action', 'call');
                form.append('op', op);

                Object.keys(params).forEach((key) => form.append(key, params[key]));

                let data;

                try {
                    const response = await fetch(AJAX, { method: 'POST', body: form });
                    data = await response.json();
                } catch (e) {
                    entry(title || op, JSON.stringify(params, null, 2), String(e), false);
                    return null;
                }

                if (data.error && data.status === undefined) {
                    entry(title || op, JSON.stringify(params, null, 2), data.error, false);
                    return null;
                }

                entry(
                    (title || op) + ' — ' + data.method,
                    JSON.stringify(data.request, null, 2),
                    'HTTP ' + data.status + '\n' + JSON.stringify(data.response, null, 2),
                    data.ok,
                    data.url
                );

                // ID созданной строки подставляем в поле, чтобы следующие кнопки работали
                if (data.ok && data.response && data.response.result && data.response.result.ID) {
                    document.getElementById('f-id').value = data.response.result.ID;
                }

                return data;
            }

            function unique(prefix) {
                return prefix + ' ' + String(Date.now()).slice(-6);
            }

            const actions = {
                'create': () => call('create', {
                    NAME:         val('f-name'),
                    PRICE:        val('f-price'),
                    MANUFACTURER: val('f-manufacturer'),
                    PRODUCT_TYPE: val('f-type'),
                }, 'Создание товара'),

                'read-one': () => call('read', { ID: val('f-id') }, 'Чтение по ID'),

                'read-list': () => call('read', { LIMIT: 5 }, 'Чтение списка'),

                'update': () => call('update', {
                    ID:    val('f-id'),
                    NAME:  val('f-name') + ' (обновлён)',
                    PRICE: val('f-price'),
                }, 'Обновление NAME и PRICE'),

                'delete': () => call('delete', { ID: val('f-id') }, 'Удаление строки'),

                'clear': () => {
                    log.innerHTML = '<div class="text-muted small">Журнал очищен.</div>';
                },

                'urls': async () => {
                    const form = new FormData();
                    form.append('sessid', SESSID);
                    form.append('action', 'urls');

                    const data = await (await fetch(AJAX, { method: 'POST', body: form })).json();

                    entry('URL вебхуков', '—', JSON.stringify(data.urls, null, 2), !!data.ok,
                        'Токены полные — не показывайте экран посторонним');
                },

                'scenario': async () => {
                    const suffix       = String(Date.now()).slice(-6);
                    const manufacturer = unique('Демо цех');
                    const productType  = unique('Демо вид');
                    const name         = 'Демо товар ' + suffix;

                    const created = await call('create', {
                        NAME: name, PRICE: 499,
                        MANUFACTURER: manufacturer, PRODUCT_TYPE: productType,
                    }, '1/6 create — справочников ещё нет, создаются');

                    if (!created || !created.ok) { return; }

                    const id = created.response.result.ID;

                    await call('read',   { ID: id }, '2/6 read — наименования вместо ID');
                    await call('update', { ID: id, NAME: name + ' (обновлён)', PRICE: 777 },
                        '3/6 update — только NAME и PRICE');
                    await call('read',   { ID: id }, '4/6 read — изменения на месте, справочники целы');
                    await call('delete', { ID: id }, '5/6 delete — удаляется только строка');
                    await call('read',   { ID: id }, '6/6 read — строки нет, ERROR_NOT_FOUND');

                    await call('create', {
                        NAME: name + ' — контрольный', PRICE: 100,
                        MANUFACTURER: manufacturer, PRODUCT_TYPE: productType,
                    }, 'Контроль: справочники выжили, CREATED = false');
                },
            };

            document.querySelectorAll('[data-demo]').forEach((button) => {
                button.addEventListener('click', async () => {
                    const action = actions[button.dataset.demo];

                    if (!action) { return; }

                    button.disabled = true;

                    try {
                        await action();
                    } finally {
                        button.disabled = false;
                    }
                });
            });

            // Состояние стенда в шапке карточки
            (async () => {
                const form = new FormData();
                form.append('sessid', SESSID);
                form.append('action', 'status');

                try {
                    const data = await (await fetch(AJAX, { method: 'POST', body: form })).json();
                    const badge = document.getElementById('demo-state');

                    if (!data.ok) {
                        badge.className = 'badge bg-danger';
                        badge.textContent = data.error;
                        return;
                    }

                    const missing = Object.keys(data.tokens).filter((op) => !data.tokens[op]);

                    badge.className = (missing.length || data.methods.length < 4) ? 'badge bg-warning text-dark' : 'badge bg-success';
                    badge.textContent = 'методов: ' + data.methods.length + ' из 4, вебхуков: '
                        + (4 - missing.length) + ' из 4';
                } catch (e) {
                    document.getElementById('demo-state').textContent = 'недоступен';
                }
            })();
        })();
    </script>
<?php endif; ?>

    <!-- ===================================================== файлы проекта -->

    <div class="card shadow-sm mt-4">
        <div class="card-header bg-success text-white">
            Файлы и объекты проекта
        </div>
        <ul class="list-group list-group-flush">
            <li class="list-group-item list-group-item-action">
                <a href="/bitrix/admin/perfmon_table.php?lang=ru&table_name=meatshop_product"
                   class="d-flex justify-content-between align-items-center">
                    <span>Таблица <code>meatshop_product</code></span>
                    <span class="badge bg-success">просмотр в админке</span>
                </a>
            </li>
            <li class="list-group-item list-group-item-action">
                <a href="<?= $iblockAdmin . $manufacturerIblock ?>"
                   class="d-flex justify-content-between align-items-center">
                    <span>Инфоблок «Производители (meatshop)», ID <?= $manufacturerIblock ?></span>
                    <span class="badge bg-primary">элементы</span>
                </a>
            </li>
            <li class="list-group-item list-group-item-action">
                <a href="<?= $iblockAdmin . $productTypeIblock ?>"
                   class="d-flex justify-content-between align-items-center">
                    <span>Инфоблок «Тип товара (meatshop)», ID <?= $productTypeIblock ?></span>
                    <span class="badge bg-primary">элементы</span>
                </a>
            </li>
            <li class="list-group-item list-group-item-action">
                <a href="/devops/" class="d-flex justify-content-between align-items-center">
                    <span>Входящие вебхуки: по одному на операцию, право <code>meatshop</code></span>
                    <span class="badge bg-warning">раздел «Разработчикам»</span>
                </a>
            </li>
            <li class="list-group-item list-group-item-action">
                <a href="/local/homeworks/homework4/ormdz/index.php"
                   class="d-flex justify-content-between align-items-center">
                    <span>Страница вывода таблицы из ДЗ #4</span>
                    <span class="badge bg-secondary">просмотр</span>
                </a>
            </li>
            <li class="list-group-item list-group-item-action">
                <a href="/bitrix/admin/fileman_file_view.php?path=/local/logs/meatshop_<?= date('d.m.Y') ?>.log"
                   class="d-flex justify-content-between align-items-center">
                    <span>Лог принятых данных и результатов обработки за сегодня</span>
                    <span class="badge bg-dark">файл</span>
                </a>
            </li>
        </ul>

        <div class="card-body">
            <h5>Структура решения</h5>
            <pre><span class="folder">local/modules/alex.newmodule/</span>
├── <span class="folder">install/</span>
│   └── <span class="file"><a href="/bitrix/admin/fileman_file_view.php?path=/local/modules/alex.newmodule/install/index.php">index.php</a></span>                    регистрация события + создание структуры
└── <span class="folder">lib/rest/</span>
    ├── <span class="file"><a href="/bitrix/admin/fileman_file_view.php?path=/local/modules/alex.newmodule/lib/rest/RestService.php">RestService.php</a></span>              scope meatshop, 4 метода, логирование, коды ошибок
    ├── <span class="file"><a href="/bitrix/admin/fileman_file_view.php?path=/local/modules/alex.newmodule/lib/rest/ProductCrudService.php">ProductCrudService.php</a></span>       CRUD, валидация, правило «только NAME и PRICE»
    ├── <span class="file"><a href="/bitrix/admin/fileman_file_view.php?path=/local/modules/alex.newmodule/lib/rest/IblockElementResolver.php">IblockElementResolver.php</a></span>    поиск по названию, создание при отсутствии
    ├── <span class="file"><a href="/bitrix/admin/fileman_file_view.php?path=/local/modules/alex.newmodule/lib/rest/MeatShopIblock.php">MeatShopIblock.php</a></span>           ID инфоблоков из опций + развёртывание
    ├── <span class="file"><a href="/bitrix/admin/fileman_file_view.php?path=/local/modules/alex.newmodule/lib/rest/Log.php">Log.php</a></span>                      файловый лог запросов и результатов
    └── <span class="folder">Model/</span>
        └── <span class="file"><a href="/bitrix/admin/fileman_file_view.php?path=/local/modules/alex.newmodule/lib/rest/Model/ProductTable.php">ProductTable.php</a></span>         ORM-модель meatshop_product
</pre>
        </div>
    </div>

<?php require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/footer.php"); ?>
