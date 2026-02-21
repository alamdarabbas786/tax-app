<?php

namespace App\Services;

use InvalidArgumentException;
use RuntimeException;

class PricingService
{
    public function calculateFare(array $input): array
    {
        $distanceKm = $this->toNumber($input['distance_km'] ?? null, 'distance_km');
        $durationMinutes = $this->toNumber($input['duration_minutes'] ?? null, 'duration_minutes');
        $costPerKm = $this->toNumber($input['driver_cost_per_km'] ?? null, 'driver_cost_per_km');
        $costPerMin = $this->toNumber($input['driver_cost_per_min'] ?? null, 'driver_cost_per_min');

        $driverCost = ($distanceKm * $costPerKm) + ($durationMinutes * $costPerMin);
        $driverProfit = $driverCost * 0.22;
        $platformFee = 70.0;
        $totalFare = $driverCost + $driverProfit + $platformFee;

        if ($totalFare < ($driverCost + $driverProfit)) {
            throw new RuntimeException('Fare below minimum driver cost plus profit');
        }

        return [
            'driver_cost' => $this->round2($driverCost),
            'driver_profit' => $this->round2($driverProfit),
            'platform_fee' => $this->round2($platformFee),
            'total_fare' => $this->round2($totalFare),
        ];
    }

    private function toNumber($value, string $name): float
    {
        if (!is_numeric($value)) {
            throw new InvalidArgumentException("Invalid {$name}");
        }

        $num = (float) $value;

        if ($num < 0) {
            throw new InvalidArgumentException("Invalid {$name}");
        }

        return $num;
    }

    private function round2(float $value): float
    {
        return round($value, 2);
    }
}