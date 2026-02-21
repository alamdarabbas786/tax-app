<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Db\Mysql;
use App\Services\Pricing;

final class FareController
{
    public function estimate(): void
    {
        try {
            $body = $this->jsonBody();
            $distance = $body['distance_km'] ?? null;
            $duration = $body['duration_minutes'] ?? null;
            $vehicleType = $body['vehicle_type'] ?? null;

            if (!is_numeric($distance) || !is_numeric($duration)) {
                $this->respond(422, ['status' => 'error', 'message' => 'Invalid distance or duration']);
                return;
            }

            $distance = (float) $distance;
            $duration = (float) $duration;

            $rows = [];
            try {
                $pdo = Mysql::connection();
                if ($vehicleType) {
                    $stmt = $pdo->prepare('SELECT vehicle_type, cost_per_km, cost_per_min, minimum_fare, platform_fee FROM vehicle_pricing WHERE vehicle_type = ? AND is_active = 1');
                    $stmt->execute([$vehicleType]);
                    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
                } else {
                    $rows = $pdo->query('SELECT vehicle_type, cost_per_km, cost_per_min, minimum_fare, platform_fee FROM vehicle_pricing WHERE is_active = 1 ORDER BY FIELD(vehicle_type, "bike","auto","mini","sedan","xl")')
                        ->fetchAll(\PDO::FETCH_ASSOC);
                }
            } catch (\Throwable $e) {
                $rows = [];
            }

            if (count($rows) === 0) {
                $config = Pricing::vehicleConfig();
                if ($vehicleType) {
                    $key = strtolower((string) $vehicleType);
                    if (!isset($config[$key])) {
                        $this->respond(422, ['status' => 'error', 'message' => 'Invalid vehicle type']);
                        return;
                    }
                    $cfg = $config[$key];
                    $rows = [[
                        'vehicle_type' => $key,
                        'cost_per_km' => $cfg['cost_per_km'],
                        'cost_per_min' => $cfg['cost_per_min'],
                        'minimum_fare' => $cfg['minimum_fare'],
                        'platform_fee' => $cfg['platform_fee']
                    ]];
                } else {
                    foreach ($config as $key => $cfg) {
                        $rows[] = [
                            'vehicle_type' => $key,
                            'cost_per_km' => $cfg['cost_per_km'],
                            'cost_per_min' => $cfg['cost_per_min'],
                            'minimum_fare' => $cfg['minimum_fare'],
                            'platform_fee' => $cfg['platform_fee']
                        ];
                    }
                }
            }

            $options = [];
            foreach ($rows as $row) {
                $fare = Pricing::calculateFare([
                    'distance_km' => $distance,
                    'duration_minutes' => $duration,
                    'driver_cost_per_km' => (float) $row['cost_per_km'],
                    'driver_cost_per_min' => (float) $row['cost_per_min'],
                    'minimum_fare' => (float) $row['minimum_fare'],
                    'platform_fee' => (float) $row['platform_fee']
                ]);

                $options[] = [
                    'vehicle_type' => $row['vehicle_type'],
                    'fare' => $fare['total_fare']
                ];
            }

            $this->respond(200, [
                'status' => 'ok',
                'distance_km' => $distance,
                'duration_minutes' => $duration,
                'vehicle_options' => $options
            ]);
        } catch (\Throwable $e) {
            $this->respond(500, ['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    private function jsonBody(): array
    {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw ?: '', true);
        return is_array($data) ? $data : [];
    }

    private function respond(int $code, array $payload): void
    {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode($payload);
    }
}
