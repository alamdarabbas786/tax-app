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

        $host = self::normalizeHost(self::firstNonEmpty([
            Env::get('REDIS_HOST', null),
            Env::get('REDISHOST', null),
            Env::get('REDIS_HOSTNAME', null),
        ]));

        $port = self::normalizePort(self::firstNonEmpty([
            Env::get('REDIS_PORT', null),
            Env::get('REDISPORT', null),
            '6379',
        ]));

        $username = self::firstNonEmpty([
            Env::get('REDIS_USER', null),
            Env::get('REDISUSER', null),
        ]);

        $password = self::firstNonEmpty([
            Env::get('REDIS_PASSWORD', null),
            Env::get('REDISPASSWORD', null),
        ]);

        // Railway users sometimes set REDIS_URL as a host-only value.
        if ($url !== '' && !str_contains($url, '://')) {
            if ($host === null) {
                [$splitHost, $splitPort] = self::splitHostPort($url);
                $host = $splitHost;
                if ($splitPort !== null) {
                    $port = $splitPort;
                }
            }
            $url = '';
        }

        $hasLocalhostUrl = self::urlIsLocalhost($url);
        $urlMissingHost = $url !== '' && !self::urlHasHost($url);

        // Prefer explicit host config when URL is absent, invalid, or localhost.
        if (($url === '' || $hasLocalhostUrl || $urlMissingHost) && $host !== null) {
            $url = self::buildRedisUrl($host, $port, $username, $password);
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

    private static function normalizeHost(?string $host): ?string
    {
        if ($host === null) {
            return null;
        }

        $host = trim($host);
        if ($host === '') {
            return null;
        }

        if (str_contains($host, '://')) {
            $parts = parse_url($host);
            if ($parts !== false && isset($parts['host']) && is_string($parts['host'])) {
                $host = trim($parts['host']);
            } elseif ($parts !== false && isset($parts['path']) && is_string($parts['path'])) {
                $host = trim($parts['path'], '/');
            }
        }

        if ($host === '') {
            return null;
        }

        [$splitHost] = self::splitHostPort($host);
        return $splitHost;
    }

    private static function normalizePort(?string $port): string
    {
        $port = trim((string) $port);
        if ($port === '' || !ctype_digit($port)) {
            return '6379';
        }

        return $port;
    }

    private static function splitHostPort(string $value): array
    {
        $value = trim($value);
        if ($value === '') {
            return [null, null];
        }

        if (preg_match('/^\[([^\]]+)\](?::([0-9]+))?$/', $value, $matches) === 1) {
            $host = trim((string) $matches[1]);
            $port = isset($matches[2]) ? trim((string) $matches[2]) : null;
            return [$host !== '' ? $host : null, $port !== '' ? $port : null];
        }

        if (preg_match('/^([^:\/]+):([0-9]+)$/', $value, $matches) === 1) {
            $host = trim((string) $matches[1]);
            $port = trim((string) $matches[2]);
            return [$host !== '' ? $host : null, $port !== '' ? $port : null];
        }

        return [$value, null];
    }

    private static function buildRedisUrl(string $host, string $port, ?string $username, ?string $password): string
    {
        $user = trim((string) $username);
        $pass = trim((string) $password);
        $auth = '';

        if ($user !== '' && $pass !== '') {
            $auth = rawurlencode($user) . ':' . rawurlencode($pass) . '@';
        } elseif ($user !== '') {
            $auth = rawurlencode($user) . '@';
        } elseif ($pass !== '') {
            $auth = ':' . rawurlencode($pass) . '@';
        }

        return sprintf('redis://%s%s:%s', $auth, $host, $port);
    }

    private static function urlIsLocalhost(string $url): bool
    {
        if ($url === '') {
            return false;
        }

        $parts = parse_url($url);
        if ($parts === false) {
            return true;
        }

        $host = strtolower(trim((string) ($parts['host'] ?? '')));
        if ($host === '') {
            return true;
        }

        return in_array($host, ['127.0.0.1', 'localhost', '::1'], true);
    }

    private static function urlHasHost(string $url): bool
    {
        if ($url === '') {
            return false;
        }

        $parts = parse_url($url);
        if ($parts === false) {
            return false;
        }

        $host = trim((string) ($parts['host'] ?? ''));
        return $host !== '';
    }
}
