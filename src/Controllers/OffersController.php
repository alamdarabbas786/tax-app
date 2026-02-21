<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\ApiAuth;
use App\Db\Mysql;
use App\Services\OfferService;

final class OffersController
{
    private OfferService $offerService;

    public function __construct()
    {
        $this->offerService = new OfferService();
    }

    public function list(): void
    {
        $auth = ApiAuth::requireRole('customer');
        if (!$auth) {
            return;
        }

        $fare = is_numeric($_GET['fare'] ?? null) ? (float)$_GET['fare'] : 0.0;
        $paymentMode = (string)($_GET['payment_mode'] ?? 'cash');
        $vehicleType = (string)($_GET['vehicle_type'] ?? '');

        $pdo = Mysql::connection();
        try {
            $offers = $this->offerService->listOffers(
                $pdo,
                $fare,
                $paymentMode,
                $vehicleType,
                $auth['subject_id'] ?? null
            );
            $this->respond(200, ['status' => 'ok', 'offers' => $offers]);
        } catch (\Throwable $e) {
            $this->respond(500, ['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    public function apply(): void
    {
        $auth = ApiAuth::requireRole('customer');
        if (!$auth) {
            return;
        }

        $data = $this->jsonBody();
        $code = strtoupper(trim((string)($data['code'] ?? '')));
        $fare = is_numeric($data['fare'] ?? null) ? (float)$data['fare'] : 0.0;
        $paymentMode = (string)($data['payment_mode'] ?? 'cash');
        $vehicleType = (string)($data['vehicle_type'] ?? '');

        if ($code === '') {
            $this->respond(422, ['status' => 'error', 'message' => 'Coupon code is required']);
            return;
        }

        $pdo = Mysql::connection();
        try {
            $applied = $this->offerService->applyCode(
                $pdo,
                $code,
                $fare,
                $paymentMode,
                $vehicleType,
                $auth['subject_id'] ?? null
            );
            $this->respond(200, ['status' => 'ok', 'offer' => $applied]);
        } catch (\Throwable $e) {
            $this->respond(422, ['status' => 'error', 'message' => $e->getMessage()]);
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
}
