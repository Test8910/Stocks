<?php

declare(strict_types=1);

$config = require dirname(__DIR__) . '/src/bootstrap.php';

use Stocks\Database;
use Stocks\PriceRepository;

$pdo = Database::pdo($config);
$driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

if ($driver === 'sqlite') {
    $sql = file_get_contents(dirname(__DIR__) . '/database/schema.sqlite.sql');
    if ($sql === false) {
        fwrite(STDERR, "Missing schema.sqlite.sql\n");
        exit(1);
    }
    $pdo->exec($sql);
} else {
    // Assume MySQL database already exists / selected via DSN
    $sql = file_get_contents(dirname(__DIR__) . '/database/schema.mysql.sql');
    if ($sql === false) {
        fwrite(STDERR, "Missing schema.mysql.sql\n");
        exit(1);
    }
    // Strip CREATE DATABASE / USE for PDO connection that already selected DB
    $sql = preg_replace('/^CREATE DATABASE.*?;\s*/mi', '', $sql) ?? $sql;
    $sql = preg_replace('/^USE\s+\w+\s*;\s*/mi', '', $sql) ?? $sql;
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
        if ($stmt !== '') {
            $pdo->exec($stmt);
        }
    }
}

$repo = new PriceRepository($pdo);
$repo->seedSymbols($config['symbols']);

echo "Database ready ({$driver}). Symbols: SOXL, QQQ\n";
