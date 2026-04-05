<?php
namespace App\Db;
use PDO;
use PDOException;
class MySQL
{
	 private static ?PDO $pdo = null;

    public static function connection(): PDO
    {
		$host = getenv('MYSQLHOST');
		$port = getenv('MYSQLPORT');
		$db   = getenv('MYSQLDATABASE');
		$user = getenv('MYSQLUSER');
		$pass = getenv('MYSQLPASSWORD');

		$dsn = "mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4";

		return new PDO($dsn, $user, $pass, [
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
		]);
	}
	 public static function ping(): void
    {
        $stmt = self::connection()->query('SELECT 1');
        $stmt->fetchColumn();
    }
	
	 private static function resolveConfig(): array
    {
        $dsn = (string) Env::get('MYSQL_DSN', '');
        $user = self::firstNonEmpty([
            Env::get('MYSQL_USER', null),
            Env::get('MYSQLUSER', null),
            Env::get('DB_USER', null),
        ]);
        $pass = self::firstNonEmpty([
            Env::get('MYSQL_PASSWORD', null),
            Env::get('MYSQLPASSWORD', null),
            Env::get('DB_PASS', null),
            Env::get('DB_PASSWORD', null),
        ]);

        if ($dsn === '') {
            $urlCandidates = [
                Env::get('MYSQL_URL', ''),
                Env::get('MYSQL_PUBLIC_URL', ''),
                Env::get('MYSQL_PRIVATE_URL', ''),
                Env::get('DATABASE_URL', ''),
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
                Env::get('MYSQL_HOST', null),
                Env::get('MYSQLHOST', null),
                Env::get('DB_HOST', null),
            ]);
            $port = self::firstNonEmpty([
                Env::get('MYSQL_PORT', null),
                Env::get('MYSQLPORT', null),
                '3306',
            ]);
            $database = self::firstNonEmpty([
                Env::get('MYSQL_DATABASE', null),
                Env::get('MYSQLDATABASE', null),
                Env::get('DB_NAME', null),
                Env::get('DB_DATABASE', null),
            ]);
            $charset = self::firstNonEmpty([
                Env::get('MYSQL_CHARSET', null),
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
