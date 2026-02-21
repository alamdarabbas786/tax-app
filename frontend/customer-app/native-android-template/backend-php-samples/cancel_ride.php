<?php
// backend-php-samples/cancel_ride.php
// POST JSON: { "ride_id": "...", "reason": "..." }

require_once __DIR__ . '/db.php';

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$rideId = $input['ride_id'] ?? '';
$reason = trim($input['reason'] ?? '');

if ($rideId === '' || $reason === '') {
  http_response_code(400);
  echo json_encode(['error' => 'ride_id and reason required']);
  exit;
}

$pdo->beginTransaction();
try {
  $stmt = $pdo->prepare('SELECT status, driver_id FROM rides WHERE id = ? LIMIT 1 FOR UPDATE');
  $stmt->execute([$rideId]);
  $ride = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$ride) {
    throw new Exception('Ride not found');
  }

  if (in_array($ride['status'], ['completed', 'cancelled'], true)) {
    throw new Exception('Ride cannot be cancelled now');
  }

  $stmt = $pdo->prepare('UPDATE rides SET status = ?, cancel_reason = ?, cancelled_at = NOW() WHERE id = ?');
  $stmt->execute(['cancelled', $reason, $rideId]);

  if (!empty($ride['driver_id'])) {
    // Optionally notify driver via FCM or websocket event.
  }

  $pdo->commit();
  echo json_encode(['ok' => true, 'penalty' => 0]);
} catch (Throwable $e) {
  $pdo->rollBack();
  http_response_code(422);
  echo json_encode(['error' => $e->getMessage()]);
}
