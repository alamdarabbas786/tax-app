<?php
// backend-php-samples/ride_status.php
// GET ?ride_id=<uuid>

require_once __DIR__ . '/db.php';

$rideId = $_GET['ride_id'] ?? '';
if ($rideId === '') {
  http_response_code(400);
  echo json_encode(['error' => 'ride_id required']);
  exit;
}

$stmt = $pdo->prepare('SELECT status FROM rides WHERE id = ? LIMIT 1');
$stmt->execute([$rideId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
  http_response_code(404);
  echo json_encode(['error' => 'ride not found']);
  exit;
}

echo json_encode(['status' => $row['status']]);
