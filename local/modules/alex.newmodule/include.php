<?php

spl_autoload_register(function ($class) {
    // Alex\Newmodule\Rest\...  → lib/rest/   (решение по REST мясной лавки)
    // Alex\Newmodule\...       → lib/        (всё остальное)
    // Более специфичный префикс идёт первым.
    $map = [
        'Alex\\Newmodule\\Rest\\' => __DIR__ . '/lib/rest/',
        'Alex\\Newmodule\\'       => __DIR__ . '/lib/',
    ];

    foreach ($map as $prefix => $baseDir) {
        $len = strlen($prefix);

        if (strncmp($prefix, $class, $len) !== 0) {
            continue;
        }

        $file = $baseDir . str_replace('\\', '/', substr($class, $len)) . '.php';

        if (file_exists($file)) {
            require_once $file;
        }

        return;
    }
});
