<?php

require_once __DIR__ . '/ApiIntegrationTestCase.php';

use App\Db\Mysql;
use App\Utils\Uuid;

final class RideLifecycleE2ETest extends ApiIntegrationTestCase
{
    public function testCustomerDriverRideLifecycleWithBusinessRules(): void
    {
        if (!class_exists('CURLFile')) {
            $this->markTestSkipped('CURLFile extension is required');
        }

        $pickupLat = 28.6139;
        $pickupLng = 77.2090;
        $dropLat = 28.6142;
        $dropLng = 77.2100;

        $driverPhone = $this->randomPhone();
        $suffix = (string)random_int(1000, 9999);
        $vehicleNumber = 'DL01ZZ' . $suffix;
        $licenseNumber = 'DL9X' . random_int(10000, 99999);

        $tmpDir = sys_get_temp_dir() . '/taxi_e2e_' . bin2hex(random_bytes(4));
        mkdir($tmpDir, 0777, true);
        $pdfPath = $tmpDir . '/doc.pdf';
        file_put_contents($pdfPath, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF");
        $jpgPath = $tmpDir . '/photo.jpg';
        $jpg = base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wCEAAkGBxAQEBUQEBAVFhUVFRUVFRUQFRUVFRUVFRUXFhUVFRUYHSggGBolGxUVITEhJSorLi4uFx8zODMtNygtLisBCgoKDg0OGhAQGi0lHyUtLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLf/AABEIAAEAAgMBIgACEQEDEQH/xAAVAAEBAAAAAAAAAAAAAAAAAAAABf/EABYBAQEBAAAAAAAAAAAAAAAAAAABAv/aAAwDAQACEAMQAAAB6gAAAAAAAAAAAP/EABYQAQEBAAAAAAAAAAAAAAAAAAABEf/aAAgBAQABPwCkP//EABYRAQEBAAAAAAAAAAAAAAAAAAABEf/aAAgBAgEBPwBqf//EABYRAQEBAAAAAAAAAAAAAAAAAAABEf/aAAgBAwEBPwBqf//Z');
        file_put_contents($jpgPath, $jpg ?: '');

        $register = $this->multipartPost('/api/driver/register', [
            'name' => 'E2E Driver',
            'phone' => $driverPhone,
            'email' => strtolower('driver_' . $driverPhone . '@example.com'),
            'vehicle_type' => 'bike',
            'vehicle_number' => $vehicleNumber,
            'license_number' => $licenseNumber,
            'address' => 'E2E Street',
            'city' => 'Delhi',
            'pin_code' => '110001',
            'aadhaar_number' => '123412341234',
            'vehicle_rc' => new CURLFile($pdfPath, 'application/pdf', 'vehicle_rc.pdf'),
            'driving_license' => new CURLFile($pdfPath, 'application/pdf', 'driving_license.pdf'),
            'aadhaar_card' => new CURLFile($pdfPath, 'application/pdf', 'aadhaar_card.pdf'),
            'driver_photo' => new CURLFile($jpgPath, 'image/jpeg', 'driver_photo.jpg')
        ]);

        $raw = strtolower((string)($register['_raw'] ?? ''));
        if (str_contains($raw, 'permission denied') || str_contains($raw, 'failed to save')) {
            $this->skipOrFailInfra('Upload path is not writable in current runtime');
        }
        $this->assertSame(200, $register['_http_code'], 'Register failed: ' . json_encode($register));
        $this->assertSame('ok', $register['status'] ?? null);

        $driverOtpReq = $this->jsonPost('/api/auth/request-otp', [
            'phone' => $driverPhone,
            'role' => 'driver'
        ]);
        $this->assertSame('ok', $driverOtpReq['status'] ?? null);
        $this->assertFalse((bool)($driverOtpReq['needs_registration'] ?? true));

        $driverVerify = $this->jsonPost('/api/auth/verify-otp', [
            'phone' => $driverPhone,
            'otp' => '1234',
            'role' => 'driver'
        ]);
        $this->assertSame(200, $driverVerify['_http_code'], 'Driver verify failed: ' . json_encode($driverVerify));
        $driverToken = (string)($driverVerify['token'] ?? '');
        $this->assertNotSame('', $driverToken);

        $setLoc = $this->jsonPost('/api/driver/location', [
            'lat' => $pickupLat,
            'lng' => $pickupLng,
            'is_available' => true
        ], $driverToken);
        $this->assertSame(200, $setLoc['_http_code'], 'Driver location failed: ' . json_encode($setLoc));
        $setPush = $this->jsonPost('/api/driver/push-token', [
            'fcm_token' => 'e2e-test-token-' . $suffix
        ], $driverToken);
        $this->assertSame(200, $setPush['_http_code'], 'Driver push-token failed: ' . json_encode($setPush));

        $customerPhone = $this->randomPhone();
        $customerReq = $this->jsonPost('/api/auth/request-otp', [
            'phone' => $customerPhone,
            'role' => 'customer'
        ]);
        $this->assertSame('ok', $customerReq['status'] ?? null);

        $customerVerify = $this->jsonPost('/api/auth/verify-otp', [
            'phone' => $customerPhone,
            'otp' => '1234',
            'role' => 'customer',
            'full_name' => 'E2E Customer'
        ]);
        $this->assertSame(200, $customerVerify['_http_code'], 'Customer verify failed: ' . json_encode($customerVerify));
        $customerToken = (string)($customerVerify['token'] ?? '');
        $this->assertNotSame('', $customerToken);

        $rideCreate = $this->jsonPost('/api/rides', [
            'pickup_lat' => $pickupLat,
            'pickup_lng' => $pickupLng,
            'drop_lat' => $dropLat,
            'drop_lng' => $dropLng,
            'pickup_address' => 'Connaught Place',
            'drop_address' => 'Noida Sector 18',
            'vehicle_type' => 'bike',
            'distance_km' => 2.1,
            'duration_minutes' => 9
        ], $customerToken);
        $this->assertContains($rideCreate['_http_code'], [200, 201], 'Ride create failed: ' . json_encode($rideCreate));
        $this->assertSame('ok', $rideCreate['status'] ?? null);
        $rideId = (string)($rideCreate['ride_id'] ?? '');
        $this->assertNotSame('', $rideId);
        $driverId = (string)($register['driver_id'] ?? '');
        $this->assertNotSame('', $driverId);

        if (($rideCreate['ride_status'] ?? null) === 'no_driver_found') {
            $this->forceDispatchForDriver($rideId, $driverId);
        }
        $rideAfterCreate = $this->jsonGet('/api/rides/' . $rideId, $customerToken);
        $this->assertSame(200, $rideAfterCreate['_http_code']);
        if (strtolower((string)($rideAfterCreate['ride']['status'] ?? '')) === 'no_driver_found') {
            $this->forceDispatchForDriver($rideId, $driverId);
        }

        $driverRequests = $this->jsonGet('/api/driver/requests', $driverToken);
        $this->assertSame(200, $driverRequests['_http_code'], 'Driver requests failed: ' . json_encode($driverRequests));

        $accept = $this->jsonPost('/api/driver/rides/' . $rideId . '/accept', [], $driverToken);
        if (($accept['_http_code'] ?? 500) !== 200) {
            $rideSnapshot = $this->jsonGet('/api/rides/' . $rideId, $customerToken);
            if (strtolower((string)($rideSnapshot['ride']['status'] ?? '')) === 'no_driver_found') {
                $this->forceDispatchForDriver($rideId, $driverId);
                $accept = $this->jsonPost('/api/driver/rides/' . $rideId . '/accept', [], $driverToken);
            }
        }
        if (($accept['_http_code'] ?? 500) !== 200) {
            $rideSnapshot = $this->jsonGet('/api/rides/' . $rideId, $customerToken);
            $this->fail('Accept failed. Driver requests=' . json_encode($driverRequests)
                . '; ride=' . json_encode($rideSnapshot)
                . '; accept=' . json_encode($accept));
        }
        $this->assertSame('ok', $accept['status'] ?? null);

        $rideForOtp = $this->jsonGet('/api/rides/' . $rideId, $customerToken);
        $this->assertSame(200, $rideForOtp['_http_code'], 'Ride fetch failed: ' . json_encode($rideForOtp));
        $otp = (string)(($rideForOtp['ride']['otp_code'] ?? ''));
        $this->assertSame(4, strlen($otp), 'OTP should be exposed for customer handoff');

        $arrived = $this->jsonPost('/api/driver/rides/' . $rideId . '/arrived', [
            'lat' => $pickupLat,
            'lng' => $pickupLng
        ], $driverToken);
        $this->assertSame(200, $arrived['_http_code'], 'Arrived failed: ' . json_encode($arrived));

        $start = $this->jsonPost('/api/driver/rides/' . $rideId . '/start', [
            'otp' => $otp
        ], $driverToken);
        $this->assertSame(200, $start['_http_code'], 'Start failed: ' . json_encode($start));

        $cancelAfterStart = $this->jsonPost('/api/driver/rides/' . $rideId . '/cancel', [
            'reason' => 'E2E should block cancel after start'
        ], $driverToken);
        $this->assertSame(422, $cancelAfterStart['_http_code'], 'Cancel must be blocked after start: ' . json_encode($cancelAfterStart));

        $progress = $this->jsonPost('/api/driver/rides/' . $rideId . '/progress', [
            'lat' => $dropLat,
            'lng' => $dropLng
        ], $driverToken);
        $this->assertSame(200, $progress['_http_code'], 'Progress failed: ' . json_encode($progress));

        $setDropLoc = $this->jsonPost('/api/driver/location', [
            'lat' => $dropLat,
            'lng' => $dropLng,
            'is_available' => false
        ], $driverToken);
        $this->assertSame(200, $setDropLoc['_http_code']);

        $complete = $this->jsonPost('/api/driver/rides/' . $rideId . '/complete', [
            'distance_km' => 2.1,
            'duration_minutes' => 10
        ], $driverToken);
        $this->assertSame(200, $complete['_http_code'], 'Complete failed: ' . json_encode($complete));
        $this->assertSame('ok', $complete['status'] ?? null);
        $this->assertGreaterThan(0, (float)($complete['fare'] ?? 0));

        $finalRide = $this->jsonGet('/api/rides/' . $rideId, $customerToken);
        $this->assertSame(200, $finalRide['_http_code']);
        $this->assertSame('ride_completed', strtolower((string)($finalRide['ride']['status'] ?? '')));
    }

    private function forceDispatchForDriver(string $rideId, string $driverId): void
    {
        $pdo = Mysql::connection();
        $rideBin = Uuid::fromString($rideId);
        $driverBin = Uuid::fromString($driverId);

        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE rides SET status = "searching", no_driver_found_at = NULL, driver_id = NULL WHERE id = ?')
                ->execute([$rideBin]);

            $hasReqTable = (bool)$pdo->query("SELECT COUNT(1) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ride_driver_requests'")
                ->fetchColumn();
            if ($hasReqTable) {
                $stmt = $pdo->prepare('INSERT INTO ride_driver_requests (ride_id, driver_id, status, distance_km, sent_at, expires_at)
                    VALUES (?, ?, "pending", 0, NOW(), DATE_ADD(NOW(), INTERVAL 120 SECOND))
                    ON DUPLICATE KEY UPDATE status = "pending", sent_at = NOW(), expires_at = DATE_ADD(NOW(), INTERVAL 120 SECOND), responded_at = NULL');
                $stmt->execute([$rideBin, $driverBin]);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
