<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\ApiAuth;
use App\Cache\RedisCache;
use App\Db\Mysql;
use App\Services\Pricing;
use App\Services\FcmService;
use App\Utils\Uuid;

final class RidesController
{
    private static array $schemaCache = [];
    private const REQUEST_EXPIRES_SECONDS = 60;

    public function create(): void
    {
        $auth = ApiAuth::requireRole('customer');
        if (!$auth) {
            return;
        }

        $data = $this->jsonBody();
        try {
            $pickupLat = $this->requireNumber($data, 'pickup_lat');
            $pickupLng = $this->requireNumber($data, 'pickup_lng');
            $dropLat = $this->requireNumber($data, 'drop_lat');
            $dropLng = $this->requireNumber($data, 'drop_lng');
            $pickupAddress = $this->requireString($data, 'pickup_address');
            $dropAddress = $this->requireString($data, 'drop_address');
            $vehicleType = strtolower(trim($this->requireString($data, 'vehicle_type')));
            $distanceKm = $this->requireNumber($data, 'distance_km');
            $durationMinutes = $this->requireNumber($data, 'duration_minutes');
        } catch (\InvalidArgumentException $e) {
            $this->respond(422, ['status' => 'error', 'message' => $e->getMessage()]);
            return;
        }
        $paymentModeRaw = strtolower(trim((string)($data['payment_mode'] ?? ($data['payment_method'] ?? 'cash'))));
        $paymentMode = $paymentModeRaw === 'online' ? 'online' : 'cash';
        $initialRideStatus = $paymentMode === 'online' ? 'awaiting_payment' : 'searching';

        $pdo = Mysql::connection();
        try {
            $pdo->beginTransaction();

            $stmtPricing = $pdo->prepare('SELECT vehicle_type, cost_per_km, cost_per_min, minimum_fare, platform_fee FROM vehicle_pricing WHERE vehicle_type = ? AND is_active = 1');
            $stmtPricing->execute([$vehicleType]);
            $pricing = $stmtPricing->fetch(\PDO::FETCH_ASSOC);
            if (!$pricing) {
                $fallbackPricing = Pricing::vehicleConfig();
                if (!isset($fallbackPricing[$vehicleType])) {
                    throw new \RuntimeException('Pricing not configured for vehicle type');
                }
                $cfg = $fallbackPricing[$vehicleType];
                $pricing = [
                    'vehicle_type' => $vehicleType,
                    'cost_per_km' => (float) $cfg['cost_per_km'],
                    'cost_per_min' => (float) $cfg['cost_per_min'],
                    'minimum_fare' => (float) $cfg['minimum_fare'],
                    'platform_fee' => (float) $cfg['platform_fee']
                ];
            }

            $fare = Pricing::calculateFare([
                'distance_km' => $distanceKm,
                'duration_minutes' => $durationMinutes,
                'driver_cost_per_km' => (float) $pricing['cost_per_km'],
                'driver_cost_per_min' => (float) $pricing['cost_per_min'],
                'minimum_fare' => (float) $pricing['minimum_fare'],
                'platform_fee' => (float) $pricing['platform_fee']
            ]);

            $rideId = Uuid::v4Binary();
            $ridesIdType = $this->columnType($pdo, 'rides', 'id');
            $ridesIdIsNumeric = $this->isNumericType($ridesIdType);
            $otpCode = (string) random_int(1000, 9999);
            $otpExpires = (new \DateTime('+15 minutes'))->format('Y-m-d H:i:s');
            $resolvedCustomerId = $this->resolveRideCustomerId($pdo, $auth);
            if ($resolvedCustomerId === null || $resolvedCustomerId === '') {
                throw new \RuntimeException('Customer session mismatch. Please login again.');
            }

            $cols = [
                'customer_id',
                'driver_id',
                'pickup_lat',
                'pickup_lng',
                'drop_lat',
                'drop_lng',
                'pickup_address',
                'drop_address',
                'vehicle_type',
                'distance_km',
                'duration_min',
                'fare',
                'driver_cost',
                'driver_profit',
                'driver_earning',
                'platform_fee',
                'otp_code',
                'otp_expires_at',
                'status',
                'searching_started_at'
            ];
            $placeholders = array_fill(0, count($cols), '?');
            $values = [
                $resolvedCustomerId,
                null,
                $pickupLat,
                $pickupLng,
                $dropLat,
                $dropLng,
                $pickupAddress,
                $dropAddress,
                $vehicleType,
                $distanceKm,
                $durationMinutes,
                $fare['total_fare'],
                $fare['driver_cost'],
                $fare['driver_profit'],
                $fare['driver_earning'],
                $fare['platform_fee'],
                $otpCode,
                $otpExpires,
                $initialRideStatus,
                $paymentMode === 'online' ? null : date('Y-m-d H:i:s')
            ];
            if (!$ridesIdIsNumeric && $this->columnExists($pdo, 'rides', 'id')) {
                array_unshift($cols, 'id');
                array_unshift($placeholders, '?');
                array_unshift($values, $rideId);
            }

            // Persist planned/estimated values (if migration 019 is applied).
            if ($this->columnExists($pdo, 'rides', 'planned_distance_km')) {
                $cols[] = 'planned_distance_km';
                $placeholders[] = '?';
                $values[] = $distanceKm;
            }
            if ($this->columnExists($pdo, 'rides', 'planned_duration_min')) {
                $cols[] = 'planned_duration_min';
                $placeholders[] = '?';
                $values[] = $durationMinutes;
            }
            if ($this->columnExists($pdo, 'rides', 'estimated_fare')) {
                $cols[] = 'estimated_fare';
                $placeholders[] = '?';
                $values[] = $fare['total_fare'];
            }
            if ($this->columnExists($pdo, 'rides', 'payment_mode')) {
                $cols[] = 'payment_mode';
                $placeholders[] = '?';
                $values[] = $paymentMode;
            }
            if ($this->columnExists($pdo, 'rides', 'payment_method')) {
                $cols[] = 'payment_method';
                $placeholders[] = '?';
                $values[] = $paymentMode;
            }
            if ($this->columnExists($pdo, 'rides', 'payment_status')) {
                $cols[] = 'payment_status';
                $placeholders[] = '?';
                $values[] = $paymentMode === 'online' ? 'pending' : 'unpaid';
            }

            $rideInsert = $pdo->prepare('INSERT INTO rides (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $placeholders) . ')');
            $rideInsert->execute($values);
            if ($ridesIdIsNumeric) {
                $insertedId = $pdo->lastInsertId();
                if ($insertedId !== '') {
                    $rideId = (int) $insertedId;
                }
            }

            $this->insertStatusHistory(
                $pdo,
                $rideId,
                $initialRideStatus,
                'customer',
                $auth['subject_id'],
                $paymentMode === 'online' ? 'Ride created. Waiting for payment confirmation' : 'Ride created'
            );

            if ($paymentMode === 'online') {
                $pdo->commit();
                $this->respond(201, [
                    'status' => 'ok',
                    'ride_id' => $this->normalizeId($rideId),
                    'fare' => $fare['total_fare'],
                    'currency' => 'INR',
                    'ride_status' => 'awaiting_payment'
                ]);
                return;
            }

            $candidates = $this->findNearbyDrivers($pdo, $pickupLat, $pickupLng, $vehicleType);
            if (count($candidates) === 0) {
                $pdo->prepare('UPDATE rides SET status="no_driver_found", no_driver_found_at = NOW() WHERE id = ?')
                    ->execute([$rideId]);
                $this->insertStatusHistory($pdo, $rideId, 'no_driver_found', 'system', null, 'No nearby drivers');
                $pdo->commit();
                $this->respond(200, [
                    'status' => 'ok',
                    'ride_id' => $this->normalizeId($rideId),
                    'fare' => $fare['total_fare'],
                    'currency' => 'INR',
                    'ride_status' => 'no_driver_found'
                ]);
                return;
            }

            $this->createDriverRequests($pdo, $rideId, $candidates);
            $this->notifyNextDriver($pdo, $rideId, $pickupAddress, $dropAddress, $fare['total_fare'], $fare['driver_earning']);

            $pdo->commit();

            $this->respond(201, [
                'status' => 'ok',
                'ride_id' => $this->normalizeId($rideId),
                'fare' => $fare['total_fare'],
                'currency' => 'INR',
                'ride_status' => 'searching'
            ]);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->respond(500, ['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    public function getRide(string $rideId): void
    {
        $auth = ApiAuth::requireAnyRole(['customer','driver','admin']);
        if (!$auth) {
            return;
        }

        $pdo = Mysql::connection();
        $rid = $this->toRideId($rideId);

        $driverLatExpr = $this->selectExpr($pdo, 'drivers', ['current_lat', 'latitude'], 'driver_lat', 'NULL');
        $driverLngExpr = $this->selectExpr($pdo, 'drivers', ['current_lng', 'longitude'], 'driver_lng', 'NULL');
        $driverVehicleExpr = $this->selectExpr($pdo, 'drivers', ['vehicle_number'], 'driver_vehicle', "''");
        $driverVehicleNumberExpr = $this->selectExpr($pdo, 'drivers', ['vehicle_number'], 'driver_vehicle_number', "''");
        $driverVehicleTypeExpr = $this->selectExpr($pdo, 'drivers', ['vehicle_type'], 'driver_vehicle_type', "''");
        $driverPhotoExpr = $this->selectExpr($pdo, 'drivers', ['driver_photo_path', 'profile_image', 'photo_url'], 'driver_photo_url', "''");

        $driverNameExpr = $this->selectExpr($pdo, 'drivers', ['name'], 'driver_name', "''");
        $driverPhoneExpr = $this->selectExpr($pdo, 'drivers', ['phone'], 'driver_phone', "''");
        $sql = 'SELECT r.*, ' . $driverNameExpr . ', ' . $driverPhoneExpr . ', '
            . $driverVehicleExpr . ', '
            . $driverVehicleNumberExpr . ', '
            . $driverVehicleTypeExpr . ', '
            . $driverLatExpr . ', '
            . $driverLngExpr . ', '
            . $driverPhotoExpr
            . ' FROM rides r LEFT JOIN drivers d ON r.driver_id = d.id WHERE r.id = ? LIMIT 1';

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$rid]);
        $ride = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$ride) {
            $this->respond(404, ['status' => 'error', 'message' => 'Ride not found']);
            return;
        }

            if ($auth['role'] === 'customer' && $ride['customer_id'] !== $auth['subject_id']) {
            $rideCustomerId = (string)$this->normalizeId($ride['customer_id'] ?? '');
            $authSubjectId = (string)$this->normalizeId($auth['subject_id'] ?? '');
            if ($rideCustomerId !== $authSubjectId) {
                $this->respond(403, ['status' => 'error', 'message' => 'Forbidden']);
                return;
            }
        }
        if ($auth['role'] === 'driver') {
            $rideDriverId = (string)$this->normalizeId($ride['driver_id'] ?? '');
            $authSubjectId = (string)$this->normalizeId($auth['subject_id'] ?? '');
            if ($rideDriverId === '' || $rideDriverId !== $authSubjectId) {
                $this->respond(403, ['status' => 'error', 'message' => 'Forbidden']);
                return;
            }
        }

        $ride['id'] = $this->normalizeId($ride['id']);
        $ride['customer_id'] = $this->normalizeId($ride['customer_id']);
        $ride['driver_id'] = $this->normalizeId($ride['driver_id']);
        $ride['status'] = $this->normalizeRideStatus($ride);
        $ride['driver_photo_url'] = $this->toAbsoluteAssetUrl((string)($ride['driver_photo_url'] ?? ''));

        if ($auth['role'] === 'customer') {
            unset($ride['driver_cost'], $ride['driver_profit'], $ride['driver_earning'], $ride['platform_fee']);
        }
        if ($auth['role'] === 'driver') {
            unset($ride['driver_cost'], $ride['driver_profit'], $ride['platform_fee']);
        }

        $this->respond(200, ['status' => 'ok', 'ride' => $ride]);
    }

    public function activateRideSearchAfterPayment(\PDO $pdo, $rideId): void
    {
        $rideStmt = $pdo->prepare('SELECT id, status, pickup_lat, pickup_lng, pickup_address, drop_address, vehicle_type, fare, driver_earning
            FROM rides
            WHERE id = ?
            LIMIT 1
            FOR UPDATE');
        $rideStmt->execute([$rideId]);
        $ride = $rideStmt->fetch(\PDO::FETCH_ASSOC);
        if (!$ride) {
            return;
        }
        $status = strtolower(trim((string)($ride['status'] ?? '')));
        if (!in_array($status, ['awaiting_payment', 'searching', 'requested', ''], true)) {
            return;
        }

        if ($status === 'awaiting_payment') {
            $pdo->prepare('UPDATE rides
                SET status = "searching", searching_started_at = COALESCE(searching_started_at, NOW())
                WHERE id = ?')->execute([$rideId]);
            $this->insertStatusHistory($pdo, $rideId, 'searching', 'system', null, 'Payment confirmed. Driver search started');
        }

        if ($this->tableExists($pdo, 'ride_driver_requests')) {
            $existsReq = $pdo->prepare('SELECT 1 FROM ride_driver_requests WHERE ride_id = ? AND status IN ("pending","queued","accepted") LIMIT 1');
            $existsReq->execute([$rideId]);
            if ($existsReq->fetchColumn()) {
                return;
            }
        }

        $pickupLat = (float)($ride['pickup_lat'] ?? 0);
        $pickupLng = (float)($ride['pickup_lng'] ?? 0);
        $vehicleType = strtolower(trim((string)($ride['vehicle_type'] ?? 'mini')));
        $candidates = $this->findNearbyDrivers($pdo, $pickupLat, $pickupLng, $vehicleType);
        if (count($candidates) === 0) {
            $pdo->prepare('UPDATE rides SET status="no_driver_found", no_driver_found_at = NOW() WHERE id = ? AND status IN ("searching","requested","")')
                ->execute([$rideId]);
            $this->insertStatusHistory($pdo, $rideId, 'no_driver_found', 'system', null, 'No nearby drivers after payment');
            return;
        }

        $this->createDriverRequests($pdo, $rideId, $candidates);
        $this->notifyNextDriver(
            $pdo,
            $rideId,
            (string)($ride['pickup_address'] ?? ''),
            (string)($ride['drop_address'] ?? ''),
            (float)($ride['fare'] ?? 0),
            (float)($ride['driver_earning'] ?? 0)
        );
    }

    public function getActiveRide(): void
    {
        $auth = ApiAuth::requireRole('customer');
        if (!$auth) {
            return;
        }

        $pdo = Mysql::connection();

        $driverLatExpr = $this->selectExpr($pdo, 'drivers', ['current_lat', 'latitude'], 'driver_lat', 'NULL');
        $driverLngExpr = $this->selectExpr($pdo, 'drivers', ['current_lng', 'longitude'], 'driver_lng', 'NULL');
        $driverVehicleExpr = $this->selectExpr($pdo, 'drivers', ['vehicle_number'], 'driver_vehicle', "''");
        $driverVehicleNumberExpr = $this->selectExpr($pdo, 'drivers', ['vehicle_number'], 'driver_vehicle_number', "''");
        $driverVehicleTypeExpr = $this->selectExpr($pdo, 'drivers', ['vehicle_type'], 'driver_vehicle_type', "''");
        $driverPhotoExpr = $this->selectExpr($pdo, 'drivers', ['driver_photo_path', 'profile_image', 'photo_url'], 'driver_photo_url', "''");

        $driverNameExpr = $this->selectExpr($pdo, 'drivers', ['name'], 'driver_name', "''");
        $driverPhoneExpr = $this->selectExpr($pdo, 'drivers', ['phone'], 'driver_phone', "''");
        $sql = 'SELECT r.*, ' . $driverNameExpr . ', ' . $driverPhoneExpr . ', '
            . $driverVehicleExpr . ', '
            . $driverVehicleNumberExpr . ', '
            . $driverVehicleTypeExpr . ', '
            . $driverLatExpr . ', '
            . $driverLngExpr . ', '
            . $driverPhotoExpr
            . ' FROM rides r LEFT JOIN drivers d ON r.driver_id = d.id
               WHERE r.customer_id = ?
                 AND r.status NOT IN ("completed", "ride_closed", "cancelled", "no_driver_found")
               ORDER BY r.created_at DESC
               LIMIT 1';

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$auth['subject_id']]);
        $ride = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$ride) {
            $this->respond(200, ['status' => 'ok', 'ride' => null]);
            return;
        }

        $ride['id'] = $this->normalizeId($ride['id']);
        $ride['customer_id'] = $this->normalizeId($ride['customer_id']);
        $ride['driver_id'] = $this->normalizeId($ride['driver_id']);
        $ride['driver_photo_url'] = $this->toAbsoluteAssetUrl((string)($ride['driver_photo_url'] ?? ''));
        unset($ride['driver_cost'], $ride['driver_profit'], $ride['driver_earning'], $ride['platform_fee']);

        $this->respond(200, ['status' => 'ok', 'ride' => $ride]);
    }

    public function cancelRide(string $rideId): void
    {
        $auth = ApiAuth::requireRole('customer');
        if (!$auth) {
            return;
        }

        $pdo = Mysql::connection();
        $rid = $this->toRideId($rideId);
        $data = $this->jsonBody();
        $reason = trim((string)($data['reason'] ?? 'Cancelled by customer'));
        if ($reason === '') {
            $reason = 'Cancelled by customer';
        }

        try {
            $pdo->beginTransaction();

            $startedAtExpr = $this->columnExists($pdo, 'rides', 'started_at') ? 'started_at' : 'NULL AS started_at';
            $rideStartedAtExpr = $this->columnExists($pdo, 'rides', 'ride_started_at') ? 'ride_started_at' : 'NULL AS ride_started_at';
            $stmt = $pdo->prepare('SELECT id, customer_id, driver_id, status, '
                . $startedAtExpr . ', '
                . $rideStartedAtExpr
                . ' FROM rides WHERE id = ? LIMIT 1 FOR UPDATE');
            $stmt->execute([$rid]);
            $ride = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$ride) {
                $pdo->rollBack();
                $this->respond(404, ['status' => 'error', 'message' => 'Ride not found']);
                return;
            }

            $rideCustomerId = (string)$this->normalizeId($ride['customer_id'] ?? '');
            $authSubjectId = (string)$this->normalizeId($auth['subject_id'] ?? '');
            if ($rideCustomerId === '' || $rideCustomerId !== $authSubjectId) {
                $pdo->rollBack();
                $this->respond(403, ['status' => 'error', 'message' => 'Forbidden']);
                return;
            }

            $currentStatus = strtolower((string)$ride['status']);
            if (in_array($currentStatus, ['completed', 'ride_closed', 'cancelled', 'no_driver_found'], true)) {
                $pdo->rollBack();
                $this->respond(422, ['status' => 'error', 'message' => 'Ride cannot be cancelled now']);
                return;
            }
            if (in_array($currentStatus, ['driver_arrived', 'arrived', 'waiting'], true)) {
                $pdo->rollBack();
                $this->respond(422, ['status' => 'error', 'message' => 'Driver has arrived. Ride cannot be cancelled at this stage.']);
                return;
            }
            $hasStartedMarker = !empty($ride['ride_started_at']) || !empty($ride['started_at']);
            if (in_array($currentStatus, ['ride_started', 'in_progress', 'enroute'], true) || $hasStartedMarker) {
                $pdo->rollBack();
                $this->respond(422, ['status' => 'error', 'message' => 'Ride already started and cannot be cancelled.']);
                return;
            }

            $rideSetParts = ['status = "cancelled"'];
            $rideSetParams = [];
            if ($this->columnExists($pdo, 'rides', 'cancelled_at')) {
                $rideSetParts[] = 'cancelled_at = NOW()';
            }
            if ($this->columnExists($pdo, 'rides', 'cancelled_by')) {
                $rideSetParts[] = 'cancelled_by = "customer"';
            }
            if ($this->columnExists($pdo, 'rides', 'cancel_reason')) {
                $rideSetParts[] = 'cancel_reason = ?';
                $rideSetParams[] = $reason;
            }
            $rideSetParams[] = $ride['id'];
            $updateStmt = $pdo->prepare('UPDATE rides SET ' . implode(', ', $rideSetParts) . ' WHERE id = ?');
            $updateStmt->execute($rideSetParams);
            $verifyStmt = $pdo->prepare('SELECT status FROM rides WHERE id = ? LIMIT 1');
            $verifyStmt->execute([$ride['id']]);
            $updatedRide = $verifyStmt->fetch(\PDO::FETCH_ASSOC) ?: [];
            if (strtolower((string)($updatedRide['status'] ?? '')) !== 'cancelled') {
                throw new \RuntimeException('Failed to update ride status to cancelled');
            }
            $this->insertStatusHistory($pdo, $rid, 'cancelled', 'customer', $auth['subject_id'], $reason);

            if ($this->tableExists($pdo, 'ride_driver_requests')) {
                $pdo->prepare('UPDATE ride_driver_requests SET status = "expired", responded_at = NOW() WHERE ride_id = ? AND status IN ("queued","pending")')
                    ->execute([$rid]);
            }

            if (!empty($ride['driver_id'])) {
                $driverStmt = $pdo->prepare('SELECT fcm_token FROM drivers WHERE id = ? LIMIT 1');
                $driverStmt->execute([$ride['driver_id']]);
                $driverRow = $driverStmt->fetch(\PDO::FETCH_ASSOC) ?: [];

                $setParts = [];
                $params = [];
                if ($this->columnExists($pdo, 'drivers', 'current_ride_id')) {
                    $setParts[] = 'current_ride_id = NULL';
                }
                if ($this->columnExists($pdo, 'drivers', 'is_available')) {
                    $setParts[] = 'is_available = 1';
                }
                if ($this->columnExists($pdo, 'drivers', 'availability')) {
                    $setParts[] = 'availability = 1';
                }
                if ($this->columnExists($pdo, 'drivers', 'ride_status')) {
                    $setParts[] = 'ride_status = "free"';
                }
                if (!empty($setParts)) {
                    $params[] = $ride['driver_id'];
                    $pdo->prepare('UPDATE drivers SET ' . implode(', ', $setParts) . ' WHERE id = ?')
                        ->execute($params);
                }

                $driverToken = trim((string)($driverRow['fcm_token'] ?? ''));
                $rideIdPublic = (string)$this->normalizeId($ride['id'] ?? $rid);
                if ($driverToken !== '') {
                    $fcm = new FcmService();
                    $fcm->sendToTokens(
                        [$driverToken],
                        [
                            'title' => 'Ride Cancelled',
                            'body' => 'Customer cancelled this ride.'
                        ],
                        [
                            'type' => 'ride_cancelled',
                            'ride_id' => $rideIdPublic,
                            'cancelled_by' => 'customer',
                            'reason' => $reason
                        ],
                        [
                            'android' => [
                                'priority' => 'HIGH',
                                'ttl' => self::REQUEST_EXPIRES_SECONDS . 's',
                                'notification' => [
                                    'channel_id' => 'ride_updates',
                                    'tag' => 'ride_cancel_' . $rideIdPublic
                                ]
                            ]
                        ]
                    );
                }
            }

            $pdo->commit();
            $this->respond(200, ['status' => 'ok', 'message' => 'Ride cancelled']);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->respond(500, ['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    private function jsonBody(): array
    {
        $body = file_get_contents('php://input');
        $data = json_decode($body ?: '', true);
        return is_array($data) ? $data : [];
    }

    private function requireString(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new \InvalidArgumentException("Missing or invalid {$key}");
        }
        return $value;
    }

    private function requireNumber(array $data, string $key): float
    {
        $value = $data[$key] ?? null;
        if (!is_numeric($value)) {
            throw new \InvalidArgumentException("Missing or invalid {$key}");
        }
        return (float) $value;
    }

    private function normalizeId($id)
    {
        if (is_string($id) && strlen($id) === 16) {
            return Uuid::toString($id);
        }
        return $id;
    }

    private function normalizeRideStatus(array $ride): string
    {
        $status = strtolower(trim((string)($ride['status'] ?? '')));
        if ($status !== '') {
            return $status;
        }

        if (!empty($ride['ride_end_time']) || !empty($ride['completed_at']) || !empty($ride['ride_completed_at'])) {
            return 'ride_completed';
        }
        if (!empty($ride['ride_start_time']) || !empty($ride['ride_started_at']) || !empty($ride['started_at'])) {
            return 'ride_started';
        }
        if (!empty($ride['driver_arrived_at']) || !empty($ride['waiting_started_at'])) {
            return 'driver_arrived';
        }
        if (!empty($ride['assigned_at']) || !empty($ride['driver_id'])) {
            return 'driver_assigned';
        }
        return 'searching';
    }

    private function toRideId(string $rideId)
    {
        $value = trim($rideId);
        if ($value === '') {
            return $value;
        }
        if (preg_match('/^[0-9a-fA-F-]{36}$/', $value) === 1) {
            return Uuid::fromString($value);
        }
        if (ctype_digit($value)) {
            return (int) $value;
        }
        return $value;
    }

    private function respond(int $status, array $payload): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($payload);
    }

    private function insertStatusHistory(\PDO $pdo, $rideId, string $status, string $role, $actorId, string $note = null): void
    {
        if (!$this->tableExists($pdo, 'ride_status_history')) {
            return;
        }
        $stmt = $pdo->prepare('INSERT INTO ride_status_history (ride_id, status, changed_by_role, changed_by_id, note) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$rideId, $status, $role, $actorId, $note]);
    }

    private function findNearbyDrivers(\PDO $pdo, float $lat, float $lng, string $vehicleType): array
    {
        $radiusKm = $this->matchRadiusKm();
        $fromRedis = $this->findNearbyDriversFromRedis($pdo, $lat, $lng, $vehicleType, $radiusKm);
        if (!empty($fromRedis)) {
            return $fromRedis;
        }

        $latCol = $this->columnExists($pdo, 'drivers', 'current_lat') ? 'd.current_lat' : 'd.latitude';
        $lngCol = $this->columnExists($pdo, 'drivers', 'current_lng') ? 'd.current_lng' : 'd.longitude';
        $availCol = $this->columnExists($pdo, 'drivers', 'is_available') ? 'd.is_available' : 'd.availability';
        $verifiedCond = $this->columnExists($pdo, 'drivers', 'is_verified')
            ? '(d.is_verified = 1 OR :dev_allow_unverified = 1)'
            : '1=1';
        $blockedCond = $this->columnExists($pdo, 'drivers', 'is_blocked') ? '(d.is_blocked IS NULL OR d.is_blocked = 0)' : '1=1';
        $penaltyCond = $this->columnExists($pdo, 'drivers', 'penalty_until') ? '(d.penalty_until IS NULL OR d.penalty_until <= NOW())' : '1=1';
        $vehicleCond = $this->columnExists($pdo, 'drivers', 'vehicle_type') ? 'AND d.vehicle_type = :vehicle_type' : '';
        $rideLockCond = $this->columnExists($pdo, 'drivers', 'current_ride_id')
            ? 'AND (
                d.current_ride_id IS NULL
                OR cr.id IS NULL
                OR cr.status IN ("ride_completed","ride_closed","cancelled","no_driver_found")
                OR (cr.status = "driver_assigned" AND COALESCE(cr.assigned_at, cr.created_at) < DATE_SUB(NOW(), INTERVAL 10 MINUTE))
                OR (cr.status = "searching" AND cr.searching_started_at IS NOT NULL AND cr.searching_started_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE))
              )'
            : '';

        $driverRatingExpr = $this->selectExpr($pdo, 'drivers', ['rating', 'avg_rating'], 'driver_rating', '4.5');
        $driverNameExpr = $this->selectExpr($pdo, 'drivers', ['name'], 'name', "''");
        $driverPhoneExpr = $this->selectExpr($pdo, 'drivers', ['phone'], 'phone', "''");
        $sql = 'SELECT d.id, ' . $driverNameExpr . ', ' . $driverPhoneExpr . ', d.fcm_token, ' . $driverRatingExpr . ', ' . $latCol . ' AS current_lat, ' . $lngCol . ' AS current_lng,
                (6371 * acos(
                    cos(radians(:lat)) * cos(radians(' . $latCol . ')) * cos(radians(' . $lngCol . ') - radians(:lng))
                    + sin(radians(:lat)) * sin(radians(' . $latCol . '))
                )) AS distance_km
                FROM drivers d
                LEFT JOIN rides cr ON ' . ($this->columnExists($pdo, 'drivers', 'current_ride_id') ? 'cr.id = d.current_ride_id' : '1=0') . '
                WHERE ' . $availCol . ' = 1
                  AND ' . $verifiedCond . '
                  AND ' . $blockedCond . '
                  AND ' . $penaltyCond . '
                  ' . $vehicleCond . '
                  AND ' . $latCol . ' IS NOT NULL
                  AND ' . $lngCol . ' IS NOT NULL
                  ' . $rideLockCond . '
                HAVING distance_km <= :radius_km
                ORDER BY distance_km ASC
                LIMIT 50';

        $stmt = $pdo->prepare($sql);
        $params = [
            'lat' => $lat,
            'lng' => $lng,
            'radius_km' => $radiusKm,
            'dev_allow_unverified' => $this->allowUnverifiedInDev() ? 1 : 0
        ];
        if ($this->columnExists($pdo, 'drivers', 'vehicle_type')) {
            $params['vehicle_type'] = $vehicleType;
        }
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            $distanceKm = round((float) $row['distance_km'], 2);
            $etaMin = $this->estimateEtaMinutes($distanceKm);
            $rating = (float)($row['driver_rating'] ?? 4.5);
            $result[] = [
                'id' => $row['id'],
                'name' => $row['name'],
                'phone' => $row['phone'],
                'fcm_token' => $row['fcm_token'],
                'distance_km' => $distanceKm,
                'eta_min' => $etaMin,
                'rating' => $rating,
                'score' => $this->driverScore($distanceKm, $etaMin, $rating)
            ];
        }
        usort($result, static function (array $a, array $b): int {
            $scoreCmp = ((float)$a['score']) <=> ((float)$b['score']);
            if ($scoreCmp !== 0) {
                return $scoreCmp;
            }
            $etaCmp = ((float)$a['eta_min']) <=> ((float)$b['eta_min']);
            if ($etaCmp !== 0) {
                return $etaCmp;
            }
            return ((float)$a['distance_km']) <=> ((float)$b['distance_km']);
        });

        return $result;
    }

    private function findNearbyDriversFromRedis(\PDO $pdo, float $lat, float $lng, string $vehicleType, float $radiusKm): array
    {
        try {
            $redis = RedisCache::client();
            $raw = $redis->executeRaw([
                'GEOSEARCH',
                'drivers:geo',
                'FROMLONLAT',
                (string)$lng,
                (string)$lat,
                'BYRADIUS',
                (string)$radiusKm,
                'km',
                'ASC',
                'COUNT',
                '100',
                'WITHDIST'
            ]);
            if (!is_array($raw) || empty($raw)) {
                return [];
            }

            $distanceByDriver = [];
            foreach ($raw as $entry) {
                if (is_array($entry)) {
                    $driverId = isset($entry[0]) ? strtolower(trim((string)$entry[0])) : '';
                    $distance = isset($entry[1]) && is_numeric($entry[1]) ? (float)$entry[1] : null;
                } else {
                    $driverId = strtolower(trim((string)$entry));
                    $distance = null;
                }
                if ($driverId === '') {
                    continue;
                }
                $distanceByDriver[$driverId] = $distance;
            }
            if (empty($distanceByDriver)) {
                return [];
            }

            $driverIds = array_keys($distanceByDriver);
            $placeholders = implode(',', array_fill(0, count($driverIds), '?'));
            $latCol = $this->columnExists($pdo, 'drivers', 'current_lat') ? 'd.current_lat' : 'd.latitude';
            $lngCol = $this->columnExists($pdo, 'drivers', 'current_lng') ? 'd.current_lng' : 'd.longitude';
            $availCol = $this->columnExists($pdo, 'drivers', 'is_available') ? 'd.is_available' : 'd.availability';
            $verifiedCond = $this->columnExists($pdo, 'drivers', 'is_verified')
                ? '(d.is_verified = 1 OR :dev_allow_unverified = 1)'
                : '1=1';
            $blockedCond = $this->columnExists($pdo, 'drivers', 'is_blocked') ? '(d.is_blocked IS NULL OR d.is_blocked = 0)' : '1=1';
            $penaltyCond = $this->columnExists($pdo, 'drivers', 'penalty_until') ? '(d.penalty_until IS NULL OR d.penalty_until <= NOW())' : '1=1';
            $vehicleCond = $this->columnExists($pdo, 'drivers', 'vehicle_type') ? 'AND d.vehicle_type = :vehicle_type' : '';
            $rideLockCond = $this->columnExists($pdo, 'drivers', 'current_ride_id')
                ? 'AND (
                    d.current_ride_id IS NULL
                    OR cr.id IS NULL
                    OR cr.status IN ("ride_completed","ride_closed","cancelled","no_driver_found")
                    OR (cr.status = "driver_assigned" AND COALESCE(cr.assigned_at, cr.created_at) < DATE_SUB(NOW(), INTERVAL 10 MINUTE))
                    OR (cr.status = "searching" AND cr.searching_started_at IS NOT NULL AND cr.searching_started_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE))
                  )'
                : '';
            $driverRatingExpr = $this->selectExpr($pdo, 'drivers', ['rating', 'avg_rating'], 'driver_rating', '4.5');
            $driverNameExpr = $this->selectExpr($pdo, 'drivers', ['name'], 'name', "''");
            $driverPhoneExpr = $this->selectExpr($pdo, 'drivers', ['phone'], 'phone', "''");

            $sql = 'SELECT d.id, ' . $driverNameExpr . ', ' . $driverPhoneExpr . ', d.fcm_token, ' . $driverRatingExpr . ', '
                . $latCol . ' AS current_lat, ' . $lngCol . ' AS current_lng
                FROM drivers d
                LEFT JOIN rides cr ON ' . ($this->columnExists($pdo, 'drivers', 'current_ride_id') ? 'cr.id = d.current_ride_id' : '1=0') . '
                WHERE d.id IN (' . $placeholders . ')
                  AND ' . $availCol . ' = 1
                  AND ' . $verifiedCond . '
                  AND ' . $blockedCond . '
                  AND ' . $penaltyCond . '
                  ' . $vehicleCond . '
                  AND ' . $latCol . ' IS NOT NULL
                  AND ' . $lngCol . ' IS NOT NULL
                  ' . $rideLockCond;
            $stmt = $pdo->prepare($sql);
            $params = [];
            foreach ($driverIds as $driverId) {
                $params[] = $this->prepareIdValueForTable($pdo, 'drivers', $driverId);
            }
            $params['dev_allow_unverified'] = $this->allowUnverifiedInDev() ? 1 : 0;
            if ($this->columnExists($pdo, 'drivers', 'vehicle_type')) {
                $params['vehicle_type'] = $vehicleType;
            }
            $stmt->execute($params);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            if (empty($rows)) {
                return [];
            }

            $result = [];
            foreach ($rows as $row) {
                $driverKey = strtolower((string)$this->normalizeId($row['id']));
                $distanceKm = isset($distanceByDriver[$driverKey]) && $distanceByDriver[$driverKey] !== null
                    ? round((float)$distanceByDriver[$driverKey], 2)
                    : round($this->haversineKm($lat, $lng, (float)($row['current_lat'] ?? 0), (float)($row['current_lng'] ?? 0)), 2);
                $etaMin = $this->estimateEtaMinutes($distanceKm);
                $rating = (float)($row['driver_rating'] ?? 4.5);
                $result[] = [
                    'id' => $row['id'],
                    'name' => $row['name'],
                    'phone' => $row['phone'],
                    'fcm_token' => $row['fcm_token'],
                    'distance_km' => $distanceKm,
                    'eta_min' => $etaMin,
                    'rating' => $rating,
                    'score' => $this->driverScore($distanceKm, $etaMin, $rating)
                ];
            }

            usort($result, static function (array $a, array $b): int {
                $scoreCmp = ((float)$a['score']) <=> ((float)$b['score']);
                if ($scoreCmp !== 0) {
                    return $scoreCmp;
                }
                $etaCmp = ((float)$a['eta_min']) <=> ((float)$b['eta_min']);
                if ($etaCmp !== 0) {
                    return $etaCmp;
                }
                return ((float)$a['distance_km']) <=> ((float)$b['distance_km']);
            });

            return $result;
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earth = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) * sin($dLat / 2)
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) * sin($dLng / 2);
        return 2 * $earth * asin(min(1, sqrt($a)));
    }

    private function estimateEtaMinutes(float $distanceKm): float
    {
        $avgSpeedKmph = 25.0;
        return round(max(1.0, ($distanceKm / $avgSpeedKmph) * 60), 1);
    }

    private function driverScore(float $distanceKm, float $etaMin, float $rating): float
    {
        $ratingPenalty = max(0.0, 5.0 - min(5.0, $rating));
        return round(($distanceKm * 0.6) + ($etaMin * 0.3) + ($ratingPenalty * 0.1), 4);
    }

    private function createDriverRequests(\PDO $pdo, $rideId, array $drivers): void
    {
        if (!$this->tableExists($pdo, 'ride_driver_requests')) {
            return;
        }
        $this->ensureRideRequestKeyTypes($pdo);
        $this->cleanupLegacyInvalidRideRequests($pdo);

        $stmt = $pdo->prepare(
            'INSERT INTO ride_driver_requests (ride_id, driver_id, status, distance_km)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               status = VALUES(status),
               distance_km = VALUES(distance_km),
               sent_at = NULL,
               expires_at = NULL,
               responded_at = NULL'
        );
        $hasEtaCol = $this->columnExists($pdo, 'ride_driver_requests', 'eta_min');
        $hasScoreCol = $this->columnExists($pdo, 'ride_driver_requests', 'match_score');
        $metaStmt = null;
        if ($hasEtaCol || $hasScoreCol) {
            $metaSet = [];
            if ($hasEtaCol) {
                $metaSet[] = 'eta_min = ?';
            }
            if ($hasScoreCol) {
                $metaSet[] = 'match_score = ?';
            }
            $metaStmt = $pdo->prepare('UPDATE ride_driver_requests SET ' . implode(', ', $metaSet) . ' WHERE ride_id = ? AND driver_id = ?');
        }
        $seen = [];
        $ordered = [];
        foreach ($drivers as $d) {
            $driverId = $d['id'] ?? null;
            if ($driverId === null) {
                continue;
            }
            $driverKey = (string) $driverId;
            if (isset($seen[$driverKey])) {
                continue;
            }
            $seen[$driverKey] = true;
            $ordered[] = $d;
        }

        $pendingDriverKey = null;
        foreach ([2.0, 5.0, 7.0] as $tierKm) {
            foreach ($ordered as $d) {
                if ((float)($d['distance_km'] ?? 9999) <= $tierKm) {
                    $pendingDriverKey = (string)($d['id'] ?? '');
                    break 2;
                }
            }
        }
        if ($pendingDriverKey === null && !empty($ordered)) {
            $pendingDriverKey = (string)($ordered[0]['id'] ?? '');
        }

        foreach ($ordered as $d) {
            $driverId = $d['id'];
            $status = ((string)$driverId === (string)$pendingDriverKey) ? 'pending' : 'queued';
            $stmt->execute([$rideId, $driverId, $status, (float)($d['distance_km'] ?? 0)]);
            if ($metaStmt) {
                $metaParams = [];
                if ($hasEtaCol) {
                    $metaParams[] = (float)($d['eta_min'] ?? $this->estimateEtaMinutes((float)($d['distance_km'] ?? 0)));
                }
                if ($hasScoreCol) {
                    $metaParams[] = (float)($d['score'] ?? $this->driverScore(
                        (float)($d['distance_km'] ?? 0),
                        (float)($d['eta_min'] ?? $this->estimateEtaMinutes((float)($d['distance_km'] ?? 0))),
                        (float)($d['rating'] ?? 4.5)
                    ));
                }
                $metaParams[] = $rideId;
                $metaParams[] = $driverId;
                $metaStmt->execute($metaParams);
            }
        }
    }

    private function notifyNextDriver(\PDO $pdo, $rideId, string $pickup, string $dropoff, float $fare, float $driverEarning): void
    {
        if (!$this->tableExists($pdo, 'ride_driver_requests')) {
            return;
        }
        $rideInfoStmt = $pdo->prepare('SELECT pickup_lat, pickup_lng, drop_lat, drop_lng, distance_km, duration_min FROM rides WHERE id = ?');
        $rideInfoStmt->execute([$rideId]);
        $rideInfo = $rideInfoStmt->fetch(\PDO::FETCH_ASSOC) ?: [
            'pickup_lat' => 0,
            'pickup_lng' => 0,
            'drop_lat' => 0,
            'drop_lng' => 0,
            'distance_km' => 0,
            'duration_min' => 0
        ];
        $fcm = new FcmService();
        $driverProfit22 = round(max(0.0, $fare) * 0.22, 2);

        for ($i = 0; $i < 50; $i++) {
            $stmt = $pdo->prepare('SELECT r.driver_id, d.fcm_token, r.sent_at
                FROM ride_driver_requests r
                JOIN drivers d ON d.id = r.driver_id
                WHERE r.ride_id = ? AND r.status = "pending" AND r.sent_at IS NULL
                ORDER BY ' . $this->driverRequestPriorityOrderSql($pdo, 'r') . '
                LIMIT 1');
            $stmt->execute([$rideId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$row) {
                $next = $pdo->prepare('SELECT driver_id FROM ride_driver_requests WHERE ride_id = ? AND status = "queued" ORDER BY ' . $this->driverRequestPriorityOrderSql($pdo) . ' LIMIT 1');
                $next->execute([$rideId]);
                $nextRow = $next->fetch(\PDO::FETCH_ASSOC);
                if (!$nextRow) {
                    $pdo->prepare('UPDATE rides SET status="no_driver_found", no_driver_found_at = NOW() WHERE id = ? AND status = "searching"')
                        ->execute([$rideId]);
                    return;
                }
                $pdo->prepare('UPDATE ride_driver_requests SET status = "pending", sent_at = NULL, expires_at = NULL, responded_at = NULL WHERE ride_id = ? AND driver_id = ? AND status = "queued"')
                    ->execute([$rideId, $nextRow['driver_id']]);
                continue;
            }

            if (empty($row['fcm_token'])) {
                // Move to next queued driver quickly when token is missing.
                $pdo->prepare('UPDATE ride_driver_requests SET status = "expired", responded_at = NOW() WHERE ride_id = ? AND driver_id = ? AND status = "pending"')
                    ->execute([$rideId, $row['driver_id']]);
                continue;
            }

            $markSent = $pdo->prepare('UPDATE ride_driver_requests
                SET sent_at = NOW(), expires_at = DATE_ADD(NOW(), INTERVAL ' . self::REQUEST_EXPIRES_SECONDS . ' SECOND)
                WHERE ride_id = ? AND driver_id = ? AND status = "pending"
                  AND sent_at IS NULL');
            $markSent->execute([$rideId, $row['driver_id']]);
            if ($markSent->rowCount() < 1) {
                return;
            }

            $rideIdPublic = (string)$this->normalizeId($rideId);
            $result = $fcm->sendToTokens([$row['fcm_token']], [
                'title' => 'New Ride Request',
                'body' => 'Accept within 5 minutes | Profit Rs ' . number_format($driverProfit22, 2, '.', '')
            ], [
                'ride_id' => $rideIdPublic,
                'pickup' => $pickup,
                'dropoff' => $dropoff,
                'pickup_lat' => $rideInfo['pickup_lat'] ?? 0,
                'pickup_lng' => $rideInfo['pickup_lng'] ?? 0,
                'drop_lat' => $rideInfo['drop_lat'] ?? 0,
                'drop_lng' => $rideInfo['drop_lng'] ?? 0,
                'fare' => $fare,
                'fare_total' => $fare,
                'driver_profit' => $driverProfit22,
                'driver_earning' => $driverProfit22,
                'driver_profit_percent' => 22,
                'distance_km' => $rideInfo['distance_km'] ?? 0,
                'duration_min' => $rideInfo['duration_min'] ?? 0,
                'expires_in_sec' => self::REQUEST_EXPIRES_SECONDS,
                'accept_endpoint' => '/api/driver/rides/' . $rideIdPublic . '/accept',
                'reject_endpoint' => '/api/driver/rides/' . $rideIdPublic . '/reject'
            ], [
                'android' => [
                    'ttl' => self::REQUEST_EXPIRES_SECONDS . 's',
                    'priority' => 'HIGH',
                    'notification' => [
                        'channel_id' => 'ride_request',
                        'tag' => 'ride_' . $rideIdPublic,
                        'sound' => 'ride_request',
                        'click_action' => 'OPEN_RIDE_REQUEST'
                    ]
                ]
            ]);

            $ok = false;
            if (($result['status'] ?? '') === 'ok' && !empty($result['results'][0]['status_code'])) {
                $code = (int) $result['results'][0]['status_code'];
                $ok = $code >= 200 && $code < 300;
            }

            if ($ok) {
                return;
            }

            $fcmErrorCode = strtoupper((string)($result['results'][0]['fcm_error_code'] ?? ''));
            $statusCode = (int)($result['results'][0]['status_code'] ?? 0);
            if ($this->isInvalidTokenError($statusCode, $fcmErrorCode)) {
                $pdo->prepare('UPDATE drivers SET fcm_token = NULL WHERE id = ?')->execute([$row['driver_id']]);
                $pdo->prepare('UPDATE ride_driver_requests SET status = "expired", responded_at = NOW() WHERE ride_id = ? AND driver_id = ? AND status = "pending"')
                    ->execute([$rideId, $row['driver_id']]);
                continue;
            }

            // For transient failures, keep pending so polling can still serve this driver.
            return;
        }
    }

    private function isInvalidTokenError(int $statusCode, string $fcmErrorCode): bool
    {
        if ($statusCode !== 400 && $statusCode !== 404) {
            return false;
        }
        return in_array($fcmErrorCode, ['UNREGISTERED', 'REGISTRATION_TOKEN_NOT_REGISTERED'], true);
    }

    private function allowUnverifiedInDev(): bool
    {
        $env = strtolower((string)($_ENV['NODE_ENV'] ?? getenv('NODE_ENV') ?? 'development'));
        return $env !== 'production';
    }

    private function matchRadiusKm(): float
    {
        $raw = $_ENV['DRIVER_MATCH_RADIUS_KM'] ?? getenv('DRIVER_MATCH_RADIUS_KM') ?? '10';
        if (!is_numeric($raw)) {
            return 10.0;
        }
        $value = (float) $raw;
        if ($value < 0.5) {
            return 0.5;
        }
        if ($value > 20) {
            return 20.0;
        }
        return $value;
    }

    private function driverRequestPriorityOrderSql(\PDO $pdo, string $alias = ''): string
    {
        $prefix = $alias !== '' ? $alias . '.' : '';
        $parts = [];
        if ($this->columnExists($pdo, 'ride_driver_requests', 'match_score')) {
            $parts[] = $prefix . 'match_score ASC';
        }
        if ($this->columnExists($pdo, 'ride_driver_requests', 'eta_min')) {
            $parts[] = $prefix . 'eta_min ASC';
        }
        $parts[] = $prefix . 'distance_km ASC';
        return implode(', ', $parts);
    }

    private function tableExists(\PDO $pdo, string $table): bool
    {
        $key = 'table:' . $table;
        if (array_key_exists($key, self::$schemaCache)) {
            return self::$schemaCache[$key];
        }
        $stmt = $pdo->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1');
        $stmt->execute([$table]);
        $exists = (bool) $stmt->fetchColumn();
        self::$schemaCache[$key] = $exists;
        return $exists;
    }

    private function columnType(\PDO $pdo, string $table, string $column): string
    {
        $key = 'type:' . $table . ':' . $column;
        if (array_key_exists($key, self::$schemaCache)) {
            return (string) self::$schemaCache[$key];
        }
        $stmt = $pdo->prepare('SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1');
        $stmt->execute([$table, $column]);
        $type = strtolower((string)($stmt->fetchColumn() ?: ''));
        self::$schemaCache[$key] = $type;
        return $type;
    }

    private function columnSqlType(\PDO $pdo, string $table, string $column): string
    {
        $key = 'ctype:' . $table . ':' . $column;
        if (array_key_exists($key, self::$schemaCache)) {
            return (string) self::$schemaCache[$key];
        }
        $stmt = $pdo->prepare('SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1');
        $stmt->execute([$table, $column]);
        $type = (string)($stmt->fetchColumn() ?: '');
        self::$schemaCache[$key] = $type;
        return $type;
    }

    private function isNumericType(string $type): bool
    {
        return strpos($type, 'int') !== false
            || strpos($type, 'decimal') !== false
            || strpos($type, 'numeric') !== false
            || strpos($type, 'float') !== false
            || strpos($type, 'double') !== false;
    }

    private function clearColumnTypeCache(string $table, string $column): void
    {
        unset(
            self::$schemaCache['type:' . $table . ':' . $column],
            self::$schemaCache['ctype:' . $table . ':' . $column]
        );
    }

    private function ensureRideRequestKeyTypes(\PDO $pdo): void
    {
        if (
            !$this->tableExists($pdo, 'rides')
            || !$this->tableExists($pdo, 'drivers')
            || !$this->tableExists($pdo, 'ride_driver_requests')
            || !$this->columnExists($pdo, 'rides', 'id')
            || !$this->columnExists($pdo, 'drivers', 'id')
            || !$this->columnExists($pdo, 'ride_driver_requests', 'ride_id')
            || !$this->columnExists($pdo, 'ride_driver_requests', 'driver_id')
        ) {
            return;
        }

        $ridesIdType = $this->columnType($pdo, 'rides', 'id');
        $reqRideIdType = $this->columnType($pdo, 'ride_driver_requests', 'ride_id');
        if ($this->isNumericType($ridesIdType) !== $this->isNumericType($reqRideIdType)) {
            $ridesColumnSqlType = $this->columnSqlType($pdo, 'rides', 'id');
            if ($ridesColumnSqlType !== '') {
                $pdo->exec('ALTER TABLE ride_driver_requests MODIFY ride_id ' . $ridesColumnSqlType . ' NOT NULL');
                $this->clearColumnTypeCache('ride_driver_requests', 'ride_id');
            }
        }

        $driversIdType = $this->columnType($pdo, 'drivers', 'id');
        $reqDriverIdType = $this->columnType($pdo, 'ride_driver_requests', 'driver_id');
        if ($this->isNumericType($driversIdType) !== $this->isNumericType($reqDriverIdType)) {
            $driversColumnSqlType = $this->columnSqlType($pdo, 'drivers', 'id');
            if ($driversColumnSqlType !== '') {
                $pdo->exec('ALTER TABLE ride_driver_requests MODIFY driver_id ' . $driversColumnSqlType . ' NOT NULL');
                $this->clearColumnTypeCache('ride_driver_requests', 'driver_id');
            }
        }
    }

    private function cleanupLegacyInvalidRideRequests(\PDO $pdo): void
    {
        if (
            !$this->tableExists($pdo, 'ride_driver_requests')
            || !$this->columnExists($pdo, 'ride_driver_requests', 'ride_id')
        ) {
            return;
        }
        $rideIdType = $this->columnType($pdo, 'ride_driver_requests', 'ride_id');
        if ($this->isNumericType($rideIdType)) {
            $pdo->exec('DELETE FROM ride_driver_requests WHERE ride_id = 0');
        }
    }

    private function resolveRideCustomerId(\PDO $pdo, array $auth)
    {
        $subjectId = $auth['subject_id'] ?? null;
        if ($subjectId === null || $subjectId === '') {
            return $subjectId;
        }

        $refTable = $this->ridesCustomerReferenceTable($pdo);
        if ($refTable === '') {
            return $subjectId;
        }

        if ($refTable === 'users') {
            if ($this->rowExistsById($pdo, 'users', $subjectId)) {
                return $this->prepareIdValueForTable($pdo, 'users', $subjectId);
            }

            if ($this->tableExists($pdo, 'customers') && $this->columnExists($pdo, 'customers', 'user_id')) {
                $stmt = $pdo->prepare('SELECT user_id FROM customers WHERE id = ? LIMIT 1');
                $stmt->execute([$this->prepareIdValueForTable($pdo, 'customers', $subjectId)]);
                $userId = $stmt->fetchColumn();
                if ($userId !== false && $userId !== null && $userId !== '') {
                    return $userId;
                }
            }

            $phone = trim((string)($auth['phone'] ?? ''));
            if ($phone !== '' && $this->tableExists($pdo, 'users') && $this->columnExists($pdo, 'users', 'phone')) {
                $roleCond = $this->columnExists($pdo, 'users', 'role') ? ' AND role = "customer"' : '';
                $stmt = $pdo->prepare('SELECT id FROM users WHERE phone = ?' . $roleCond . ' LIMIT 1');
                $stmt->execute([$phone]);
                $userId = $stmt->fetchColumn();
                if ($userId !== false && $userId !== null && $userId !== '') {
                    return $userId;
                }
                $userId = $this->findIdByPhoneNormalized($pdo, 'users', $phone, $roleCond);
                if ($userId !== null && $userId !== '') {
                    return $userId;
                }
            }

            return $this->prepareIdValueForTable($pdo, 'users', $subjectId);
        }

        if ($refTable === 'customers') {
            if ($this->rowExistsById($pdo, 'customers', $subjectId)) {
                return $this->prepareIdValueForTable($pdo, 'customers', $subjectId);
            }

            if ($this->tableExists($pdo, 'customers') && $this->columnExists($pdo, 'customers', 'user_id')) {
                $stmt = $pdo->prepare('SELECT id FROM customers WHERE user_id = ? LIMIT 1');
                $stmt->execute([$this->prepareIdValueForTable($pdo, 'users', $subjectId)]);
                $customerId = $stmt->fetchColumn();
                if ($customerId !== false && $customerId !== null && $customerId !== '') {
                    return $customerId;
                }
            }

            $phone = trim((string)($auth['phone'] ?? ''));
            if ($phone !== '' && $this->tableExists($pdo, 'customers') && $this->columnExists($pdo, 'customers', 'phone')) {
                $stmt = $pdo->prepare('SELECT id FROM customers WHERE phone = ? LIMIT 1');
                $stmt->execute([$phone]);
                $customerId = $stmt->fetchColumn();
                if ($customerId !== false && $customerId !== null && $customerId !== '') {
                    return $customerId;
                }
                $customerId = $this->findIdByPhoneNormalized($pdo, 'customers', $phone, '');
                if ($customerId !== null && $customerId !== '') {
                    return $customerId;
                }
            }

            return $this->prepareIdValueForTable($pdo, 'customers', $subjectId);
        }

        return $subjectId;
    }

    private function findIdByPhoneNormalized(\PDO $pdo, string $table, string $phone, string $extraCond): ?string
    {
        if (!$this->tableExists($pdo, $table) || !$this->columnExists($pdo, $table, 'phone')) {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $phone ?? '');
        if (!is_string($digits) || $digits === '') {
            return null;
        }
        $last10 = substr($digits, -10);
        if ($last10 === '') {
            return null;
        }
        $sql = 'SELECT id FROM ' . $table . '
            WHERE RIGHT(REPLACE(REPLACE(REPLACE(phone, "+", ""), "-", ""), " ", ""), 10) = ?'
            . $extraCond . '
            LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$last10]);
        $id = $stmt->fetchColumn();
        if ($id === false || $id === null || $id === '') {
            return null;
        }
        return (string)$id;
    }

    private function ridesCustomerReferenceTable(\PDO $pdo): string
    {
        $key = 'fk:rides:customer_id:ref';
        if (array_key_exists($key, self::$schemaCache)) {
            return (string) self::$schemaCache[$key];
        }
        $stmt = $pdo->prepare('SELECT LOWER(REFERENCED_TABLE_NAME)
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = "rides"
              AND COLUMN_NAME = "customer_id"
              AND REFERENCED_TABLE_NAME IS NOT NULL
            LIMIT 1');
        $stmt->execute();
        $ref = (string)($stmt->fetchColumn() ?: '');
        self::$schemaCache[$key] = $ref;
        return $ref;
    }

    private function rowExistsById(\PDO $pdo, string $table, $id): bool
    {
        if (!$this->tableExists($pdo, $table) || !$this->columnExists($pdo, $table, 'id')) {
            return false;
        }
        $stmt = $pdo->prepare('SELECT 1 FROM ' . $table . ' WHERE id = ? LIMIT 1');
        $stmt->execute([$this->prepareIdValueForTable($pdo, $table, $id)]);
        return (bool)$stmt->fetchColumn();
    }

    private function prepareIdValueForTable(\PDO $pdo, string $table, $id)
    {
        if (!is_string($id)) {
            return $id;
        }
        if (!preg_match('/^[0-9a-f\\-]{36}$/i', $id)) {
            return $id;
        }
        $colType = strtolower($this->columnSqlType($pdo, $table, 'id'));
        if (strpos($colType, 'binary(16)') !== false) {
            return Uuid::fromString($id);
        }
        return $id;
    }

    private function columnExists(\PDO $pdo, string $table, string $column): bool
    {
        $key = 'col:' . $table . ':' . $column;
        if (array_key_exists($key, self::$schemaCache)) {
            return self::$schemaCache[$key];
        }
        $stmt = $pdo->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1');
        $stmt->execute([$table, $column]);
        $exists = (bool) $stmt->fetchColumn();
        self::$schemaCache[$key] = $exists;
        return $exists;
    }

    private function selectExpr(\PDO $pdo, string $table, array $candidates, string $alias, string $default = 'NULL'): string
    {
        $tableAliasMap = [
            'drivers' => 'd',
            'rides' => 'r',
        ];
        $tableAlias = $tableAliasMap[$table] ?? '';
        foreach ($candidates as $column) {
            if ($this->columnExists($pdo, $table, $column)) {
                $qualified = $tableAlias !== '' ? ($tableAlias . '.' . $column) : $column;
                return $qualified . ' AS ' . $alias;
            }
        }
        return $default . ' AS ' . $alias;
    }

    private function toAbsoluteAssetUrl(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }
        $host = $_SERVER['HTTP_HOST'] ?? '';
        if ($host === '') {
            return $path;
        }
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $normalized = '/' . ltrim($path, '/');
        return $scheme . '://' . $host . $normalized;
    }
}


