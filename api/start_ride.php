<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/realtime_events.php';
require_once __DIR__ . '/fcm.php';
require_once __DIR__ . '/notification_store.php';

api_guard();
$data = json_input();
$missing = require_fields($data, ['ride_id', 'driver_id']);
if ($missing) {
    json_response(422, ['status' => 'error', 'message' => 'ride_id, driver_id required']);
    exit;
}

$rideId = (int) $data['ride_id'];
$driverId = (int) $data['driver_id'];

try {
    $pdo = db();
    $stmt = $pdo->prepare('UPDATE rides
        SET status = "in_progress", in_progress_at = NOW(), updated_at = NOW()
        WHERE id = ? AND driver_id = ? AND status IN ("ride_started","accepted")');
    $stmt->execute([$rideId, $driverId]);
    if ($stmt->rowCount() !== 1) {
        json_response(409, ['status' => 'error', 'message' => 'Ride cannot be started']);
        exit;
    }
    $info = $pdo->prepare('SELECT customer_id FROM rides WHERE id = ? LIMIT 1');
    $info->execute([$rideId]);
    $ride = $info->fetch();
    $customerId = isset($ride['customer_id']) ? (int)$ride['customer_id'] : 0;

    $published = emit_ride_event('ride_started', $rideId, ['driver_id' => $driverId, 'customer_id' => $customerId]);
    notification_insert(
        $pdo,
        $rideId,
        $customerId > 0 ? 'customer' : 'system',
        $customerId > 0 ? $customerId : null,
        'ride_started',
        'websocket',
        null,
        null,
        ['driver_id' => $driverId],
        $published ? 'sent' : 'failed',
        $published ? null : 'websocket_offline'
    );

    $fallback = null;
    if (!$published && $customerId > 0) {
        $fallback = fcm_send_to_entity(
            $pdo,
            'customer',
            $customerId,
            ['title' => 'Ride Started', 'body' => 'Your trip has started'],
            ['ride_id' => (string)$rideId, 'status' => 'in_progress'],
            60,
            ['role' => 'customer', 'recipient_id' => $customerId, 'event_type' => 'ride_started']
        );
    }

    json_response(200, ['status' => 'ok', 'message' => 'Ride in progress', 'fallback_fcm' => $fallback]);
} catch (Throwable $e) {
    json_response(500, ['status' => 'error', 'message' => $e->getMessage()]);
}
