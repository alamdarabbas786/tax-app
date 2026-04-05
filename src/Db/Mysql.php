<?php
namespace App\Db;

use PDO;

class MySQL
{
    private static ?PDO $pdo = null;

    public static function connection(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        [$dsn, $user, $pass] = self::resolveConfig();

        self::$pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);

        return self::$pdo;
    }

    public static function ping(): void
    {
        self::connection()->query('SELECT 1');
    }

    private static function resolveConfig(): array
    {
        // ===== OPTION 1: Railway standard env =====
        $host = getenv('MYSQLHOST');
        $port = getenv('MYSQLPORT');
        $db   = getenv('MYSQLDATABASE');
        $user = getenv('MYSQLUSER');
        $pass = getenv('MYSQLPASSWORD');

        if ($host && $db) {
            $dsn = "mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4";
            return [$dsn, $user, $pass];
        }

        // ===== OPTION 2: MYSQL_URL (Railway format) =====
        $url = getenv('MYSQL_URL');

        if ($url && str_starts_with($url, 'mysql://')) {
            $parts = parse_url($url);

            $host = $parts['host'] ?? '';
            $port = $parts['port'] ?? '3306';
            $db   = ltrim($parts['path'] ?? '', '/');
            $user = $parts['user'] ?? '';
            $pass = $parts['pass'] ?? '';

            $dsn = "mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4";

            return [$dsn, $user, $pass];
        }

        // ===== OPTION 3: LOCAL FALLBACK =====
        $host = getenv('DB_HOST') ?: 'localhost';
        $port = getenv('DB_PORT') ?: '3306';
        $db   = getenv('DB_NAME') ?: 'test';
        $user = getenv('DB_USER') ?: 'root';
        $pass = getenv('DB_PASS') ?: '';

        $dsn = "mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4";

        return [$dsn, $user, $pass];
    }
}
