<?php
// backend-php-samples/live_ride.php
// GET ?ride_id=<uuid>

require_once __DIR__ . '/db.php';

$rideId = $_GET['ride_id'] ?? '';
if ($rideId === '') {
  http_response_code(400);
  echo json_encode(['error' => 'ride_id required']);
  exit;
}

$sql = "SELECT
  r.id,
  r.status,
  r.pickup_lat,
  r.pickup_lng,
  r.drop_lat,
  r.drop_lng,
  r.pickup_address,
  r.drop_address,
  r.fare,
  r.otp_code,
  d.name AS driver_name,
  d.phone AS driver_phone,
  d.vehicle_number,
  d.vehicle_model,
  d.rating,
  d.latitude AS driver_lat,
  d.longitude AS driver_lng,
  d.profile_image AS photo_url
FROM rides r
LEFT JOIN drivers d ON d.id = r.driver_id
WHERE r.id = ? LIMIT 1";

$stmt = $pdo->prepare($sql);
$stmt->execute([$rideId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
  http_response_code(404);
  echo json_encode(['error' => 'ride not found']);
  exit;
}

echo json_encode([
  'ride' => [
    'id' => $row['id'],
    'status' => $row['status'],
    'pickup_lat' => (float)$row['pickup_lat'],
    'pickup_lng' => (float)$row['pickup_lng'],
    'drop_lat' => (float)$row['drop_lat'],
    'drop_lng' => (float)$row['drop_lng'],
    'pickup_address' => $row['pickup_address'],
    'drop_address' => $row['drop_address'],
    'fare' => $row['fare'],
    'otp_code' => $row['otp_code'] ?: '----'
  ],
  'driver' => [
    'name' => $row['driver_name'] ?: 'Driver',
    'phone' => $row['driver_phone'] ?: '',
    'vehicle_number' => $row['vehicle_number'] ?: '--',
    'vehicle_model' => $row['vehicle_model'] ?: 'Bike',
    'rating' => $row['rating'] ?: '4.8',
    'latitude' => $row['driver_lat'] ? (float)$row['driver_lat'] : 0,
    'longitude' => $row['driver_lng'] ? (float)$row['driver_lng'] : 0,
    'photo_url' => $row['photo_url'] ?: ''
  ]
]);
