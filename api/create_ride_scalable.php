<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/shard_db.php';
require_once __DIR__ . '/queue.php';
require_once __DIR__ . '/rate_limit.php';
require_once __DIR__ . '/redis.php';

api_guard();
$data = json_input();
$missing = require_fields($data, [
    'city_id', 'customer_id', 'pickup_latitude', 'pickup_longitude', 'drop_latitude', 'drop_longitude', 'estimated_fare', 'payment_method'
]);
if ($missing) {
    json_response(422, ['status' => 'error', 'message' => 'Validation failed', 'missing' => $missing]);
    exit;
}

$cityId = (int)$data['city_id'];
$customerId = (int)$data['customer_id'];
$pickupLat = (float)$data['pickup_latitude'];
$pickupLng = (float)$data['pickup_longitude'];
$dropLat = (float)$data['drop_latitude'];
$dropLng = (float)$data['drop_longitude'];
$fare = round((float)$data['estimated_fare'], 2);
$paymentMethod = trim((string)$data['payment_method']);
$driverProfit = round($fare * 0.8, 2);

if ($cityId <= 0 || $customerId <= 0) {
    json_response(422, ['status' => 'error', 'message' => 'Invalid city_id or customer_id']);
    exit;
}

$limit = (int)(env_value('RIDE_REQUESTS_PER_MINUTE', '6') ?? '6');
$rl = redis_rate_limit_allow('taxi:rl:ride_req:' . $cityId . ':' . $customerId, max(1, $limit), 60);
if (!$rl['allowed']) {
    json_response(429, [
        'status' => 'error',
        'message' => 'Rate limit exceeded',
        'retry_after_seconds' => $rl['retry_after']
    ]);
    exit;
}

$lockKey = 'taxi:lock:create:' . $cityId . ':' . $customerId;
if (!redis_acquire_lock($lockKey, 3)) {
    json_response(409, ['status' => 'error', 'message' => 'Another ride request is in progress']);
    exit;
}

try {
    $pdo = shard_db($cityId);
    $pdo->beginTransaction();

    $stmt = $pdo->prepare('INSERT INTO rides
        (customer_id, driver_id, pickup_lat, pickup_lng, drop_lat, drop_lng, fare, driver_profit, payment_method, status, created_at, updated_at)
        VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, "searching", NOW(), NOW())');
    $stmt->execute([$customerId, $pickupLat, $pickupLng, $dropLat, $dropLng, $fare, $driverProfit, $paymentMethod]);
    $rideId = (int)$pdo->lastInsertId();

    $job = [
        'type' => 'dispatch_ride',
        'city_id' => $cityId,
        'ride_id' => $rideId,
        'customer_id' => $customerId,
        'pickup_lat' => $pickupLat,
        'pickup_lng' => $pickupLng,
        'drop_lat' => $dropLat,
        'drop_lng' => $dropLng,
        'fare' => $fare,
        'driver_profit' => $driverProfit,
        'attempt' => 0,
        'created_at' => date('c')
    ];
    if (!queue_push('taxi:queue:ride_dispatch', $job)) {
        throw new RuntimeException('Failed to enqueue ride dispatch');
    }

    $pdo->commit();
    json_response(202, [
        'status' => 'ok',
        'ride_id' => $rideId,
        'city_id' => $cityId,
        'ride_status' => 'searching',
        'dispatch' => 'queued'
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    json_response(500, ['status' => 'error', 'message' => $e->getMessage()]);
} finally {
    redis_release_lock($lockKey);
}

