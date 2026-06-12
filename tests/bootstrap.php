<?php

spl_autoload_register(function (string $class): void {
    $prefixes = [
        'Platform\\Recruiting\\Tests\\' => __DIR__ . '/',
        'Platform\\Recruiting\\' => __DIR__ . '/../src/',
    ];

    foreach ($prefixes as $prefix => $baseDir) {
        if (str_starts_with($class, $prefix)) {
            $file = $baseDir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (file_exists($file)) {
                require $file;
            }
            return;
        }
    }
});
