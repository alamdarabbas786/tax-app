<?php

declare(strict_types=1);

require_once __DIR__ . '/redis.php';

function emit_ride_event(string $event, int $rideId, array $payload = []): bool
{
    $base = [
        'event' => $event,
        'ride_id' => $rideId,
        'room' => (string) $rideId,
        'ts' => date('c')
    ];
    return redis_publish_event(array_merge($base, $payload));
}
