<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/ride_dispatch.php';

api_guard();
$data = json_input();
$missing = require_fields($data, ['ride_id']);
if ($missing) {
    json_response(422, ['status' => 'error', 'message' => 'ride_id required']);
    exit;
}
$rideId = (int) $data['ride_id'];

try {
    $pdo = db();
    $pdo->beginTransaction();

    $expireStmt = $pdo->prepare('UPDATE ride_requests_tracking
        SET status = "expired", responded_at = NOW(), updated_at = NOW()
        WHERE ride_id = ? AND status = "pending" AND expires_at <= NOW()');
    $expireStmt->execute([$rideId]);

    $result = dispatch_next_driver($pdo, $rideId, 30, 60);

    $pdo->commit();
    json_response(200, $result);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    json_response(500, ['status' => 'error', 'message' => $e->getMessage()]);
}
