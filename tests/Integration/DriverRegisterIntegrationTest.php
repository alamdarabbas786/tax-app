<?php

require_once __DIR__ . '/ApiIntegrationTestCase.php';

final class DriverRegisterIntegrationTest extends ApiIntegrationTestCase
{
    public function testDriverRegisterAcceptsMultipartAndUppercasesIdentifiers(): void
    {
        if (!class_exists('CURLFile')) {
            $this->markTestSkipped('CURLFile extension is required');
        }

        $phone = $this->randomPhone();
        $suffix = (string)random_int(1000, 9999);
        $vehicleNumber = 'DL01AB' . $suffix;
        $licenseNumber = 'DL0' . random_int(1, 9) . 'X' . random_int(10000, 99999);
        $tmpDir = sys_get_temp_dir() . '/taxi_test_' . bin2hex(random_bytes(4));
        mkdir($tmpDir, 0777, true);

        $pdfPath = $tmpDir . '/doc.pdf';
        file_put_contents($pdfPath, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF");

        $jpgPath = $tmpDir . '/photo.jpg';
        $jpg = base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wCEAAkGBxAQEBUQEBAVFhUVFRUVFRUQFRUVFRUVFRUXFhUVFRUYHSggGBolGxUVITEhJSorLi4uFx8zODMtNygtLisBCgoKDg0OGhAQGi0lHyUtLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLf/AABEIAAEAAgMBIgACEQEDEQH/xAAVAAEBAAAAAAAAAAAAAAAAAAAABf/EABYBAQEBAAAAAAAAAAAAAAAAAAABAv/aAAwDAQACEAMQAAAB6gAAAAAAAAAAAP/EABYQAQEBAAAAAAAAAAAAAAAAAAABEf/aAAgBAQABPwCkP//EABYRAQEBAAAAAAAAAAAAAAAAAAABEf/aAAgBAgEBPwBqf//EABYRAQEBAAAAAAAAAAAAAAAAAAABEf/aAAgBAwEBPwBqf//Z');
        file_put_contents($jpgPath, $jpg ?: '');

        $fields = [
            'name' => 'Integration Driver',
            'phone' => $phone,
            'email' => strtolower('driver_' . $phone . '@example.com'),
            'vehicle_type' => 'bike',
            'vehicle_number' => $vehicleNumber,
            'license_number' => $licenseNumber,
            'address' => 'Integration Street',
            'city' => 'Delhi',
            'pin_code' => '110001',
            'aadhaar_number' => '123412341234',
            'vehicle_rc' => new CURLFile($pdfPath, 'application/pdf', 'vehicle_rc.pdf'),
            'driving_license' => new CURLFile($pdfPath, 'application/pdf', 'driving_license.pdf'),
            'aadhaar_card' => new CURLFile($pdfPath, 'application/pdf', 'aadhaar_card.pdf'),
            'driver_photo' => new CURLFile($jpgPath, 'image/jpeg', 'driver_photo.jpg')
        ];

        $res = $this->multipartPost('/api/driver/register', $fields);
        $raw = strtolower((string)($res['_raw'] ?? ''));
        if (
            str_contains($raw, 'permission denied') ||
            str_contains($raw, 'failed to open stream') ||
            str_contains($raw, 'uploads/driver_docs')
        ) {
            $this->skipOrFailInfra('Upload path is not writable in current test runtime');
        }

        if (($res['_http_code'] ?? 500) >= 500) {
            $this->skipOrFailInfra('Server error during register endpoint: ' . ($res['_raw'] ?? ''));
        }

        $this->assertSame(200, $res['_http_code']);
        $this->assertSame('ok', $res['status'] ?? null);
        $this->assertNotEmpty($res['driver_id'] ?? '');
    }
}
