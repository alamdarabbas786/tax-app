<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/fcm.php';
require_once __DIR__ . '/redis.php';
require_once __DIR__ . '/realtime_events.php';
require_once __DIR__ . '/notification_store.php';

api_guard();
$data = json_input();
$missing = require_fields($data, ['ride_id', 'driver_id']);
if ($missing) {
    json_response(422, ['status' => 'error', 'message' => 'ride_id and driver_id required']);
    exit;
}

$rideId = (int) $data['ride_id'];
$driverId = (int) $data['driver_id'];

try {
    $lockKey = 'taxi:lock:accept:' . $rideId;
    if (!redis_acquire_lock($lockKey, 8)) {
        json_response(409, ['status' => 'error', 'message' => 'Ride is being processed']);
        exit;
    }

    $pdo = db();
    $pdo->beginTransaction();

    $trackStmt = $pdo->prepare('SELECT id FROM ride_requests_tracking
        WHERE ride_id = ? AND driver_id = ? AND status = "pending" AND expires_at > NOW() FOR UPDATE');
    $trackStmt->execute([$rideId, $driverId]);
    $track = $trackStmt->fetch();
    if (!$track) {
        $pdo->rollBack();
        json_response(409, ['status' => 'error', 'message' => 'Offer expired or invalid']);
        exit;
    }

    $rideUpdate = $pdo->prepare('UPDATE rides
        SET status = "accepted", driver_id = ?, accepted_at = NOW(), updated_at = NOW()
        WHERE id = ? AND status = "searching" AND driver_id IS NULL');
    $rideUpdate->execute([$driverId, $rideId]);
    if ($rideUpdate->rowCount() !== 1) {
        $pdo->rollBack();
        json_response(409, ['status' => 'error', 'message' => 'Ride already assigned']);
        exit;
    }

    $pdo->prepare('UPDATE drivers SET availability = 0, ride_status = "busy", updated_at = NOW() WHERE id = ?')
        ->execute([$driverId]);
    $pdo->prepare('UPDATE ride_requests_tracking SET status = "accepted", responded_at = NOW(), updated_at = NOW() WHERE id = ?')
        ->execute([$track['id']]);
    $pdo->prepare('UPDATE ride_requests_tracking
        SET status = "expired", responded_at = NOW(), updated_at = NOW()
        WHERE ride_id = ? AND id <> ? AND status IN ("pending","queued")')
        ->execute([$rideId, $track['id']]);

    $info = $pdo->prepare('SELECT r.customer_id, r.pickup_lat, r.pickup_lng, r.drop_lat, r.drop_lng,
        d.name AS driver_name, d.vehicle_number, d.rating, d.phone AS driver_phone, d.latitude AS driver_lat, d.longitude AS driver_lng
        FROM rides r JOIN drivers d ON d.id = r.driver_id WHERE r.id = ?');
    $info->execute([$rideId]);
    $row = $info->fetch();

    $customerNotify = null;
    if ($row && isset($row['customer_id'])) {
        $customerId = (int)$row['customer_id'];
        $customerNotify = fcm_send_to_entity(
            $pdo,
            'customer',
            $customerId,
            ['title' => 'Driver Assigned', 'body' => ($row['driver_name'] ?? 'Driver') . ' accepted your ride'],
            [
                'ride_id' => $rideId,
                'status' => 'accepted',
                'driver_name' => $row['driver_name'] ?? '',
                'vehicle' => $row['vehicle_number'] ?? '',
                'rating' => $row['rating'] ?? '',
                'phone' => $row['driver_phone'] ?? '',
                'live_lat' => $row['driver_lat'] ?? '',
                'live_lng' => $row['driver_lng'] ?? ''
            ],
            60,
            [
                'role' => 'customer',
                'recipient_id' => $customerId,
                'event_type' => 'ride_accepted'
            ]
        );

        $notificationId = notification_insert(
            $pdo,
            $rideId,
            'customer',
            $customerId,
            'ride_accepted',
            'fcm',
            'Driver Assigned',
            ($row['driver_name'] ?? 'Driver') . ' accepted your ride',
            ['driver_id' => $driverId],
            ($customerNotify['status'] ?? '') === 'ok' ? 'sent' : 'failed',
            $customerNotify['error'] ?? null
        );
        if ($notificationId) {
            foreach (($customerNotify['attempts'] ?? []) as $attempt) {
                notification_insert_attempt($pdo, $notificationId, [
                    'attempt' => $attempt['attempt'] ?? 1,
                    'status_code' => $attempt['status_code'] ?? null,
                    'is_success' => ($attempt['status'] ?? '') === 'ok',
                    'error' => $attempt['error'] ?? null,
                    'response' => $attempt['response'] ?? null
                ]);
            }
        }
    }

    $published = emit_ride_event('ride_accepted', $rideId, [
        'driver_id' => $driverId,
        'customer_id' => (int)($row['customer_id'] ?? 0),
        'driver_name' => $row['driver_name'] ?? '',
        'vehicle_number' => $row['vehicle_number'] ?? ''
    ]);
    notification_insert(
        $pdo,
        $rideId,
        'customer',
        isset($row['customer_id']) ? (int)$row['customer_id'] : null,
        'ride_accepted',
        'websocket',
        null,
        null,
        ['driver_id' => $driverId],
        $published ? 'sent' : 'failed',
        $published ? null : 'websocket_offline'
    );

    $pdo->commit();
    redis_set_driver_available($driverId, false);
    json_response(200, ['status' => 'ok', 'message' => 'Ride accepted', 'customer_notified' => $customerNotify]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    json_response(500, ['status' => 'error', 'message' => $e->getMessage()]);
} finally {
    if (isset($lockKey)) {
        redis_release_lock($lockKey);
    }
}
