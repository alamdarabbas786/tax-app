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

    /**
     * Resolve MySQL connection settings from multiple env conventions.
     * Priority:
     * 1) MYSQL_DSN (+ MYSQL_USER / MYSQL_PASSWORD)
     * 2) MYSQL_URL / MYSQL_PUBLIC_URL / MYSQL_PRIVATE_URL / DATABASE_URL / etc.
     * 3) Discrete env vars: MYSQLHOST / MYSQLDATABASE / MYSQLPORT / etc.
     * 4) Fallback default DSN (airport_taxi)
     *
     * @return array{0:string,1:?string,2:?string}
     */
    private static function resolveConfig(): array
    {
        $dsn = trim((string) Env::get('MYSQL_DSN', ''));
        $user = Env::get('MYSQL_USER', null);
        $pass = Env::get('MYSQL_PASSWORD', null);

        if ($dsn !== '') {
            return [$dsn, $user, $pass];
        }

        $urlCandidates = [
            Env::get('MYSQL_URL', null),
            Env::get('MYSQL_PUBLIC_URL', null),
            Env::get('MYSQL_PRIVATE_URL', null),
            Env::get('DATABASE_URL', null),
            Env::get('DATABASE_PUBLIC_URL', null),
            Env::get('DATABASE_PRIVATE_URL', null),
            Env::get('CLEARDB_DATABASE_URL', null),
            Env::get('JAWSDB_URL', null),
        ];

        foreach ($urlCandidates as $candidate) {
            if (!is_string($candidate) || trim($candidate) === '') {
                continue;
            }

            [$urlDsn, $urlUser, $urlPass] = self::fromUrl($candidate);
            if ($urlDsn !== '') {
                return [$urlDsn, $user ?? $urlUser, $pass ?? $urlPass];
            }
        }

        [$partsDsn, $partsUser, $partsPass] = self::fromDiscreteEnv();
        if ($partsDsn !== '') {
            return [$partsDsn, $user ?? $partsUser, $pass ?? $partsPass];
        }

        // Final fallback for environments where only host/user/password defaults are expected.
        $defaultDsn = 'mysql:host=127.0.0.1;port=3306;dbname=airport_taxi;charset=utf8mb4';
        $defaultUser = $user ?? Env::get('DB_USER', 'appuser');
        $defaultPass = $pass ?? Env::get('DB_PASSWORD', null);

        return [$defaultDsn, $defaultUser, $defaultPass];
    }

    /**
     * @return array{0:string,1:?string,2:?string}
     */
    private static function fromUrl(string $value): array
    {
        $raw = trim($value);
        if ($raw === '') {
            return ['', null, null];
        }

        if (str_starts_with(strtolower($raw), 'mysql:')) {
            return [$raw, null, null];
        }

        $parts = parse_url($raw);
        if (!is_array($parts)) {
            return ['', null, null];
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['mysql', 'mariadb'], true)) {
            return ['', null, null];
        }

        $host = (string) ($parts['host'] ?? '127.0.0.1');
        $port = (int) ($parts['port'] ?? 3306);
        $db = ltrim((string) ($parts['path'] ?? ''), '/');
        if ($db === '') {
            return ['', null, null];
        }

        parse_str((string) ($parts['query'] ?? ''), $query);
        $charset = is_string($query['charset'] ?? null) && trim((string) $query['charset']) !== ''
            ? (string) $query['charset']
            : 'utf8mb4';

        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $db, $charset);
        $user = isset($parts['user']) ? urldecode((string) $parts['user']) : null;
        $pass = isset($parts['pass']) ? urldecode((string) $parts['pass']) : null;

        return [$dsn, $user, $pass];
    }

    /**
     * @return array{0:string,1:?string,2:?string}
     */
    private static function fromDiscreteEnv(): array
    {
        $host = Env::get(
            'MYSQLHOST',
            Env::get('MYSQL_HOST', Env::get('DB_HOST', Env::get('DATABASE_HOST', null)))
        );
        $db = Env::get(
            'MYSQLDATABASE',
            Env::get('MYSQL_DATABASE', Env::get('DB_DATABASE', Env::get('DATABASE_NAME', null)))
        );
        if (!is_string($host) || trim($host) === '' || !is_string($db) || trim($db) === '') {
            return ['', null, null];
        }

        $portRaw = Env::get('MYSQLPORT', Env::get('MYSQL_PORT', Env::get('DB_PORT', Env::get('DATABASE_PORT', '3306'))));
        $port = is_string($portRaw) && ctype_digit($portRaw) ? (int) $portRaw : 3306;

        $charset = Env::get('MYSQL_CHARSET', 'utf8mb4') ?: 'utf8mb4';
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', trim($host), $port, trim($db), $charset);

        $user = Env::get(
            'MYSQLUSER',
            Env::get('MYSQL_USERNAME', Env::get('MYSQL_USER', Env::get('DB_USER', Env::get('DATABASE_USER', null))))
        );
        $pass = Env::get(
            'MYSQLPASSWORD',
            Env::get('MYSQL_PASSWORD', Env::get('DB_PASSWORD', Env::get('DATABASE_PASSWORD', null)))
        );

        return [$dsn, $user, $pass];
    }
}
