<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/ride_dispatch.php';
require_once __DIR__ . '/redis.php';

api_guard();

try {
    $pdo = db();
    $offerTimeout = (int)(env_value('RIDE_OFFER_TIMEOUT_SECONDS', '30') ?? '30');
    $offerTimeout = max(30, min(60, $offerTimeout));
    $rows = $pdo->query('SELECT id, ride_id FROM ride_requests_tracking
        WHERE status = "pending" AND expires_at <= NOW()
        ORDER BY expires_at ASC
        LIMIT 50')->fetchAll();

    $processed = 0;
    foreach ($rows as $row) {
        $pdo->beginTransaction();
        $lock = $pdo->prepare('SELECT id, ride_id FROM ride_requests_tracking
            WHERE id = ? AND status = "pending" AND expires_at <= NOW() FOR UPDATE');
        $lock->execute([$row['id']]);
        $curr = $lock->fetch();
        if (!$curr) {
            $pdo->rollBack();
            continue;
        }

        $pdo->prepare('UPDATE ride_requests_tracking SET status = "expired", responded_at = NOW(), updated_at = NOW() WHERE id = ?')
            ->execute([$curr['id']]);
        $rideId = (int)$curr['ride_id'];
        $rideLock = 'taxi:lock:retry:' . $rideId;
        if (!redis_acquire_lock($rideLock, 5)) {
            $pdo->rollBack();
            continue;
        }
        dispatch_next_driver($pdo, $rideId, $offerTimeout, 60);
        redis_release_lock($rideLock);
        $pdo->commit();
        $processed++;
    }

    json_response(200, ['status' => 'ok', 'processed' => $processed]);
} catch (Throwable $e) {
    if (isset($rideLock)) {
        redis_release_lock($rideLock);
    }
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    json_response(500, ['status' => 'error', 'message' => $e->getMessage()]);
}
