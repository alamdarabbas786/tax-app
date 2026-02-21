<?php

namespace App\Db;

use App\Config\Env;
use PDO;

class Pg
{
    private static ?PDO $pdo = null;

    public static function connection(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $dsn = Env::get('DATABASE_URL', '');
        if ($dsn === '') {
            throw new \RuntimeException('DATABASE_URL is not set');
        }

        self::$pdo = new PDO($dsn, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);

        return self::$pdo;
    }

    public static function ping(): void
    {
        $stmt = self::connection()->query('SELECT 1');
        $stmt->fetchColumn();
    }
}