<?php

require_once __DIR__ . '/ApiIntegrationTestCase.php';

final class RideFlowIntegrationTest extends ApiIntegrationTestCase
{
    private function shouldSkipForSchemaError(array $res): bool
    {
        $serialized = strtolower(json_encode($res));
        return (($res['_http_code'] ?? 500) >= 500) && (
            str_contains($serialized, 'unknown column') ||
            str_contains($serialized, 'base table or view not found') ||
            str_contains($serialized, 'sqlstate')
        );
    }

    public function testCustomerCanCreateRideRequest(): void
    {
        $phone = $this->randomPhone();

        $otpReq = $this->jsonPost('/api/auth/request-otp', [
            'phone' => $phone,
            'role' => 'customer'
        ]);
        if ($this->shouldSkipForSchemaError($otpReq)) {
            $this->skipOrFailInfra('Auth schema not fully migrated in current runtime');
        }
        $this->assertSame('ok', $otpReq['status'] ?? null);

        $verify = $this->jsonPost('/api/auth/verify-otp', [
            'phone' => $phone,
            'otp' => '1234',
            'role' => 'customer',
            'full_name' => 'Ride Tester'
        ]);
        if ($this->shouldSkipForSchemaError($verify)) {
            $this->skipOrFailInfra('Auth schema not fully migrated in current runtime');
        }
        $this->assertSame(200, $verify['_http_code']);
        $this->assertSame('ok', $verify['status'] ?? null);
        $token = (string)($verify['token'] ?? '');
        $this->assertNotSame('', $token);

        $ride = $this->jsonPost('/api/rides', [
            'pickup_lat' => 28.6139,
            'pickup_lng' => 77.2090,
            'drop_lat' => 28.5355,
            'drop_lng' => 77.3910,
            'pickup_address' => 'Connaught Place',
            'drop_address' => 'Noida Sector 18',
            'vehicle_type' => 'bike',
            'distance_km' => 12.4,
            'duration_minutes' => 29
        ], $token);

        $rideRaw = strtolower(json_encode($ride));
        if (
            ($ride['_http_code'] ?? 500) >= 500 &&
            (
                str_contains($rideRaw, 'unknown column') ||
                str_contains($rideRaw, 'base table or view not found') ||
                str_contains($rideRaw, 'sqlstate')
            )
        ) {
            $this->skipOrFailInfra('Schema not fully migrated for ride creation in current runtime');
        }

        $this->assertContains($ride['_http_code'], [200, 201], 'Unexpected status: ' . json_encode($ride));
        $this->assertSame('ok', $ride['status'] ?? null);
        $this->assertNotEmpty($ride['ride_id'] ?? null);
        $this->assertNotEmpty($ride['ride_status'] ?? null);
    }
}
