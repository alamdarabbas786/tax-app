<?php

declare(strict_types=1);

require_once __DIR__ . '/redis.php';

function redis_rate_limit_allow(string $bucket, int $limit, int $windowSeconds): array
{
    $redis = redis_client();
    if (!$redis) {
        return ['allowed' => true, 'remaining' => $limit, 'retry_after' => 0];
    }

    $limit = max(1, $limit);
    $windowSeconds = max(1, $windowSeconds);

    try {
        $count = (int)$redis->incr($bucket);
        if ($count === 1) {
            $redis->expire($bucket, $windowSeconds);
        }
        $ttl = (int)$redis->ttl($bucket);
        if ($ttl < 0) {
            $ttl = $windowSeconds;
        }
        return [
            'allowed' => $count <= $limit,
            'remaining' => max(0, $limit - $count),
            'retry_after' => $ttl
        ];
    } catch (Throwable $e) {
        return ['allowed' => true, 'remaining' => $limit, 'retry_after' => 0];
    }
}

