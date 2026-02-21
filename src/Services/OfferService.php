<?php

declare(strict_types=1);

namespace App\Services;

final class OfferService
{
    private array $schemaCache = [];

    public function listOffers(
        \PDO $pdo,
        float $fare,
        string $paymentMode = 'cash',
        string $vehicleType = '',
        $customerId = null
    ): array {
        if (!$this->tableExists($pdo, 'offers')) {
            return [];
        }

        $paymentMode = $this->normalizePaymentMode($paymentMode);
        $vehicleType = strtolower(trim($vehicleType));
        $isNewCustomer = $this->isNewCustomer($pdo, $customerId);

        $sql = 'SELECT *
                FROM offers
                WHERE is_active = 1
                  AND (start_at IS NULL OR start_at <= NOW())
                  AND (end_at IS NULL OR end_at >= NOW())';
        $stmt = $pdo->query($sql);
        $rows = $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];

        $result = [];
        foreach ($rows as $row) {
            if (!$this->isOfferAllowedForContext($row, $paymentMode, $vehicleType, $isNewCustomer)) {
                continue;
            }
            $discount = $this->calculateDiscount($row, $fare);
            if ($discount <= 0) {
                continue;
            }
            $result[] = [
                'id' => (int)($row['id'] ?? 0),
                'code' => strtoupper((string)($row['code'] ?? '')),
                'title' => (string)($row['title'] ?? ''),
                'description' => (string)($row['description'] ?? ''),
                'discount_type' => strtolower((string)($row['discount_type'] ?? 'flat')),
                'discount_value' => (float)($row['discount_value'] ?? 0),
                'max_discount' => isset($row['max_discount']) ? (float)$row['max_discount'] : null,
                'min_fare' => (float)($row['min_fare'] ?? 0),
                'discount_amount' => $discount,
                'final_fare' => round(max(0.0, $fare - $discount), 2)
            ];
        }

        usort($result, static function (array $a, array $b): int {
            return ($b['discount_amount'] <=> $a['discount_amount']);
        });

        return $result;
    }

    public function applyCode(
        \PDO $pdo,
        string $code,
        float $fare,
        string $paymentMode = 'cash',
        string $vehicleType = '',
        $customerId = null
    ): array {
        if (!$this->tableExists($pdo, 'offers')) {
            throw new \RuntimeException('Offers module not available');
        }

        $normalizedCode = strtoupper(trim($code));
        if ($normalizedCode === '') {
            throw new \RuntimeException('Coupon code is required');
        }

        $stmt = $pdo->prepare(
            'SELECT *
             FROM offers
             WHERE UPPER(code) = ?
               AND is_active = 1
               AND (start_at IS NULL OR start_at <= NOW())
               AND (end_at IS NULL OR end_at >= NOW())
             LIMIT 1'
        );
        $stmt->execute([$normalizedCode]);
        $offer = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$offer) {
            throw new \RuntimeException('Invalid or expired coupon code');
        }

        $paymentMode = $this->normalizePaymentMode($paymentMode);
        $vehicleType = strtolower(trim($vehicleType));
        $isNewCustomer = $this->isNewCustomer($pdo, $customerId);
        if (!$this->isOfferAllowedForContext($offer, $paymentMode, $vehicleType, $isNewCustomer)) {
            throw new \RuntimeException('Coupon is not applicable for this trip');
        }

        $discount = $this->calculateDiscount($offer, $fare);
        if ($discount <= 0) {
            throw new \RuntimeException('Coupon is not applicable on current fare');
        }

        return [
            'id' => (int)($offer['id'] ?? 0),
            'code' => strtoupper((string)($offer['code'] ?? '')),
            'title' => (string)($offer['title'] ?? ''),
            'description' => (string)($offer['description'] ?? ''),
            'discount_type' => strtolower((string)($offer['discount_type'] ?? 'flat')),
            'discount_value' => (float)($offer['discount_value'] ?? 0),
            'max_discount' => isset($offer['max_discount']) ? (float)$offer['max_discount'] : null,
            'discount_amount' => $discount,
            'final_fare' => round(max(0.0, $fare - $discount), 2)
        ];
    }

    private function calculateDiscount(array $offer, float $fare): float
    {
        $base = round(max(0.0, $fare), 2);
        $minFare = (float)($offer['min_fare'] ?? 0);
        if ($base < $minFare) {
            return 0.0;
        }

        $type = strtolower((string)($offer['discount_type'] ?? 'flat'));
        $value = (float)($offer['discount_value'] ?? 0);
        if ($value <= 0) {
            return 0.0;
        }

        $discount = 0.0;
        if ($type === 'percent') {
            $discount = round(($base * $value) / 100.0, 2);
            $maxDiscount = isset($offer['max_discount']) ? (float)$offer['max_discount'] : 0.0;
            if ($maxDiscount > 0) {
                $discount = min($discount, $maxDiscount);
            }
        } else {
            $discount = round($value, 2);
        }

        return round(max(0.0, min($discount, $base)), 2);
    }

    private function normalizePaymentMode(string $value): string
    {
        $v = strtolower(trim($value));
        if ($v === 'online') {
            return 'online';
        }
        return 'cash';
    }

    private function isOfferAllowedForContext(array $offer, string $paymentMode, string $vehicleType, bool $isNewCustomer): bool
    {
        $offerPaymentMode = strtolower(trim((string)($offer['payment_mode'] ?? 'any')));
        if ($offerPaymentMode !== 'any' && $offerPaymentMode !== $paymentMode) {
            return false;
        }

        $offerVehicleType = strtolower(trim((string)($offer['vehicle_type'] ?? '')));
        if ($offerVehicleType !== '' && $offerVehicleType !== $vehicleType) {
            return false;
        }

        $newUserOnly = (int)($offer['new_user_only'] ?? 0) === 1;
        if ($newUserOnly && !$isNewCustomer) {
            return false;
        }

        return true;
    }

    private function isNewCustomer(\PDO $pdo, $customerId): bool
    {
        if ($customerId === null || $customerId === '') {
            return false;
        }
        if (!$this->tableExists($pdo, 'rides')) {
            return false;
        }
        if (!$this->columnExists($pdo, 'rides', 'customer_id')) {
            return false;
        }

        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM rides
             WHERE customer_id = ?
               AND status IN ("ride_completed", "completed", "ride_closed")'
        );
        $stmt->execute([$customerId]);
        $count = (int)$stmt->fetchColumn();
        return $count === 0;
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
}
