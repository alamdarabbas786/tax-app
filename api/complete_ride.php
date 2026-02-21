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
$clientFare = isset($data['final_fare']) && is_numeric($data['final_fare']) ? round((float) $data['final_fare'], 2) : null;
$clientLat = isset($data['current_lat']) && is_numeric($data['current_lat']) ? (float) $data['current_lat'] : null;
$clientLng = isset($data['current_lng']) && is_numeric($data['current_lng']) ? (float) $data['current_lng'] : null;
$arrivalRadiusMeters = (float) (env_value('DROP_ARRIVAL_RADIUS_METERS', '50') ?? '50');
if ($arrivalRadiusMeters < 30) {
    $arrivalRadiusMeters = 30;
}
if ($arrivalRadiusMeters > 100) {
    $arrivalRadiusMeters = 100;
}

try {
    $pdo = db();
    $pdo->beginTransaction();

    $rideStmt = $pdo->prepare('SELECT id, customer_id, status, fare, drop_lat, drop_lng
        FROM rides
        WHERE id = ? AND driver_id = ?
        FOR UPDATE');
    $rideStmt->execute([$rideId, $driverId]);
    $ride = $rideStmt->fetch();
    if (!$ride || !in_array((string)$ride['status'], ['in_progress', 'ride_started'], true)) {
        $pdo->rollBack();
        json_response(409, ['status' => 'error', 'message' => 'Ride cannot be completed']);
        exit;
    }

    $drvStmt = $pdo->prepare('SELECT latitude, longitude FROM drivers WHERE id = ? LIMIT 1');
    $drvStmt->execute([$driverId]);
    $driver = $drvStmt->fetch();
    $driverLat = isset($driver['latitude']) && is_numeric($driver['latitude']) ? (float)$driver['latitude'] : null;
    $driverLng = isset($driver['longitude']) && is_numeric($driver['longitude']) ? (float)$driver['longitude'] : null;
    if ($driverLat === null && $clientLat !== null) {
        $driverLat = $clientLat;
    }
    if ($driverLng === null && $clientLng !== null) {
        $driverLng = $clientLng;
    }

    if ($driverLat === null || $driverLng === null) {
        $pdo->rollBack();
        json_response(409, ['status' => 'error', 'message' => 'Driver location unavailable. Reach drop location first.']);
        exit;
    }

    $dropLat = (float)$ride['drop_lat'];
    $dropLng = (float)$ride['drop_lng'];
    $distanceToDropMeters = haversine_meters($driverLat, $driverLng, $dropLat, $dropLng);
    if ($distanceToDropMeters > $arrivalRadiusMeters) {
        $pdo->rollBack();
        json_response(409, [
            'status' => 'error',
            'message' => 'Drop location par pahuchne ke baad hi ride complete hogi',
            'distance_to_drop_meters' => round($distanceToDropMeters, 1),
            'required_radius_meters' => $arrivalRadiusMeters
        ]);
        exit;
    }

    $actualGeneratedFare = resolve_actual_generated_fare($pdo, $rideId, (float)$ride['fare'], $clientFare);
    if ($actualGeneratedFare <= 0) {
        $pdo->rollBack();
        json_response(409, ['status' => 'error', 'message' => 'Actual fare not available']);
        exit;
    }

    $stmt = $pdo->prepare('UPDATE rides
        SET fare = ?, status = "awaiting_payment", completed_at = NOW(), updated_at = NOW()
        WHERE id = ? AND driver_id = ? AND status IN ("in_progress","ride_started")');
    $stmt->execute([$actualGeneratedFare, $rideId, $driverId]);
    if ($stmt->rowCount() !== 1) {
        $pdo->rollBack();
        json_response(409, ['status' => 'error', 'message' => 'Ride cannot be completed']);
        exit;
    }

    if (column_exists($pdo, 'rides', 'final_fare')) {
        $upFinal = $pdo->prepare('UPDATE rides SET final_fare = ? WHERE id = ?');
        $upFinal->execute([$actualGeneratedFare, $rideId]);
    }

    $customerId = isset($ride['customer_id']) ? (int)$ride['customer_id'] : 0;

    $published = emit_ride_event('ride_completed', $rideId, [
        'driver_id' => $driverId,
        'customer_id' => $customerId,
        'fare' => $actualGeneratedFare
    ]);
    notification_insert(
        $pdo,
        $rideId,
        $customerId > 0 ? 'customer' : 'system',
        $customerId > 0 ? $customerId : null,
        'ride_completed',
        'websocket',
        null,
        null,
        ['driver_id' => $driverId, 'fare' => $actualGeneratedFare],
        $published ? 'sent' : 'failed',
        $published ? null : 'websocket_offline'
    );

    $fallback = null;
    if (!$published && $customerId > 0) {
        $fallback = fcm_send_to_entity(
            $pdo,
            'customer',
            $customerId,
            ['title' => 'Ride Completed', 'body' => 'Ride completed. Please proceed to payment'],
            ['ride_id' => (string)$rideId, 'status' => 'awaiting_payment', 'fare' => (string)$actualGeneratedFare],
            60,
            ['role' => 'customer', 'recipient_id' => $customerId, 'event_type' => 'ride_completed']
        );
    }

    $pdo->commit();
    json_response(200, [
        'status' => 'ok',
        'message' => 'Ride marked awaiting payment',
        'actual_fare' => $actualGeneratedFare,
        'distance_to_drop_meters' => round($distanceToDropMeters, 1),
        'fallback_fcm' => $fallback
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    json_response(500, ['status' => 'error', 'message' => $e->getMessage()]);
}

function resolve_actual_generated_fare(PDO $pdo, int $rideId, float $existingFare, ?float $clientFare): float
{
    if (column_exists($pdo, 'rides', 'final_fare')) {
        $stmt = $pdo->prepare('SELECT final_fare FROM rides WHERE id = ? LIMIT 1');
        $stmt->execute([$rideId]);
        $row = $stmt->fetch();
        $storedFinalFare = isset($row['final_fare']) && is_numeric($row['final_fare']) ? (float)$row['final_fare'] : 0.0;
        if ($storedFinalFare > 0) {
            return round($storedFinalFare, 2);
        }
    }

    if ($existingFare > 0) {
        return round($existingFare, 2);
    }
    if ($clientFare !== null && $clientFare > 0) {
        return round($clientFare, 2);
    }
    return 0.0;
}

function column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(1)
        FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function haversine_meters(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $earthRadius = 6371000.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2
        + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return $earthRadius * $c;
}
