<?php

spl_autoload_register(function ($class) {

    // Префикс namespace, за который отвечает этот автозагрузчик
    $prefix = 'App\\';
    $baseDir = __DIR__ . '/';

    // Класс не из нашего namespace - пропускаем
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    // Отрезаем префикс, оставляем хвост namespace
    $relativeClass = substr($class, $len);

    // App\Debug\Log -> Debug/Log.php
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});
