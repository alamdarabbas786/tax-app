<?php

namespace App\Services;

class FlightTracking
{
    public function track(string $flightNumber): array
    {
        $flightNumber = $this->normalizeFlightNumber($flightNumber);

        // Mock API response
        return [
            'flight_number' => $flightNumber,
            'scheduled_arrival' => '2026-02-06 18:45:00',
            'actual_arrival' => '2026-02-06 18:57:00',
            'status' => 'arrived',
            'flight_type' => 'domestic',
            'airport_code' => 'JFK'
        ];
    }

    private function normalizeFlightNumber(string $flightNumber): string
    {
        return strtoupper(preg_replace('/\s+/', '', trim($flightNumber)));
    }
}