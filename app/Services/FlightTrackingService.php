<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class FlightTrackingService
{
    private const CACHE_TTL_SECONDS = 120;

    public function track(string $flightNumber): array
    {
        $flightNumber = $this->normalizeFlightNumber($flightNumber);

        return Cache::remember(
            $this->cacheKey($flightNumber),
            self::CACHE_TTL_SECONDS,
            function () use ($flightNumber) {
                $raw = $this->fetchFromMockApi($flightNumber);
                return $this->normalize($raw);
            }
        );
    }

    private function fetchFromMockApi(string $flightNumber): array
    {
        return [
            'flight_number' => $flightNumber,
            'scheduled_arrival' => '2026-02-06 18:45:00',
            'actual_arrival' => '2026-02-06 18:57:00',
            'status' => 'arrived',
            'source' => 'mock_api'
        ];
    }

    private function normalize(array $raw): array
    {
        return [
            'flight_number' => $raw['flight_number'] ?? '',
            'scheduled_arrival' => $raw['scheduled_arrival'] ?? null,
            'actual_arrival' => $raw['actual_arrival'] ?? null,
            'status' => $raw['status'] ?? 'unknown',
            'source' => $raw['source'] ?? 'unknown'
        ];
    }

    private function cacheKey(string $flightNumber): string
    {
        return 'flight_tracking:' . $flightNumber;
    }

    private function normalizeFlightNumber(string $flightNumber): string
    {
        return strtoupper(preg_replace('/\s+/', '', trim($flightNumber)));
    }
}