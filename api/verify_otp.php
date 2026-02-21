<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/realtime_events.php';

api_guard();
$data = json_input();
$missing = require_fields($data, ['ride_id', 'driver_id', 'otp']);
if ($missing) {
    json_response(422, ['status' => 'error', 'message' => 'ride_id, driver_id, otp required']);
    exit;
}

$rideId = (int) $data['ride_id'];
$driverId = (int) $data['driver_id'];
$otp = trim((string) $data['otp']);

try {
    $pdo = db();
    $pdo->beginTransaction();

    $stmt = $pdo->prepare('SELECT r.id, r.customer_id, u.otp
        FROM rides r
        JOIN users u ON u.id = r.customer_id
        WHERE r.id = ? AND r.driver_id = ? AND r.status IN ("accepted","arrived") FOR UPDATE');
    $stmt->execute([$rideId, $driverId]);
    $row = $stmt->fetch();
    if (!$row) {
        $pdo->rollBack();
        json_response(404, ['status' => 'error', 'message' => 'Ride not found']);
        exit;
    }

    if (!hash_equals((string) $row['otp'], $otp)) {
        $pdo->rollBack();
        json_response(422, ['status' => 'error', 'message' => 'Invalid OTP']);
        exit;
    }

    $pdo->prepare('UPDATE rides SET status = "ride_started", started_at = NOW(), updated_at = NOW() WHERE id = ?')
        ->execute([$rideId]);

    $pdo->commit();
    emit_ride_event('ride_started', $rideId, ['driver_id' => $driverId]);
    json_response(200, ['status' => 'ok', 'message' => 'OTP verified. Ride started.']);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    json_response(500, ['status' => 'error', 'message' => $e->getMessage()]);
}
