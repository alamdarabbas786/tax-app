<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

api_guard();
$data = json_input();
$missing = require_fields($data, ['ride_id', 'customer_id', 'driver_id', 'rating']);
if ($missing) {
    json_response(422, ['status' => 'error', 'message' => 'ride_id, customer_id, driver_id, rating required']);
    exit;
}

$rideId = (int) $data['ride_id'];
$customerId = (int) $data['customer_id'];
$driverId = (int) $data['driver_id'];
$rating = (int) $data['rating'];
$feedback = trim((string) ($data['feedback'] ?? ''));

if ($rating < 1 || $rating > 5) {
    json_response(422, ['status' => 'error', 'message' => 'rating must be 1 to 5']);
    exit;
}

try {
    $pdo = db();
    $pdo->beginTransaction();

    $stmt = $pdo->prepare('INSERT INTO ratings (ride_id, customer_id, driver_id, rating, feedback, created_at)
        VALUES (?, ?, ?, ?, ?, NOW())');
    $stmt->execute([$rideId, $customerId, $driverId, $rating, $feedback !== '' ? $feedback : null]);

    $avgStmt = $pdo->prepare('SELECT AVG(rating) AS avg_rating FROM ratings WHERE driver_id = ?');
    $avgStmt->execute([$driverId]);
    $avg = (float) ($avgStmt->fetch()['avg_rating'] ?? 0);

    $pdo->prepare('UPDATE drivers SET rating = ?, updated_at = NOW() WHERE id = ?')
        ->execute([round($avg, 2), $driverId]);

    $pdo->commit();
    json_response(200, ['status' => 'ok', 'message' => 'Rating submitted']);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    json_response(500, ['status' => 'error', 'message' => $e->getMessage()]);
}

