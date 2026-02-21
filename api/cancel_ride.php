<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/realtime_events.php';
require_once __DIR__ . '/fcm.php';

api_guard();
$data = json_input();
$missing = require_fields($data, ['ride_id', 'cancelled_by']);
if ($missing) {
    json_response(422, ['status' => 'error', 'message' => 'ride_id, cancelled_by required']);
    exit;
}

$rideId = (int)$data['ride_id'];
$cancelledBy = strtolower(trim((string)$data['cancelled_by']));
if (!in_array($cancelledBy, ['customer', 'driver', 'admin'], true)) {
    json_response(422, ['status' => 'error', 'message' => 'cancelled_by must be customer|driver|admin']);
    exit;
}

try {
    $pdo = db();
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('SELECT id, customer_id, driver_id, status FROM rides WHERE id = ? FOR UPDATE');
    $stmt->execute([$rideId]);
    $ride = $stmt->fetch();
    if (!$ride) {
        $pdo->rollBack();
        json_response(404, ['status' => 'error', 'message' => 'Ride not found']);
        exit;
    }

    if (in_array($ride['status'], ['completed', 'expired'], true)) {
        $pdo->rollBack();
        json_response(409, ['status' => 'error', 'message' => 'Ride already closed']);
        exit;
    }

    if ($cancelledBy === 'driver' && in_array($ride['status'], ['ride_started', 'in_progress', 'awaiting_payment', 'completed'], true)) {
        $pdo->rollBack();
        json_response(409, [
            'status' => 'error',
            'message' => 'Ride started होने के बाद driver cancel नहीं कर सकता'
        ]);
        exit;
    }

    $pdo->prepare('UPDATE rides SET status = "expired", updated_at = NOW() WHERE id = ?')->execute([$rideId]);
    $pdo->prepare('UPDATE ride_requests_tracking
        SET status = "expired", responded_at = NOW(), updated_at = NOW()
        WHERE ride_id = ? AND status IN ("queued","pending")')->execute([$rideId]);
    if (!empty($ride['driver_id'])) {
        $pdo->prepare('UPDATE drivers SET availability = 1, ride_status = "free", updated_at = NOW() WHERE id = ?')
            ->execute([$ride['driver_id']]);
    }

    $pdo->commit();

    if (!empty($ride['driver_id'])) {
        $drv = $pdo->prepare('SELECT fcm_token FROM drivers WHERE id = ?');
        $drv->execute([$ride['driver_id']]);
        $driver = $drv->fetch();
        fcm_send_to_token($driver['fcm_token'] ?? null, [
            'title' => 'Ride Cancelled',
            'body' => 'This ride has been cancelled'
        ], [
            'ride_id' => $rideId,
            'status' => 'cancelled',
            'cancelled_by' => $cancelledBy
        ], 60);
    }

    emit_ride_event('ride_cancelled', $rideId, [
        'cancelled_by' => $cancelledBy,
        'driver_id' => (int)($ride['driver_id'] ?? 0),
        'customer_id' => (int)($ride['customer_id'] ?? 0)
    ]);

    json_response(200, ['status' => 'ok', 'message' => 'Ride cancelled']);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    json_response(500, ['status' => 'error', 'message' => $e->getMessage()]);
}
