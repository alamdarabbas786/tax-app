<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/ride_dispatch.php';
require_once __DIR__ . '/realtime_events.php';

api_guard();
$data = json_input();
$missing = require_fields($data, [
    'customer_id', 'pickup_latitude', 'pickup_longitude', 'drop_latitude', 'drop_longitude', 'estimated_fare', 'payment_method'
]);
if ($missing) {
    json_response(422, ['status' => 'error', 'message' => 'Validation failed', 'missing' => $missing]);
    exit;
}

$customerId = (int) $data['customer_id'];
$pickupLat = (float) $data['pickup_latitude'];
$pickupLng = (float) $data['pickup_longitude'];
$dropLat = (float) $data['drop_latitude'];
$dropLng = (float) $data['drop_longitude'];
$fare = round((float) $data['estimated_fare'], 2);
$paymentMethod = trim((string) $data['payment_method']);
$driverProfit = round($fare * 0.8, 2);

try {
    $pdo = db();
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('INSERT INTO rides
        (customer_id, driver_id, pickup_lat, pickup_lng, drop_lat, drop_lng, fare, driver_profit, payment_method, status, created_at, updated_at)
        VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, "searching", NOW(), NOW())');
    $stmt->execute([$customerId, $pickupLat, $pickupLng, $dropLat, $dropLng, $fare, $driverProfit, $paymentMethod]);

    $rideId = (int)$pdo->lastInsertId();
    $offerTimeout = (int)(env_value('RIDE_OFFER_TIMEOUT_SECONDS', '30') ?? '30');
    $offerTimeout = max(30, min(60, $offerTimeout));
    $dispatch = dispatch_next_driver($pdo, $rideId, $offerTimeout, 60);

    $pdo->commit();
    emit_ride_event('ride_requested', $rideId, [
        'customer_id' => $customerId,
        'pickup_lat' => $pickupLat,
        'pickup_lng' => $pickupLng,
        'drop_lat' => $dropLat,
        'drop_lng' => $dropLng
    ]);

    json_response(201, [
        'status' => 'ok',
        'ride_id' => $rideId,
        'ride_status' => 'searching',
        'message' => 'Searching for nearby drivers...',
        'dispatch' => $dispatch
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    json_response(500, ['status' => 'error', 'message' => $e->getMessage()]);
}
