<?php

declare(strict_types=1);

require_once __DIR__ . '/redis.php';

function queue_push(string $queueName, array $payload): bool
{
    $redis = redis_client();
    if (!$redis) {
        return false;
    }
    try {
        $raw = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if (!is_string($raw) || $raw === '') {
            return false;
        }
        $redis->lpush($queueName, [$raw]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function queue_pop(string $queueName, int $timeoutSeconds = 5): ?array
{
    $redis = redis_client();
    if (!$redis) {
        return null;
    }
    try {
        $row = $redis->brpop([$queueName], max(1, $timeoutSeconds));
        if (!is_array($row) || count($row) < 2) {
            return null;
        }
        $decoded = json_decode((string)$row[1], true);
        return is_array($decoded) ? $decoded : null;
    } catch (Throwable $e) {
        return null;
    }
}

function queue_schedule(string $zsetName, array $payload, int $delaySeconds): bool
{
    $redis = redis_client();
    if (!$redis) {
        return false;
    }
    try {
        $raw = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if (!is_string($raw) || $raw === '') {
            return false;
        }
        $score = time() + max(1, $delaySeconds);
        $redis->zadd($zsetName, [$raw => $score]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function queue_promote_due(string $delayedZset, string $queueName, int $batch = 100): int
{
    $redis = redis_client();
    if (!$redis) {
        return 0;
    }
    try {
        $now = time();
        $items = $redis->zrangebyscore($delayedZset, '-inf', (string)$now, ['limit' => [0, max(1, $batch)]]);
        if (!is_array($items) || $items === []) {
            return 0;
        }

        $count = 0;
        foreach ($items as $raw) {
            if ($redis->zrem($delayedZset, (string)$raw) > 0) {
                $redis->lpush($queueName, [(string)$raw]);
                $count++;
            }
        }
        return $count;
    } catch (Throwable $e) {
        return 0;
    }
}

