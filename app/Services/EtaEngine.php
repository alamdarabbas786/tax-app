<?php

namespace App\Services;

use Carbon\Carbon;
use InvalidArgumentException;

class EtaEngine
{
    public function passengerReadyTime(string $arrivalTime, string $flightType, string $timeOfDay): Carbon
    {
        $arrival = Carbon::parse($arrivalTime);

        $flightType = strtolower(trim($flightType));
        if (!in_array($flightType, ['domestic', 'international'], true)) {
            throw new InvalidArgumentException('Invalid flight_type');
        }

        $timeOfDay = strtolower(trim($timeOfDay));
        if (!in_array($timeOfDay, ['peak', 'offpeak'], true)) {
            throw new InvalidArgumentException('Invalid time_of_day');
        }

        $exitMinutes = $flightType === 'domestic' ? 20 : 35;
        $bufferMinutes = $timeOfDay === 'peak' ? 10 : 0;

        return $arrival->copy()->addMinutes($exitMinutes + $bufferMinutes);
    }
}