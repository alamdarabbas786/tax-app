<?php

namespace App\Db;

use App\Config\Env;
use PDO;
use PDOException;

class Mysql
{
    private static ?PDO $pdo = null;

    public static function connection(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $dsn = Env::get('MYSQL_DSN', '');
        if ($dsn === '') {
            throw new \RuntimeException('MYSQL_DSN is not set');
        }

        $user = Env::get('MYSQL_USER', null);
        $pass = Env::get('MYSQL_PASSWORD', null);

        try {
            self::$pdo = new PDO($dsn, $user ?: null, $pass ?: null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
            ]);
        } catch (PDOException $e) {
            // When PHP runs on host machine (not inside Docker), service-name DNS like host=mysql does not resolve.
            $fallbackDsn = preg_replace('/host=mysql(?=;|$)/i', 'host=127.0.0.1', $dsn, 1) ?: $dsn;
            if ($fallbackDsn === $dsn) {
                throw $e;
            }

            self::$pdo = new PDO($fallbackDsn, $user ?: null, $pass ?: null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
            ]);
        }

        return self::$pdo;
    }

    public static function ping(): void
    {
        $stmt = self::connection()->query('SELECT 1');
        $stmt->fetchColumn();
    }
}
