<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/fcm.php';

api_guard();
$data = json_input();
$missing = require_fields($data, ['driver_id', 'ride_id', 'pickup_lat', 'pickup_lng', 'drop_lat', 'drop_lng', 'estimated_fare', 'driver_profit']);
if ($missing) {
    json_response(422, ['status' => 'error', 'message' => 'Validation failed', 'missing' => $missing]);
    exit;
}

$driverId = (int)$data['driver_id'];
$rideId = (int)$data['ride_id'];
$ttl = isset($data['ttl_seconds']) && is_numeric($data['ttl_seconds']) ? (int)$data['ttl_seconds'] : 60;
$ttl = max(15, min(300, $ttl));

try {
    $pdo = db();
    $stmt = $pdo->prepare('SELECT id, online_status, availability FROM drivers WHERE id = ? LIMIT 1');
    $stmt->execute([$driverId]);
    $driver = $stmt->fetch();
    if (!$driver) {
        json_response(404, ['status' => 'error', 'message' => 'Driver not found']);
        exit;
    }

    $result = fcm_send_to_entity(
        $pdo,
        'driver',
        $driverId,
        [
            'title' => 'New Ride Request',
            'body' => 'Pickup and drop details. Tap to view details'
        ],
        [
            'ride_id' => $rideId,
            'pickup_lat' => (string)$data['pickup_lat'],
            'pickup_lng' => (string)$data['pickup_lng'],
            'drop_lat' => (string)$data['drop_lat'],
            'drop_lng' => (string)$data['drop_lng'],
            'estimated_fare' => (string)$data['estimated_fare'],
            'driver_profit' => (string)$data['driver_profit'],
            'accept_endpoint' => '/api/accept_ride.php',
            'reject_endpoint' => '/api/reject_ride.php'
        ],
        $ttl,
        [
            'role' => 'driver',
            'recipient_id' => $driverId,
            'event_type' => 'ride_requested'
        ]
    );

    json_response(200, [
        'status' => 'ok',
        'driver_online' => (int)($driver['online_status'] ?? 0) === 1,
        'driver_available' => (int)($driver['availability'] ?? 0) === 1,
        'fcm' => $result
    ]);
} catch (Throwable $e) {
    json_response(500, ['status' => 'error', 'message' => $e->getMessage()]);
}
