<?php

declare(strict_types=1);

namespace App\Services;

final class WalletSettlementService
{
    private array $schemaCache = [];

    public function commissionRatePercent(): float
    {
        $raw = $_ENV['COMMISSION_RATE_PERCENT'] ?? getenv('COMMISSION_RATE_PERCENT') ?? '20';
        if (!is_numeric($raw)) {
            return 20.0;
        }
        $value = (float)$raw;
        if ($value < 0) {
            return 0.0;
        }
        if ($value > 100) {
            return 100.0;
        }
        return $value;
    }

    public function calculateCommission(float $totalFare, ?float $ratePercent = null): array
    {
        $fare = max(0.0, round($totalFare, 2));
        $rate = $ratePercent === null ? $this->commissionRatePercent() : max(0.0, min(100.0, $ratePercent));
        $commission = round($fare * ($rate / 100.0), 2);
        $driverEarning = round(max(0.0, $fare - $commission), 2);

        return [
            'total_fare' => $fare,
            'commission_rate_percent' => $rate,
            'commission_amount' => $commission,
            'driver_earning' => $driverEarning
        ];
    }

    public function normalizePaymentMode(?string $value): string
    {
        $v = strtolower(trim((string)$value));
        if ($v === 'online') {
            return 'online';
        }
        return 'cash';
    }

    public function settleRide(
        \PDO $pdo,
        $rideId,
        $driverId,
        float $totalFare,
        string $paymentMode,
        ?float $ratePercent = null
    ): array {
        $paymentMode = $this->normalizePaymentMode($paymentMode);
        $calc = $this->calculateCommission($totalFare, $ratePercent);

        $rideLock = $pdo->prepare(
            'SELECT id, driver_id, status,
                    ' . $this->selectExpr($pdo, 'rides', ['wallet_settled'], 'wallet_settled', '0') . ',
                    ' . $this->selectExpr($pdo, 'rides', ['wallet_settled_at'], 'wallet_settled_at', 'NULL') . '
             FROM rides
             WHERE id = ?
             LIMIT 1
             FOR UPDATE'
        );
        $rideLock->execute([$rideId]);
        $ride = $rideLock->fetch(\PDO::FETCH_ASSOC);
        if (!$ride) {
            throw new \RuntimeException('Ride not found for settlement');
        }

        if ((string)$ride['driver_id'] !== (string)$driverId) {
            throw new \RuntimeException('Ride-driver mismatch for settlement');
        }

        $alreadySettled = ((int)($ride['wallet_settled'] ?? 0) === 1) || !empty($ride['wallet_settled_at']);
        if ($alreadySettled) {
            throw new \RuntimeException('Ride wallet already settled');
        }

        $driverLock = $pdo->prepare(
            'SELECT id,
                    ' . $this->selectExpr($pdo, 'drivers', ['wallet_balance'], 'wallet_balance', '0') . ',
                    ' . $this->selectExpr($pdo, 'drivers', ['total_earnings'], 'total_earnings', '0') . '
             FROM drivers
             WHERE id = ?
             LIMIT 1
             FOR UPDATE'
        );
        $driverLock->execute([$driverId]);
        $driver = $driverLock->fetch(\PDO::FETCH_ASSOC);
        if (!$driver) {
            throw new \RuntimeException('Driver not found for settlement');
        }

        $balanceBefore = round((float)($driver['wallet_balance'] ?? 0), 2);
        $delta = $paymentMode === 'online'
            ? (float)$calc['driver_earning']
            : (float)(-$calc['commission_amount']);
        $balanceAfter = round($balanceBefore + $delta, 2);

        $driverSet = [];
        $driverParams = [];
        if ($this->columnExists($pdo, 'drivers', 'wallet_balance')) {
            $driverSet[] = 'wallet_balance = ?';
            $driverParams[] = $balanceAfter;
        }
        if ($this->columnExists($pdo, 'drivers', 'total_earnings')) {
            $driverSet[] = 'total_earnings = ?';
            $driverParams[] = round((float)($driver['total_earnings'] ?? 0) + (float)$calc['driver_earning'], 2);
        }
        if (!empty($driverSet)) {
            $driverParams[] = $driverId;
            $pdo->prepare('UPDATE drivers SET ' . implode(', ', $driverSet) . ' WHERE id = ?')->execute($driverParams);
        }

        $rideSet = [];
        $rideParams = [];
        if ($this->columnExists($pdo, 'rides', 'payment_mode')) {
            $rideSet[] = 'payment_mode = ?';
            $rideParams[] = $paymentMode;
        }
        if ($this->columnExists($pdo, 'rides', 'commission_amount')) {
            $rideSet[] = 'commission_amount = ?';
            $rideParams[] = $calc['commission_amount'];
        }
        if ($this->columnExists($pdo, 'rides', 'total_fare')) {
            $rideSet[] = 'total_fare = ?';
            $rideParams[] = $calc['total_fare'];
        }
        if ($this->columnExists($pdo, 'rides', 'wallet_settled')) {
            $rideSet[] = 'wallet_settled = 1';
        }
        if ($this->columnExists($pdo, 'rides', 'wallet_settled_at')) {
            $rideSet[] = 'wallet_settled_at = NOW()';
        }
        if (!empty($rideSet)) {
            $rideParams[] = $rideId;
            $pdo->prepare('UPDATE rides SET ' . implode(', ', $rideSet) . ' WHERE id = ?')->execute($rideParams);
        }

        $transactionType = $paymentMode === 'online' ? 'credit' : 'debit';
        $transactionAmount = $paymentMode === 'online' ? $calc['driver_earning'] : $calc['commission_amount'];
        $description = $paymentMode === 'online'
            ? 'Ride Earnings After Commission'
            : 'Commission Deduction';

        if ($this->tableExists($pdo, 'wallet_transactions')) {
            $columns = ['driver_id', 'ride_id', 'transaction_type', 'amount', 'description'];
            $values = [$driverId, $rideId, $transactionType, $transactionAmount, $description];

            if ($this->columnExists($pdo, 'wallet_transactions', 'balance_before')) {
                $columns[] = 'balance_before';
                $values[] = $balanceBefore;
            }
            if ($this->columnExists($pdo, 'wallet_transactions', 'balance_after')) {
                $columns[] = 'balance_after';
                $values[] = $balanceAfter;
            }

            $placeholders = implode(', ', array_fill(0, count($columns), '?'));
            $pdo->prepare(
                'INSERT INTO wallet_transactions (' . implode(', ', $columns) . ') VALUES (' . $placeholders . ')'
            )->execute($values);
        }

        return [
            'payment_mode' => $paymentMode,
            'commission_rate_percent' => $calc['commission_rate_percent'],
            'commission_amount' => $calc['commission_amount'],
            'driver_earning' => $calc['driver_earning'],
            'wallet_delta' => round($delta, 2),
            'wallet_balance_before' => $balanceBefore,
            'wallet_balance_after' => $balanceAfter,
            'transaction_type' => $transactionType,
            'transaction_amount' => round((float)$transactionAmount, 2),
            'transaction_description' => $description
        ];
    }

    public function createManualAdjustment(
        \PDO $pdo,
        $driverId,
        string $transactionType,
        float $amount,
        string $description,
        $rideId = null
    ): array {
        $type = strtolower(trim($transactionType)) === 'debit' ? 'debit' : 'credit';
        $value = round(max(0.0, $amount), 2);

        $driverLock = $pdo->prepare(
            'SELECT id,
                    ' . $this->selectExpr($pdo, 'drivers', ['wallet_balance'], 'wallet_balance', '0') . '
             FROM drivers
             WHERE id = ?
             LIMIT 1
             FOR UPDATE'
        );
        $driverLock->execute([$driverId]);
        $driver = $driverLock->fetch(\PDO::FETCH_ASSOC);
        if (!$driver) {
            throw new \RuntimeException('Driver not found');
        }

        $before = round((float)($driver['wallet_balance'] ?? 0), 2);
        $delta = $type === 'credit' ? $value : -$value;
        $after = round($before + $delta, 2);

        if ($this->columnExists($pdo, 'drivers', 'wallet_balance')) {
            $pdo->prepare('UPDATE drivers SET wallet_balance = ? WHERE id = ?')->execute([$after, $driverId]);
        }

        if ($this->tableExists($pdo, 'wallet_transactions')) {
            $columns = ['driver_id', 'ride_id', 'transaction_type', 'amount', 'description'];
            $values = [$driverId, $rideId, $type, $value, $description];
            if ($this->columnExists($pdo, 'wallet_transactions', 'balance_before')) {
                $columns[] = 'balance_before';
                $values[] = $before;
            }
            if ($this->columnExists($pdo, 'wallet_transactions', 'balance_after')) {
                $columns[] = 'balance_after';
                $values[] = $after;
            }
            $placeholders = implode(', ', array_fill(0, count($columns), '?'));
            $pdo->prepare(
                'INSERT INTO wallet_transactions (' . implode(', ', $columns) . ') VALUES (' . $placeholders . ')'
            )->execute($values);
        }

        return [
            'driver_id' => $driverId,
            'transaction_type' => $type,
            'amount' => $value,
            'wallet_balance_before' => $before,
            'wallet_balance_after' => $after,
            'description' => $description
        ];
    }

    public function driverWalletSummary(\PDO $pdo, $driverId): array
    {
        $stmt = $pdo->prepare(
            'SELECT id,
                    ' . $this->selectExpr($pdo, 'drivers', ['wallet_balance'], 'wallet_balance', '0') . ',
                    ' . $this->selectExpr($pdo, 'drivers', ['total_earnings'], 'total_earnings', '0') . '
             FROM drivers
             WHERE id = ?
             LIMIT 1'
        );
        $stmt->execute([$driverId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            throw new \RuntimeException('Driver not found');
        }

        $stats = [
            'total_credits' => 0.0,
            'total_debits' => 0.0,
            'transactions_count' => 0
        ];
        if ($this->tableExists($pdo, 'wallet_transactions')) {
            $agg = $pdo->prepare(
                'SELECT
                    SUM(CASE WHEN transaction_type = "credit" THEN amount ELSE 0 END) AS total_credits,
                    SUM(CASE WHEN transaction_type = "debit" THEN amount ELSE 0 END) AS total_debits,
                    COUNT(*) AS transactions_count
                 FROM wallet_transactions
                 WHERE driver_id = ?'
            );
            $agg->execute([$driverId]);
            $s = $agg->fetch(\PDO::FETCH_ASSOC) ?: [];
            $stats = [
                'total_credits' => round((float)($s['total_credits'] ?? 0), 2),
                'total_debits' => round((float)($s['total_debits'] ?? 0), 2),
                'transactions_count' => (int)($s['transactions_count'] ?? 0)
            ];
        }

        return [
            'driver_id' => $driverId,
            'wallet_balance' => round((float)($row['wallet_balance'] ?? 0), 2),
            'total_earnings' => round((float)($row['total_earnings'] ?? 0), 2),
            ...$stats
        ];
    }

    private function tableExists(\PDO $pdo, string $table): bool
    {
        $key = 'table:' . $table;
        if (array_key_exists($key, $this->schemaCache)) {
            return (bool)$this->schemaCache[$key];
        }
        $stmt = $pdo->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1');
        $stmt->execute([$table]);
        $exists = (bool)$stmt->fetchColumn();
        $this->schemaCache[$key] = $exists;
        return $exists;
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

    private function selectExpr(\PDO $pdo, string $table, array $candidates, string $alias, string $default = 'NULL'): string
    {
        foreach ($candidates as $column) {
            if ($this->columnExists($pdo, $table, $column)) {
                return $column . ' AS ' . $alias;
            }
        }
        return $default . ' AS ' . $alias;
    }
}
