<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

function shard_city_map(): array
{
    static $map = null;
    if (is_array($map)) {
        return $map;
    }

    $raw = env_value('SHARD_CITY_MAP_JSON', '{}') ?? '{}';
    $decoded = json_decode($raw, true);
    $map = is_array($decoded) ? $decoded : [];
    return $map;
}

function shard_db_default(): array
{
    return [
        'dsn' => env_value('MYSQL_DSN', 'mysql:host=127.0.0.1;port=3306;dbname=airport_taxi;charset=utf8mb4'),
        'user' => env_value('MYSQL_USER', 'appuser'),
        'password' => env_value('MYSQL_PASSWORD', 'AppPass123!')
    ];
}

function shard_city_config(int $cityId): array
{
    $map = shard_city_map();
    $cityKey = (string)$cityId;
    $selected = (isset($map[$cityKey]) && is_array($map[$cityKey])) ? $map[$cityKey] : [];
    $fallback = shard_db_default();
    return [
        'dsn' => (string)($selected['dsn'] ?? $fallback['dsn']),
        'user' => (string)($selected['user'] ?? $fallback['user']),
        'password' => (string)($selected['password'] ?? $fallback['password'])
    ];
}

function shard_db(int $cityId): PDO
{
    static $pool = [];
    $cfg = shard_city_config($cityId);
    $poolKey = sha1($cfg['dsn'] . '|' . $cfg['user']);
    if (isset($pool[$poolKey]) && $pool[$poolKey] instanceof PDO) {
        return $pool[$poolKey];
    }

    $pdo = new PDO($cfg['dsn'], $cfg['user'], $cfg['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_PERSISTENT => true
    ]);
    $pool[$poolKey] = $pdo;
    return $pdo;
}

