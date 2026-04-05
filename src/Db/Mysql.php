<?php
namespace App\Db;
use PDO;
use PDOException;
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
        $stmt = self::connection()->query('SELECT 1');
        $stmt->fetchColumn();
    }
	
	private static function resolveConfig(): array
    {
        $dsn = (string) (getenv('MYSQL_DSN') ?: '');
        $user = self::firstNonEmpty([
            getenv('MYSQL_USER') ?: null,
            getenv('MYSQLUSER') ?: null,
            getenv('DB_USER') ?: null,
        ]);
        $pass = self::firstNonEmpty([
            getenv('MYSQL_PASSWORD') ?: null,
            getenv('MYSQLPASSWORD') ?: null,
            getenv('DB_PASS') ?: null,
            getenv('DB_PASSWORD') ?: null,
        ]);

        if ($dsn === '') {
            $urlCandidates = [
                getenv('MYSQL_URL') ?: '',
                getenv('MYSQL_PUBLIC_URL') ?: '',
                getenv('MYSQL_PRIVATE_URL') ?: '',
                getenv('DATABASE_URL') ?: '',
            ];

            foreach ($urlCandidates as $rawUrl) {
                $url = trim((string) $rawUrl);
                if ($url === '') {
                    continue;
                }

                if (str_starts_with($url, 'mysql:')) {
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
                getenv('MYSQL_HOST') ?: null,
                getenv('MYSQLHOST') ?: null,
                getenv('DB_HOST') ?: null,
            ]);
            $port = self::firstNonEmpty([
                getenv('MYSQL_PORT') ?: null,
                getenv('MYSQLPORT') ?: null,
                '3306',
            ]);
            $database = self::firstNonEmpty([
                getenv('MYSQL_DATABASE') ?: null,
                getenv('MYSQLDATABASE') ?: null,
                getenv('DB_NAME') ?: null,
                getenv('DB_DATABASE') ?: null,
            ]);
            $charset = self::firstNonEmpty([
                getenv('MYSQL_CHARSET') ?: null,
                'utf8mb4',
            ]);

            if (($host ?? '') !== '' && ($database ?? '') !== '') {
                $dsn = sprintf(
                    'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                    $host,
                    $port,
                    $database,
                    $charset
                );
            }
        }

        if ($dsn === '') {
            throw new \RuntimeException(
                'MySQL config missing. Set MYSQL_DSN or MYSQL_URL (or MYSQLHOST/MYSQLDATABASE vars).'
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
}
?>
