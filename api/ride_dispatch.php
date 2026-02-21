<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/fcm.php';
require_once __DIR__ . '/redis.php';
require_once __DIR__ . '/realtime_events.php';
require_once __DIR__ . '/notification_store.php';

function dispatch_next_driver(PDO $pdo, int $rideId, int $offerTimeoutSeconds = 30, int $fcmTtlSeconds = 60): array
{
    $offerTimeoutSeconds = max(30, min(60, $offerTimeoutSeconds));
    $fcmTtlSeconds = max($offerTimeoutSeconds, $fcmTtlSeconds);

    $lockKey = 'taxi:lock:dispatch:' . $rideId;
    if (!redis_acquire_lock($lockKey, 5)) {
        return ['status' => 'skipped', 'message' => 'Dispatch locked'];
    }

    try {
        $rideStmt = $pdo->prepare('SELECT id, customer_id, pickup_lat, pickup_lng, drop_lat, drop_lng, fare, driver_profit, status
            FROM rides
            WHERE id = ?
            FOR UPDATE');
        $rideStmt->execute([$rideId]);
        $ride = $rideStmt->fetch();
        if (!$ride) {
            return ['status' => 'error', 'message' => 'Ride not found'];
        }
        if ($ride['status'] !== 'searching') {
            return ['status' => 'skipped', 'message' => 'Ride not searching'];
        }

        $hasPending = $pdo->prepare('SELECT id FROM ride_requests_tracking
            WHERE ride_id = ? AND status = "pending" AND expires_at > NOW()
            LIMIT 1');
        $hasPending->execute([$rideId]);
        if ($hasPending->fetch()) {
            return ['status' => 'pending', 'message' => 'Driver already pending'];
        }

        $candidates = build_driver_candidates($pdo, (float) $ride['pickup_lat'], (float) $ride['pickup_lng']);
        if ($candidates === []) {
            $pdo->prepare('UPDATE rides SET status = "expired", updated_at = NOW() WHERE id = ? AND status = "searching"')->execute([$rideId]);
            emit_ride_event('ride_search_expired', $rideId, ['reason' => 'no_driver_in_radius']);
            return ['status' => 'no_driver', 'message' => 'No online driver in 2 km'];
        }

        queue_candidates($pdo, $rideId, $candidates);

        $nextStmt = $pdo->prepare('SELECT t.id, t.driver_id, t.distance_km, d.fcm_token
            FROM ride_requests_tracking t
            JOIN drivers d ON d.id = t.driver_id
            WHERE t.ride_id = ?
              AND t.status = "queued"
              AND d.online_status = 1
              AND d.availability = 1
              AND d.ride_status = "free"
            ORDER BY t.distance_km ASC
            LIMIT 1
            FOR UPDATE');
        $nextStmt->execute([$rideId]);
        $next = $nextStmt->fetch();

        if (!$next) {
            $pdo->prepare('UPDATE rides SET status = "expired", updated_at = NOW() WHERE id = ? AND status = "searching"')->execute([$rideId]);
            emit_ride_event('ride_search_expired', $rideId, ['reason' => 'queue_empty']);
            return ['status' => 'no_driver', 'message' => 'No available driver in queue'];
        }

        $pdo->prepare('UPDATE ride_requests_tracking
            SET status = "pending", offered_at = NOW(), expires_at = DATE_ADD(NOW(), INTERVAL ? SECOND), updated_at = NOW()
            WHERE id = ?')->execute([$offerTimeoutSeconds, $next['id']]);

        $payload = [
            'ride_id' => $rideId,
            'pickup_lat' => $ride['pickup_lat'],
            'pickup_lng' => $ride['pickup_lng'],
            'drop_lat' => $ride['drop_lat'],
            'drop_lng' => $ride['drop_lng'],
            'estimated_fare' => $ride['fare'],
            'driver_profit' => $ride['driver_profit'],
            'accept_endpoint' => '/api/accept_ride.php',
            'reject_endpoint' => '/api/reject_ride.php',
            'expires_in_sec' => $offerTimeoutSeconds
        ];

        $driverToken = fcm_resolve_target_token($pdo, 'driver', (int)$next['driver_id'], $next['fcm_token'] ?? null);
        $fcmResult = fcm_send_to_token(
            $driverToken,
            [
                'title' => 'New Ride Request',
                'body' => 'Pickup request received. Accept within ' . $offerTimeoutSeconds . ' seconds.'
            ],
            $payload,
            $fcmTtlSeconds,
            [
                'role' => 'driver',
                'recipient_id' => (int)$next['driver_id'],
                'event_type' => 'ride_requested'
            ]
        );

        log_notification_result(
            $pdo,
            $rideId,
            (int)$next['driver_id'],
            'ride_requested',
            $fcmResult,
            $payload
        );

        emit_ride_event('ride_requested', $rideId, [
            'driver_id' => (int) $next['driver_id'],
            'distance_km' => round((float) $next['distance_km'], 3),
            'fcm' => [
                'status' => $fcmResult['status'] ?? 'unknown',
                'status_code' => $fcmResult['status_code'] ?? null
            ]
        ]);

        return [
            'status' => 'ok',
            'driver_id' => (int) $next['driver_id'],
            'distance_km' => round((float) $next['distance_km'], 3),
            'fcm' => $fcmResult
        ];
    } finally {
        redis_release_lock($lockKey);
    }
}

function log_notification_result(PDO $pdo, int $rideId, int $driverId, string $eventType, array $fcmResult, array $payload): void
{
    try {
        $notificationId = notification_insert(
            $pdo,
            $rideId,
            'driver',
            $driverId,
            $eventType,
            'fcm',
            'New Ride Request',
            'Pickup request received.',
            $payload,
            ($fcmResult['status'] ?? '') === 'ok' ? 'sent' : 'failed',
            $fcmResult['error'] ?? null
        );

        $stmt = $pdo->prepare('INSERT INTO notification_logs
            (ride_id, driver_id, event_type, fcm_status_code, fcm_status, fcm_error, payload)
            VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $rideId,
            $driverId,
            $eventType,
            $fcmResult['status_code'] ?? null,
            $fcmResult['status'] ?? null,
            $fcmResult['error'] ?? null,
            json_encode($payload, JSON_UNESCAPED_SLASHES)
        ]);

        if ($notificationId) {
            foreach (($fcmResult['attempts'] ?? []) as $attempt) {
                notification_insert_attempt($pdo, $notificationId, [
                    'attempt' => $attempt['attempt'] ?? 1,
                    'status_code' => $attempt['status_code'] ?? null,
                    'is_success' => ($attempt['status'] ?? '') === 'ok',
                    'error' => $attempt['error'] ?? null,
                    'response' => $attempt['response'] ?? null
                ]);
            }
        }
    } catch (Throwable $e) {
        // logging must never break request dispatch
    }
}

function build_driver_candidates(PDO $pdo, float $pickupLat, float $pickupLng): array
{
    $geoRows = redis_geo_nearest_drivers($pickupLat, $pickupLng, 2.0, 100);
    $rows = [];
    if ($geoRows !== []) {
        $idToDistance = [];
        $ids = [];
        foreach ($geoRows as $g) {
            $id = (int)($g['driver_id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $ids[] = $id;
            $idToDistance[$id] = round((float)($g['distance_km'] ?? 0), 3);
        }
        if ($ids !== []) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare('SELECT id
                FROM drivers
                WHERE id IN (' . $placeholders . ')
                  AND online_status = 1
                  AND availability = 1
                  AND ride_status = "free"');
            $stmt->execute($ids);
            $valid = $stmt->fetchAll(PDO::FETCH_COLUMN);
            foreach ($valid as $driverId) {
                $driverId = (int)$driverId;
                if (isset($idToDistance[$driverId])) {
                    $rows[] = ['driver_id' => $driverId, 'distance_km' => $idToDistance[$driverId]];
                }
            }
            usort($rows, static fn(array $a, array $b) => $a['distance_km'] <=> $b['distance_km']);
        }
    }

    if ($rows !== []) {
        return $rows;
    }

    // Fallback to MySQL Haversine if Redis is not available.
    $distanceExpr = haversine_sql_expr('d.latitude', 'd.longitude');
    $stmt = $pdo->prepare('SELECT d.id AS driver_id, ' . $distanceExpr . ' AS distance_km
        FROM drivers d
        WHERE d.online_status = 1
          AND d.availability = 1
          AND d.ride_status = "free"
          AND d.latitude IS NOT NULL
          AND d.longitude IS NOT NULL
        HAVING distance_km <= 2
        ORDER BY distance_km ASC
        LIMIT 100');
    $stmt->execute([
        'pickup_lat' => $pickupLat,
        'pickup_lng' => $pickupLng
    ]);
    return array_map(static function (array $row): array {
        return [
            'driver_id' => (int)$row['driver_id'],
            'distance_km' => round((float)$row['distance_km'], 3)
        ];
    }, $stmt->fetchAll());
}

function queue_candidates(PDO $pdo, int $rideId, array $candidates): void
{
    $insert = $pdo->prepare('INSERT INTO ride_requests_tracking
        (ride_id, driver_id, status, distance_km, offered_at, expires_at, created_at, updated_at)
        VALUES (?, ?, "queued", ?, NOW(), DATE_ADD(NOW(), INTERVAL 30 SECOND), NOW(), NOW())
        ON DUPLICATE KEY UPDATE distance_km = LEAST(distance_km, VALUES(distance_km)), updated_at = NOW()');

    foreach ($candidates as $candidate) {
        $insert->execute([
            $rideId,
            (int)$candidate['driver_id'],
            (float)$candidate['distance_km']
        ]);
    }
}
