<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/api/config.php';
require_once dirname(__DIR__, 2) . '/api/ride_dispatch.php';
require_once dirname(__DIR__, 2) . '/api/redis.php';

$intervalMs = (int)($_SERVER['argv'][1] ?? 2000);
if ($intervalMs < 250) {
    $intervalMs = 250;
}

echo "Retry worker started (interval {$intervalMs}ms)\n";

while (true) {
    try {
        $pdo = db();
        $offerTimeout = (int)(env_value('RIDE_OFFER_TIMEOUT_SECONDS', '30') ?? '30');
        $offerTimeout = max(30, min(60, $offerTimeout));
        $rows = $pdo->query('SELECT id, ride_id FROM ride_requests_tracking
            WHERE status = "pending" AND expires_at <= NOW()
            ORDER BY expires_at ASC
            LIMIT 50')->fetchAll();

        foreach ($rows as $row) {
            $rideId = (int)$row['ride_id'];
            $rideLock = 'taxi:lock:retry:' . $rideId;
            if (!redis_acquire_lock($rideLock, 5)) {
                continue;
            }

            try {
                $pdo->beginTransaction();
                $lock = $pdo->prepare('SELECT id FROM ride_requests_tracking
                    WHERE id = ? AND status = "pending" AND expires_at <= NOW() FOR UPDATE');
                $lock->execute([$row['id']]);
                if (!$lock->fetch()) {
                    $pdo->rollBack();
                    continue;
                }
                $pdo->prepare('UPDATE ride_requests_tracking
                    SET status = "expired", responded_at = NOW(), updated_at = NOW()
                    WHERE id = ?')->execute([$row['id']]);
                dispatch_next_driver($pdo, $rideId, $offerTimeout, 60);
                $pdo->commit();
            } catch (Throwable $inner) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            } finally {
                redis_release_lock($rideLock);
            }
        }
    } catch (Throwable $e) {
        // keep worker alive
    }

    usleep($intervalMs * 1000);
}
