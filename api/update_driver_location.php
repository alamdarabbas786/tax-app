<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/redis.php';
require_once __DIR__ . '/realtime_events.php';

api_guard();
$data = json_input();
$missing = require_fields($data, ['driver_id', 'latitude', 'longitude']);
if ($missing) {
    json_response(422, ['status' => 'error', 'message' => 'driver_id, latitude, longitude required']);
    exit;
}

$driverId = (int) $data['driver_id'];
$lat = (float) $data['latitude'];
$lng = (float) $data['longitude'];
$online = array_key_exists('online_status', $data) ? (int) (bool) $data['online_status'] : null;
$availability = array_key_exists('availability', $data) ? (int) (bool) $data['availability'] : null;

try {
    $pdo = db();
    $stmt = $pdo->prepare('UPDATE drivers
        SET latitude = ?, longitude = ?, last_seen_at = NOW(),
            online_status = COALESCE(?, online_status),
            availability = COALESCE(?, availability),
            updated_at = NOW()
        WHERE id = ?');
    $stmt->execute([$lat, $lng, $online, $availability, $driverId]);

    if ($stmt->rowCount() < 1) {
        json_response(404, ['status' => 'error', 'message' => 'Driver not found']);
        exit;
    }

    redis_geo_upsert_driver($driverId, $lat, $lng, [
        'online_status' => (string)($online ?? 1),
        'availability' => (string)($availability ?? 1)
    ]);
    if ($availability !== null) {
        redis_set_driver_available($driverId, (bool)$availability);
    }

    emit_ride_event('driver_location_updated', 0, [
        'driver_id' => $driverId,
        'lat' => $lat,
        'lng' => $lng
    ]);

    json_response(200, ['status' => 'ok', 'message' => 'Location updated']);
} catch (Throwable $e) {
    json_response(500, ['status' => 'error', 'message' => $e->getMessage()]);
}
