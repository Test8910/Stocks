<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Stocks\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

$configPath = dirname(__DIR__) . '/config/config.php';
if (!is_file($configPath)) {
    $configPath = dirname(__DIR__) . '/config/config.example.php';
}

/** @var array $config */
$config = require $configPath;

return $config;
