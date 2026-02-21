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

    $url = env_value('REDIS_URL', '');
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
