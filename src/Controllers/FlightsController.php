<?php

namespace App\Controllers;

use App\Services\FlightTracking;

class FlightsController
{
    public function getByNumber(string $flightNumber): void
    {
        $service = new FlightTracking();
        $data = $service->track($flightNumber);
        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'ok', 'flight' => $data]);
    }
}