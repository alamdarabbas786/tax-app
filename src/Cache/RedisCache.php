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

        $url = Env::get('REDIS_URL', '');
        if ($url === '') {
            throw new \RuntimeException('REDIS_URL is not set');
        }

        self::$client = new Client($url);
        return self::$client;
    }

    public static function ping(): void
    {
        self::client()->ping();
    }
}