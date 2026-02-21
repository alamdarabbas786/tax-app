<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\ApiAuth;
use App\Db\Mysql;
use App\Services\WalletSettlementService;
use App\Utils\Uuid;

final class WalletController
{
    private WalletSettlementService $walletService;
    private array $schemaCache = [];

    public function __construct()
    {
        $this->walletService = new WalletSettlementService();
    }

    public function calculateCommission(): void
    {
        $auth = ApiAuth::requireAnyRole(['customer', 'driver', 'admin']);
        if (!$auth) {
            return;
        }

        $data = $this->jsonBody();
        $fare = is_numeric($data['total_fare'] ?? null) ? (float)$data['total_fare'] : 0.0;
        $rate = is_numeric($data['commission_rate_percent'] ?? null) ? (float)$data['commission_rate_percent'] : null;
        $paymentMode = $this->walletService->normalizePaymentMode((string)($data['payment_mode'] ?? 'cash'));

        $calc = $this->walletService->calculateCommission($fare, $rate);
        $this->respond(200, [
            'status' => 'ok',
            'payment_mode' => $paymentMode,
            ...$calc
        ]);
    }

    public function updateWallet(): void
    {
        $auth = ApiAuth::requireRole('admin');
        if (!$auth) {
            return;
        }

        $data = $this->jsonBody();
        $driverIdRaw = trim((string)($data['driver_id'] ?? ''));
        $type = strtolower(trim((string)($data['transaction_type'] ?? '')));
        $amount = is_numeric($data['amount'] ?? null) ? (float)$data['amount'] : 0.0;
        $description = trim((string)($data['description'] ?? 'Manual Wallet Adjustment'));
        $rideIdRaw = trim((string)($data['ride_id'] ?? ''));

        if ($driverIdRaw === '' || $amount <= 0 || !in_array($type, ['credit', 'debit'], true)) {
            $this->respond(422, ['status' => 'error', 'message' => 'driver_id, amount and transaction_type are required']);
            return;
        }

        $pdo = Mysql::connection();
        $pdo->beginTransaction();
        try {
            $driverId = $this->toIdValue($driverIdRaw);
            $rideId = $rideIdRaw !== '' ? $this->toIdValue($rideIdRaw) : null;

            $result = $this->walletService->createManualAdjustment(
                $pdo,
                $driverId,
                $type,
                $amount,
                $description,
                $rideId
            );
            $pdo->commit();
            $this->respond(200, ['status' => 'ok', 'message' => 'Wallet updated', 'adjustment' => $result]);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->respond(500, ['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    public function driverWalletSummary(): void
    {
        $auth = ApiAuth::requireAnyRole(['driver', 'admin']);
        if (!$auth) {
            return;
        }

        $driverIdRaw = '';
        if ($auth['role'] === 'admin') {
            $driverIdRaw = trim((string)($_GET['driver_id'] ?? ''));
        } else {
            $driverIdRaw = (string)($auth['subject_id'] ?? '');
        }
        if ($driverIdRaw === '') {
            $this->respond(422, ['status' => 'error', 'message' => 'driver_id required']);
            return;
        }

        $pdo = Mysql::connection();
        try {
            $driverId = $this->resolveDriverId($pdo, $driverIdRaw, $auth);
            if ($driverId === null) {
                $this->respond(404, ['status' => 'error', 'message' => 'Driver not found']);
                return;
            }
            $summary = $this->walletService->driverWalletSummary($pdo, $driverId);
            $summary['driver_id'] = $this->normalizeId($summary['driver_id']);
            $this->respond(200, ['status' => 'ok', 'wallet' => $summary]);
        } catch (\Throwable $e) {
            $this->respond(500, ['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    public function payoutDriver(): void
    {
        $auth = ApiAuth::requireRole('admin');
        if (!$auth) {
            return;
        }

        $data = $this->jsonBody();
        $driverIdRaw = trim((string)($data['driver_id'] ?? ''));
        $requestedAmount = is_numeric($data['amount'] ?? null) ? (float)$data['amount'] : null;
        $description = trim((string)($data['description'] ?? 'Driver payout to bank account'));
        if ($driverIdRaw === '') {
            $this->respond(422, ['status' => 'error', 'message' => 'driver_id required']);
            return;
        }

        $pdo = Mysql::connection();
        $pdo->beginTransaction();
        try {
            $driverId = $this->toIdValue($driverIdRaw);
            $summary = $this->walletService->driverWalletSummary($pdo, $driverId);
            $walletBalance = (float)($summary['wallet_balance'] ?? 0);
            if ($walletBalance <= 0) {
                $pdo->rollBack();
                $this->respond(422, ['status' => 'error', 'message' => 'No positive wallet balance for payout']);
                return;
            }

            $payoutAmount = $requestedAmount !== null && $requestedAmount > 0
                ? min($walletBalance, round($requestedAmount, 2))
                : $walletBalance;

            $adjustment = $this->walletService->createManualAdjustment(
                $pdo,
                $driverId,
                'debit',
                $payoutAmount,
                $description
            );

            $pdo->commit();
            $this->respond(200, [
                'status' => 'ok',
                'message' => 'Payout processed',
                'payout' => [
                    'driver_id' => $this->normalizeId($driverId),
                    'amount' => $payoutAmount,
                    'wallet_balance_after' => $adjustment['wallet_balance_after']
                ]
            ]);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->respond(500, ['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    private function resolveDriverId(\PDO $pdo, string $driverIdRaw, array $auth): mixed
    {
        $id = $this->toIdValue($driverIdRaw);
        $stmt = $pdo->prepare('SELECT id FROM drivers WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $existing = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($existing && array_key_exists('id', $existing)) {
            return $existing['id'];
        }

        $phone = trim((string)($auth['phone'] ?? ''));
        if ($phone !== '' && $this->columnExists($pdo, 'drivers', 'phone')) {
            $byPhone = $pdo->prepare('SELECT id FROM drivers WHERE phone = ? LIMIT 1');
            $byPhone->execute([$phone]);
            $row = $byPhone->fetch(\PDO::FETCH_ASSOC);
            if ($row && array_key_exists('id', $row)) {
                return $row['id'];
            }
        }
        return null;
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

    private function toIdValue(string $id): mixed
    {
        if (preg_match('/^[0-9a-f\-]{36}$/i', $id)) {
            return Uuid::fromString($id);
        }
        if (ctype_digit($id)) {
            return (int)$id;
        }
        return $id;
    }

    private function normalizeId($id): mixed
    {
        if (is_string($id) && strlen($id) === 16) {
            return Uuid::toString($id);
        }
        return $id;
    }

    private function columnExists(\PDO $pdo, string $table, string $column): bool
    {
        $key = 'col:' . $table . ':' . $column;
        if (array_key_exists($key, $this->schemaCache)) {
            return (bool)$this->schemaCache[$key];
        }
        $stmt = $pdo->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1');
        $stmt->execute([$table, $column]);
        $exists = (bool)$stmt->fetchColumn();
        $this->schemaCache[$key] = $exists;
        return $exists;
    }
}
