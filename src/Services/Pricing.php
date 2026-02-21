<?php

declare(strict_types=1);

namespace App\Services;

final class Pricing
{
    private const PROFIT_MARGIN = 0.22;

    private const VEHICLE_CONFIG = [
        'bike' => [
            'cost_per_km' => 6.0,
            'cost_per_min' => 1.2,
            'minimum_fare' => 25.0,
            'platform_fee' => 5.0
        ],
        'auto' => [
            'cost_per_km' => 8.0,
            'cost_per_min' => 1.6,
            'minimum_fare' => 35.0,
            'platform_fee' => 7.0
        ],
        'mini' => [
            'cost_per_km' => 10.0,
            'cost_per_min' => 2.0,
            'minimum_fare' => 45.0,
            'platform_fee' => 10.0
        ],
        'sedan' => [
            'cost_per_km' => 13.0,
            'cost_per_min' => 2.6,
            'minimum_fare' => 65.0,
            'platform_fee' => 12.0
        ],
        'xl' => [
            'cost_per_km' => 16.0,
            'cost_per_min' => 3.2,
            'minimum_fare' => 85.0,
            'platform_fee' => 15.0
        ]
    ];

    public static function vehicleConfig(): array
    {
        return self::VEHICLE_CONFIG;
    }

    public static function calculateFare(array $input): array
    {
        $distanceKm = self::toNumber($input['distance_km'] ?? 0, 'distance_km');
        $durationMin = self::toNumber($input['duration_minutes'] ?? 0, 'duration_minutes');
        $costPerKm = self::toNumber($input['driver_cost_per_km'] ?? 0, 'driver_cost_per_km');
        $costPerMin = self::toNumber($input['driver_cost_per_min'] ?? 0, 'driver_cost_per_min');
        $minimumFare = self::toNumber($input['minimum_fare'] ?? 0, 'minimum_fare');
        $platformFee = self::toNumber($input['platform_fee'] ?? 0, 'platform_fee');

        $driverCost = self::round2(($distanceKm * $costPerKm) + ($durationMin * $costPerMin));
        $platformFee = self::round2($platformFee);

        $baseProfit = self::round2($driverCost * self::PROFIT_MARGIN);
        $baseFare = self::round2($driverCost + $baseProfit + $platformFee);

        // Keep fare breakup check valid while still honoring minimum fare.
        $minimumRequiredProfit = self::round2(max(0, $minimumFare - $driverCost - $platformFee));
        $driverProfit = max($baseProfit, $minimumRequiredProfit);
        $driverProfit = self::round2($driverProfit);

        $driverEarning = self::round2($driverCost + $driverProfit);
        $totalFare = self::round2($driverEarning + $platformFee);

        return [
            'driver_cost' => $driverCost,
            'driver_profit' => $driverProfit,
            'driver_earning' => $driverEarning,
            'platform_fee' => $platformFee,
            'total_fare' => $totalFare
        ];
    }

    public static function calculateFareForVehicle(string $vehicleType, float $distanceKm, float $durationMin): array
    {
        $key = strtolower(trim($vehicleType));
        if (!isset(self::VEHICLE_CONFIG[$key])) {
            throw new \InvalidArgumentException('Invalid vehicle type');
        }
        $cfg = self::VEHICLE_CONFIG[$key];
        return self::calculateFare([
            'distance_km' => $distanceKm,
            'duration_minutes' => $durationMin,
            'driver_cost_per_km' => $cfg['cost_per_km'],
            'driver_cost_per_min' => $cfg['cost_per_min'],
            'minimum_fare' => $cfg['minimum_fare'],
            'platform_fee' => $cfg['platform_fee']
        ]);
    }

    private static function toNumber($value, string $field): float
    {
        if (!is_numeric($value)) {
            throw new \InvalidArgumentException('Invalid ' . $field);
        }
        $num = (float) $value;
        if ($num < 0) {
            throw new \InvalidArgumentException('Invalid ' . $field);
        }
        return $num;
    }

    private static function round2(float $value): float
    {
        return round($value, 2);
    }
}
