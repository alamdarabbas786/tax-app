<?php

require_once __DIR__ . '/ApiIntegrationTestCase.php';

final class AuthIntegrationTest extends ApiIntegrationTestCase
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

    public function testDriverOtpFlowSignalsRegistrationWhenMissing(): void
    {
        $phone = $this->randomPhone();
        $res = $this->jsonPost('/api/auth/request-otp', [
            'phone' => $phone,
            'role' => 'driver'
        ]);
        if ($this->shouldSkipForSchemaError($res)) {
            $this->skipOrFailInfra('Auth schema not fully migrated in current runtime');
        }

        $this->assertSame(200, $res['_http_code']);
        $this->assertSame('ok', $res['status'] ?? null);
        $this->assertTrue((bool)($res['needs_registration'] ?? false));
        $this->assertFalse((bool)($res['otp_required'] ?? true));
    }

    public function testCustomerOtpRequestAndVerify(): void
    {
        $phone = $this->randomPhone();

        $request = $this->jsonPost('/api/auth/request-otp', [
            'phone' => $phone,
            'role' => 'customer'
        ]);
        if ($this->shouldSkipForSchemaError($request)) {
            $this->skipOrFailInfra('Auth schema not fully migrated in current runtime');
        }
        $this->assertSame(200, $request['_http_code']);
        $this->assertSame('ok', $request['status'] ?? null);
        $this->assertTrue((bool)($request['otp_required'] ?? false));

        $verify = $this->jsonPost('/api/auth/verify-otp', [
            'phone' => $phone,
            'otp' => '1234',
            'role' => 'customer',
            'full_name' => 'Test Customer'
        ]);
        if ($this->shouldSkipForSchemaError($verify)) {
            $this->skipOrFailInfra('Auth schema not fully migrated in current runtime');
        }
        $this->assertSame(200, $verify['_http_code']);
        $this->assertSame('ok', $verify['status'] ?? null);
        $this->assertNotEmpty($verify['token'] ?? '');
    }
}
