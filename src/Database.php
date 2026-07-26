<?php

declare(strict_types=1);

namespace Stocks;

use PDO;
use RuntimeException;

final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(array $config): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $db = $config['db'] ?? [];
        $driver = $db['driver'] ?? 'sqlite';

        if ($driver === 'sqlite') {
            $path = $db['sqlite_path'] ?? (dirname(__DIR__) . '/storage/stocks.sqlite');
            $dir = dirname($path);
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new RuntimeException("Unable to create storage dir: {$dir}");
            }
            $pdo = new PDO('sqlite:' . $path, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $pdo->exec('PRAGMA foreign_keys = ON');
            self::$pdo = $pdo;
            return self::$pdo;
        }

        if ($driver === 'mysql') {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $db['host'] ?? '127.0.0.1',
                (int) ($db['port'] ?? 3306),
                $db['name'] ?? 'stocks',
                $db['charset'] ?? 'utf8mb4'
            );
            self::$pdo = new PDO($dsn, $db['user'] ?? '', $db['pass'] ?? '', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            return self::$pdo;
        }

        throw new RuntimeException("Unsupported DB driver: {$driver}");
    }
}
