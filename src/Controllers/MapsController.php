<?php

declare(strict_types=1);

namespace App\Controllers;

final class MapsController
{
    public function route(): void
    {
        $data = $this->jsonBody();
        $pickupInput = is_array($data['pickup'] ?? null) ? $data['pickup'] : [];
        $dropoffInput = is_array($data['dropoff'] ?? null) ? $data['dropoff'] : [];
        $vehicleType = strtolower(trim((string) ($data['vehicle_type'] ?? '')));
        $navigationProfile = $this->navigationProfileFromVehicle($vehicleType);
        $googleMode = $this->googleModeFromProfile($navigationProfile);

        $pickup = $this->resolveLocation($pickupInput);
        $dropoff = $this->resolveLocation($dropoffInput);

        if (!$pickup || !$dropoff) {
            $this->respond(422, ['status' => 'error', 'message' => 'Invalid pickup or dropoff']);
            return;
        }

        $apiKey = getenv('GOOGLE_MAPS_API_KEY') ?: '';
        $polyline = null;
        $steps = [];

        if ($apiKey !== '') {
            $googleResult = $this->fetchGoogleDistance($pickup, $dropoff, $apiKey, $googleMode);
            $directionResult = $this->fetchGoogleDirections($pickup, $dropoff, $apiKey, $googleMode);
            if (is_array($directionResult)) {
                $polyline = $directionResult['polyline'] ?? null;
                $steps = is_array($directionResult['steps'] ?? null) ? $directionResult['steps'] : [];
            }
            if ($googleResult) {
                if ($polyline) {
                    $googleResult['polyline'] = $polyline;
                }
                if (!empty($steps)) {
                    $googleResult['steps'] = $steps;
                }
                $googleResult['navigation_profile'] = $navigationProfile;
                $googleResult['travel_mode'] = $googleMode;
                $this->respond(200, $googleResult);
                return;
            }
        }

        $distanceKm = $this->haversine($pickup['lat'], $pickup['lng'], $dropoff['lat'], $dropoff['lng']);
        $durationMinutes = max(1.0, ($distanceKm / 30.0) * 60.0);

        $response = [
            'status' => 'ok',
            'distance_km' => round($distanceKm, 2),
            'duration_minutes' => round($durationMinutes, 1),
            'pickup' => [
                'lat' => $pickup['lat'],
                'lng' => $pickup['lng'],
                'address' => $pickup['address'] ?? null,
                'place_id' => $pickup['place_id'] ?? null
            ],
            'dropoff' => [
                'lat' => $dropoff['lat'],
                'lng' => $dropoff['lng'],
                'address' => $dropoff['address'] ?? null,
                'place_id' => $dropoff['place_id'] ?? null
            ],
            'navigation_profile' => $navigationProfile,
            'travel_mode' => $googleMode,
            'steps' => [
                [
                    'instruction' => $navigationProfile === 'bike'
                        ? 'Follow bike route to destination'
                        : 'Follow driving route to destination',
                    'distance_meters' => (int) round($distanceKm * 1000),
                    'duration_seconds' => (int) round($durationMinutes * 60)
                ]
            ]
        ];

        if ($polyline) {
            $response['polyline'] = $polyline;
        }

        $this->respond(200, $response);
    }

    private function fetchGoogleDistance(array $pickup, array $dropoff, string $apiKey, string $mode): ?array
    {
        $origin = !empty($pickup['place_id'])
            ? 'place_id:' . $pickup['place_id']
            : ($pickup['lat'] . ',' . $pickup['lng']);
        $destination = !empty($dropoff['place_id'])
            ? 'place_id:' . $dropoff['place_id']
            : ($dropoff['lat'] . ',' . $dropoff['lng']);

        $url = 'https://maps.googleapis.com/maps/api/distancematrix/json?origins=' . urlencode($origin)
            . '&destinations=' . urlencode($destination)
            . '&mode=' . urlencode($mode)
            . '&key=' . urlencode($apiKey);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err || !$raw) {
            return null;
        }

        $json = json_decode($raw, true);
        if (!is_array($json) || ($json['status'] ?? '') !== 'OK') {
            return null;
        }

        $element = $json['rows'][0]['elements'][0] ?? null;
        if (!is_array($element) || ($element['status'] ?? '') !== 'OK') {
            return null;
        }

        $distanceKm = ((int)($element['distance']['value'] ?? 0)) / 1000;
        $durationMinutes = ((int)($element['duration']['value'] ?? 0)) / 60;

        return [
            'status' => 'ok',
            'distance_km' => round($distanceKm, 2),
            'duration_minutes' => round($durationMinutes, 1),
            'pickup' => [
                'lat' => $pickup['lat'],
                'lng' => $pickup['lng'],
                'address' => $pickup['address'] ?? null,
                'place_id' => $pickup['place_id'] ?? null
            ],
            'dropoff' => [
                'lat' => $dropoff['lat'],
                'lng' => $dropoff['lng'],
                'address' => $dropoff['address'] ?? null,
                'place_id' => $dropoff['place_id'] ?? null
            ]
        ];
    }

    private function fetchGoogleDirections(array $pickup, array $dropoff, string $apiKey, string $mode): ?array
    {
        $origin = !empty($pickup['place_id'])
            ? 'place_id:' . $pickup['place_id']
            : ($pickup['lat'] . ',' . $pickup['lng']);
        $destination = !empty($dropoff['place_id'])
            ? 'place_id:' . $dropoff['place_id']
            : ($dropoff['lat'] . ',' . $dropoff['lng']);

        $url = 'https://maps.googleapis.com/maps/api/directions/json?origin=' . urlencode($origin)
            . '&destination=' . urlencode($destination)
            . '&mode=' . urlencode($mode)
            . '&key=' . urlencode($apiKey);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err || !$raw) {
            return null;
        }

        $json = json_decode($raw, true);
        if (!is_array($json) || ($json['status'] ?? '') !== 'OK') {
            return null;
        }

        $points = $json['routes'][0]['overview_polyline']['points'] ?? null;
        $legSteps = $json['routes'][0]['legs'][0]['steps'] ?? [];
        $steps = [];
        if (is_array($legSteps)) {
            foreach ($legSteps as $step) {
                if (!is_array($step)) {
                    continue;
                }
                $html = (string) ($step['html_instructions'] ?? '');
                $text = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if ($text === '') {
                    continue;
                }
                $steps[] = [
                    'instruction' => $text,
                    'distance_meters' => (int) ($step['distance']['value'] ?? 0),
                    'duration_seconds' => (int) ($step['duration']['value'] ?? 0)
                ];
            }
        }

        return [
            'polyline' => (is_string($points) && $points !== '') ? $points : null,
            'steps' => $steps
        ];
    }

    private function navigationProfileFromVehicle(string $vehicleType): string
    {
        if ($vehicleType === 'bike') {
            return 'bike';
        }
        return 'car';
    }

    private function googleModeFromProfile(string $profile): string
    {
        if ($profile === 'bike') {
            return 'bicycling';
        }
        return 'driving';
    }

    private function resolveLocation(array $input): ?array
    {
        $lat = $input['lat'] ?? null;
        $lng = $input['lng'] ?? null;
        $placeId = $input['place_id'] ?? null;
        $address = $input['address'] ?? null;

        if (is_numeric($lat) && is_numeric($lng)) {
            return [
                'lat' => (float)$lat,
                'lng' => (float)$lng,
                'place_id' => is_string($placeId) && trim($placeId) !== '' ? trim($placeId) : null,
                'address' => is_string($address) && trim($address) !== '' ? trim($address) : null
            ];
        }

        if (is_string($placeId) && trim($placeId) !== '') {
            return [
                'lat' => 0.0,
                'lng' => 0.0,
                'place_id' => trim($placeId),
                'address' => is_string($address) && trim($address) !== '' ? trim($address) : null
            ];
        }

        return null;
    }

    private function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadiusKm = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) * sin($dLat / 2)
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
            * sin($dLng / 2) * sin($dLng / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return $earthRadiusKm * $c;
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
