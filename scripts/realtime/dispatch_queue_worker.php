<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/api/config.php';
require_once dirname(__DIR__, 2) . '/api/shard_db.php';
require_once dirname(__DIR__, 2) . '/api/queue.php';
require_once dirname(__DIR__, 2) . '/api/redis.php';
require_once dirname(__DIR__, 2) . '/api/fcm.php';

$queueName = env_value('DISPATCH_QUEUE_NAME', 'taxi:queue:ride_dispatch') ?? 'taxi:queue:ride_dispatch';
$delayedName = env_value('DISPATCH_DELAYED_ZSET', 'taxi:queue:ride_dispatch:delayed') ?? 'taxi:queue:ride_dispatch:delayed';
$maxJobRetry = (int)(env_value('DISPATCH_JOB_MAX_RETRY', '5') ?? '5');
$radiusKm = (float)(env_value('DISPATCH_RADIUS_KM', '3') ?? '3');
$offerTimeout = (int)(env_value('RIDE_OFFER_TIMEOUT_SECONDS', '30') ?? '30');
$offerTimeout = max(30, min(60, $offerTimeout));

echo "Dispatch worker started queue={$queueName}\n";

while (true) {
    queue_promote_due($delayedName, $queueName, 200);
    $job = queue_pop($queueName, 5);
    if (!$job) {
        continue;
    }

    try {
        $type = (string)($job['type'] ?? '');
        if ($type !== 'dispatch_ride') {
            continue;
        }

        $attempt = (int)($job['attempt'] ?? 0);
        $cityId = (int)($job['city_id'] ?? 0);
        $rideId = (int)($job['ride_id'] ?? 0);
        $customerId = (int)($job['customer_id'] ?? 0);
        $pickupLat = (float)($job['pickup_lat'] ?? 0);
        $pickupLng = (float)($job['pickup_lng'] ?? 0);
        if ($cityId <= 0 || $rideId <= 0 || $customerId <= 0) {
            continue;
        }

        $pdo = shard_db($cityId);
        $lockKey = 'taxi:lock:dispatch:' . $cityId . ':' . $rideId;
        if (!redis_acquire_lock($lockKey, 10)) {
            $job['attempt'] = $attempt + 1;
            queue_schedule($delayedName, $job, 1);
            continue;
        }

        try {
            $pdo->beginTransaction();
            $rideStmt = $pdo->prepare('SELECT id, status FROM rides WHERE id = ? FOR UPDATE');
            $rideStmt->execute([$rideId]);
            $ride = $rideStmt->fetch();
            if (!$ride || (string)$ride['status'] !== 'searching') {
                $pdo->rollBack();
                continue;
            }

            $geo = redis_geo_nearest_drivers_city($cityId, $pickupLat, $pickupLng, $radiusKm, 100);
            if ($geo === []) {
                $pdo->prepare('UPDATE rides SET status = "expired", updated_at = NOW() WHERE id = ? AND status = "searching"')
                    ->execute([$rideId]);
                $pdo->commit();
                continue;
            }

            $distanceMap = [];
            $driverIds = [];
            foreach ($geo as $row) {
                $driverId = (int)$row['driver_id'];
                if ($driverId <= 0) {
                    continue;
                }
                $driverIds[] = $driverId;
                $distanceMap[$driverId] = (float)$row['distance_km'];
            }
            if ($driverIds === []) {
                $pdo->rollBack();
                continue;
            }

            $in = implode(',', array_fill(0, count($driverIds), '?'));
            $dr = $pdo->prepare('SELECT id FROM drivers
                WHERE id IN (' . $in . ')
                  AND online_status = 1
                  AND availability = 1
                  AND ride_status = "free"
                LIMIT 100');
            $dr->execute($driverIds);
            $available = array_map(static fn($v): int => (int)$v, $dr->fetchAll(PDO::FETCH_COLUMN));
            if ($available === []) {
                $pdo->rollBack();
                continue;
            }

            usort($available, static function (int $a, int $b) use ($distanceMap): int {
                return ($distanceMap[$a] ?? 9999) <=> ($distanceMap[$b] ?? 9999);
            });

            $targetDriver = $available[0];
            $expiresAt = date('Y-m-d H:i:s', time() + $offerTimeout);
            $track = $pdo->prepare('INSERT INTO ride_requests_tracking
                (ride_id, driver_id, status, distance_km, offered_at, expires_at, created_at, updated_at)
                VALUES (?, ?, "pending", ?, NOW(), ?, NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                  status = VALUES(status),
                  distance_km = VALUES(distance_km),
                  offered_at = NOW(),
                  expires_at = VALUES(expires_at),
                  updated_at = NOW()');
            $track->execute([$rideId, $targetDriver, (float)($distanceMap[$targetDriver] ?? 0.0), $expiresAt]);
            $pdo->commit();

            $payload = [
                'ride_id' => (string)$rideId,
                'city_id' => (string)$cityId,
                'pickup_lat' => (string)$pickupLat,
                'pickup_lng' => (string)$pickupLng,
                'drop_lat' => (string)($job['drop_lat'] ?? ''),
                'drop_lng' => (string)($job['drop_lng'] ?? ''),
                'estimated_fare' => (string)($job['fare'] ?? ''),
                'driver_profit' => (string)($job['driver_profit'] ?? ''),
                'accept_endpoint' => '/api/driver_accept.php',
                'expires_in_sec' => (string)$offerTimeout
            ];
            $res = fcm_send_to_entity(
                $pdo,
                'driver',
                $targetDriver,
                ['title' => 'New Ride Request', 'body' => 'Accept within ' . $offerTimeout . ' seconds'],
                $payload,
                60,
                ['role' => 'driver', 'recipient_id' => $targetDriver, 'event_type' => 'ride_requested']
            );

            if (($res['status'] ?? '') !== 'ok') {
                if ($attempt < $maxJobRetry) {
                    $job['attempt'] = $attempt + 1;
                    $delay = (int)(2 ** min(6, $job['attempt']));
                    queue_schedule($delayedName, $job, $delay);
                }
            }
        } finally {
            redis_release_lock($lockKey);
        }
    } catch (Throwable $e) {
        $attempt = (int)($job['attempt'] ?? 0);
        if ($attempt < $maxJobRetry) {
            $job['attempt'] = $attempt + 1;
            $delay = (int)(2 ** min(6, $job['attempt']));
            queue_schedule($delayedName, $job, $delay);
        }
    }
}

