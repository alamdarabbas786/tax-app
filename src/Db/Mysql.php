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

    private static function parseMysqlUriToPdoDsn(string $url): array
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return ['', '', ''];
        }

        $host = (string) ($parts['host'] ?? '');
        if ($host === '') {
            return ['', '', ''];
        }

        $port = (string) ($parts['port'] ?? 3306);
        $path = (string) ($parts['path'] ?? '');
        $database = ltrim($path, '/');
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

        $user = isset($parts['user']) ? rawurldecode((string) $parts['user']) : '';
        $pass = isset($parts['pass']) ? rawurldecode((string) $parts['pass']) : '';
=======
=======
>>>>>>> theirs
=======
>>>>>>> theirs
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
<<<<<<< ours
            Env::get('DATABASE_URL', null),
            Env::get('CLEARDB_DATABASE_URL', null),
=======
            Env::get('MYSQL_PUBLIC_URL', null),
            Env::get('MYSQL_PRIVATE_URL', null),
            Env::get('DATABASE_URL', null),
            Env::get('DATABASE_PUBLIC_URL', null),
            Env::get('DATABASE_PRIVATE_URL', null),
            Env::get('CLEARDB_DATABASE_URL', null),
            Env::get('JAWSDB_URL', null),
>>>>>>> theirs
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

<<<<<<< ours
<<<<<<< ours
        throw new \RuntimeException('MYSQL_DSN is not set (also checked MYSQL_URL/DATABASE_URL and MYSQLHOST-style env vars)');
=======
=======
>>>>>>> theirs
        // Final fallback for environments where only host/user/password defaults are expected.
        $defaultDsn = 'mysql:host=127.0.0.1;port=3306;dbname=airport_taxi;charset=utf8mb4';
        $defaultUser = $user ?? Env::get('DB_USER', 'appuser');
        $defaultPass = $pass ?? Env::get('DB_PASSWORD', null);

        return [$defaultDsn, $defaultUser, $defaultPass];
<<<<<<< ours
>>>>>>> theirs
=======
>>>>>>> theirs
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
<<<<<<< ours
        $host = Env::get('MYSQLHOST', Env::get('MYSQL_HOST', Env::get('DB_HOST', null)));
        $db = Env::get('MYSQLDATABASE', Env::get('MYSQL_DATABASE', Env::get('DB_DATABASE', null)));
=======
        $host = Env::get(
            'MYSQLHOST',
            Env::get('MYSQL_HOST', Env::get('DB_HOST', Env::get('DATABASE_HOST', null)))
        );
        $db = Env::get(
            'MYSQLDATABASE',
            Env::get('MYSQL_DATABASE', Env::get('DB_DATABASE', Env::get('DATABASE_NAME', null)))
        );
>>>>>>> theirs
        if (!is_string($host) || trim($host) === '' || !is_string($db) || trim($db) === '') {
            return ['', null, null];
        }

<<<<<<< ours
        $portRaw = Env::get('MYSQLPORT', Env::get('MYSQL_PORT', Env::get('DB_PORT', '3306')));
=======
        $portRaw = Env::get('MYSQLPORT', Env::get('MYSQL_PORT', Env::get('DB_PORT', Env::get('DATABASE_PORT', '3306'))));
>>>>>>> theirs
        $port = is_string($portRaw) && ctype_digit($portRaw) ? (int) $portRaw : 3306;

        $charset = Env::get('MYSQL_CHARSET', 'utf8mb4') ?: 'utf8mb4';
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', trim($host), $port, trim($db), $charset);

<<<<<<< ours
        $user = Env::get('MYSQLUSER', Env::get('MYSQL_USERNAME', Env::get('MYSQL_USER', Env::get('DB_USER', null))));
        $pass = Env::get('MYSQLPASSWORD', Env::get('MYSQL_PASSWORD', Env::get('DB_PASSWORD', null)));
<<<<<<< ours
>>>>>>> theirs
=======
>>>>>>> theirs
=======
        $user = Env::get(
            'MYSQLUSER',
            Env::get('MYSQL_USERNAME', Env::get('MYSQL_USER', Env::get('DB_USER', Env::get('DATABASE_USER', null))))
        );
        $pass = Env::get(
            'MYSQLPASSWORD',
            Env::get('MYSQL_PASSWORD', Env::get('DB_PASSWORD', Env::get('DATABASE_PASSWORD', null)))
        );
>>>>>>> theirs

        return [$dsn, $user, $pass];
    }
}
?>