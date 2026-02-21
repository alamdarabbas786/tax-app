<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\ApiAuth;
use App\Cache\RedisCache;
use App\Db\Mysql;
use App\Services\Pricing;
use App\Utils\Uuid;
use App\Services\FcmService;
use App\Services\WalletSettlementService;


final class DriverController
{
    private static array $schemaCache = [];
    private const REQUEST_EXPIRES_SECONDS = 60;
    private const DRIVER_REQUEST_COOLDOWN_MINUTES = 5;

    public function register(): void
    {
        $auth = ApiAuth::tokenRow();

        $required = [
            'name',
            'phone',
            'email',
            'vehicle_type',
            'vehicle_number',
            'license_number',
            'address',
            'city',
            'pin_code',
            'aadhaar_number'
        ];

        $errors = [];
        foreach ($required as $field) {
            if (empty($_POST[$field] ?? null)) {
                $errors[$field] = 'required';
            }
        }

        if (!empty($errors)) {
            $this->respond(422, ['status' => 'error', 'message' => 'Validation failed', 'fields' => $errors]);
            return;
        }

        $files = [
            'vehicle_rc' => ['application/pdf', 'image/jpeg'],
            'driving_license' => ['application/pdf', 'image/jpeg'],
            'aadhaar_card' => ['application/pdf', 'image/jpeg'],
            'driver_photo' => ['image/jpeg']
        ];

        $optionalFiles = [
            'insurance_doc' => ['application/pdf'],
            'puc_doc' => ['application/pdf']
        ];

        $uploadDir = __DIR__ . '/../../uploads/driver_docs';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $savedPaths = [];
        foreach ($files as $field => $mimes) {
            if (empty($_FILES[$field]['tmp_name'])) {
                $this->respond(422, ['status' => 'error', 'message' => "Missing file: {$field}"]);
                return;
            }

            $file = $_FILES[$field];
            if (!is_uploaded_file($file['tmp_name'])) {
                $this->respond(400, ['status' => 'error', 'message' => "Invalid upload: {$field}"]);
                return;
            }

            if ($file['size'] > 4 * 1024 * 1024) {
                $this->respond(422, ['status' => 'error', 'message' => "File too large: {$field}"]);
                return;
            }

            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $detected = $finfo->file($file['tmp_name']);
            if (!in_array($detected, $mimes, true)) {
                $this->respond(422, ['status' => 'error', 'message' => "Invalid file type for {$field}", 'expected' => $mimes, 'got' => $detected]);
                return;
            }

            $ext = $detected === 'image/jpeg' ? 'jpg' : 'pdf';
            $name = bin2hex(random_bytes(16)) . ".{$ext}";
            $dest = $uploadDir . '/' . $name;
            if (!move_uploaded_file($file['tmp_name'], $dest)) {
                $this->respond(500, ['status' => 'error', 'message' => "Failed to save {$field}"]);
                return;
            }

            $savedPaths[$field] = 'uploads/driver_docs/' . $name;
        }

        $optionalSaved = [
            'insurance_doc' => null,
            'puc_doc' => null
        ];
        foreach ($optionalFiles as $field => $mimes) {
            if (empty($_FILES[$field]['tmp_name'])) {
                continue;
            }
            $file = $_FILES[$field];
            if (!is_uploaded_file($file['tmp_name'])) {
                $this->respond(400, ['status' => 'error', 'message' => "Invalid upload: {$field}"]);
                return;
            }
            if ($file['size'] > 4 * 1024 * 1024) {
                $this->respond(422, ['status' => 'error', 'message' => "File too large: {$field}"]);
                return;
            }
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $detected = $finfo->file($file['tmp_name']);
            if (!in_array($detected, $mimes, true)) {
                $this->respond(422, ['status' => 'error', 'message' => "Invalid file type for {$field}", 'expected' => $mimes, 'got' => $detected]);
                return;
            }
            $ext = 'pdf';
            $name = bin2hex(random_bytes(16)) . ".{$ext}";
            $dest = $uploadDir . '/' . $name;
            if (!move_uploaded_file($file['tmp_name'], $dest)) {
                $this->respond(500, ['status' => 'error', 'message' => "Failed to save {$field}"]);
                return;
            }
            $optionalSaved[$field] = 'uploads/driver_docs/' . $name;
        }

        $phone = trim((string)($auth['phone'] ?? ($_POST['phone'] ?? '')));
        $email = strtolower(trim((string)$_POST['email']));
        $name = trim((string)$_POST['name']);
        $vehicleType = trim((string)$_POST['vehicle_type']);
        $vehicleNumber = strtoupper(trim((string)$_POST['vehicle_number']));
        $licenseNumber = strtoupper(trim((string)$_POST['license_number']));
        $address = trim((string)$_POST['address']);
        $city = trim((string)$_POST['city']);
        $pinCode = trim((string)$_POST['pin_code']);
        $aadhaar = trim((string)$_POST['aadhaar_number']);

        $pdo = Mysql::connection();
        $driverId = Uuid::v4Binary();
        try {
            // Legacy schema mode: drivers linked to users table and no phone/email on drivers.
            if ($this->columnExists($pdo, 'drivers', 'user_id') && $this->tableExists($pdo, 'users')) {
                $dup = $pdo->prepare('SELECT d.id
                    FROM drivers d
                    JOIN users u ON u.id = d.user_id
                    WHERE u.phone = ? OR u.email = ?
                    LIMIT 1');
                $dup->execute([$phone, $email]);
                if ($dup->fetch(\PDO::FETCH_ASSOC)) {
                    $this->respond(409, ['status' => 'error', 'message' => 'Phone or email already registered']);
                    return;
                }

                $userId = Uuid::v4Binary();
                $userInsert = $pdo->prepare('INSERT INTO users (id, role, email, phone, full_name, is_active) VALUES (?, "driver", ?, ?, ?, 1)');
                $userInsert->execute([$userId, $email, $phone, $name !== '' ? $name : 'Driver']);

                $legacyInsert = $pdo->prepare('INSERT INTO drivers
                    (id, user_id, license_number, vehicle_make, vehicle_model, vehicle_plate, vehicle_number, vehicle_capacity, cost_per_km, cost_per_min, address_line, city, pin_code, aadhaar_number, rc_file, driving_license_file, aadhaar_file, driver_photo_path, is_available, verification_status)
                    VALUES
                    (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, "pending")');
                $legacyInsert->execute([
                    $driverId,
                    $userId,
                    $licenseNumber,
                    'NA',
                    'NA',
                    $vehicleNumber,
                    $vehicleNumber,
                    1,
                    0,
                    0,
                    $address,
                    $city,
                    $pinCode,
                    $aadhaar,
                    $savedPaths['vehicle_rc'],
                    $savedPaths['driving_license'],
                    $savedPaths['aadhaar_card'],
                    $savedPaths['driver_photo']
                ]);

                // Keep compatibility/profile columns in sync when they exist on legacy schema.
                $syncSet = [];
                $syncParams = [];
                if ($this->columnExists($pdo, 'drivers', 'vehicle_type')) {
                    $syncSet[] = 'vehicle_type = ?';
                    $syncParams[] = $vehicleType;
                }
                if ($this->columnExists($pdo, 'drivers', 'name')) {
                    $syncSet[] = 'name = ?';
                    $syncParams[] = $name;
                }
                if ($this->columnExists($pdo, 'drivers', 'phone')) {
                    $syncSet[] = 'phone = ?';
                    $syncParams[] = $phone;
                }
                if ($this->columnExists($pdo, 'drivers', 'email')) {
                    $syncSet[] = 'email = ?';
                    $syncParams[] = $email;
                }
                if (!empty($syncSet)) {
                    $syncParams[] = $driverId;
                    $pdo->prepare('UPDATE drivers SET ' . implode(', ', $syncSet) . ' WHERE id = ?')->execute($syncParams);
                }
            } else {
                // New schema mode: profile columns on drivers table.
                $existingDriverId = null;
                $dupConds = [];
                $dupParams = [];
                if ($this->columnExists($pdo, 'drivers', 'phone')) {
                    $dupConds[] = 'phone = ?';
                    $dupParams[] = $phone;
                }
                if ($email !== '' && $this->columnExists($pdo, 'drivers', 'email')) {
                    $dupConds[] = 'email = ?';
                    $dupParams[] = $email;
                }
                if (!empty($dupConds)) {
                    $stmt = $pdo->prepare('SELECT id, '
                        . $this->selectExpr($pdo, 'drivers', ['phone'], 'phone', "''")
                        . ', ' . $this->selectExpr($pdo, 'drivers', ['email'], 'email', "''")
                        . ' FROM drivers WHERE ' . implode(' OR ', $dupConds) . ' LIMIT 1');
                    $stmt->execute($dupParams);
                    $existing = $stmt->fetch(\PDO::FETCH_ASSOC);
                    if ($existing) {
                        $samePhone = isset($existing['phone']) && (string)$existing['phone'] === $phone;
                        if ($samePhone) {
                            $existingDriverId = $existing['id'];
                        } else {
                            $this->respond(409, ['status' => 'error', 'message' => 'Phone or email already registered']);
                            return;
                        }
                    }
                }

                $idType = strtolower($this->columnType($pdo, 'drivers', 'id'));
                $idIsNumeric = strpos($idType, 'int') !== false || strpos($idType, 'decimal') !== false;

                $columns = [];
                $values = [];

                if ($this->columnExists($pdo, 'drivers', 'id') && !$idIsNumeric) {
                    $columns[] = 'id';
                    $values[] = $driverId;
                }
                if ($this->columnExists($pdo, 'drivers', 'name')) {
                    $columns[] = 'name';
                    $values[] = $name !== '' ? $name : 'Driver';
                } elseif ($this->columnExists($pdo, 'drivers', 'full_name')) {
                    $columns[] = 'full_name';
                    $values[] = $name !== '' ? $name : 'Driver';
                }
                if ($this->columnExists($pdo, 'drivers', 'phone')) {
                    $columns[] = 'phone';
                    $values[] = $phone;
                }
                if ($this->columnExists($pdo, 'drivers', 'email')) {
                    $columns[] = 'email';
                    $values[] = $email;
                }
                if ($this->columnExists($pdo, 'drivers', 'vehicle_type')) {
                    $columns[] = 'vehicle_type';
                    $values[] = $vehicleType;
                }
                if ($this->columnExists($pdo, 'drivers', 'vehicle_number')) {
                    $columns[] = 'vehicle_number';
                    $values[] = $vehicleNumber;
                }
                if ($this->columnExists($pdo, 'drivers', 'license_number')) {
                    $columns[] = 'license_number';
                    $values[] = $licenseNumber;
                }
                if ($this->columnExists($pdo, 'drivers', 'address')) {
                    $columns[] = 'address';
                    $values[] = $address;
                } elseif ($this->columnExists($pdo, 'drivers', 'address_line')) {
                    $columns[] = 'address_line';
                    $values[] = $address;
                }
                if ($this->columnExists($pdo, 'drivers', 'city')) {
                    $columns[] = 'city';
                    $values[] = $city;
                }
                if ($this->columnExists($pdo, 'drivers', 'pin_code')) {
                    $columns[] = 'pin_code';
                    $values[] = $pinCode;
                }
                if ($this->columnExists($pdo, 'drivers', 'aadhaar_number')) {
                    $columns[] = 'aadhaar_number';
                    $values[] = $aadhaar;
                }
                if ($this->columnExists($pdo, 'drivers', 'vehicle_rc_path')) {
                    $columns[] = 'vehicle_rc_path';
                    $values[] = $savedPaths['vehicle_rc'];
                } elseif ($this->columnExists($pdo, 'drivers', 'rc_file')) {
                    $columns[] = 'rc_file';
                    $values[] = $savedPaths['vehicle_rc'];
                }
                if ($this->columnExists($pdo, 'drivers', 'driving_license_path')) {
                    $columns[] = 'driving_license_path';
                    $values[] = $savedPaths['driving_license'];
                } elseif ($this->columnExists($pdo, 'drivers', 'driving_license_file')) {
                    $columns[] = 'driving_license_file';
                    $values[] = $savedPaths['driving_license'];
                }
                if ($this->columnExists($pdo, 'drivers', 'aadhaar_card_path')) {
                    $columns[] = 'aadhaar_card_path';
                    $values[] = $savedPaths['aadhaar_card'];
                } elseif ($this->columnExists($pdo, 'drivers', 'aadhaar_file')) {
                    $columns[] = 'aadhaar_file';
                    $values[] = $savedPaths['aadhaar_card'];
                }
                if ($this->columnExists($pdo, 'drivers', 'driver_photo_path')) {
                    $columns[] = 'driver_photo_path';
                    $values[] = $savedPaths['driver_photo'];
                }
                if ($this->columnExists($pdo, 'drivers', 'insurance_doc_path')) {
                    $columns[] = 'insurance_doc_path';
                    $values[] = $optionalSaved['insurance_doc'];
                }
                if ($this->columnExists($pdo, 'drivers', 'puc_doc_path')) {
                    $columns[] = 'puc_doc_path';
                    $values[] = $optionalSaved['puc_doc'];
                }
                if ($this->columnExists($pdo, 'drivers', 'rating')) {
                    $columns[] = 'rating';
                    $values[] = 0.0;
                }
                if ($this->columnExists($pdo, 'drivers', 'total_rides')) {
                    $columns[] = 'total_rides';
                    $values[] = 0;
                }
                if ($this->columnExists($pdo, 'drivers', 'is_verified')) {
                    $columns[] = 'is_verified';
                    $values[] = 0;
                }
                if ($this->columnExists($pdo, 'drivers', 'is_available')) {
                    $columns[] = 'is_available';
                    $values[] = 1;
                } elseif ($this->columnExists($pdo, 'drivers', 'availability')) {
                    $columns[] = 'availability';
                    $values[] = 1;
                }
                if ($this->columnExists($pdo, 'drivers', 'is_blocked')) {
                    $columns[] = 'is_blocked';
                    $values[] = 0;
                }
                if ($this->columnExists($pdo, 'drivers', 'verification_status')) {
                    $columns[] = 'verification_status';
                    $values[] = 'pending';
                }
                if ($this->columnExists($pdo, 'drivers', 'online_status')) {
                    $columns[] = 'online_status';
                    $values[] = 0;
                }
                if ($this->columnExists($pdo, 'drivers', 'ride_status')) {
                    $columns[] = 'ride_status';
                    $values[] = 'free';
                }

                if (empty($columns)) {
                    $this->respond(500, ['status' => 'error', 'message' => 'Driver schema is not compatible']);
                    return;
                }

                if ($existingDriverId !== null) {
                    $setParts = [];
                    $setValues = [];
                    foreach ($columns as $idx => $column) {
                        if ($column === 'id') {
                            continue;
                        }
                        $setParts[] = $column . ' = ?';
                        $setValues[] = $values[$idx];
                    }
                    if (!empty($setParts)) {
                        $setValues[] = $existingDriverId;
                        $update = $pdo->prepare('UPDATE drivers SET ' . implode(', ', $setParts) . ' WHERE id = ?');
                        $update->execute($setValues);
                    }
                    $driverId = (string)$existingDriverId;
                } else {
                    $placeholders = implode(', ', array_fill(0, count($columns), '?'));
                    $insert = $pdo->prepare('INSERT INTO drivers (' . implode(', ', $columns) . ') VALUES (' . $placeholders . ')');
                    $insert->execute($values);
                }
            }
        } catch (\PDOException $e) {
            if ((string)$e->getCode() === '23000') {
                $this->respond(409, ['status' => 'error', 'message' => 'Driver already registered with phone/email/license/vehicle']);
                return;
            }
            throw $e;
        }

        $this->respond(200, ['status' => 'ok', 'message' => 'Driver registered', 'driver_id' => $this->normalizeId($driverId), 'is_verified' => false]);
    }

    public function me(): void
    {
        $auth = ApiAuth::tokenRow();
        if (!$auth) {
            $this->respond(401, ['status' => 'error', 'message' => 'Unauthorized']);
            return;
        }


        $pdo = Mysql::connection();
        $driverId = $this->resolveDriverId($pdo, $auth);
        if ($driverId === null) {
            $this->respond(404, ['status' => 'error', 'message' => 'Driver not found']);
            return;
        }
        $select = [
            'id',
            $this->selectExpr($pdo, 'drivers', ['name', 'full_name'], 'name', "''"),
            $this->selectExpr($pdo, 'drivers', ['phone'], 'phone', "''"),
            $this->selectExpr($pdo, 'drivers', ['email'], 'email'),
            $this->selectExpr($pdo, 'drivers', ['vehicle_type'], 'vehicle_type'),
            $this->selectExpr($pdo, 'drivers', ['vehicle_number'], 'vehicle_number'),
            $this->selectExpr($pdo, 'drivers', ['license_number'], 'license_number'),
            $this->selectExpr($pdo, 'drivers', ['city'], 'city'),
            $this->selectExpr($pdo, 'drivers', ['pin_code'], 'pin_code'),
            $this->selectExpr($pdo, 'drivers', ['rating'], 'rating', '0'),
            $this->selectExpr($pdo, 'drivers', ['total_rides'], 'total_rides', '0'),
            $this->selectExpr($pdo, 'drivers', ['is_verified'], 'is_verified', '0'),
            $this->selectExpr($pdo, 'drivers', ['is_available', 'availability'], 'is_available', '1'),
            $this->selectExpr($pdo, 'drivers', ['is_blocked'], 'is_blocked', '0'),
            $this->selectExpr($pdo, 'drivers', ['verification_status'], 'verification_status', "'approved'"),
            $this->selectExpr($pdo, 'drivers', ['current_lat', 'latitude'], 'current_lat', 'NULL'),
            $this->selectExpr($pdo, 'drivers', ['current_lng', 'longitude'], 'current_lng', 'NULL'),
            $this->selectExpr($pdo, 'drivers', ['last_ping_at', 'last_seen_at'], 'last_ping_at', 'NULL'),
            $this->selectExpr($pdo, 'drivers', ['current_ride_id'], 'current_ride_id', 'NULL')
        ];
        $stmt = $pdo->prepare('SELECT ' . implode(', ', $select) . ' FROM drivers WHERE id = ? LIMIT 1');
        $stmt->execute([$driverId]);
        $driver = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$driver) {
            $this->respond(404, ['status' => 'error', 'message' => 'Driver not found']);
            return;
        }
        if (empty($driver['phone']) && !empty($auth['phone'])) {
            $driver['phone'] = $auth['phone'];
        }

        $driver['total_rides'] = (int) ($driver['total_rides'] ?? 0);
        $driver['rating'] = $driver['total_rides'] > 0 ? (float) ($driver['rating'] ?? 0) : 0.0;
        $driver['id'] = $this->normalizeId($driver['id']);
        $driver['current_ride_id'] = $this->normalizeId($driver['current_ride_id']);
        $this->respond(200, ['status' => 'ok', 'driver' => $driver]);
    }

    public function stats(): void
    {
        $auth = ApiAuth::tokenRow();

        $pdo = Mysql::connection();
        $driverId = $this->resolveDriverId($pdo, $auth ?: []);
        if ($driverId === null) {
            $this->respond(404, ['status' => 'error', 'message' => 'Driver not found']);
            return;
        }
        $stmt = $pdo->prepare('SELECT id, '
            . $this->selectExpr($pdo, 'drivers', ['rating'], 'rating', '0')
            . ', ' . $this->selectExpr($pdo, 'drivers', ['total_rides'], 'total_rides', '0')
            . ', ' . $this->selectExpr($pdo, 'drivers', ['is_verified'], 'is_verified', '0')
            . ' FROM drivers WHERE id = ? LIMIT 1');
        $stmt->execute([$driverId]);
        $driver = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$driver) {
            $this->respond(404, ['status' => 'error', 'message' => 'Driver not found']);
            return;
        }

        $rideStatus = $this->columnExists($pdo, 'rides', 'status') ? 'status = "ride_completed"' : '1=1';
        $earningColumn = $this->columnExists($pdo, 'rides', 'driver_earning')
            ? 'driver_earning'
            : ($this->columnExists($pdo, 'rides', 'driver_profit') ? 'driver_profit' : '0');
        $earnStmt = $pdo->prepare('SELECT COALESCE(SUM(' . $earningColumn . '),0) AS earnings_today FROM rides WHERE driver_id = ? AND '
            . $rideStatus . ' AND DATE(updated_at) = CURDATE()');
        $earnStmt->execute([$driver['id']]);
        $earn = $earnStmt->fetch(\PDO::FETCH_ASSOC);
        $totalRides = (int) ($driver['total_rides'] ?? 0);
        $rating = $totalRides > 0 ? (float) ($driver['rating'] ?? 0) : 0.0;

        $this->respond(200, [
            'status' => 'ok',
            'rating' => $rating,
            'total_rides' => $totalRides,
            'is_verified' => (bool) $driver['is_verified'],
            'earnings_today' => (float) ($earn['earnings_today'] ?? 0)
        ]);
    }

    public function earningsHistory(): void
    {
        $auth = ApiAuth::tokenRow();

        $limit = $_GET['limit'] ?? 20;
        if (!is_numeric($limit)) {
            $limit = 20;
        }
        $limit = max(1, min(100, (int) $limit));

        $pdo = Mysql::connection();
        $driverId = $this->resolveDriverId($pdo, $auth ?: []);
        if ($driverId === null) {
            $this->respond(404, ['status' => 'error', 'message' => 'Driver not found']);
            return;
        }
        $driverStmt = $pdo->prepare('SELECT id FROM drivers WHERE id = ? LIMIT 1');
        $driverStmt->execute([$driverId]);
        $driver = $driverStmt->fetch(\PDO::FETCH_ASSOC);
        if (!$driver) {
            $this->respond(404, ['status' => 'error', 'message' => 'Driver not found']);
            return;
        }

        $earningExpr = $this->columnExists($pdo, 'rides', 'driver_earning')
            ? 'COALESCE(driver_earning,0)'
            : ($this->columnExists($pdo, 'rides', 'driver_profit') ? 'COALESCE(driver_profit,0)' : '0');
        $fareExpr = $this->columnExists($pdo, 'rides', 'final_fare')
            ? 'COALESCE(final_fare, fare, 0)'
            : 'COALESCE(fare,0)';
        $stmt = $pdo->prepare('SELECT id, pickup_address, drop_address, distance_km, duration_min,
            ' . $earningExpr . ' AS driver_earning,
            ' . $fareExpr . ' AS fare,
            status, updated_at
            FROM rides
            WHERE driver_id = ?
              AND status IN ("ride_completed","completed","ride_closed","awaiting_payment")
            ORDER BY updated_at DESC
            LIMIT ' . $limit);
        $stmt->execute([$driver['id']]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['id'] = $this->normalizeId($row['id']);
            $fare = isset($row['fare']) && is_numeric($row['fare']) ? (float)$row['fare'] : 0.0;
            $earning = isset($row['driver_earning']) && is_numeric($row['driver_earning']) ? (float)$row['driver_earning'] : 0.0;
            $row['fare_amount'] = round($fare, 2);
            $row['driver_earning'] = round($earning > 0 ? $earning : ($fare * 0.22), 2);
        }

        $this->respond(200, ['status' => 'ok', 'rides' => $rows]);
    }

    public function setAvailability(): void
    {
        $auth = ApiAuth::tokenRow();

        $data = $this->jsonBody();
        $isAvailable = $data['is_available'] ?? null;
        if (!is_bool($isAvailable)) {
            $this->respond(422, ['status' => 'error', 'message' => 'is_available required']);
            return;
        }

        $pdo = Mysql::connection();
        $driverId = $this->resolveDriverId($pdo, $auth ?: []);
        if ($driverId === null) {
            $this->respond(404, ['status' => 'error', 'message' => 'Driver not found']);
            return;
        }
        $stmt = $pdo->prepare('UPDATE drivers SET is_available = ? WHERE id = ?');
        $stmt->execute([$isAvailable ? 1 : 0, $driverId]);

        $this->respond(200, ['status' => 'ok']);
    }

    public function updateLocation(): void
    {
        $auth = ApiAuth::tokenRow();

        $data = $this->jsonBody();
        $lat = $data['lat'] ?? null;
        $lng = $data['lng'] ?? null;
        $altitude = $data['altitude'] ?? null;
        $isAvailable = $data['is_available'] ?? null;

        if (!is_numeric($lat) || !is_numeric($lng)) {
            $this->respond(422, ['status' => 'error', 'message' => 'lat, lng required']);
            return;
        }

        $pdo = Mysql::connection();
        $driverId = $this->resolveDriverId($pdo, $auth ?: []);
        if ($driverId === null) {
            $this->respond(404, ['status' => 'error', 'message' => 'Driver not found']);
            return;
        }

        if ($isAvailable === true && $this->columnExists($pdo, 'drivers', 'current_ride_id') && $this->tableExists($pdo, 'rides')) {
            // Release stale ride lock so driver can receive new requests.
            if ($this->columnExists($pdo, 'rides', 'status')) {
                $cleanup = $pdo->prepare('UPDATE drivers d
                    LEFT JOIN rides r ON r.id = d.current_ride_id
                    SET d.current_ride_id = NULL
                    WHERE d.id = ?
                      AND d.current_ride_id IS NOT NULL
                      AND (
                        r.id IS NULL
                        OR r.status IN ("ride_completed","ride_closed","cancelled","no_driver_found")
                        OR (r.status = "driver_assigned" AND COALESCE(r.assigned_at, r.created_at) < DATE_SUB(NOW(), INTERVAL 10 MINUTE))
                        OR (r.status IN ("searching","requested","") AND r.searching_started_at IS NOT NULL AND r.searching_started_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE))
                      )');
                $cleanup->execute([$driverId]);
            }
        }

        $latCol = $this->columnExists($pdo, 'drivers', 'current_lat') ? 'current_lat' : 'latitude';
        $lngCol = $this->columnExists($pdo, 'drivers', 'current_lng') ? 'current_lng' : 'longitude';
        $altCol = $this->columnExists($pdo, 'drivers', 'current_altitude')
            ? 'current_altitude'
            : ($this->columnExists($pdo, 'drivers', 'altitude') ? 'altitude' : null);
        $seenCol = $this->columnExists($pdo, 'drivers', 'last_ping_at')
            ? 'last_ping_at'
            : ($this->columnExists($pdo, 'drivers', 'last_seen_at') ? 'last_seen_at' : null);
        $availCol = $this->columnExists($pdo, 'drivers', 'is_available')
            ? 'is_available'
            : ($this->columnExists($pdo, 'drivers', 'availability') ? 'availability' : null);
        $onlineCol = $this->columnExists($pdo, 'drivers', 'online_status') ? 'online_status' : null;

        $setParts = [$latCol . ' = ?', $lngCol . ' = ?'];
        $params = [(float) $lat, (float) $lng];
        if ($altCol !== null && is_numeric($altitude)) {
            $setParts[] = $altCol . ' = ?';
            $params[] = (float) $altitude;
        }
        if ($seenCol !== null) {
            $setParts[] = $seenCol . ' = NOW()';
        }
        if ($availCol !== null) {
            $setParts[] = $availCol . ' = COALESCE(?, ' . $availCol . ')';
            $params[] = is_bool($isAvailable) ? ($isAvailable ? 1 : 0) : null;
        }
        if ($onlineCol !== null) {
            $setParts[] = $onlineCol . ' = COALESCE(?, ' . $onlineCol . ')';
            $params[] = is_bool($isAvailable) ? ($isAvailable ? 1 : 0) : null;
        }
        $params[] = $driverId;

        $stmt = $pdo->prepare('UPDATE drivers SET ' . implode(', ', $setParts) . ' WHERE id = ?');
        $stmt->execute($params);

        try {
            $redis = RedisCache::client();
            $driverKey = strtolower((string)$this->normalizeId($driverId));
            $redis->geoadd('drivers:geo', (float)$lng, (float)$lat, $driverKey);
            $redis->setex('drivers:heartbeat:' . $driverKey, 180, (string)time());
            if ($isAvailable === false) {
                $redis->zrem('drivers:geo', $driverKey);
            }
        } catch (\Throwable $e) {
            // Geo cache is best-effort; DB remains source of truth.
        }

        $this->respond(200, ['status' => 'ok']);
    }

    public function updatePushToken(): void
    {
        $auth = ApiAuth::tokenRow();

        $data = $this->jsonBody();
        $tokenRaw = $data['fcm_token'] ?? ($data['token'] ?? null);
        if (is_array($tokenRaw)) {
            $tokenRaw = $tokenRaw['token'] ?? ($tokenRaw['data'] ?? null);
        }
        $token = is_string($tokenRaw) ? trim($tokenRaw) : '';
        if ($token === '') {
            $this->respond(422, ['status' => 'error', 'message' => 'fcm_token required']);
            return;
        }

        $pdo = Mysql::connection();
        $driverId = $this->resolveDriverId($pdo, $auth ?: []);
        if ($driverId === null) {
            $phone = trim((string)($auth['phone'] ?? ''));
            if ($phone !== '' && $this->columnExists($pdo, 'drivers', 'phone')) {
                $lookup = $pdo->prepare('SELECT id FROM drivers WHERE phone = ? LIMIT 1');
                $lookup->execute([$phone]);
                $row = $lookup->fetch(\PDO::FETCH_ASSOC);
                if ($row && isset($row['id'])) {
                    $driverId = (string)$row['id'];
                }
            }
            if ($driverId === null
                && $phone !== ''
                && $this->tableExists($pdo, 'users')
                && $this->columnExists($pdo, 'users', 'phone')
                && $this->columnExists($pdo, 'drivers', 'user_id')) {
                $lookup = $pdo->prepare('SELECT d.id FROM drivers d JOIN users u ON u.id = d.user_id WHERE u.phone = ? LIMIT 1');
                $lookup->execute([$phone]);
                $row = $lookup->fetch(\PDO::FETCH_ASSOC);
                if ($row && isset($row['id'])) {
                    $driverId = (string)$row['id'];
                }
            }
        }
        if ($driverId === null) {
            $this->respond(404, ['status' => 'error', 'message' => 'Driver not found']);
            return;
        }
        $stmt = $pdo->prepare('UPDATE drivers SET fcm_token = ? WHERE id = ?');
        $stmt->execute([$token, $driverId]);

        $this->respond(200, ['status' => 'ok', 'driver_id' => $this->normalizeId($driverId)]);
    }

    public function nearbyRequests(): void
    {
        $auth = ApiAuth::tokenRow();

        $pdo = Mysql::connection();
        if ($this->tableExists($pdo, 'ride_driver_requests')) {
            $this->processUnavailablePendingRequests($pdo);
            $this->processExpiredPendingRequests($pdo);
        }

        $driverSelect = [
            'id',
            $this->selectExpr($pdo, 'drivers', ['vehicle_type'], 'vehicle_type'),
            $this->selectExpr($pdo, 'drivers', ['is_verified'], 'is_verified', '0'),
            $this->selectExpr($pdo, 'drivers', ['is_blocked'], 'is_blocked', '0'),
            $this->selectExpr($pdo, 'drivers', ['penalty_until'], 'penalty_until', 'NULL')
        ];
        $driverId = $this->resolveDriverId($pdo, $auth ?: []);
        if ($driverId === null) {
            $this->respond(200, ['status' => 'ok', 'requests' => []]);
            return;
        }
        $driverRow = $pdo->prepare('SELECT ' . implode(', ', $driverSelect) . ' FROM drivers WHERE id = ? LIMIT 1');
        $driverRow->execute([$driverId]);
        $driver = $driverRow->fetch(\PDO::FETCH_ASSOC);
        if (!$driver) {
            $this->respond(200, ['status' => 'ok', 'requests' => []]);
            return;
        }
        if ($this->columnExists($pdo, 'drivers', 'vehicle_type') && empty($driver['vehicle_type'])) {
            $this->respond(200, ['status' => 'ok', 'requests' => []]);
            return;
        }
        $allowUnverifiedInDev = $this->allowUnverifiedInDev();
        if (((int)($driver['is_verified'] ?? 1) !== 1 && !$allowUnverifiedInDev) || (int)($driver['is_blocked'] ?? 0) === 1) {
            $this->respond(403, ['status' => 'error', 'message' => 'Driver not eligible']);
            return;
        }
        if (!empty($driver['penalty_until']) && strtotime($driver['penalty_until']) > time()) {
            $this->respond(403, ['status' => 'error', 'message' => 'Driver temporarily offline due to penalty']);
            return;
        }

        $rows = [];
        if ($this->tableExists($pdo, 'ride_driver_requests')) {
            $stmt = $pdo->prepare('SELECT r.id, r.pickup_address, r.drop_address, r.distance_km, r.duration_min, r.driver_earning, r.pickup_lat, r.pickup_lng, r.drop_lat, r.drop_lng, req.expires_at
                    FROM rides r
                    JOIN ride_driver_requests req ON req.ride_id = r.id
                    WHERE r.status IN ("searching","requested","") AND req.driver_id = ? AND req.status = "pending"');
            $stmt->execute([$driver['id']]);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } elseif ($this->tableExists($pdo, 'ride_requests_tracking')) {
            $stmt = $pdo->prepare('SELECT r.id,
                    r.pickup_lat, r.pickup_lng, r.drop_lat, r.drop_lng,
                    r.fare AS fare_total, r.driver_profit AS driver_earning,
                    t.expires_at
                FROM rides r
                JOIN ride_requests_tracking t ON t.ride_id = r.id
                WHERE r.status IN ("searching","requested","") AND t.driver_id = ? AND t.status = "pending"
                ORDER BY t.offered_at DESC');
            $stmt->execute([$driver['id']]);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($rows as &$row) {
                if (!isset($row['pickup_address'])) {
                    $row['pickup_address'] = 'Pickup (' . ($row['pickup_lat'] ?? '') . ', ' . ($row['pickup_lng'] ?? '') . ')';
                }
                if (!isset($row['drop_address'])) {
                    $row['drop_address'] = 'Drop (' . ($row['drop_lat'] ?? '') . ', ' . ($row['drop_lng'] ?? '') . ')';
                }
                $row['distance_km'] = isset($row['distance_km']) ? (float)$row['distance_km'] : 0;
                $row['duration_min'] = isset($row['duration_min']) ? (float)$row['duration_min'] : 0;
            }
            unset($row);
        }

        // Use fallback only when request-tracking tables are unavailable.
        // Otherwise rejected/expired requests can reappear repeatedly for the same driver.
        if (
            count($rows) === 0
            && !$this->tableExists($pdo, 'ride_driver_requests')
            && !$this->tableExists($pdo, 'ride_requests_tracking')
        ) {
            $rows = $this->fetchFallbackSearchingRides($pdo, $driver);
        }

        foreach ($rows as &$row) {
            $row['id'] = $this->normalizeId($row['id']);
            if (!empty($row['expires_at'])) {
                $remaining = strtotime((string) $row['expires_at']) - time();
                $row['expires_in_sec'] = max(1, $remaining);
            }
        }

        $this->respond(200, ['status' => 'ok', 'requests' => $rows]);
    }

    public function acceptRide(string $rideId): void
    {
        $auth = ApiAuth::tokenRow();

        $pdo = Mysql::connection();
        $rideLockToken = null;
        $pdo->beginTransaction();
        try {
            $driverId = $this->resolveDriverId($pdo, $auth ?: []);
            if ($driverId === null) {
                $pdo->rollBack();
                $this->respond(404, ['status' => 'error', 'message' => 'Driver not found']);
                return;
            }
            if (!$this->acquireRideAcceptLock((string)$rideId, $rideLockToken)) {
                $pdo->rollBack();
                $this->respond(409, ['status' => 'error', 'message' => 'Ride accept is being processed']);
                return;
            }
            $hasDriverVehicleType = $this->columnExists($pdo, 'drivers', 'vehicle_type');
            $hasRideVehicleType = $this->columnExists($pdo, 'rides', 'vehicle_type');

            $driverSelect = [
                'id',
                ($hasDriverVehicleType ? 'vehicle_type' : 'NULL AS vehicle_type'),
                $this->selectExpr($pdo, 'drivers', ['is_verified'], 'is_verified', '1'),
                $this->selectExpr($pdo, 'drivers', ['is_blocked'], 'is_blocked', '0'),
                $this->selectExpr($pdo, 'drivers', ['penalty_until'], 'penalty_until', 'NULL'),
                $this->selectExpr($pdo, 'drivers', ['current_ride_id'], 'current_ride_id', 'NULL'),
            ];
            $driverStmt = $pdo->prepare('SELECT ' . implode(', ', $driverSelect) . ' FROM drivers WHERE id = ? LIMIT 1 FOR UPDATE');
            $driverStmt->execute([$driverId]);
            $driver = $driverStmt->fetch(\PDO::FETCH_ASSOC);
            $allowUnverifiedInDev = $this->allowUnverifiedInDev();
            if (!$driver || ((int)$driver['is_verified'] !== 1 && !$allowUnverifiedInDev) || (int)$driver['is_blocked'] === 1) {
                $pdo->rollBack();
                $this->respond(403, ['status' => 'error', 'message' => 'Driver not verified']);
                return;
            }
            if (!empty($driver['penalty_until']) && strtotime($driver['penalty_until']) > time()) {
                $pdo->rollBack();
                $this->respond(403, ['status' => 'error', 'message' => 'Driver temporarily offline due to penalty']);
                return;
            }
            if (!empty($driver['current_ride_id'])) {
                $pdo->rollBack();
                $this->respond(409, ['status' => 'error', 'message' => 'Driver already on a ride']);
                return;
            }

            $rideStmt = $pdo->prepare('SELECT id, status, driver_id, '
                . ($hasRideVehicleType ? 'vehicle_type' : 'NULL AS vehicle_type')
                . ' FROM rides WHERE id = ? FOR UPDATE');
            $rideStmt->execute([$this->toIdBinary($rideId)]);
            $ride = $rideStmt->fetch(\PDO::FETCH_ASSOC);
            if (!$ride || !in_array((string)($ride['status'] ?? ''), ['searching', 'requested', ''], true) || !empty($ride['driver_id'])) {
                $pdo->rollBack();
                $this->respond(409, ['status' => 'error', 'message' => 'Ride not available']);
                return;
            }
            if ($hasDriverVehicleType && $hasRideVehicleType && $ride['vehicle_type'] !== $driver['vehicle_type']) {
                $pdo->rollBack();
                $this->respond(422, ['status' => 'error', 'message' => 'Vehicle type mismatch']);
                return;
            }

            $hasTrackingTable = $this->tableExists($pdo, 'ride_driver_requests');
            $hasPendingRequest = false;
            if ($hasTrackingTable) {
                $reqStmt = $pdo->prepare('SELECT id FROM ride_driver_requests WHERE ride_id = ? AND driver_id = ? AND status = "pending" FOR UPDATE');
                $reqStmt->execute([$this->toIdBinary($rideId), $driver['id']]);
                $hasPendingRequest = (bool) $reqStmt->fetch();
            }

            if ($hasTrackingTable && $hasPendingRequest) {
                $pdo->prepare('UPDATE ride_driver_requests SET status = "accepted", responded_at = NOW() WHERE ride_id = ? AND driver_id = ?')
                    ->execute([$this->toIdBinary($rideId), $driver['id']]);
                $pdo->prepare('UPDATE ride_driver_requests SET status = "expired", responded_at = NOW() WHERE ride_id = ? AND status IN ("pending","queued") AND driver_id <> ?')
                    ->execute([$this->toIdBinary($rideId), $driver['id']]);
            }

            $assign = $pdo->prepare('UPDATE rides SET driver_id = ?, status = "driver_assigned", assigned_at = NOW() WHERE id = ? AND status IN ("searching","requested","") AND (driver_id IS NULL OR driver_id = "")');
            $assign->execute([$driver['id'], $this->toIdBinary($rideId)]);
            if ($assign->rowCount() !== 1) {
                $pdo->rollBack();
                $this->respond(409, ['status' => 'error', 'message' => 'Ride already assigned']);
                return;
            }

            $setParts = [];
            $setParams = [];
            $availCol = $this->columnExists($pdo, 'drivers', 'is_available')
                ? 'is_available'
                : ($this->columnExists($pdo, 'drivers', 'availability') ? 'availability' : null);
            if ($availCol !== null) {
                $setParts[] = $availCol . ' = 0';
            }
            if ($this->columnExists($pdo, 'drivers', 'current_ride_id')) {
                $setParts[] = 'current_ride_id = ?';
                $setParams[] = $this->toIdBinary($rideId);
            }
            if (!empty($setParts)) {
                $setParams[] = $driver['id'];
                $pdo->prepare('UPDATE drivers SET ' . implode(', ', $setParts) . ' WHERE id = ?')
                    ->execute($setParams);
            }

            $this->insertStatusHistory($pdo, $this->toIdBinary($rideId), 'driver_assigned', 'driver', $driver['id'], 'Driver accepted');

            $pdo->commit();
            $this->respond(200, ['status' => 'ok', 'message' => 'Ride accepted']);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->respond(500, ['status' => 'error', 'message' => $e->getMessage()]);
        } finally {
            $this->releaseRideAcceptLock((string)$rideId, $rideLockToken);
        }
    }

   public function rejectRide(string $rideId): void
	{
		$auth = ApiAuth::requireRole('driver');
		if (!$auth) {
			return;
		}

		$pdo = Mysql::connection();
		$pdo->beginTransaction();
		try {
            $driverId = $this->resolveDriverId($pdo, $auth ?: []);
            if ($driverId === null) {
                $pdo->rollBack();
                $this->respond(404, ['status' => 'error', 'message' => 'Driver not found']);
                return;
            }
			$driverStmt = $pdo->prepare('SELECT id FROM drivers WHERE id = ? LIMIT 1 FOR UPDATE');
			$driverStmt->execute([$driverId]);
			$driver = $driverStmt->fetch(\PDO::FETCH_ASSOC);
			if (!$driver) {
				$pdo->rollBack();
				$this->respond(404, ['status' => 'error', 'message' => 'Driver not found']);
				return;
			}

			$reqUpdate = $pdo->prepare('UPDATE ride_driver_requests
                SET status = "rejected", responded_at = NOW()
                WHERE ride_id = ? AND driver_id = ? AND status IN ("pending","queued")');
			$reqUpdate->execute([$this->toIdBinary($rideId), $driver['id']]);
			if ($reqUpdate->rowCount() < 1) {
				$pdo->rollBack();
				$this->respond(409, ['status' => 'error', 'message' => 'No pending request']);
				return;
			}

            $this->applyDriverRequestCooldown($pdo, (string)$driver['id']);

            $rideIdBin = $this->toIdBinary($rideId);
            $this->dispatchNextQueuedDriver($pdo, $rideIdBin);
            $rideStatusStmt = $pdo->prepare('SELECT status FROM rides WHERE id = ? LIMIT 1');
            $rideStatusStmt->execute([$rideIdBin]);
            $nextRideStatus = strtolower(trim((string)($rideStatusStmt->fetchColumn() ?: '')));
            if ($nextRideStatus === 'no_driver_found') {
                $this->insertStatusHistory($pdo, $rideIdBin, 'no_driver_found', 'system', null, 'No drivers accepted');
            } else {
                $this->insertStatusHistory($pdo, $rideIdBin, 'searching', 'driver', $driver['id'], 'Driver rejected');
            }

			$pdo->commit();
			$this->respond(200, ['status' => 'ok', 'message' => 'Ride rejected']);
		} catch (\Throwable $e) {
			if ($pdo->inTransaction()) {
				$pdo->rollBack();
			}
			$this->respond(500, ['status' => 'error', 'message' => $e->getMessage()]);
		}
	}

private function fetchRideForNotify(\PDO $pdo, $rideId): array
{
    $stmt = $pdo->prepare('SELECT pickup_address, drop_address, pickup_lat, pickup_lng, drop_lat, drop_lng, fare, driver_earning, distance_km, duration_min FROM rides WHERE id = ?');
    $stmt->execute([$rideId]);
    $ride = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!$ride) {
        return [
            'pickup_address' => '',
            'drop_address' => '',
            'pickup_lat' => 0,
            'pickup_lng' => 0,
            'drop_lat' => 0,
            'drop_lng' => 0,
            'fare' => 0,
            'driver_earning' => 0,
            'distance_km' => 0,
            'duration_min' => 0
        ];
    }
    return $ride;
}

private function notifyNextDriver(\PDO $pdo, $rideId, string $pickup, string $dropoff, float $fare, float $driverEarning): void
	{
		$fcm = new FcmService();
        $rideInfo = $this->fetchRideForNotify($pdo, $rideId);
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

			$result = $fcm->sendToTokens([$row['fcm_token']], [
				'title' => 'New Ride Request',
				'body' => 'Accept within 5 minutes | Profit Rs ' . number_format($driverProfit22, 2, '.', '')
			], [
				'ride_id' => Uuid::toString($rideId),
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
                'accept_endpoint' => '/api/driver/rides/' . Uuid::toString($rideId) . '/accept',
                'reject_endpoint' => '/api/driver/rides/' . Uuid::toString($rideId) . '/reject'
			], [
				'android' => [
					'ttl' => self::REQUEST_EXPIRES_SECONDS . 's',
					'priority' => 'HIGH',
					'notification' => [
						'channel_id' => 'ride_request',
						'tag' => 'ride_' . Uuid::toString($rideId),
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

			// Keep request pending for transient failures; timeout processor will escalate after expiry.
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

    private function acquireRideAcceptLock(string $rideId, ?string &$token): bool
    {
        $token = null;
        $lockKey = 'ride:accept:lock:' . strtolower(trim($rideId));
        if ($lockKey === 'ride:accept:lock:') {
            return true;
        }
        try {
            $token = bin2hex(random_bytes(16));
            $result = RedisCache::client()->set($lockKey, $token, 'NX', 'EX', 12);
            return $result === true || strtoupper((string)$result) === 'OK';
        } catch (\Throwable $e) {
            // If Redis is unavailable, continue with DB row locks.
            return true;
        }
    }

    private function releaseRideAcceptLock(string $rideId, ?string $token): void
    {
        if ($token === null || $token === '') {
            return;
        }
        $lockKey = 'ride:accept:lock:' . strtolower(trim($rideId));
        if ($lockKey === 'ride:accept:lock:') {
            return;
        }
        try {
            $current = RedisCache::client()->get($lockKey);
            if (is_string($current) && hash_equals($current, $token)) {
                RedisCache::client()->del([$lockKey]);
            }
        } catch (\Throwable $e) {
        }
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

    private function processExpiredPendingRequests(\PDO $pdo): void
    {
        $rows = $pdo->query('SELECT id, ride_id, driver_id FROM ride_driver_requests WHERE status = "pending" AND expires_at IS NOT NULL AND expires_at <= NOW() ORDER BY expires_at ASC LIMIT 25')
            ->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare('SELECT id, ride_id, driver_id FROM ride_driver_requests WHERE id = ? AND status = "pending" AND expires_at IS NOT NULL AND expires_at <= NOW() FOR UPDATE');
                $stmt->execute([$row['id']]);
                $expired = $stmt->fetch(\PDO::FETCH_ASSOC);
                if (!$expired) {
                    $pdo->rollBack();
                    continue;
                }

                $pdo->prepare('UPDATE ride_driver_requests SET status = "expired", responded_at = NOW() WHERE id = ?')
                    ->execute([$row['id']]);
                if (!empty($expired['driver_id'])) {
                    $this->applyDriverRequestCooldown($pdo, (string)$expired['driver_id']);
                }

                $rideStmt = $pdo->prepare('SELECT id, status FROM rides WHERE id = ? FOR UPDATE');
                $rideStmt->execute([$expired['ride_id']]);
                $ride = $rideStmt->fetch(\PDO::FETCH_ASSOC);
                if (!$ride || !in_array((string)($ride['status'] ?? ''), ['searching', 'requested', ''], true)) {
                    $pdo->commit();
                    continue;
                }

                $this->dispatchNextQueuedDriver($pdo, $expired['ride_id']);
                $pdo->commit();
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            }
        }
    }

    private function applyDriverRequestCooldown(\PDO $pdo, string $driverId): void
    {
        if ($driverId === '' || !$this->columnExists($pdo, 'drivers', 'penalty_until')) {
            return;
        }
        $pdo->prepare('UPDATE drivers
            SET penalty_until = GREATEST(COALESCE(penalty_until, NOW()), DATE_ADD(NOW(), INTERVAL ' . self::DRIVER_REQUEST_COOLDOWN_MINUTES . ' MINUTE))
            WHERE id = ?')->execute([$driverId]);
    }

    private function dispatchNextQueuedDriver(\PDO $pdo, $rideId): void
    {
        $availabilityCol = $this->columnExists($pdo, 'drivers', 'is_available')
            ? 'd.is_available'
            : ($this->columnExists($pdo, 'drivers', 'availability') ? 'd.availability' : '1');
        $verifiedCond = $this->columnExists($pdo, 'drivers', 'is_verified') ? ' AND d.is_verified = 1' : '';
        $blockedCond = $this->columnExists($pdo, 'drivers', 'is_blocked') ? ' AND (d.is_blocked IS NULL OR d.is_blocked = 0)' : '';
        $penaltyCond = $this->columnExists($pdo, 'drivers', 'penalty_until') ? ' AND (d.penalty_until IS NULL OR d.penalty_until <= NOW())' : '';
        $rideLockCond = $this->columnExists($pdo, 'drivers', 'current_ride_id') ? ' AND d.current_ride_id IS NULL' : '';
        $nextEligible = null;
        $attempts = $this->countDispatchFailures($pdo, $rideId);
        $tiers = $this->dispatchRadiusTiersKm();
        $startTierIdx = min(count($tiers) - 1, max(0, $attempts));
        for ($i = $startTierIdx; $i < count($tiers); $i++) {
            $radius = (float)$tiers[$i];
            $queuedStmt = $pdo->prepare('SELECT req.driver_id
                FROM ride_driver_requests req
                JOIN drivers d ON d.id = req.driver_id
                WHERE req.ride_id = ?
                  AND req.status = "queued"
                  AND req.distance_km <= ?
                  AND ' . $availabilityCol . ' = 1'
                  . $verifiedCond
                  . $blockedCond
                  . $penaltyCond
                  . $rideLockCond . '
                ORDER BY ' . $this->driverRequestPriorityOrderSql($pdo, 'req') . '
                LIMIT 1
                FOR UPDATE');
            $queuedStmt->execute([$rideId, $radius]);
            $nextEligible = $queuedStmt->fetch(\PDO::FETCH_ASSOC);
            if ($nextEligible) {
                break;
            }
        }

        if (!$nextEligible) {
            $queuedUnavailableConds = [$availabilityCol . ' = 0'];
            if ($this->columnExists($pdo, 'drivers', 'is_verified')) {
                $queuedUnavailableConds[] = 'd.is_verified = 0';
            }
            if ($this->columnExists($pdo, 'drivers', 'is_blocked')) {
                $queuedUnavailableConds[] = 'd.is_blocked = 1';
            }
            if ($this->columnExists($pdo, 'drivers', 'penalty_until')) {
                $queuedUnavailableConds[] = '(d.penalty_until IS NOT NULL AND d.penalty_until > NOW())';
            }
            if ($this->columnExists($pdo, 'drivers', 'current_ride_id')) {
                $queuedUnavailableConds[] = 'd.current_ride_id IS NOT NULL';
            }
            $pdo->prepare('UPDATE ride_driver_requests req
                JOIN drivers d ON d.id = req.driver_id
                SET req.status = "expired", req.responded_at = NOW()
                WHERE req.ride_id = ?
                  AND req.status = "queued"
                  AND (' . implode(' OR ', $queuedUnavailableConds) . ')')->execute([$rideId]);

            $remainingQueuedStmt = $pdo->prepare('SELECT 1 FROM ride_driver_requests WHERE ride_id = ? AND status = "queued" LIMIT 1');
            $remainingQueuedStmt->execute([$rideId]);
            if ($remainingQueuedStmt->fetch()) {
                return;
            }

            $pdo->prepare('UPDATE rides SET status = "no_driver_found", no_driver_found_at = NOW() WHERE id = ? AND status IN ("searching","requested","")')
                ->execute([$rideId]);
            $this->insertStatusHistory($pdo, $rideId, 'no_driver_found', 'system', null, 'No online drivers available in queue');
            return;
        }

        $pdo->prepare('UPDATE ride_driver_requests SET status = "pending", sent_at = NULL, expires_at = NULL, responded_at = NULL WHERE ride_id = ? AND driver_id = ?')
            ->execute([$rideId, $nextEligible['driver_id']]);

        $rideInfo = $this->fetchRideForNotify($pdo, $rideId);
        $this->notifyNextDriver(
            $pdo,
            $rideId,
            (string) ($rideInfo['pickup_address'] ?? ''),
            (string) ($rideInfo['drop_address'] ?? ''),
            (float) ($rideInfo['fare'] ?? 0),
            (float) ($rideInfo['driver_earning'] ?? 0)
        );
    }

    private function dispatchRadiusTiersKm(): array
    {
        return [2.0, 5.0, 7.0];
    }

    private function countDispatchFailures(\PDO $pdo, $rideId): int
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) AS c
            FROM ride_driver_requests
            WHERE ride_id = ?
              AND status IN ("rejected","expired")
              AND responded_at IS NOT NULL');
        $stmt->execute([$rideId]);
        return max(0, (int)(($stmt->fetch(\PDO::FETCH_ASSOC) ?: [])['c'] ?? 0));
    }

    private function processUnavailablePendingRequests(\PDO $pdo): void
    {
        $availabilityCol = $this->columnExists($pdo, 'drivers', 'is_available')
            ? 'd.is_available'
            : ($this->columnExists($pdo, 'drivers', 'availability') ? 'd.availability' : '1');
        $unavailableConds = [$availabilityCol . ' = 0'];
        if ($this->columnExists($pdo, 'drivers', 'is_verified')) {
            $unavailableConds[] = 'd.is_verified = 0';
        }
        if ($this->columnExists($pdo, 'drivers', 'is_blocked')) {
            $unavailableConds[] = 'd.is_blocked = 1';
        }
        if ($this->columnExists($pdo, 'drivers', 'penalty_until')) {
            $unavailableConds[] = '(d.penalty_until IS NOT NULL AND d.penalty_until > NOW())';
        }
        if ($this->columnExists($pdo, 'drivers', 'current_ride_id')) {
            $unavailableConds[] = 'd.current_ride_id IS NOT NULL';
        }
        $unavailableWhere = implode(' OR ', $unavailableConds);

        $rowsStmt = $pdo->prepare('SELECT req.id, req.ride_id
            FROM ride_driver_requests req
            JOIN drivers d ON d.id = req.driver_id
            JOIN rides r ON r.id = req.ride_id
            WHERE req.status = "pending"
              AND r.status IN ("searching","requested","")
              AND (' . $unavailableWhere . ')
            ORDER BY req.sent_at ASC
            LIMIT 25');
        $rowsStmt->execute();
        $rows = $rowsStmt->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare('SELECT req.id, req.ride_id
                    FROM ride_driver_requests req
                    JOIN drivers d ON d.id = req.driver_id
                    JOIN rides r ON r.id = req.ride_id
                    WHERE req.id = ?
                      AND req.status = "pending"
                      AND r.status IN ("searching","requested","")
                      AND (' . $unavailableWhere . ')
                    FOR UPDATE');
                $stmt->execute([$row['id']]);
                $unavailable = $stmt->fetch(\PDO::FETCH_ASSOC);
                if (!$unavailable) {
                    $pdo->rollBack();
                    continue;
                }

                $pdo->prepare('UPDATE ride_driver_requests SET status = "expired", responded_at = NOW() WHERE id = ?')
                    ->execute([$row['id']]);
                $this->dispatchNextQueuedDriver($pdo, $unavailable['ride_id']);
                $pdo->commit();
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            }
        }
    }



    public function arrivedRide(string $rideId): void
    {
        $auth = ApiAuth::tokenRow();
        $data = $this->jsonBody();
        $bodyLat = $data['lat'] ?? null;
        $bodyLng = $data['lng'] ?? null;

        $pdo = Mysql::connection();
        $driverId = $this->resolveDriverId($pdo, $auth ?: []);
        if ($driverId === null) {
            $this->respond(404, ['status' => 'error', 'message' => 'Driver not found']);
            return;
        }
        $latCol = $this->columnExists($pdo, 'drivers', 'current_lat') ? 'current_lat' : 'latitude';
        $lngCol = $this->columnExists($pdo, 'drivers', 'current_lng') ? 'current_lng' : 'longitude';

        $rideStmt = $pdo->prepare('SELECT id, status, driver_id, pickup_lat, pickup_lng FROM rides WHERE id = ? LIMIT 1');
        $rideStmt->execute([$this->toIdBinary($rideId)]);
        $ride = $rideStmt->fetch(\PDO::FETCH_ASSOC);
        if (!$ride) {
            $this->respond(409, ['status' => 'error', 'message' => 'Ride cannot be marked arrived']);
            return;
        }
        $assignedDriverId = $this->normalizeId($ride['driver_id'] ?? null);
        $currentDriverId = $this->normalizeId($driverId);
        if ($assignedDriverId !== null && $assignedDriverId !== '' && (string)$assignedDriverId !== (string)$currentDriverId) {
            $this->respond(409, ['status' => 'error', 'message' => 'Ride cannot be marked arrived']);
            return;
        }
        $driverStmt = $pdo->prepare('SELECT id, ' . $latCol . ' AS driver_lat, ' . $lngCol . ' AS driver_lng FROM drivers WHERE id = ? LIMIT 1');
        $driverStmt->execute([$driverId]);
        $driver = $driverStmt->fetch(\PDO::FETCH_ASSOC);
        if (!$driver) {
            $this->respond(404, ['status' => 'error', 'message' => 'Driver not found']);
            return;
        }
        $rideStatus = strtolower((string)($ride['status'] ?? ''));
        if (in_array($rideStatus, ['cancelled', 'ride_completed', 'completed', 'ride_closed', 'no_driver_found', 'ride_started', 'in_progress', 'enroute'], true)) {
            $this->respond(409, ['status' => 'error', 'message' => 'Ride cannot be marked arrived']);
            return;
        }
        if (in_array($rideStatus, ['driver_arrived', 'arrived', 'waiting'], true)) {
            $this->respond(200, [
                'status' => 'ok',
                'message' => 'Arrived at pickup',
                'next_step' => 'Enter customer OTP to start ride'
            ]);
            return;
        }

        $driverLat = is_numeric($bodyLat) ? (float) $bodyLat : (is_numeric($driver['driver_lat'] ?? null) ? (float) $driver['driver_lat'] : null);
        $driverLng = is_numeric($bodyLng) ? (float) $bodyLng : (is_numeric($driver['driver_lng'] ?? null) ? (float) $driver['driver_lng'] : null);
        $pickupLat = is_numeric($ride['pickup_lat'] ?? null) ? (float) $ride['pickup_lat'] : null;
        $pickupLng = is_numeric($ride['pickup_lng'] ?? null) ? (float) $ride['pickup_lng'] : null;

        if ($driverLat !== null && $driverLng !== null && $pickupLat !== null && $pickupLng !== null) {
            $distanceMeters = $this->haversineMeters($driverLat, $driverLng, $pickupLat, $pickupLng);
            $arrivalThresholdMeters = 250;
            if ($distanceMeters > $arrivalThresholdMeters && $this->isProductionEnv()) {
                $this->respond(422, [
                    'status' => 'error',
                    'message' => 'You are not at pickup yet',
                    'distance_meters' => round($distanceMeters, 1),
                    'required_meters' => $arrivalThresholdMeters
                ]);
                return;
            }
        }

        $setParts = ['status = "driver_arrived"'];
        if ($this->columnExists($pdo, 'rides', 'driver_arrived_at')) {
            $setParts[] = 'driver_arrived_at = NOW()';
        }
        if ($this->columnExists($pdo, 'rides', 'waiting_started_at')) {
            $setParts[] = 'waiting_started_at = NOW()';
        }
        $stmt = $pdo->prepare('UPDATE rides SET driver_id = ?, ' . implode(', ', $setParts) . ' WHERE id = ? AND (driver_id = ? OR driver_id IS NULL OR driver_id = "")');
        $stmt->execute([$driverId, $this->toIdBinary($rideId), $driverId]);
        if ($stmt->rowCount() !== 1) {
            $checkStmt = $pdo->prepare('SELECT 1 FROM rides WHERE id = ? AND (driver_id = ? OR driver_id IS NULL OR driver_id = "") LIMIT 1');
            $checkStmt->execute([$this->toIdBinary($rideId), $driverId]);
            if (!$checkStmt->fetchColumn()) {
                $this->respond(409, ['status' => 'error', 'message' => 'Ride cannot be marked arrived']);
                return;
            }
        }

        $this->insertStatusHistory($pdo, $this->toIdBinary($rideId), 'driver_arrived', 'driver', null, 'Driver arrived');
        $this->respond(200, [
            'status' => 'ok',
            'message' => 'Arrived at pickup',
            'next_step' => 'Enter customer OTP to start ride'
        ]);
    }

    public function startRide(string $rideId): void
    {
        $auth = ApiAuth::requireRole('driver');
        if (!$auth) {
            return;
        }

        $data = $this->jsonBody();
        $otp = $data['otp'] ?? null;
        if (!is_string($otp) || strlen(trim($otp)) !== 4) {
            $this->respond(422, ['status' => 'error', 'message' => 'OTP required']);
            return;
        }

        $pdo = Mysql::connection();
        $driverId = $this->resolveDriverId($pdo, $auth ?: []);
        if ($driverId === null) {
            $this->respond(404, ['status' => 'error', 'message' => 'Driver not found']);
            return;
        }
        $latCol = $this->columnExists($pdo, 'drivers', 'current_lat') ? 'current_lat' : 'latitude';
        $lngCol = $this->columnExists($pdo, 'drivers', 'current_lng') ? 'current_lng' : 'longitude';
        $stmt = $pdo->prepare('SELECT r.id, r.otp_code, r.otp_expires_at, d.id AS driver_id, d.' . $latCol . ' AS driver_lat, d.' . $lngCol . ' AS driver_lng
            FROM rides r
            JOIN drivers d ON d.id = r.driver_id
            WHERE r.id = ?
              AND d.id = ?
              AND (r.status IN ("driver_assigned","driver_arriving","driver_arrived","arrived","accepted","assigned","waiting","") OR r.status IS NULL)
            LIMIT 1
            FOR UPDATE');
        $pdo->beginTransaction();
        $stmt->execute([$this->toIdBinary($rideId), $driverId]);
        $ride = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$ride) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->respond(409, ['status' => 'error', 'message' => 'Ride cannot be started']);
            return;
        }

        if ($ride['otp_code'] !== trim($otp)) {
            $pdo->rollBack();
            $this->respond(422, ['status' => 'error', 'message' => 'Invalid OTP']);
            return;
        }
        if (!empty($ride['otp_expires_at']) && strtotime($ride['otp_expires_at']) < time()) {
            $pdo->rollBack();
            $this->respond(422, ['status' => 'error', 'message' => 'OTP expired']);
            return;
        }

        $startLat = is_numeric($ride['driver_lat'] ?? null) ? (float)$ride['driver_lat'] : null;
        $startLng = is_numeric($ride['driver_lng'] ?? null) ? (float)$ride['driver_lng'] : null;

        $setParts = [
            'status = "ride_started"'
        ];
        if ($this->columnExists($pdo, 'rides', 'ride_started_at')) {
            $setParts[] = 'ride_started_at = NOW()';
        } elseif ($this->columnExists($pdo, 'rides', 'started_at')) {
            $setParts[] = 'started_at = NOW()';
        }
        $params = [];
        if ($this->columnExists($pdo, 'rides', 'ride_start_time')) {
            $setParts[] = 'ride_start_time = NOW()';
        }
        if ($this->columnExists($pdo, 'rides', 'total_distance_km')) {
            $setParts[] = 'total_distance_km = 0';
        }
        if ($this->columnExists($pdo, 'rides', 'total_duration_min')) {
            $setParts[] = 'total_duration_min = 0';
        }
        if ($startLat !== null && $startLng !== null) {
            if ($this->columnExists($pdo, 'rides', 'start_lat')) {
                $setParts[] = 'start_lat = ?';
                $params[] = $startLat;
            }
            if ($this->columnExists($pdo, 'rides', 'start_lng')) {
                $setParts[] = 'start_lng = ?';
                $params[] = $startLng;
            }
            if ($this->columnExists($pdo, 'rides', 'last_lat')) {
                $setParts[] = 'last_lat = ?';
                $params[] = $startLat;
            }
            if ($this->columnExists($pdo, 'rides', 'last_lng')) {
                $setParts[] = 'last_lng = ?';
                $params[] = $startLng;
            }
            if ($this->columnExists($pdo, 'rides', 'end_lat')) {
                $setParts[] = 'end_lat = ?';
                $params[] = $startLat;
            }
            if ($this->columnExists($pdo, 'rides', 'end_lng')) {
                $setParts[] = 'end_lng = ?';
                $params[] = $startLng;
            }
        }
        $params[] = $this->toIdBinary($rideId);

        $pdo->prepare('UPDATE rides SET ' . implode(', ', $setParts) . ' WHERE id = ?')->execute($params);

        $this->insertStatusHistory($pdo, $this->toIdBinary($rideId), 'ride_started', 'driver', null, 'OTP verified');
        $pdo->commit();
        $this->respond(200, ['status' => 'ok', 'message' => 'Ride started']);
    }

    public function updateRideProgress(string $rideId): void
    {
        $auth = ApiAuth::requireRole('driver');
        if (!$auth) {
            return;
        }

        $data = $this->jsonBody();
        $lat = $data['lat'] ?? null;
        $lng = $data['lng'] ?? null;
        if (!is_numeric($lat) || !is_numeric($lng)) {
            $this->respond(422, ['status' => 'error', 'message' => 'lat, lng required']);
            return;
        }

        $pdo = Mysql::connection();
        $driverId = $this->resolveDriverId($pdo, $auth ?: []);
        if ($driverId === null) {
            $this->respond(404, ['status' => 'error', 'message' => 'Driver not found']);
            return;
        }
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT r.id, r.status,
                    ' . ($this->columnExists($pdo, 'rides', 'last_lat') ? 'r.last_lat' : 'NULL') . ' AS last_lat,
                    ' . ($this->columnExists($pdo, 'rides', 'last_lng') ? 'r.last_lng' : 'NULL') . ' AS last_lng,
                    ' . ($this->columnExists($pdo, 'rides', 'total_distance_km') ? 'r.total_distance_km' : '0') . ' AS total_distance_km,
                    d.id AS driver_id
                FROM rides r
                JOIN drivers d ON d.id = r.driver_id
                WHERE r.id = ? AND d.id = ?
                LIMIT 1
                FOR UPDATE');
            $stmt->execute([$this->toIdBinary($rideId), $driverId]);
            $ride = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$ride) {
                $pdo->rollBack();
                $this->respond(404, ['status' => 'error', 'message' => 'Ride not found']);
                return;
            }

            $rideStatus = strtolower((string)($ride['status'] ?? ''));
            if (!in_array($rideStatus, ['ride_started', 'in_progress'], true)) {
                $pdo->rollBack();
                $this->respond(409, ['status' => 'error', 'message' => 'Ride is not in progress']);
                return;
            }

            $status = strtolower((string)($ride['status'] ?? ''));
            if (!in_array($status, ['ride_started', 'in_progress'], true)) {
                $pdo->rollBack();
                $this->respond(409, ['status' => 'error', 'message' => 'Ride is not in progress']);
                return;
            }

            $newLat = (float)$lat;
            $newLng = (float)$lng;

            $lastLat = is_numeric($ride['last_lat'] ?? null) ? (float)$ride['last_lat'] : null;
            $lastLng = is_numeric($ride['last_lng'] ?? null) ? (float)$ride['last_lng'] : null;
            $deltaMeters = 0.0;
            $deltaKm = 0.0;
            if ($lastLat !== null && $lastLng !== null) {
                $deltaMeters = $this->haversineMeters($lastLat, $lastLng, $newLat, $newLng);
                // Anti-tamper: ignore big jumps (GPS spoof / network jumps)
                if ($deltaMeters < 0) {
                    $deltaMeters = 0;
                }
                if ($deltaMeters <= 500.0) {
                    $deltaKm = $deltaMeters / 1000.0;
                } else {
                    $deltaKm = 0.0;
                }
            }

            $totalKm = (float)($ride['total_distance_km'] ?? 0);
            $totalKm = max(0.0, $totalKm + $deltaKm);

            $setParts = [];
            $params = [];
            if ($this->columnExists($pdo, 'rides', 'last_lat')) {
                $setParts[] = 'last_lat = ?';
                $params[] = $newLat;
            }
            if ($this->columnExists($pdo, 'rides', 'last_lng')) {
                $setParts[] = 'last_lng = ?';
                $params[] = $newLng;
            }
            if ($this->columnExists($pdo, 'rides', 'end_lat')) {
                $setParts[] = 'end_lat = ?';
                $params[] = $newLat;
            }
            if ($this->columnExists($pdo, 'rides', 'end_lng')) {
                $setParts[] = 'end_lng = ?';
                $params[] = $newLng;
            }
            if ($this->columnExists($pdo, 'rides', 'total_distance_km')) {
                $setParts[] = 'total_distance_km = ?';
                $params[] = round($totalKm, 3);
            }

            if (!empty($setParts)) {
                $params[] = $this->toIdBinary($rideId);
                $pdo->prepare('UPDATE rides SET ' . implode(', ', $setParts) . ' WHERE id = ?')->execute($params);
            }

            $pdo->commit();
            $this->respond(200, [
                'status' => 'ok',
                'ride_id' => $rideId,
                'delta_meters' => round($deltaMeters, 1),
                'total_distance_km' => round($totalKm, 3)
            ]);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $message = (string)$e->getMessage();
            if (stripos($message, 'already settled') !== false) {
                $this->respond(409, ['status' => 'error', 'message' => $message]);
                return;
            }
            $this->respond(500, ['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    public function completeRide(string $rideId): void
    {
        $auth = ApiAuth::requireRole('driver');
        if (!$auth) {
            return;
        }

        // Backward compatible: allow client values, but prefer server tracked totals when available.
        $data = $this->jsonBody();
        $clientDistanceKm = $data['distance_km'] ?? null;
        $clientDurationMin = $data['duration_minutes'] ?? null;
        $walletService = new WalletSettlementService();

        $pdo = Mysql::connection();
        $driverId = $this->resolveDriverId($pdo, $auth ?: []);
        if ($driverId === null) {
            $this->respond(404, ['status' => 'error', 'message' => 'Driver not found']);
            return;
        }
        $pdo->beginTransaction();
        try {
            $latCol = $this->columnExists($pdo, 'drivers', 'current_lat') ? 'current_lat' : 'latitude';
            $lngCol = $this->columnExists($pdo, 'drivers', 'current_lng') ? 'current_lng' : 'longitude';
            $waitingRateExpr = $this->columnExists($pdo, 'vehicle_pricing', 'waiting_rate_per_min')
                ? 'vp.waiting_rate_per_min'
                : '0';

            $rideStmt = $pdo->prepare('SELECT r.*, vp.cost_per_km, vp.cost_per_min, vp.minimum_fare, vp.platform_fee, ' . $waitingRateExpr . ' AS waiting_rate_per_min,
                    d.' . $latCol . ' AS driver_lat, d.' . $lngCol . ' AS driver_lng
                FROM rides r
                JOIN vehicle_pricing vp ON vp.vehicle_type = r.vehicle_type
                JOIN drivers d ON d.id = r.driver_id
                WHERE r.id = ? AND d.id = ?
                FOR UPDATE');
            $rideStmt->execute([$this->toIdBinary($rideId), $driverId]);
            $ride = $rideStmt->fetch(\PDO::FETCH_ASSOC);
            if (!$ride) {
                $pdo->rollBack();
                $this->respond(404, ['status' => 'error', 'message' => 'Ride not found']);
                return;
            }

            // Server tracked distance + duration
            $trackedDistanceKm = $this->columnExists($pdo, 'rides', 'total_distance_km') ? (float)($ride['total_distance_km'] ?? 0) : 0.0;

            $startTime = $ride['ride_start_time'] ?? $ride['ride_started_at'] ?? $ride['started_at'] ?? null;
            $now = new \DateTimeImmutable('now');
            $durationMin = 0.0;
            if (!empty($startTime)) {
                $start = new \DateTimeImmutable((string)$startTime);
                $diffSec = max(0, $now->getTimestamp() - $start->getTimestamp());
                $durationMin = round($diffSec / 60.0, 0);
            }

            $actualDistanceKm = $trackedDistanceKm > 0 ? $trackedDistanceKm : (is_numeric($clientDistanceKm) ? (float)$clientDistanceKm : (float)($ride['distance_km'] ?? 0));
            $actualDurationMin = $durationMin > 0 ? $durationMin : (is_numeric($clientDurationMin) ? (float)$clientDurationMin : (float)($ride['duration_min'] ?? 0));

            $waitingMinutes = 0;
            if (!empty($ride['waiting_started_at'])) {
                $waitingMinutes = (int) floor((time() - strtotime($ride['waiting_started_at'])) / 60);
                if ($waitingMinutes < 0) {
                    $waitingMinutes = 0;
                }
            }
            $waitingCharge = $waitingMinutes * (float) $ride['waiting_rate_per_min'];

            // Decide whether to charge estimated fare or dynamic actual fare.
            $plannedDistanceKm =
                $this->columnExists($pdo, 'rides', 'planned_distance_km') && is_numeric($ride['planned_distance_km'] ?? null)
                    ? (float)$ride['planned_distance_km']
                    : (float)($ride['distance_km'] ?? 0);
            $plannedDurationMin =
                $this->columnExists($pdo, 'rides', 'planned_duration_min') && is_numeric($ride['planned_duration_min'] ?? null)
                    ? (float)$ride['planned_duration_min']
                    : (float)($ride['duration_min'] ?? 0);
            $estimatedFare =
                $this->columnExists($pdo, 'rides', 'estimated_fare') && is_numeric($ride['estimated_fare'] ?? null)
                    ? (float)$ride['estimated_fare']
                    : (float)($ride['fare'] ?? 0);

            $endLat = is_numeric($ride['driver_lat'] ?? null) ? (float)$ride['driver_lat'] : (is_numeric($ride['end_lat'] ?? null) ? (float)$ride['end_lat'] : null);
            $endLng = is_numeric($ride['driver_lng'] ?? null) ? (float)$ride['driver_lng'] : (is_numeric($ride['end_lng'] ?? null) ? (float)$ride['end_lng'] : null);

            $atDrop = false;
            $dropMeters = null;
            if ($endLat !== null && $endLng !== null && is_numeric($ride['drop_lat'] ?? null) && is_numeric($ride['drop_lng'] ?? null)) {
                $dropMeters = $this->haversineMeters($endLat, $endLng, (float)$ride['drop_lat'], (float)$ride['drop_lng']);
                $atDrop = $dropMeters <= 50.0;
            }

            if (!$atDrop) {
                $pdo->rollBack();
                $this->respond(409, [
                    'status' => 'error',
                    'message' => 'Drop location par pahunchne ke baad hi ride complete hogi',
                    'drop_distance_meters' => $dropMeters !== null ? round($dropMeters, 1) : null
                ]);
                return;
            }

            $plannedKm = max(0.001, $plannedDistanceKm);
            $isSignificantDeviation = $actualDistanceKm > ($plannedKm * 1.15);

            $dynamicBreakdown = Pricing::calculateFare([
                'distance_km' => (float)$actualDistanceKm,
                'duration_minutes' => (float)$actualDurationMin,
                'driver_cost_per_km' => (float) $ride['cost_per_km'],
                'driver_cost_per_min' => (float) $ride['cost_per_min'],
                'minimum_fare' => (float) $ride['minimum_fare'],
                'platform_fee' => (float) $ride['platform_fee']
            ]);
            $estimatedBreakdown = Pricing::calculateFare([
                'distance_km' => (float)$plannedDistanceKm,
                'duration_minutes' => (float)$plannedDurationMin,
                'driver_cost_per_km' => (float) $ride['cost_per_km'],
                'driver_cost_per_min' => (float) $ride['cost_per_min'],
                'minimum_fare' => (float) $ride['minimum_fare'],
                'platform_fee' => (float) $ride['platform_fee']
            ]);

            $dynamicFareTotal = (float)$dynamicBreakdown['total_fare'] + $waitingCharge;
            $estimatedFareTotal = (float)$estimatedFare + $waitingCharge;

            $useEstimated = false;
            if ($atDrop) {
                // Full drop reached: charge estimated fare unless route exceeded significantly.
                $useEstimated = !$isSignificantDeviation;
            } else {
                // Ended early: charge dynamic if actual < planned, else cap at estimated.
                $useEstimated = !($actualDistanceKm < $plannedDistanceKm);
            }

            $chosen = $useEstimated ? $estimatedBreakdown : $dynamicBreakdown;
            $baseFareTotal = $useEstimated ? $estimatedFareTotal : $dynamicFareTotal;

            // Minimum fare safety should follow configured vehicle minimum fare, not a hardcoded value.
            $configuredMinFare = max(0.0, (float)($ride['minimum_fare'] ?? 0));
            $baseFareTotal = max($baseFareTotal, $configuredMinFare + (float)$waitingCharge);

            // Round to nearest integer for display, but keep breakup consistent with chk_fare_breakup.
            // We'll adjust platform_fee by a small delta so that:
            // fare == driver_cost + driver_profit + platform_fee AND driver_earning == driver_cost + driver_profit
            $targetFare = (float)round($baseFareTotal, 0); // integer (e.g. 123)
            $targetFare2 = (float)round($targetFare, 2);   // 123.00

            $driverCost = (float)round((float)$chosen['driver_cost'], 2);
            $platformFee = (float)round((float)$chosen['platform_fee'], 2);

            // Profit includes waiting charge.
            $driverProfit = (float)round(max(0.0, ((float)$chosen['driver_profit'] + $waitingCharge)), 2);

            // Ensure sum can reach target fare (if rounding caused mismatch, adjust platform fee by delta).
            $interim = (float)round($driverCost + $driverProfit + $platformFee, 2);
            $delta = (float)round($targetFare2 - $interim, 2);
            if ($delta !== 0.0) {
                $platformFee = (float)round(max(0.0, $platformFee + $delta), 2);
            }

            $finalDriverProfit = $driverProfit;
            $finalDriverEarning = (float)round($driverCost + $finalDriverProfit, 2);
            $finalFare = (float)round($finalDriverEarning + $platformFee, 2);
            $fmtMoney = static function (float $amount): string {
                return number_format($amount, 2, '.', '');
            };

            $completeSet = ['status = "ride_completed"'];
            if ($this->columnExists($pdo, 'rides', 'ride_completed_at')) {
                $completeSet[] = 'ride_completed_at = NOW()';
            } elseif ($this->columnExists($pdo, 'rides', 'completed_at')) {
                $completeSet[] = 'completed_at = NOW()';
            }
            $completeSet[] = 'distance_km = ?';
            $completeSet[] = 'duration_min = ?';
            if ($this->columnExists($pdo, 'rides', 'waiting_minutes')) {
                $completeSet[] = 'waiting_minutes = ?';
            }
            if ($this->columnExists($pdo, 'rides', 'waiting_charge')) {
                $completeSet[] = 'waiting_charge = ?';
            }
            $completeSet[] = 'fare = ?';
            if ($this->columnExists($pdo, 'rides', 'total_fare')) {
                $completeSet[] = 'total_fare = ?';
            }
            $completeSet[] = 'driver_cost = ?';
            $completeSet[] = 'driver_profit = ?';
            $completeSet[] = 'driver_earning = ?';
            $completeSet[] = 'platform_fee = ?';

            $update = $pdo->prepare('UPDATE rides SET ' . implode(', ', $completeSet) . ' WHERE id = ?');
            $updateParams = [
                (float) $actualDistanceKm,
                (float) $actualDurationMin,
            ];
            if ($this->columnExists($pdo, 'rides', 'waiting_minutes')) {
                $updateParams[] = $waitingMinutes;
            }
            if ($this->columnExists($pdo, 'rides', 'waiting_charge')) {
                $updateParams[] = $waitingCharge;
            }
            $updateParams[] = $fmtMoney($finalFare);
            if ($this->columnExists($pdo, 'rides', 'total_fare')) {
                $updateParams[] = $fmtMoney($finalFare);
            }
            $updateParams[] = $fmtMoney($driverCost);
            $updateParams[] = $fmtMoney($finalDriverProfit);
            $updateParams[] = $fmtMoney($finalDriverEarning);
            $updateParams[] = $fmtMoney($platformFee);
            $updateParams[] = $this->toIdBinary($rideId);
            $update->execute([
                ...$updateParams
            ]);

            // Persist tracking summary (if migration 019 is applied)
            $trackSet = [];
            $trackParams = [];
            if ($this->columnExists($pdo, 'rides', 'ride_end_time')) {
                $trackSet[] = 'ride_end_time = NOW()';
            }
            if ($this->columnExists($pdo, 'rides', 'total_distance_km')) {
                $trackSet[] = 'total_distance_km = ?';
                $trackParams[] = round((float)$actualDistanceKm, 3);
            }
            if ($this->columnExists($pdo, 'rides', 'total_duration_min')) {
                $trackSet[] = 'total_duration_min = ?';
                $trackParams[] = (int)round((float)$actualDurationMin, 0);
            }
            if ($this->columnExists($pdo, 'rides', 'final_fare')) {
                $trackSet[] = 'final_fare = ?';
                $trackParams[] = (float)round($targetFare2, 2);
            }
            if ($this->columnExists($pdo, 'rides', 'end_lat') && $endLat !== null) {
                $trackSet[] = 'end_lat = ?';
                $trackParams[] = $endLat;
            }
            if ($this->columnExists($pdo, 'rides', 'end_lng') && $endLng !== null) {
                $trackSet[] = 'end_lng = ?';
                $trackParams[] = $endLng;
            }
            if (!empty($trackSet)) {
                $trackParams[] = $this->toIdBinary($rideId);
                $pdo->prepare('UPDATE rides SET ' . implode(', ', $trackSet) . ' WHERE id = ?')->execute($trackParams);
            }

            $driverId = $this->resolveDriverId($pdo, $auth ?: []);
            if ($driverId !== null) {
                $driverSetParts = [];
                $driverParams = [];
                $availCol = $this->columnExists($pdo, 'drivers', 'is_available')
                    ? 'is_available'
                    : ($this->columnExists($pdo, 'drivers', 'availability') ? 'availability' : null);
                if ($availCol !== null) {
                    $driverSetParts[] = $availCol . ' = 1';
                }
                if ($this->columnExists($pdo, 'drivers', 'total_rides')) {
                    $driverSetParts[] = 'total_rides = total_rides + 1';
                }
                if ($this->columnExists($pdo, 'drivers', 'current_ride_id')) {
                    $driverSetParts[] = 'current_ride_id = NULL';
                }
                if (!empty($driverSetParts)) {
                    $driverParams[] = $driverId;
                    $pdo->prepare('UPDATE drivers SET ' . implode(', ', $driverSetParts) . ' WHERE id = ?')
                        ->execute($driverParams);
                }
            }

            $this->insertStatusHistory($pdo, $this->toIdBinary($rideId), 'ride_completed', 'driver', null, 'Ride completed');

            $paymentModeRaw = (string)(
                $data['payment_mode']
                ?? $data['payment_method']
                ?? $ride['payment_mode']
                ?? $ride['payment_method']
                ?? 'cash'
            );
            $settlement = $walletService->settleRide(
                $pdo,
                $ride['id'],
                $driverId,
                $finalFare,
                $paymentModeRaw
            );

            $pdo->commit();
            $this->respond(200, [
                'status' => 'ok',
                'message' => 'Ride completed',
                'fare' => $finalFare,
                'final_fare' => $finalFare,
                'total_fare' => $finalFare,
                'used_estimated_fare' => $useEstimated,
                'at_drop' => $atDrop,
                'drop_distance_meters' => $dropMeters !== null ? round($dropMeters, 1) : null,
                'actual_distance_km' => round((float)$actualDistanceKm, 3),
                'actual_duration_minutes' => (int)round((float)$actualDurationMin, 0),
                'waiting_minutes' => $waitingMinutes,
                'waiting_charge' => $waitingCharge,
                'wallet_settlement' => $settlement
            ]);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->respond(500, ['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    public function completeRideFromBody(): void
    {
        $data = $this->jsonBody();
        $rideId = trim((string)($data['ride_id'] ?? ''));
        if ($rideId === '') {
            $this->respond(422, ['status' => 'error', 'message' => 'ride_id is required']);
            return;
        }
        $this->completeRide($rideId);
    }

    public function cancelRide(string $rideId): void
    {
        $auth = ApiAuth::tokenRow();

        $data = $this->jsonBody();
        $reason = trim((string)($data['reason'] ?? 'Driver cancelled'));

        // Driver cancellation toggle (enabled by default).
        $allowDriverCancelRaw = $_ENV['ALLOW_DRIVER_CANCEL'] ?? getenv('ALLOW_DRIVER_CANCEL');
        if ($allowDriverCancelRaw === false || $allowDriverCancelRaw === null || $allowDriverCancelRaw === '') {
            $allowDriverCancelRaw = '1';
        }
        $allowDriverCancel = filter_var((string)$allowDriverCancelRaw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($allowDriverCancel === null) {
            $allowDriverCancel = true;
        }
        if (!$allowDriverCancel) {
            $this->respond(422, [
                'status' => 'error',
                'message' => 'Driver cancellation is disabled. Ask customer to cancel from customer app.'
            ]);
            return;
        }

        $pdo = Mysql::connection();
        $pdo->beginTransaction();
        try {
            $latCol = $this->columnExists($pdo, 'drivers', 'current_lat') ? 'd.current_lat' : 'd.latitude';
            $lngCol = $this->columnExists($pdo, 'drivers', 'current_lng') ? 'd.current_lng' : 'd.longitude';

            $rideStmt = $pdo->prepare('SELECT r.id, r.customer_id, r.status, r.drop_lat, r.drop_lng,
                    ' . ($this->columnExists($pdo, 'rides', 'ride_started_at') ? 'r.ride_started_at' : 'NULL') . ' AS ride_started_at,
                    ' . ($this->columnExists($pdo, 'rides', 'started_at') ? 'r.started_at' : 'NULL') . ' AS started_at,
                    d.id AS driver_id, ' . $latCol . ' AS driver_lat, ' . $lngCol . ' AS driver_lng
                FROM rides r
                JOIN drivers d ON d.id = r.driver_id
                WHERE r.id = ?
                  AND d.id = ?
                  AND (r.status IN ("driver_assigned","driver_arrived","waiting","accepted","assigned","arrived","driver_arriving","enroute","ride_started","in_progress","") OR r.status IS NULL)
                LIMIT 1
                FOR UPDATE');
            $driverId = $this->resolveDriverId($pdo, $auth ?: []);
            if ($driverId === null) {
                $pdo->rollBack();
                $this->respond(404, ['status' => 'error', 'message' => 'Driver not found']);
                return;
            }
            $rideStmt->execute([$this->toIdBinary($rideId), $driverId]);
            $ride = $rideStmt->fetch(\PDO::FETCH_ASSOC);
            if (!$ride) {
                $pdo->rollBack();
                $this->respond(409, ['status' => 'error', 'message' => 'Ride cannot be cancelled']);
                return;
            }

            $status = strtolower((string)($ride['status'] ?? ''));
            // Strict rule: once ride has started, cancel is not allowed.
            $hasStartedMarker = !empty($ride['ride_started_at']) || !empty($ride['started_at']);
            if (in_array($status, ['ride_started', 'in_progress', 'enroute'], true) || $hasStartedMarker) {
                $pdo->rollBack();
                $this->respond(422, [
                    'status' => 'error',
                    'message' => 'Ride already started. Reach drop and use End Ride.'
                ]);
                return;
            }

            $dailyCancelLimitRaw = $_ENV['DRIVER_DAILY_CANCEL_LIMIT'] ?? getenv('DRIVER_DAILY_CANCEL_LIMIT') ?? '5';
            $dailyCancelLimit = is_numeric($dailyCancelLimitRaw) ? max(1, (int)$dailyCancelLimitRaw) : 5;
            $todayCancelCount = $this->countDriverCancelledRidesToday($pdo, $driverId);
            if ($todayCancelCount >= $dailyCancelLimit) {
                $pdo->rollBack();
                $this->respond(429, [
                    'status' => 'error',
                    'message' => 'Daily cancel limit reached. You can cancel up to ' . $dailyCancelLimit . ' requests per day.'
                ]);
                return;
            }

            $rideSetParts = ['status = "cancelled"'];
            $rideParams = [];
            if ($this->columnExists($pdo, 'rides', 'cancelled_at')) {
                $rideSetParts[] = 'cancelled_at = NOW()';
            }
            if ($this->columnExists($pdo, 'rides', 'cancelled_by')) {
                $rideSetParts[] = 'cancelled_by = "driver"';
            }
            if ($this->columnExists($pdo, 'rides', 'cancel_reason')) {
                $rideSetParts[] = 'cancel_reason = ?';
                $rideParams[] = $reason;
            }
            $rideParams[] = $ride['id'];
            $stmt = $pdo->prepare('UPDATE rides SET ' . implode(', ', $rideSetParts) . ' WHERE id = ?');
            $stmt->execute($rideParams);
            $verifyStmt = $pdo->prepare('SELECT status FROM rides WHERE id = ? LIMIT 1');
            $verifyStmt->execute([$ride['id']]);
            $updatedRide = $verifyStmt->fetch(\PDO::FETCH_ASSOC) ?: [];
            if (strtolower((string)($updatedRide['status'] ?? '')) !== 'cancelled') {
                throw new \RuntimeException('Failed to update ride status to cancelled');
            }

            $isProd = $this->isProductionEnv();
            $setParts = [];
            $params = [];
            $availCol = $this->columnExists($pdo, 'drivers', 'is_available')
                ? 'is_available'
                : ($this->columnExists($pdo, 'drivers', 'availability') ? 'availability' : null);
            if ($availCol !== null) {
                $setParts[] = $availCol . ' = ?';
                $params[] = $isProd ? 0 : 1;
            }
            if ($this->columnExists($pdo, 'drivers', 'penalty_until')) {
                $setParts[] = $isProd ? 'penalty_until = DATE_ADD(NOW(), INTERVAL 15 MINUTE)' : 'penalty_until = NULL';
            }
            if ($this->columnExists($pdo, 'drivers', 'current_ride_id')) {
                $setParts[] = 'current_ride_id = NULL';
            }
            if (!empty($setParts)) {
                $params[] = $driverId;
                $pdo->prepare('UPDATE drivers SET ' . implode(', ', $setParts) . ' WHERE id = ?')
                    ->execute($params);
            }

            $this->insertStatusHistory($pdo, $this->toIdBinary($rideId), 'cancelled', 'driver', null, $reason);

            // Notify customer when driver cancels so customer app can show popup and go Home.
            $customerToken = '';
            if ($this->tableExists($pdo, 'customers') && $this->columnExists($pdo, 'customers', 'fcm_token')) {
                $custStmt = $pdo->prepare('SELECT fcm_token FROM customers WHERE id = ? LIMIT 1');
                $custStmt->execute([$ride['customer_id'] ?? null]);
                $customerToken = trim((string)(($custStmt->fetch(\PDO::FETCH_ASSOC) ?: [])['fcm_token'] ?? ''));
            }
            if ($customerToken === '' && $this->tableExists($pdo, 'users') && $this->columnExists($pdo, 'users', 'fcm_token')) {
                $custStmt = $pdo->prepare('SELECT fcm_token FROM users WHERE id = ? LIMIT 1');
                $custStmt->execute([$ride['customer_id'] ?? null]);
                $customerToken = trim((string)(($custStmt->fetch(\PDO::FETCH_ASSOC) ?: [])['fcm_token'] ?? ''));
            }
            if ($customerToken !== '') {
                $fcm = new \App\Services\FcmService();
                $fcm->sendToTokens(
                    [$customerToken],
                    [
                        'title' => 'Ride Cancelled',
                        'body' => 'Your ride request was cancelled by driver.'
                    ],
                    [
                        'type' => 'ride_cancelled',
                        'ride_id' => (string)$this->normalizeId($ride['id'] ?? $this->toIdBinary($rideId)),
                        'cancelled_by' => 'driver',
                        'reason' => $reason
                    ],
                    [
                        'android' => [
                            'priority' => 'HIGH',
                            'ttl' => '120s',
                            'notification' => [
                                'channel_id' => 'ride_updates',
                                'tag' => 'ride_cancel_driver_' . (string)$this->normalizeId($ride['id'] ?? $this->toIdBinary($rideId))
                            ]
                        ]
                    ]
                );
            }

            $pdo->commit();
            $this->respond(200, [
                'status' => 'ok',
                'message' => $isProd
                    ? 'Ride cancelled. Temporary penalty applied.'
                    : 'Ride cancelled.'
            ]);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->respond(500, ['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    private function isProductionEnv(): bool
    {
        $env = strtolower((string)($_ENV['NODE_ENV'] ?? getenv('NODE_ENV') ?? 'development'));
        return $env === 'production';
    }

    private function allowUnverifiedInDev(): bool
    {
        return !$this->isProductionEnv();
    }

    private function resolveDriverId(\PDO $pdo, array $auth): ?string
    {
        $subjectId = $auth['subject_id'] ?? null;
        if ($subjectId !== null && $subjectId !== '') {
            $stmt = $pdo->prepare('SELECT id FROM drivers WHERE id = ? LIMIT 1');
            $stmt->execute([$subjectId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($row && array_key_exists('id', $row)) {
                return (string) $row['id'];
            }
        }

        $phone = trim((string)($auth['phone'] ?? ''));
        if ($phone === '') {
            return null;
        }

        if ($this->columnExists($pdo, 'drivers', 'phone')) {
            $stmt = $pdo->prepare('SELECT id FROM drivers WHERE phone = ? LIMIT 1');
            $stmt->execute([$phone]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($row && array_key_exists('id', $row)) {
                return (string) $row['id'];
            }
        }

        if ($this->tableExists($pdo, 'users')
            && $this->columnExists($pdo, 'drivers', 'user_id')
            && $this->columnExists($pdo, 'users', 'phone')) {
            $stmt = $pdo->prepare('SELECT d.id FROM drivers d JOIN users u ON u.id = d.user_id WHERE u.phone = ? LIMIT 1');
            $stmt->execute([$phone]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($row && array_key_exists('id', $row)) {
                return (string) $row['id'];
            }
        }

        return null;
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
        if ($value > 20.0) {
            return 20.0;
        }
        return $value;
    }

    private function countDriverCancelledRidesToday(\PDO $pdo, string $driverId): int
    {
        $dateColumn = null;
        foreach (['cancelled_at', 'updated_at', 'modified_at', 'created_at'] as $col) {
            if ($this->columnExists($pdo, 'rides', $col)) {
                $dateColumn = $col;
                break;
            }
        }

        $where = ['driver_id = ?', 'status = "cancelled"'];
        $params = [$driverId];
        if ($this->columnExists($pdo, 'rides', 'cancelled_by')) {
            $where[] = 'cancelled_by = "driver"';
        }
        if ($dateColumn !== null) {
            $where[] = 'DATE(' . $dateColumn . ') = CURDATE()';
        }
        $stmt = $pdo->prepare('SELECT COUNT(*) AS c FROM rides WHERE ' . implode(' AND ', $where));
        $stmt->execute($params);
        $count = (int)(($stmt->fetch(\PDO::FETCH_ASSOC) ?: [])['c'] ?? 0);
        if ($count > 0 || $dateColumn !== null || !$this->tableExists($pdo, 'ride_status_history')) {
            return $count;
        }

        if (!$this->columnExists($pdo, 'ride_status_history', 'created_at')) {
            return $count;
        }
        $histStmt = $pdo->prepare('
            SELECT COUNT(*) AS c
            FROM ride_status_history
            WHERE changed_by_role = "driver"
              AND changed_by_id = ?
              AND status = "cancelled"
              AND DATE(created_at) = CURDATE()
        ');
        $histStmt->execute([$driverId]);
        return (int)(($histStmt->fetch(\PDO::FETCH_ASSOC) ?: [])['c'] ?? 0);
    }

    private function insertStatusHistory(\PDO $pdo, $rideId, string $status, string $role, $actorId, string $note = null): void
    {
        if (!$this->tableExists($pdo, 'ride_status_history')) {
            return;
        }
        $stmt = $pdo->prepare('INSERT INTO ride_status_history (ride_id, status, changed_by_role, changed_by_id, note) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$rideId, $status, $role, $actorId, $note]);
    }

    private function jsonBody(): array
    {
        $body = file_get_contents('php://input');
        $data = json_decode($body ?: '', true);
        return is_array($data) ? $data : [];
    }

    private function respond(int $status, array $payload): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($payload);
    }

    private function normalizeId($id)
    {
        if (is_string($id) && strlen($id) === 16) {
            return Uuid::toString($id);
        }
        return $id;
    }

    private function toIdBinary($id)
    {
        if (is_string($id) && preg_match('/^[0-9a-f\-]{36}$/i', $id)) {
            return Uuid::fromString($id);
        }
        return $id;
    }

    private function fetchFallbackSearchingRides(\PDO $pdo, array $driver): array
    {
        $latCol = $this->columnExists($pdo, 'drivers', 'current_lat') ? 'current_lat' : 'latitude';
        $lngCol = $this->columnExists($pdo, 'drivers', 'current_lng') ? 'current_lng' : 'longitude';

        $driverLocStmt = $pdo->prepare('SELECT ' . $latCol . ' AS lat, ' . $lngCol . ' AS lng FROM drivers WHERE id = ? LIMIT 1');
        $driverLocStmt->execute([$driver['id']]);
        $driverLoc = $driverLocStmt->fetch(\PDO::FETCH_ASSOC);
        if (!$driverLoc || !is_numeric($driverLoc['lat'] ?? null) || !is_numeric($driverLoc['lng'] ?? null)) {
            return [];
        }

        $pickupAddrExpr = $this->columnExists($pdo, 'rides', 'pickup_address')
            ? 'r.pickup_address'
            : 'CONCAT("Pickup (", r.pickup_lat, ", ", r.pickup_lng, ")")';
        $dropAddrExpr = $this->columnExists($pdo, 'rides', 'drop_address')
            ? 'r.drop_address'
            : 'CONCAT("Drop (", r.drop_lat, ", ", r.drop_lng, ")")';
        $durationExpr = $this->columnExists($pdo, 'rides', 'duration_min') ? 'r.duration_min' : '0';
        $earningExpr = $this->columnExists($pdo, 'rides', 'driver_earning')
            ? 'r.driver_earning'
            : ($this->columnExists($pdo, 'rides', 'driver_profit') ? 'r.driver_profit' : '0');
        $driverVehicleType = (string)($driver['vehicle_type'] ?? '');
        $rideHasVehicleType = $this->columnExists($pdo, 'rides', 'vehicle_type');

        $sql = 'SELECT r.id,
                ' . $pickupAddrExpr . ' AS pickup_address,
                ' . $dropAddrExpr . ' AS drop_address,
                (6371 * acos(
                    cos(radians(:lat)) * cos(radians(r.pickup_lat)) * cos(radians(r.pickup_lng) - radians(:lng))
                    + sin(radians(:lat)) * sin(radians(r.pickup_lat))
                )) AS distance_km,
                ' . $durationExpr . ' AS duration_min,
                ' . $earningExpr . ' AS driver_earning,
                r.pickup_lat, r.pickup_lng, r.drop_lat, r.drop_lng
            FROM rides r
            WHERE r.status IN ("searching","requested","")
              AND (r.driver_id IS NULL OR r.driver_id = "")'
            . ($rideHasVehicleType && $driverVehicleType !== '' ? ' AND r.vehicle_type = :vehicle_type' : '')
            . ' HAVING distance_km <= ' . $this->matchRadiusKm() . '
            ORDER BY distance_km ASC
            LIMIT 3';

        $stmt = $pdo->prepare($sql);
        $params = [
            'lat' => (float)$driverLoc['lat'],
            'lng' => (float)$driverLoc['lng']
        ];
        if ($rideHasVehicleType && $driverVehicleType !== '') {
            $params['vehicle_type'] = $driverVehicleType;
        }
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['expires_in_sec'] = self::REQUEST_EXPIRES_SECONDS;
        }
        unset($row);
        return $rows;
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
        $type = (string)($stmt->fetchColumn() ?: '');
        self::$schemaCache[$key] = $type;
        return $type;
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
        foreach ($candidates as $column) {
            if ($this->columnExists($pdo, $table, $column)) {
                return $column . ' AS ' . $alias;
            }
        }
        return $default . ' AS ' . $alias;
    }

    private function haversineMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) * sin($dLat / 2)
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) * sin($dLng / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return $earthRadius * $c;
    }
}









