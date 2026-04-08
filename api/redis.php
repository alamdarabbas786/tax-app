<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';

use Predis\Client;

function redis_client(): ?Client
{
    static $client = false;
    if ($client instanceof Client) {
        return $client;
    }
    if ($client === null) {
        return null;
    }

    $url = redis_resolve_url();
    if ($url === '' || !class_exists(Client::class)) {
        $client = null;
        return null;
    }

    try {
        $client = new Client($url);
        $client->ping();
        return $client;
    } catch (Throwable $e) {
        $client = null;
        return null;
    }
}

function redis_resolve_url(): string
{
    $url = trim((string) redis_first_non_empty([
        env_value('REDIS_URL', ''),
        env_value('REDIS_PRIVATE_URL', ''),
        env_value('REDIS_PUBLIC_URL', ''),
    ]));

    $host = redis_normalize_host(redis_first_non_empty([
        env_value('REDIS_HOST', ''),
        env_value('REDISHOST', ''),
        env_value('REDIS_HOSTNAME', ''),
    ]));

    $port = redis_normalize_port(redis_first_non_empty([
        env_value('REDIS_PORT', ''),
        env_value('REDISPORT', ''),
        '6379',
    ]));

    $username = redis_first_non_empty([
        env_value('REDIS_USER', ''),
        env_value('REDISUSER', ''),
    ]);

    $password = redis_first_non_empty([
        env_value('REDIS_PASSWORD', ''),
        env_value('REDISPASSWORD', ''),
    ]);

    if ($url !== '' && !str_contains($url, '://')) {
        if ($host === null) {
            [$splitHost, $splitPort] = redis_split_host_port($url);
            $host = $splitHost;
            if ($splitPort !== null) {
                $port = $splitPort;
            }
        }
        $url = '';
    }

    $hasLocalhostUrl = redis_url_is_localhost($url);
    $urlMissingHost = $url !== '' && !redis_url_has_host($url);

    if (($url === '' || $hasLocalhostUrl || $urlMissingHost) && $host !== null) {
        $url = redis_build_url($host, $port, $username, $password);
    }

    return $url;
}

function redis_first_non_empty(array $values): ?string
{
    foreach ($values as $value) {
        $trimmed = trim((string) $value);
        if ($trimmed !== '') {
            return $trimmed;
        }
    }

    return null;
}

function redis_normalize_host(?string $host): ?string
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

    [$splitHost] = redis_split_host_port($host);
    return $splitHost;
}

function redis_normalize_port(?string $port): string
{
    $port = trim((string) $port);
    if ($port === '' || !ctype_digit($port)) {
        return '6379';
    }

    return $port;
}

function redis_split_host_port(string $value): array
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

function redis_build_url(string $host, string $port, ?string $username, ?string $password): string
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

function redis_url_is_localhost(string $url): bool
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

function redis_url_has_host(string $url): bool
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

function redis_driver_geo_key(): string
{
    return env_value('REDIS_DRIVER_GEO_KEY', 'taxi:drivers:geo') ?? 'taxi:drivers:geo';
}

function redis_driver_geo_city_key(int $cityId): string
{
    return 'taxi:drivers:geo:city:' . max(0, $cityId);
}

function redis_events_channel(): string
{
    return env_value('REDIS_EVENTS_CHANNEL', 'taxi:events') ?? 'taxi:events';
}

function redis_driver_meta_key(int $driverId): string
{
    return 'taxi:driver:meta:' . $driverId;
}

function redis_driver_status_key(int $driverId): string
{
    return 'taxi:driver:status:' . $driverId;
}

function redis_geo_upsert_driver(int $driverId, float $lat, float $lng, array $meta = []): bool
{
    $redis = redis_client();
    if (!$redis) {
        return false;
    }

    try {
        $redis->geoadd(redis_driver_geo_key(), [$lng, $lat, (string) $driverId]);
        $redis->hmset(redis_driver_meta_key($driverId), [
            'lat' => (string) $lat,
            'lng' => (string) $lng,
            'updated_at' => (string) time()
        ]);
        if (!empty($meta)) {
            $safeMeta = [];
            foreach ($meta as $k => $v) {
                $safeMeta[(string) $k] = $v === null ? '' : (string) $v;
            }
            $redis->hmset(redis_driver_meta_key($driverId), $safeMeta);
        }
        $redis->expire(redis_driver_meta_key($driverId), 1800);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function redis_geo_upsert_driver_city(int $cityId, int $driverId, float $lat, float $lng): bool
{
    $redis = redis_client();
    if (!$redis) {
        return false;
    }
    try {
        $redis->executeRaw([
            'GEOADD',
            redis_driver_geo_city_key($cityId),
            (string)$lng,
            (string)$lat,
            (string)$driverId
        ]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function redis_set_driver_available(int $driverId, bool $available): void
{
    $redis = redis_client();
    if (!$redis) {
        return;
    }
    try {
        $redis->setex(redis_driver_status_key($driverId), 1800, $available ? '1' : '0');
    } catch (Throwable $e) {
        // noop
    }
}

function redis_geo_nearest_drivers(float $lat, float $lng, float $radiusKm = 2.0, int $limit = 50): array
{
    $redis = redis_client();
    if (!$redis) {
        return [];
    }

    try {
        $result = $redis->executeRaw([
            'GEORADIUS',
            redis_driver_geo_key(),
            (string) $lng,
            (string) $lat,
            (string) $radiusKm,
            'km',
            'WITHDIST',
            'ASC',
            'COUNT',
            (string) max(1, $limit)
        ]);
    } catch (Throwable $e) {
        return [];
    }

    if (!is_array($result)) {
        return [];
    }

    $rows = [];
    foreach ($result as $item) {
        if (!is_array($item) || count($item) < 2) {
            continue;
        }
        $driverId = (int) $item[0];
        $distanceKm = (float) $item[1];
        if ($driverId <= 0) {
            continue;
        }
        $rows[] = [
            'driver_id' => $driverId,
            'distance_km' => $distanceKm
        ];
    }

    return $rows;
}

function redis_geo_nearest_drivers_city(int $cityId, float $lat, float $lng, float $radiusKm = 3.0, int $limit = 100): array
{
    $redis = redis_client();
    if (!$redis) {
        return [];
    }

    try {
        $result = $redis->executeRaw([
            'GEOSEARCH',
            redis_driver_geo_city_key($cityId),
            'FROMLONLAT',
            (string)$lng,
            (string)$lat,
            'BYRADIUS',
            (string)$radiusKm,
            'km',
            'WITHDIST',
            'ASC',
            'COUNT',
            (string)max(1, $limit)
        ]);
    } catch (Throwable $e) {
        return [];
    }

    if (!is_array($result)) {
        return [];
    }

    $rows = [];
    foreach ($result as $item) {
        if (!is_array($item) || count($item) < 2) {
            continue;
        }
        $driverId = (int)$item[0];
        if ($driverId <= 0) {
            continue;
        }
        $rows[] = [
            'driver_id' => $driverId,
            'distance_km' => (float)$item[1]
        ];
    }
    return $rows;
}

function redis_acquire_lock(string $key, int $ttlSeconds = 5): bool
{
    $redis = redis_client();
    if (!$redis) {
        return true;
    }
    try {
        $res = $redis->set($key, '1', 'EX', max(1, $ttlSeconds), 'NX');
        return $res === true || $res === 'OK';
    } catch (Throwable $e) {
        return true;
    }
}

function redis_release_lock(string $key): void
{
    $redis = redis_client();
    if (!$redis) {
        return;
    }
    try {
        $redis->del([$key]);
    } catch (Throwable $e) {
        // noop
    }
}

function redis_publish_event(array $event): bool
{
    $redis = redis_client();
    if (!$redis) {
        return false;
    }
    try {
        $payload = json_encode($event, JSON_UNESCAPED_SLASHES);
        if (is_string($payload) && $payload !== '') {
            $published = $redis->publish(redis_events_channel(), $payload);
            return (int)$published > 0;
        }
    } catch (Throwable $e) {
        return false;
    }
    return false;
}
