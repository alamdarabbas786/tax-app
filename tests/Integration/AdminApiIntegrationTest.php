<?php

require_once __DIR__ . '/ApiIntegrationTestCase.php';

final class AdminApiIntegrationTest extends ApiIntegrationTestCase
{
    public function testAdminApiRequiresTwoFactorsAndWorksWithBoth(): void
    {
        $unauth = $this->rawRequest('POST', '/api/admin/fcm-test', [
            'driver_phone' => '9999999999'
        ], []);
        $this->assertSame(401, $unauth['http_code'], 'Admin API should reject missing auth');

        $oneFactor = $this->rawRequest('POST', '/api/admin/fcm-test', [
            'driver_phone' => '9999999999'
        ], [
            'X-Admin-Key: change-me'
        ]);
        $this->assertSame(401, $oneFactor['http_code'], 'Admin API should reject single factor auth');

        $twoFactor = $this->rawRequest('POST', '/api/admin/fcm-test', [
            'driver_phone' => '9999999999'
        ], [
            'X-Admin-Key: change-me',
            'X-Admin-Bearer: change-me'
        ]);
        $this->assertContains($twoFactor['http_code'], [404, 422], 'Expected app-level validation after admin auth');
        $this->assertIsArray($twoFactor['json']);
        $this->assertSame('error', $twoFactor['json']['status'] ?? null);

        $mysqlHealth = $this->rawRequest('GET', '/mysql-health/full', null, [
            'X-Admin-Key: change-me',
            'X-Admin-Bearer: change-me'
        ]);
        $this->assertSame(200, $mysqlHealth['http_code'], 'Admin mysql-health should be reachable');
        $this->assertIsArray($mysqlHealth['json']);
    }

    private function rawRequest(string $method, string $path, ?array $jsonBody, array $headers): array
    {
        $ch = curl_init($this->baseUrl . $path);
        $finalHeaders = $headers;
        if ($jsonBody !== null) {
            $finalHeaders[] = 'Content-Type: application/json';
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $finalHeaders,
            CURLOPT_POSTFIELDS => $jsonBody !== null ? json_encode($jsonBody) : null,
            CURLOPT_TIMEOUT => 20
        ]);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        $this->assertSame('', $err, 'cURL error for ' . $path . ': ' . $err);
        $this->assertNotFalse($raw, 'No response for ' . $path);
        $json = json_decode((string)$raw, true);

        return [
            'http_code' => $code,
            'raw' => (string)$raw,
            'json' => is_array($json) ? $json : null
        ];
    }
}
