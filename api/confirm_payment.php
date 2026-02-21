<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/realtime_events.php';

api_guard();
$data = json_input();
$missing = require_fields($data, ['ride_id', 'driver_id', 'amount']);
if ($missing) {
    json_response(422, ['status' => 'error', 'message' => 'ride_id, driver_id, amount required']);
    exit;
}

$rideId = (int) $data['ride_id'];
$driverId = (int) $data['driver_id'];
$amount = round((float) $data['amount'], 2);

try {
    $pdo = db();
    $pdo->beginTransaction();

    $ride = $pdo->prepare('SELECT status FROM rides WHERE id = ? AND driver_id = ? FOR UPDATE');
    $ride->execute([$rideId, $driverId]);
    $row = $ride->fetch();
    if (!$row || !in_array($row['status'], ['awaiting_payment', 'completed'], true)) {
        $pdo->rollBack();
        json_response(409, ['status' => 'error', 'message' => 'Ride is not payable']);
        exit;
    }

    $insPay = $pdo->prepare('INSERT INTO payments (ride_id, amount, payment_status, created_at, updated_at)
        VALUES (?, ?, "paid", NOW(), NOW())
        ON DUPLICATE KEY UPDATE amount = VALUES(amount), payment_status = "paid", updated_at = NOW()');
    $insPay->execute([$rideId, $amount]);

    $pdo->prepare('UPDATE rides SET status = "completed", updated_at = NOW() WHERE id = ?')->execute([$rideId]);
    $pdo->prepare('UPDATE drivers SET availability = 1, ride_status = "free", updated_at = NOW() WHERE id = ?')->execute([$driverId]);

    $pdo->commit();
    emit_ride_event('ride_closed', $rideId, ['driver_id' => $driverId, 'amount' => $amount]);
    json_response(200, ['status' => 'ok', 'message' => 'Payment confirmed and ride completed']);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    json_response(500, ['status' => 'error', 'message' => $e->getMessage()]);
}
