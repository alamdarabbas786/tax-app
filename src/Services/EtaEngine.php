<?php

namespace App\Services;

class EtaEngine
{
    public function passengerReadyTime(string $arrivalTime, string $flightType, string $timeOfDay): \DateTimeImmutable
    {
        $arrival = new \DateTimeImmutable($arrivalTime);

        $flightType = strtolower(trim($flightType));
        if (!in_array($flightType, ['domestic', 'international'], true)) {
            throw new \InvalidArgumentException('Invalid flight_type');
        }

        $timeOfDay = strtolower(trim($timeOfDay));
        if (!in_array($timeOfDay, ['peak', 'offpeak'], true)) {
            throw new \InvalidArgumentException('Invalid time_of_day');
        }

        $exitMinutes = $flightType === 'domestic' ? 20 : 35;
        $bufferMinutes = $timeOfDay === 'peak' ? 10 : 0;

        return $arrival->modify(sprintf('+%d minutes', $exitMinutes + $bufferMinutes));
    }
}