<?php

    // Префикс namespace, за который отвечает этот автозагрузчик

    spl_autoload_register(function ($class) {
        $map = [
            'App\\' => __DIR__ . '/../App/',
            'Models\\' => __DIR__ . '/../lib/Models/',
        ];

        foreach ($map as $prefix => $baseDir) {
            $len = strlen($prefix);
            if (strncmp($prefix, $class, $len) === 0) {
                $relativeClass = substr($class, $len);
                $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
                if (file_exists($file)) {
                    require $file;
                    return;
                }
            }
        }
    });

/*    $prefix = 'App\\';
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
});*/
