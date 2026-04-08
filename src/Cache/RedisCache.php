<?php

namespace App\Cache;

use App\Config\Env;
use Predis\Client;

class RedisCache
{
    private static ?Client $client = null;

    public static function client(): Client
    {
        if (self::$client instanceof Client) {
            return self::$client;
        }

        $url = self::resolveRedisUrl();

        self::$client = new Client($url);
        return self::$client;
    }

    public static function ping(): void
    {
        self::client()->ping();
    }

    private static function resolveRedisUrl(): string
    {
        $url = trim((string) self::firstNonEmpty([
            Env::get('REDIS_URL', null),
            Env::get('REDIS_PRIVATE_URL', null),
            Env::get('REDIS_PUBLIC_URL', null),
        ]));

        $host = self::firstNonEmpty([
            Env::get('REDIS_HOST', null),
            Env::get('REDISHOST', null),
            Env::get('REDIS_HOSTNAME', null),
        ]);

        $port = self::firstNonEmpty([
            Env::get('REDIS_PORT', null),
            Env::get('REDISPORT', null),
            '6379',
        ]);

        $password = self::firstNonEmpty([
            Env::get('REDIS_PASSWORD', null),
            Env::get('REDISPASSWORD', null),
        ]);

        $hasLocalhostUrl =
            $url !== '' &&
            (
                str_contains($url, '127.0.0.1') ||
                str_contains(strtolower($url), 'localhost')
            );

        // If REDIS_HOST is set explicitly, prefer it over localhost defaults.
        if (($url === '' || $hasLocalhostUrl) && ($host ?? '') !== '') {
            $auth = ($password ?? '') !== '' ? ':' . rawurlencode((string) $password) . '@' : '';
            $url = sprintf('redis://%s%s:%s', $auth, trim((string) $host), trim((string) $port));
        }

        if ($url === '') {
            throw new \RuntimeException(
                'Redis config missing. Set REDIS_URL or REDIS_HOST (+ optional REDIS_PORT/REDIS_PASSWORD).'
            );
        }

        return $url;
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
