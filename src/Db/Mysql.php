<<<<<<< HEAD
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
=======
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

        [$dsn, $user, $pass] = self::resolveConfig();

        try {
            self::$pdo = new PDO($dsn, $user ?: null, $pass ?: null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
        } catch (PDOException $e) {
            // "localhost" in PDO MySQL can force socket mode. Prefer TCP fallback for container/remote environments.
            $fallbackDsn = preg_replace('/host=(localhost|mysql)(?=;|$)/i', 'host=127.0.0.1', $dsn, 1) ?: $dsn;
            if ($fallbackDsn === $dsn) {
                throw $e;
            }

            self::$pdo = new PDO($fallbackDsn, $user ?: null, $pass ?: null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
        }

        return self::$pdo;
    }

    public static function ping(): void
    {
        $stmt = self::connection()->query('SELECT 1');
        $stmt->fetchColumn();
    }

    /**
     * Resolve MySQL connection settings from multiple env conventions.
     *
     * Priority:
     * 1) MYSQL_DSN (+ MYSQL_USER / MYSQL_PASSWORD)
     * 2) MYSQL_URL / MYSQL_PUBLIC_URL / MYSQL_PRIVATE_URL / DATABASE_URL (mysql:// or mysql:...)
     * 3) MYSQL_HOST + MYSQL_PORT + MYSQL_DATABASE style vars
     *
     * @return array{0:string,1:?string,2:?string}
     */
    private static function resolveConfig(): array
    {
        $dsn = trim((string) Env::get('MYSQL_DSN', ''));

        $user = self::firstNonEmpty([
            Env::get('MYSQL_USER', null),
            Env::get('MYSQLUSER', null),
            Env::get('DB_USER', null),
            Env::get('DB_USERNAME', null),
            Env::get('DATABASE_USER', null),
        ]);

        $pass = self::firstNonEmpty([
            Env::get('MYSQL_PASSWORD', null),
            Env::get('MYSQLPASSWORD', null),
            Env::get('DB_PASS', null),
            Env::get('DB_PASSWORD', null),
            Env::get('DATABASE_PASSWORD', null),
        ]);

        if ($dsn === '') {
            $urlCandidates = [
                Env::get('MYSQL_URL', ''),
                Env::get('MYSQL_PUBLIC_URL', ''),
                Env::get('MYSQL_PRIVATE_URL', ''),
                Env::get('DATABASE_URL', ''),
                Env::get('DATABASE_PUBLIC_URL', ''),
                Env::get('DATABASE_PRIVATE_URL', ''),
                Env::get('CLEARDB_DATABASE_URL', ''),
                Env::get('JAWSDB_URL', ''),
            ];

            foreach ($urlCandidates as $rawUrl) {
                $url = trim((string) $rawUrl);
                if ($url === '') {
                    continue;
                }

                if (str_starts_with(strtolower($url), 'mysql:')) {
                    $dsn = $url;
                    break;
                }

                if (preg_match('/^(mysql|mariadb):\/\//i', $url) === 1) {
                    [$parsedDsn, $parsedUser, $parsedPass] = self::parseMysqlUriToPdoDsn($url);
                    if ($parsedDsn !== '') {
                        $dsn = $parsedDsn;
                        if (($user ?? '') === '' && $parsedUser !== '') {
                            $user = $parsedUser;
                        }
                        if (($pass ?? '') === '' && $parsedPass !== '') {
                            $pass = $parsedPass;
                        }
                        break;
                    }
                }
            }
        }

        if ($dsn === '') {
            $host = self::firstNonEmpty([
                Env::get('MYSQL_HOST', null),
                Env::get('MYSQLHOST', null),
                Env::get('DB_HOST', null),
                Env::get('DATABASE_HOST', null),
            ]);

            $port = self::firstNonEmpty([
                Env::get('MYSQL_PORT', null),
                Env::get('MYSQLPORT', null),
                Env::get('DB_PORT', null),
                Env::get('DATABASE_PORT', null),
                '3306',
            ]);

            $database = self::firstNonEmpty([
                Env::get('MYSQL_DATABASE', null),
                Env::get('MYSQLDATABASE', null),
                Env::get('DB_NAME', null),
                Env::get('DB_DATABASE', null),
                Env::get('DATABASE_NAME', null),
            ]);

            $charset = self::firstNonEmpty([
                Env::get('MYSQL_CHARSET', null),
                'utf8mb4',
            ]);

            if (($host ?? '') !== '' && ($database ?? '') !== '') {
                $dsn = sprintf(
                    'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                    trim($host),
                    trim((string) $port),
                    trim($database),
                    trim((string) $charset)
                );
            }
        }

        if ($dsn === '') {
            throw new \RuntimeException(
                'MySQL config missing. Set MYSQL_DSN or MYSQL_URL (or MYSQL_HOST/MYSQL_DATABASE vars).'
            );
        }

        return [$dsn, $user, $pass];
    }

    private static function firstNonEmpty(array $values): ?string
    {
        foreach ($values as $value) {
            if ($value === null) {
                continue;
            }

            $trimmed = trim((string) $value);
            if ($trimmed !== '') {
                return $trimmed;
            }
        }

        return null;
    }

    /**
     * @return array{0:string,1:string,2:string}
     */
    private static function parseMysqlUriToPdoDsn(string $url): array
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return ['', '', ''];
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['mysql', 'mariadb'], true)) {
            return ['', '', ''];
        }

        $host = (string) ($parts['host'] ?? '');
        if ($host === '') {
            return ['', '', ''];
        }

        $port = (string) ($parts['port'] ?? 3306);
        $database = ltrim((string) ($parts['path'] ?? ''), '/');
        if ($database === '') {
            return ['', '', ''];
        }

        $charset = 'utf8mb4';
        if (isset($parts['query'])) {
            parse_str((string) $parts['query'], $query);
            if (!empty($query['charset'])) {
                $charset = (string) $query['charset'];
            }
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            $host,
            $port,
            $database,
            $charset
        );

        $uriUser = isset($parts['user']) ? rawurldecode((string) $parts['user']) : '';
        $uriPass = isset($parts['pass']) ? rawurldecode((string) $parts['pass']) : '';

        return [$dsn, $uriUser, $uriPass];
    }
}
>>>>>>> 029df88 (fix api routing and mobile login)
