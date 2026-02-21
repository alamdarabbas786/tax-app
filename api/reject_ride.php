<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/ride_dispatch.php';
require_once __DIR__ . '/realtime_events.php';

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
    $pdo = db();
    $offerTimeout = (int)(env_value('RIDE_OFFER_TIMEOUT_SECONDS', '30') ?? '30');
    $offerTimeout = max(30, min(60, $offerTimeout));
    $pdo->beginTransaction();

    $stmt = $pdo->prepare('UPDATE ride_requests_tracking
        SET status = "rejected", responded_at = NOW(), updated_at = NOW()
        WHERE ride_id = ? AND driver_id = ? AND status = "pending"');
    $stmt->execute([$rideId, $driverId]);

    $result = dispatch_next_driver($pdo, $rideId, $offerTimeout, 60);
    emit_ride_event('ride_rejected_by_driver', $rideId, [
        'driver_id' => $driverId
    ]);

    $pdo->commit();
    json_response(200, ['status' => 'ok', 'dispatch' => $result]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    json_response(500, ['status' => 'error', 'message' => $e->getMessage()]);
}
