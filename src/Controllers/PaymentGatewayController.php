<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\ApiAuth;
use App\Db\Mysql;
use App\Services\RazorpayService;
use App\Utils\Uuid;

final class PaymentGatewayController
{
    public function createOrder(): void
    {
        $auth = ApiAuth::requireRole('customer');
        if (!$auth) {
            return;
        }

        $data = $this->jsonBody();
        $rideIdRaw = trim((string)($data['ride_id'] ?? ''));
        if ($rideIdRaw === '') {
            $this->respond(422, ['status' => 'error', 'message' => 'ride_id is required']);
            return;
        }

        $pdo = Mysql::connection();
        $ride = $this->fetchOwnedRide($pdo, $auth, $rideIdRaw);
        if (!$ride) {
            $this->respond(404, ['status' => 'error', 'message' => 'Ride not found']);
            return;
        }

        $amount = is_numeric($data['amount'] ?? null)
            ? (float)$data['amount']
            : (float)($ride['fare'] ?? $ride['total_fare'] ?? 0);
        if ($amount <= 0) {
            $this->respond(422, ['status' => 'error', 'message' => 'Invalid amount']);
            return;
        }
        $currency = strtoupper(trim((string)($data['currency'] ?? 'INR'))) ?: 'INR';

        $service = new RazorpayService();
        if (!$service->isConfigured()) {
            $this->respond(500, ['status' => 'error', 'message' => 'Razorpay is not configured']);
            return;
        }

        try {
            $rideIdText = $this->normalizeIdToString($ride['id']);
            $receipt = $this->buildRazorpayIdentifier('ride', $rideIdText, 40);
            $order = $service->createOrder(
                $amount,
                $currency,
                $receipt,
                ['ride_id' => $rideIdText]
            );
            $this->ensureGatewayPaymentsTable($pdo);
            $this->insertGatewayPayment(
                $pdo,
                $rideIdText,
                $this->normalizeIdToString($ride['customer_id'] ?? $auth['subject_id']),
                $amount,
                $currency,
                (string)($order['id'] ?? ''),
                null,
                null,
                null,
                'created',
                json_encode($order)
            );
            $this->respond(200, [
                'status' => 'ok',
                'gateway' => 'razorpay',
                'key_id' => $service->getPublicKey(),
                'order_id' => (string)($order['id'] ?? ''),
                'amount' => $amount,
                'currency' => $currency
            ]);
        } catch (\Throwable $e) {
            $this->respond(500, ['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    public function createPaymentLink(): void
    {
        $auth = ApiAuth::requireRole('customer');
        if (!$auth) {
            return;
        }
        $data = $this->jsonBody();
        $rideIdRaw = trim((string)($data['ride_id'] ?? ''));
        if ($rideIdRaw === '') {
            $this->respond(422, ['status' => 'error', 'message' => 'ride_id is required']);
            return;
        }

        $pdo = Mysql::connection();
        $ride = $this->fetchOwnedRide($pdo, $auth, $rideIdRaw);
        if (!$ride) {
            $this->respond(404, ['status' => 'error', 'message' => 'Ride not found']);
            return;
        }

        $amount = is_numeric($data['amount'] ?? null)
            ? (float)$data['amount']
            : (float)($ride['fare'] ?? $ride['total_fare'] ?? 0);
        if ($amount <= 0) {
            $this->respond(422, ['status' => 'error', 'message' => 'Invalid amount']);
            return;
        }
        $currency = strtoupper(trim((string)($data['currency'] ?? 'INR'))) ?: 'INR';
        $description = trim((string)($data['description'] ?? 'Taxi ride payment'));

        $service = new RazorpayService();
        if (!$service->isConfigured()) {
            $this->respond(500, ['status' => 'error', 'message' => 'Razorpay is not configured']);
            return;
        }

        try {
            $rideIdText = $this->normalizeIdToString($ride['id']);
            $referenceId = $this->buildRazorpayIdentifier('ride', $rideIdText, 40);
            $customer = $this->fetchCustomerContact($pdo, $ride['customer_id'] ?? $auth['subject_id']);
            $link = $service->createPaymentLink(
                $amount,
                $currency,
                $referenceId,
                $description,
                $customer
            );
            $this->ensureGatewayPaymentsTable($pdo);
            $this->insertGatewayPayment(
                $pdo,
                $rideIdText,
                $this->normalizeIdToString($ride['customer_id'] ?? $auth['subject_id']),
                $amount,
                $currency,
                (string)($link['order_id'] ?? ''),
                null,
                (string)($link['id'] ?? ''),
                (string)($link['short_url'] ?? $link['url'] ?? ''),
                'created',
                json_encode($link)
            );
            $this->respond(200, [
                'status' => 'ok',
                'gateway' => 'razorpay',
                'payment_link_id' => (string)($link['id'] ?? ''),
                'payment_url' => (string)($link['short_url'] ?? $link['url'] ?? ''),
                'amount' => $amount,
                'currency' => $currency
            ]);
        } catch (\Throwable $e) {
            $this->respond(500, ['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    public function verifyPayment(): void
    {
        $auth = ApiAuth::requireRole('customer');
        if (!$auth) {
            return;
        }
        $data = $this->jsonBody();
        $rideIdRaw = trim((string)($data['ride_id'] ?? ''));
        $orderId = trim((string)($data['razorpay_order_id'] ?? ''));
        $paymentId = trim((string)($data['razorpay_payment_id'] ?? ''));
        $signature = trim((string)($data['razorpay_signature'] ?? ''));
        if ($rideIdRaw === '' || $orderId === '' || $paymentId === '' || $signature === '') {
            $this->respond(422, ['status' => 'error', 'message' => 'ride_id, razorpay_order_id, razorpay_payment_id, razorpay_signature are required']);
            return;
        }

        $pdo = Mysql::connection();
        $ride = $this->fetchOwnedRide($pdo, $auth, $rideIdRaw);
        if (!$ride) {
            $this->respond(404, ['status' => 'error', 'message' => 'Ride not found']);
            return;
        }

        $service = new RazorpayService();
        if (!$service->verifyPaymentSignature($orderId, $paymentId, $signature)) {
            $this->respond(422, ['status' => 'error', 'message' => 'Invalid payment signature']);
            return;
        }

        try {
            $pdo->beginTransaction();
            $this->ensureGatewayPaymentsTable($pdo);
            $this->insertGatewayPayment(
                $pdo,
                $this->normalizeIdToString($ride['id']),
                $this->normalizeIdToString($ride['customer_id'] ?? $auth['subject_id']),
                (float)($ride['fare'] ?? $ride['total_fare'] ?? 0),
                'INR',
                $orderId,
                $paymentId,
                null,
                null,
                'paid',
                json_encode($data),
                $signature
            );
            $this->markRidePaid($pdo, $ride['id']);
            $pdo->commit();
            $this->respond(200, ['status' => 'ok', 'message' => 'Payment verified']);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->respond(500, ['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    public function paymentStatus(): void
    {
        $auth = ApiAuth::requireRole('customer');
        if (!$auth) {
            return;
        }
        $rideIdRaw = trim((string)($_GET['ride_id'] ?? ''));
        if ($rideIdRaw === '') {
            $this->respond(422, ['status' => 'error', 'message' => 'ride_id is required']);
            return;
        }
        $pdo = Mysql::connection();
        $ride = $this->fetchOwnedRide($pdo, $auth, $rideIdRaw);
        if (!$ride) {
            $this->respond(404, ['status' => 'error', 'message' => 'Ride not found']);
            return;
        }
        $this->ensureGatewayPaymentsTable($pdo);
        $stmt = $pdo->prepare('SELECT status, gateway_order_id, gateway_payment_id, payment_link_id, payment_link_url, updated_at
            FROM gateway_payments
            WHERE ride_id = ?
            ORDER BY id DESC
            LIMIT 1');
        $stmt->execute([$this->normalizeIdToString($ride['id'])]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
        if ($row) {
            $row = $this->syncPaymentStatusWithGateway($pdo, $ride, $row);
        }
        $this->respond(200, ['status' => 'ok', 'payment' => $row]);
    }

    public function razorpayWebhook(): void
    {
        $raw = file_get_contents('php://input') ?: '';
        $signature = (string)($_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? '');
        $service = new RazorpayService();
        if (!$service->verifyWebhookSignature($raw, $signature)) {
            $this->respond(401, ['status' => 'error', 'message' => 'Invalid webhook signature']);
            return;
        }

        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            $this->respond(422, ['status' => 'error', 'message' => 'Invalid payload']);
            return;
        }
        $event = strtolower(trim((string)($payload['event'] ?? '')));
        $paymentEntity = $payload['payload']['payment']['entity'] ?? [];
        $orderId = trim((string)($paymentEntity['order_id'] ?? ''));
        $paymentId = trim((string)($paymentEntity['id'] ?? ''));
        $status = trim((string)($paymentEntity['status'] ?? ''));
        if ($orderId === '') {
            $this->respond(200, ['status' => 'ok']);
            return;
        }

        $pdo = Mysql::connection();
        try {
            $pdo->beginTransaction();
            $this->ensureGatewayPaymentsTable($pdo);
            $newStatus = in_array($event, ['payment.captured', 'order.paid'], true) || $status === 'captured'
                ? 'paid'
                : ($status === 'failed' ? 'failed' : 'created');
            $update = $pdo->prepare('UPDATE gateway_payments
                SET status = ?, gateway_payment_id = COALESCE(NULLIF(?, ""), gateway_payment_id), raw_response = ?
                WHERE gateway_order_id = ? OR payment_link_id = ?');
            $update->execute([$newStatus, $paymentId, json_encode($payload), $orderId, $orderId]);

            if ($newStatus === 'paid') {
                $rideStmt = $pdo->prepare('SELECT ride_id FROM gateway_payments WHERE gateway_order_id = ? OR payment_link_id = ? ORDER BY id DESC LIMIT 1');
                $rideStmt->execute([$orderId, $orderId]);
                $rideId = (string)($rideStmt->fetchColumn() ?: '');
                if ($rideId !== '') {
                    $this->markRidePaid($pdo, $rideId);
                }
            }
            $pdo->commit();
            $this->respond(200, ['status' => 'ok']);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->respond(500, ['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    private function markRidePaid(\PDO $pdo, $rideId): void
    {
        $resolvedRideId = is_string($rideId) ? $this->toRideId($rideId) : $rideId;
        $set = [];
        $params = [];
        if ($this->columnExists($pdo, 'rides', 'payment_status')) {
            $set[] = 'payment_status = "paid"';
        }
        if ($this->columnExists($pdo, 'rides', 'payment_mode')) {
            $set[] = 'payment_mode = "online"';
        }
        if ($this->columnExists($pdo, 'rides', 'payment_method')) {
            $set[] = 'payment_method = "online"';
        }
        if (empty($set)) {
            (new RidesController())->activateRideSearchAfterPayment($pdo, $resolvedRideId);
            return;
        }
        $params[] = $resolvedRideId;
        $pdo->prepare('UPDATE rides SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($params);
        (new RidesController())->activateRideSearchAfterPayment($pdo, $resolvedRideId);
    }

    private function syncPaymentStatusWithGateway(\PDO $pdo, array $ride, array $paymentRow): array
    {
        $currentStatus = strtolower(trim((string)($paymentRow['status'] ?? '')));
        if (in_array($currentStatus, ['paid', 'verified', 'failed', 'refunded'], true)) {
            return $paymentRow;
        }

        $paymentLinkId = trim((string)($paymentRow['payment_link_id'] ?? ''));
        if ($paymentLinkId === '') {
            return $paymentRow;
        }

        $service = new RazorpayService();
        if (!$service->isConfigured()) {
            return $paymentRow;
        }

        try {
            $link = $service->fetchPaymentLink($paymentLinkId);
            $gatewayStatus = strtolower(trim((string)($link['status'] ?? '')));
            $gatewayPaymentId = trim((string)(
                $link['payment_id']
                ?? ($link['payments'][0]['payment_id'] ?? ($link['payments'][0]['id'] ?? ''))
            ));
            $resolvedStatus = 'created';
            if (in_array($gatewayStatus, ['paid', 'captured'], true)) {
                $resolvedStatus = 'paid';
            } elseif (in_array($gatewayStatus, ['cancelled', 'expired', 'failed'], true)) {
                $resolvedStatus = 'failed';
            }

            if ($resolvedStatus !== $currentStatus || $gatewayPaymentId !== trim((string)($paymentRow['gateway_payment_id'] ?? ''))) {
                $pdo->beginTransaction();
                $update = $pdo->prepare('UPDATE gateway_payments
                    SET status = ?, gateway_payment_id = COALESCE(NULLIF(?, ""), gateway_payment_id), raw_response = ?
                    WHERE ride_id = ?
                    ORDER BY id DESC
                    LIMIT 1');
                $update->execute([
                    $resolvedStatus,
                    $gatewayPaymentId !== '' ? $gatewayPaymentId : null,
                    json_encode($link),
                    $this->normalizeIdToString($ride['id'])
                ]);
                if ($resolvedStatus === 'paid') {
                    $this->markRidePaid($pdo, $ride['id']);
                }
                $pdo->commit();
            }

            $reload = $pdo->prepare('SELECT status, gateway_order_id, gateway_payment_id, payment_link_id, payment_link_url, updated_at
                FROM gateway_payments
                WHERE ride_id = ?
                ORDER BY id DESC
                LIMIT 1');
            $reload->execute([$this->normalizeIdToString($ride['id'])]);
            return $reload->fetch(\PDO::FETCH_ASSOC) ?: $paymentRow;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return $paymentRow;
        }
    }

    private function ensureGatewayPaymentsTable(\PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS gateway_payments (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            ride_id VARCHAR(64) NOT NULL,
            customer_id VARCHAR(64) NOT NULL,
            gateway VARCHAR(32) NOT NULL DEFAULT "razorpay",
            gateway_order_id VARCHAR(100) NULL,
            gateway_payment_id VARCHAR(100) NULL,
            gateway_signature VARCHAR(255) NULL,
            payment_link_id VARCHAR(100) NULL,
            payment_link_url TEXT NULL,
            amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            currency CHAR(3) NOT NULL DEFAULT "INR",
            status ENUM("created","paid","failed","refunded","verified") NOT NULL DEFAULT "created",
            raw_response LONGTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_gateway_payments_ride_created (ride_id, created_at),
            INDEX idx_gateway_payments_order (gateway_order_id),
            INDEX idx_gateway_payments_link (payment_link_id),
            INDEX idx_gateway_payments_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }

    private function insertGatewayPayment(
        \PDO $pdo,
        string $rideId,
        string $customerId,
        float $amount,
        string $currency,
        ?string $orderId,
        ?string $paymentId,
        ?string $paymentLinkId,
        ?string $paymentLinkUrl,
        string $status,
        ?string $rawResponse,
        ?string $signature = null
    ): void {
        $stmt = $pdo->prepare('INSERT INTO gateway_payments
            (ride_id, customer_id, gateway, gateway_order_id, gateway_payment_id, gateway_signature, payment_link_id, payment_link_url, amount, currency, status, raw_response)
            VALUES (?, ?, "razorpay", ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $rideId,
            $customerId,
            $orderId ?: null,
            $paymentId ?: null,
            $signature ?: null,
            $paymentLinkId ?: null,
            $paymentLinkUrl ?: null,
            round(max(0.0, $amount), 2),
            strtoupper($currency ?: 'INR'),
            $status,
            $rawResponse
        ]);
    }

    private function fetchOwnedRide(\PDO $pdo, array $auth, string $rideIdRaw): ?array
    {
        $rideId = $this->toRideId($rideIdRaw);
        $stmt = $pdo->prepare('SELECT id, customer_id, fare, total_fare, status FROM rides WHERE id = ? LIMIT 1');
        $stmt->execute([$rideId]);
        $ride = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$ride) {
            return null;
        }
        $rideCustomer = $this->normalizeIdToString($ride['customer_id'] ?? '');
        $authCustomer = $this->normalizeIdToString($auth['subject_id'] ?? '');
        if ($rideCustomer === '' || $authCustomer === '' || $rideCustomer !== $authCustomer) {
            return null;
        }
        return $ride;
    }

    private function fetchCustomerContact(\PDO $pdo, $customerId): ?array
    {
        $id = $customerId;
        $phone = '';
        $email = '';
        if ($this->tableExists($pdo, 'users')) {
            $cols = [];
            if ($this->columnExists($pdo, 'users', 'phone')) {
                $cols[] = 'phone';
            }
            if ($this->columnExists($pdo, 'users', 'email')) {
                $cols[] = 'email';
            }
            if (!empty($cols)) {
                $stmt = $pdo->prepare('SELECT ' . implode(', ', $cols) . ' FROM users WHERE id = ? LIMIT 1');
                $stmt->execute([$id]);
                $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
                $phone = trim((string)($row['phone'] ?? ''));
                $email = trim((string)($row['email'] ?? ''));
            }
        }
        if ($phone === '' && $this->tableExists($pdo, 'customers') && $this->columnExists($pdo, 'customers', 'phone')) {
            $stmt = $pdo->prepare('SELECT phone FROM customers WHERE id = ? LIMIT 1');
            $stmt->execute([$id]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
            $phone = trim((string)($row['phone'] ?? ''));
        }
        if ($email === '' && $this->tableExists($pdo, 'customers') && $this->columnExists($pdo, 'customers', 'email')) {
            $stmt = $pdo->prepare('SELECT email FROM customers WHERE id = ? LIMIT 1');
            $stmt->execute([$id]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
            $email = trim((string)($row['email'] ?? ''));
        }
        $customer = [];
        if ($phone !== '') {
            $customer['contact'] = $phone;
        }
        if ($email !== '') {
            $customer['email'] = $email;
        }
        return empty($customer) ? null : $customer;
    }

    private function tableExists(\PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1');
        $stmt->execute([$table]);
        return (bool)$stmt->fetchColumn();
    }

    private function columnExists(\PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1');
        $stmt->execute([$table, $column]);
        return (bool)$stmt->fetchColumn();
    }

    private function normalizeIdToString($id): string
    {
        if ($id === null) {
            return '';
        }
        if (is_string($id) && strlen($id) === 16) {
            return Uuid::toString($id);
        }
        return trim((string)$id);
    }

    private function buildRazorpayIdentifier(string $prefix, string $rawId, int $maxLength = 40): string
    {
        $safePrefix = preg_replace('/[^A-Za-z0-9_.-]/', '', trim($prefix)) ?: 'ref';
        $safeRaw = preg_replace('/[^A-Za-z0-9_.-]/', '', str_replace('-', '', trim($rawId))) ?: 'unknown';
        $base = $safePrefix . '_' . $safeRaw;

        if (strlen($base) <= $maxLength) {
            return $base;
        }

        $hash = substr(sha1($safeRaw), 0, 8);
        $available = $maxLength - strlen($safePrefix) - 1 - 1 - strlen($hash);
        if ($available < 1) {
            $available = 1;
        }
        $trimmed = substr($safeRaw, 0, $available);

        return $safePrefix . '_' . $trimmed . '_' . $hash;
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
            return (int)$value;
        }
        return $value;
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
}
