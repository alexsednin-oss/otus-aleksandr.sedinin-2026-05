<?php

/**
 * Автозагрузка классов модуля: Alex\Autoservice\<Path> → lib/<Path>.php
 */
spl_autoload_register(static function (string $class): void {
    $prefix  = 'Alex\\Autoservice\\';
    $baseDir = __DIR__ . '/lib/';

    $length = strlen($prefix);

    if (strncmp($prefix, $class, $length) !== 0) {
        return;
    }

    $file = $baseDir . str_replace('\\', '/', substr($class, $length)) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});
