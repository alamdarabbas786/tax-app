<?php

namespace App\Controllers;

use App\Db\Mysql;
use App\Utils\Uuid;

class AuthController
{
    private static array $schemaCache = [];

    public function checkMobile(): void
    {
        $data = $this->jsonBody();
        $phone = trim((string)($data['phone'] ?? ''));

        if ($phone === '') {
            $this->respond(422, ['status' => 'error', 'message' => 'Invalid phone']);
            return;
        }

        $pdo = Mysql::connection();
        $row = $this->findCustomerByPhone($pdo, $phone);

        $this->respond(200, ['exists' => $row ? true : false]);
    }

    public function sendOtp(): void
    {
        $data = $this->jsonBody();
        $phone = trim((string)($data['phone'] ?? ''));

        if ($phone === '') {
            $this->respond(422, ['status' => 'error', 'message' => 'Invalid phone']);
            return;
        }

        $otp = '1234';
        $hash = password_hash($otp, PASSWORD_DEFAULT);
        $expiresAt = (new \DateTime('+10 minutes'))->format('Y-m-d H:i:s');

        $pdo = Mysql::connection();
        $this->ensureAuthTables($pdo);
        $stmt = $pdo->prepare('INSERT INTO auth_otps (id, phone, role, otp_hash, expires_at) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE otp_hash=VALUES(otp_hash), expires_at=VALUES(expires_at)');
        $stmt->execute([Uuid::v4Binary(), $phone, 'customer', $hash, $expiresAt]);

        $this->respond(200, ['status' => 'ok', 'success' => true]);
    }

    public function verifyOtpSimple(): void
    {
        $data = $this->jsonBody();
        $phone = trim((string)($data['phone'] ?? ''));
        $otp = trim((string)($data['otp'] ?? ''));

        if ($phone === '' || $otp === '') {
            $this->respond(422, ['status' => 'error', 'message' => 'Invalid phone or otp']);
            return;
        }

        $pdo = Mysql::connection();
        $this->ensureAuthTables($pdo);
        $otpStmt = $pdo->prepare('SELECT otp_hash, expires_at FROM auth_otps WHERE phone = ? AND role = ? LIMIT 1');
        $otpStmt->execute([$phone, 'customer']);
        $otpRow = $otpStmt->fetch(\PDO::FETCH_ASSOC);
        if (!$otpRow && !$this->allowMockOtpBypass($otp)) {
            $this->respond(401, ['status' => 'error', 'message' => 'OTP not found']);
            return;
        }

        if (!$this->allowMockOtpBypass($otp)) {
            $now = new \DateTime();
            if ($now > new \DateTime($otpRow['expires_at'])) {
                $this->respond(401, ['status' => 'error', 'message' => 'OTP expired']);
                return;
            }
            if (!password_verify($otp, $otpRow['otp_hash'])) {
                $this->respond(401, ['status' => 'error', 'message' => 'Invalid OTP']);
                return;
            }
        }

        $row = $this->findCustomerByPhone($pdo, $phone);
        $userExists = $row ? true : false;

        $del = $pdo->prepare('DELETE FROM auth_otps WHERE phone = ? AND role = ?');
        $del->execute([$phone, 'customer']);

        $this->respond(200, ['status' => 'ok', 'user_exists' => $userExists]);
    }

    public function registerCustomer(): void
    {
        $data = $this->jsonBody();
        $fullName = trim((string)($data['full_name'] ?? ''));
        $phone = trim((string)($data['phone'] ?? ''));
        $email = trim((string)($data['email'] ?? ''));
        $city = trim((string)($data['city'] ?? ''));
        $pinCode = trim((string)($data['pin_code'] ?? ''));

        if ($fullName === '' || $phone === '' || $email === '' || $city === '' || $pinCode === '') {
            $this->respond(422, ['status' => 'error', 'message' => 'Missing required fields']);
            return;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->respond(422, ['status' => 'error', 'message' => 'Invalid email']);
            return;
        }
        if (!preg_match('/^\d{6}$/', $pinCode)) {
            $this->respond(422, ['status' => 'error', 'message' => 'Invalid pin code']);
            return;
        }

        $pdo = Mysql::connection();
        $existing = $this->findCustomerByPhone($pdo, $phone);
        if ($existing) {
            $this->respond(409, ['status' => 'error', 'message' => 'Customer already exists']);
            return;
        }
        $this->insertCustomer($pdo, Uuid::v4Binary(), $phone, $fullName, $email);

        $this->respond(200, ['status' => 'ok', 'success' => true]);
    }

    public function requestOtp(): void
    {
        $data = $this->jsonBody();
        $phone = trim((string)($data['phone'] ?? ''));
        $role = trim((string)($data['role'] ?? 'customer'));

        if ($phone === '') {
            $this->respond(422, ['status' => 'error', 'message' => 'Invalid phone']);
            return;
        }
        if (!in_array($role, ['customer','driver','admin'], true)) {
            $this->respond(422, ['status' => 'error', 'message' => 'Invalid role']);
            return;
        }

        $pdo = Mysql::connection();
        $this->ensureAuthTables($pdo);
        $now = (new \DateTime())->format('Y-m-d H:i:s');

        if ($role === 'driver') {
            $driver = $this->findDriverByPhone($pdo, $phone);
            if (!$driver) {
                $this->respond(200, [
                    'status' => 'ok',
                    'needs_registration' => true,
                    'otp_required' => false,
                    'server_time' => $now
                ]);
                return;
            }
        }

        $otp = '1234';
        $hash = password_hash($otp, PASSWORD_DEFAULT);
        $expiresAt = (new \DateTime('+10 minutes'))->format('Y-m-d H:i:s');
        $stmt = $pdo->prepare('INSERT INTO auth_otps (id, phone, role, otp_hash, expires_at) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE otp_hash=VALUES(otp_hash), expires_at=VALUES(expires_at)');
        $stmt->execute([Uuid::v4Binary(), $phone, $role, $hash, $expiresAt]);
        $this->respond(200, [
            'status' => 'ok',
            'otp' => $otp,
            'needs_registration' => false,
            'otp_required' => true,
            'expires_in_seconds' => 600,
            'expires_at' => $expiresAt,
            'server_time' => $now
        ]);
    }

    public function verifyOtp(): void
    {
        $data = $this->jsonBody();
        $phone = trim((string)($data['phone'] ?? ''));
        $otp = trim((string)($data['otp'] ?? ''));
        $role = trim((string)($data['role'] ?? 'customer'));
        $fullName = trim((string)($data['full_name'] ?? 'User'));
        $email = $data['email'] ?? null;

        if ($phone === '' || $otp === '') {
            $this->respond(422, ['status' => 'error', 'message' => 'Invalid phone or otp']);
            return;
        }
        if (!in_array($role, ['customer','driver','admin'], true)) {
            $this->respond(422, ['status' => 'error', 'message' => 'Invalid role']);
            return;
        }
        if (is_string($email) && trim($email) !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->respond(422, ['status' => 'error', 'message' => 'Invalid email']);
            return;
        }

        $pdo = Mysql::connection();
        $this->ensureAuthTables($pdo);
        $otpStmt = $pdo->prepare('SELECT otp_hash, expires_at FROM auth_otps WHERE phone = ? AND role = ? LIMIT 1');
        $otpStmt->execute([$phone, $role]);
        $otpRow = $otpStmt->fetch(\PDO::FETCH_ASSOC);
        if (!$otpRow && !$this->allowMockOtpBypass($otp)) {
            $this->respond(401, ['status' => 'error', 'message' => 'OTP not found']);
            return;
        }

        if (!$this->allowMockOtpBypass($otp)) {
            $now = new \DateTime();
            if ($now > new \DateTime($otpRow['expires_at'])) {
                $this->respond(401, ['status' => 'error', 'message' => 'OTP expired']);
                return;
            }
            if (!password_verify($otp, $otpRow['otp_hash'])) {
                $this->respond(401, ['status' => 'error', 'message' => 'Invalid OTP']);
                return;
            }
        }

        $pdo->beginTransaction();
        try {
            $subjectId = null;
            $needsRegistration = false;

            if ($role === 'customer') {
                if ($this->ridesCustomerReferencesUsers($pdo)) {
                    $row = $this->findUserCustomerByPhone($pdo, $phone);
                    if (!$row) {
                        $subjectId = $this->insertUserCustomer($pdo, Uuid::v4Binary(), $phone, $fullName ?: 'Customer', $email);
                    } else {
                        $subjectId = $row['id'];
                    }
                } else {
                    $row = $this->findCustomerByPhone($pdo, $phone);
                    if (!$row) {
                        $subjectId = Uuid::v4Binary();
                        $this->insertCustomer($pdo, $subjectId, $phone, $fullName ?: 'Customer', $email);
                    } else {
                        $subjectId = $row['id'];
                    }
                }
            }

            if ($role === 'driver') {
                $row = $this->findDriverByPhone($pdo, $phone);
                if ($row) {
                    $subjectId = $row['id'];
                } else {
                    $needsRegistration = true;
                }
            }

            if ($role === 'admin') {
                $stmt = $pdo->prepare('SELECT id FROM admins WHERE phone = ? OR email = ? LIMIT 1');
                $stmt->execute([$phone, $email]);
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);
                if (!$row) {
                    $pdo->rollBack();
                    $this->respond(403, ['status' => 'error', 'message' => 'Admin not registered']);
                    return;
                }
                $subjectId = $row['id'];
            }

            $token = bin2hex(random_bytes(20));
            $expiresAt = (new \DateTime('+30 days'))->format('Y-m-d H:i:s');
            $insertToken = $pdo->prepare('INSERT INTO auth_tokens (id, role, subject_id, phone, token, expires_at) VALUES (?, ?, ?, ?, ?, ?)');
            $insertToken->execute([Uuid::v4Binary(), $role, $subjectId, $phone, $token, $expiresAt]);

            $del = $pdo->prepare('DELETE FROM auth_otps WHERE phone = ? AND role = ?');
            $del->execute([$phone, $role]);

            $pdo->commit();

            $this->respond(200, [
                'status' => 'ok',
                'token' => $token,
                'role' => $role,
                'subject_id' => $this->normalizeSubjectId($subjectId),
                'needs_registration' => $needsRegistration
            ]);
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

    private function respond(int $status, array $payload): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($payload);
    }

    private function allowMockOtpBypass(string $otp): bool
    {
        $env = strtolower((string)($_ENV['NODE_ENV'] ?? getenv('NODE_ENV') ?? 'development'));
        return $env !== 'production' && trim($otp) === '1234';
    }

    private function ensureAuthTables(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS auth_otps (
                id BINARY(16) PRIMARY KEY,
                phone VARCHAR(32) NOT NULL,
                role ENUM('customer','driver','admin') NOT NULL,
                otp_hash VARCHAR(255) NOT NULL,
                expires_at DATETIME NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_otps_phone_role (phone, role),
                INDEX idx_otps_expires (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS auth_tokens (
                id BINARY(16) PRIMARY KEY,
                role ENUM('customer','driver','admin') NOT NULL,
                subject_id VARBINARY(32) NULL,
                phone VARCHAR(32) NOT NULL,
                token VARCHAR(80) NOT NULL UNIQUE,
                expires_at DATETIME NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_tokens_subject (subject_id, role),
                INDEX idx_tokens_phone_role (phone, role)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    private function findDriverByPhone(\PDO $pdo, string $phone): ?array
    {
        if ($this->columnExists($pdo, 'drivers', 'phone')) {
            $stmt = $pdo->prepare('SELECT id FROM drivers WHERE phone = ? LIMIT 1');
            $stmt->execute([$phone]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($row) {
                return $row;
            }
        }

        if ($this->tableExists($pdo, 'users')
            && $this->columnExists($pdo, 'drivers', 'user_id')
            && $this->columnExists($pdo, 'users', 'phone')) {
            $stmt = $pdo->prepare('SELECT d.id FROM drivers d JOIN users u ON u.id = d.user_id WHERE u.phone = ? LIMIT 1');
            $stmt->execute([$phone]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            return $row ?: null;
        }

        return null;
    }

    private function findCustomerByPhone(\PDO $pdo, string $phone): ?array
    {
        if ($this->tableExists($pdo, 'customers') && $this->columnExists($pdo, 'customers', 'phone')) {
            $stmt = $pdo->prepare('SELECT id FROM customers WHERE phone = ? LIMIT 1');
            $stmt->execute([$phone]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            return $row ?: null;
        }
        if ($this->tableExists($pdo, 'users') && $this->columnExists($pdo, 'users', 'phone')) {
            $roleCond = $this->columnExists($pdo, 'users', 'role') ? ' AND role = "customer"' : '';
            $stmt = $pdo->prepare('SELECT id FROM users WHERE phone = ?' . $roleCond . ' LIMIT 1');
            $stmt->execute([$phone]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            return $row ?: null;
        }
        return null;
    }

    private function findUserCustomerByPhone(\PDO $pdo, string $phone): ?array
    {
        if (!$this->tableExists($pdo, 'users') || !$this->columnExists($pdo, 'users', 'phone')) {
            return null;
        }
        $roleCond = $this->columnExists($pdo, 'users', 'role') ? ' AND role = "customer"' : '';
        $stmt = $pdo->prepare('SELECT id FROM users WHERE phone = ?' . $roleCond . ' LIMIT 1');
        $stmt->execute([$phone]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function insertUserCustomer(\PDO $pdo, string $id, string $phone, string $fullName, ?string $email)
    {
        if (!$this->tableExists($pdo, 'users')) {
            return $id;
        }

        $idType = strtolower($this->columnType($pdo, 'users', 'id'));
        $idIsNumeric = strpos($idType, 'int') !== false || strpos($idType, 'decimal') !== false;
        $columns = [];
        $values = [];

        if (!$idIsNumeric && $this->columnExists($pdo, 'users', 'id')) {
            $columns[] = 'id';
            $values[] = $id;
        }
        if ($this->columnExists($pdo, 'users', 'role')) {
            $columns[] = 'role';
            $values[] = 'customer';
        }
        if ($this->columnExists($pdo, 'users', 'email')) {
            $columns[] = 'email';
            $values[] = (is_string($email) && trim($email) !== '') ? trim($email) : null;
        }
        if ($this->columnExists($pdo, 'users', 'phone')) {
            $columns[] = 'phone';
            $values[] = $phone;
        }
        if ($this->columnExists($pdo, 'users', 'full_name')) {
            $columns[] = 'full_name';
            $values[] = $fullName;
        } elseif ($this->columnExists($pdo, 'users', 'name')) {
            $columns[] = 'name';
            $values[] = $fullName;
        }
        if ($this->columnExists($pdo, 'users', 'is_active')) {
            $columns[] = 'is_active';
            $values[] = 1;
        }

        if (!empty($columns)) {
            $placeholders = implode(', ', array_fill(0, count($columns), '?'));
            $sql = 'INSERT INTO users (' . implode(', ', $columns) . ') VALUES (' . $placeholders . ')';
            $insert = $pdo->prepare($sql);
            $insert->execute($values);
        }

        if ($idIsNumeric) {
            $insertedId = $pdo->lastInsertId();
            if ($insertedId !== '') {
                return $insertedId;
            }
            $row = $this->findUserCustomerByPhone($pdo, $phone);
            if ($row && isset($row['id'])) {
                return $row['id'];
            }
        }

        return $id;
    }

    private function ridesCustomerReferencesUsers(\PDO $pdo): bool
    {
        if (!$this->tableExists($pdo, 'rides') || !$this->columnExists($pdo, 'rides', 'customer_id')) {
            return false;
        }
        $key = 'fk:rides:customer_id:reftable';
        if (array_key_exists($key, self::$schemaCache)) {
            return self::$schemaCache[$key] === 'users';
        }
        $stmt = $pdo->prepare('SELECT REFERENCED_TABLE_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = "rides"
              AND COLUMN_NAME = "customer_id"
              AND REFERENCED_TABLE_NAME IS NOT NULL
            LIMIT 1');
        $stmt->execute();
        $ref = strtolower((string)($stmt->fetchColumn() ?: ''));
        self::$schemaCache[$key] = $ref;
        return $ref === 'users';
    }

    private function insertCustomer(\PDO $pdo, string $id, string $phone, string $fullName, ?string $email): void
    {
        if ($this->tableExists($pdo, 'customers')) {
            $columns = [];
            $values = [];

            if ($this->columnExists($pdo, 'customers', 'id')) {
                $idType = strtolower($this->columnType($pdo, 'customers', 'id'));
                $idIsNumeric = strpos($idType, 'int') !== false || strpos($idType, 'decimal') !== false;
                if (!$idIsNumeric) {
                    $columns[] = 'id';
                    $values[] = $id;
                }
            }

            if ($this->columnExists($pdo, 'customers', 'phone')) {
                $columns[] = 'phone';
                $values[] = $phone;
            }

            if ($this->columnExists($pdo, 'customers', 'full_name')) {
                $columns[] = 'full_name';
                $values[] = $fullName;
            } elseif ($this->columnExists($pdo, 'customers', 'name')) {
                $columns[] = 'name';
                $values[] = $fullName;
            }

            if ($this->columnExists($pdo, 'customers', 'email')) {
                $columns[] = 'email';
                $values[] = (is_string($email) && trim($email) !== '') ? trim($email) : null;
            }

            if (!empty($columns)) {
                $placeholders = implode(', ', array_fill(0, count($columns), '?'));
                $sql = 'INSERT INTO customers (' . implode(', ', $columns) . ') VALUES (' . $placeholders . ')';
                $insert = $pdo->prepare($sql);
                $insert->execute($values);
            }
            return;
        }

        if ($this->tableExists($pdo, 'users')) {
            $idType = strtolower($this->columnType($pdo, 'users', 'id'));
            $idIsNumeric = strpos($idType, 'int') !== false || strpos($idType, 'decimal') !== false;
            $columns = [];
            $values = [];

            if (!$idIsNumeric && $this->columnExists($pdo, 'users', 'id')) {
                $columns[] = 'id';
                $values[] = $id;
            }

            if ($this->columnExists($pdo, 'users', 'role')) {
                $columns[] = 'role';
                $values[] = 'customer';
            }

            if ($this->columnExists($pdo, 'users', 'email')) {
                $columns[] = 'email';
                $values[] = (is_string($email) && trim($email) !== '') ? trim($email) : null;
            }

            if ($this->columnExists($pdo, 'users', 'phone')) {
                $columns[] = 'phone';
                $values[] = $phone;
            }

            if ($this->columnExists($pdo, 'users', 'full_name')) {
                $columns[] = 'full_name';
                $values[] = $fullName;
            } elseif ($this->columnExists($pdo, 'users', 'name')) {
                $columns[] = 'name';
                $values[] = $fullName;
            }

            if ($this->columnExists($pdo, 'users', 'is_active')) {
                $columns[] = 'is_active';
                $values[] = 1;
            }

            if (!empty($columns)) {
                $placeholders = implode(', ', array_fill(0, count($columns), '?'));
                $sql = 'INSERT INTO users (' . implode(', ', $columns) . ') VALUES (' . $placeholders . ')';
                $insert = $pdo->prepare($sql);
                $insert->execute($values);
            }
        }
    }

    private function columnType(\PDO $pdo, string $table, string $column): string
    {
        $cacheKey = 'type:' . strtolower($table) . ':' . strtolower($column);
        if (array_key_exists($cacheKey, self::$schemaCache)) {
            return (string) self::$schemaCache[$cacheKey];
        }
        $stmt = $pdo->prepare('SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1');
        $stmt->execute([$table, $column]);
        $type = (string)($stmt->fetchColumn() ?: '');
        self::$schemaCache[$cacheKey] = $type;
        return $type;
    }

    private function tableExists(\PDO $pdo, string $table): bool
    {
        $key = 'table:' . strtolower($table);
        if (array_key_exists($key, self::$schemaCache)) {
            return self::$schemaCache[$key];
        }
        $stmt = $pdo->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1');
        $stmt->execute([$table]);
        $exists = (bool) $stmt->fetchColumn();
        self::$schemaCache[$key] = $exists;
        return $exists;
    }

    private function columnExists(\PDO $pdo, string $table, string $column): bool
    {
        $key = 'col:' . strtolower($table) . ':' . strtolower($column);
        if (array_key_exists($key, self::$schemaCache)) {
            return self::$schemaCache[$key];
        }
        $stmt = $pdo->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1');
        $stmt->execute([$table, $column]);
        $exists = (bool) $stmt->fetchColumn();
        self::$schemaCache[$key] = $exists;
        return $exists;
    }

    private function normalizeSubjectId($subjectId): ?string
    {
        if ($subjectId === null) {
            return null;
        }
        if (is_string($subjectId) && strlen($subjectId) === 16) {
            return Uuid::toString($subjectId);
        }
        return (string) $subjectId;
    }
}
