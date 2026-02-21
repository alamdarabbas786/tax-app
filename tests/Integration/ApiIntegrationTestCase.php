<?php

use PHPUnit\Framework\TestCase;

abstract class ApiIntegrationTestCase extends TestCase
{
    protected string $baseUrl;
    protected bool $strictIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        $this->baseUrl = rtrim((string)(getenv('TEST_BASE_URL') ?: 'http://localhost:3000'), '/');
        $this->strictIntegration = (string)(getenv('INTEGRATION_STRICT') ?: '0') === '1';
        $this->ensureApiReachable();
    }

    protected function jsonPost(string $path, array $payload, ?string $bearer = null): array
    {
        $headers = ['Content-Type: application/json'];
        if ($bearer) {
            $headers[] = 'Authorization: Bearer ' . $bearer;
        }
        [$raw, $code, $err] = $this->execWithRetry($this->baseUrl . $path, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => 20
        ]);

        $this->assertSame('', $err, 'cURL error for ' . $path . ': ' . $err);
        $this->assertNotFalse($raw, 'No response for ' . $path);
        $json = json_decode((string)$raw, true);
        $this->assertIsArray($json, 'Invalid JSON for ' . $path . ': ' . $raw);
        $json['_http_code'] = $code;
        return $json;
    }

    protected function jsonGet(string $path, ?string $bearer = null): array
    {
        $headers = [];
        if ($bearer) {
            $headers[] = 'Authorization: Bearer ' . $bearer;
        }
        [$raw, $code, $err] = $this->execWithRetry($this->baseUrl . $path, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 20
        ]);

        $this->assertSame('', $err, 'cURL error for ' . $path . ': ' . $err);
        $this->assertNotFalse($raw, 'No response for ' . $path);
        $json = json_decode((string)$raw, true);
        $this->assertIsArray($json, 'Invalid JSON for ' . $path . ': ' . $raw);
        $json['_http_code'] = $code;
        return $json;
    }

    protected function multipartPost(string $path, array $fields, ?string $bearer = null): array
    {
        $headers = [];
        if ($bearer) {
            $headers[] = 'Authorization: Bearer ' . $bearer;
        }
        [$raw, $code, $err] = $this->execWithRetry($this->baseUrl . $path, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $fields,
            CURLOPT_TIMEOUT => 40
        ]);

        $this->assertSame('', $err, 'cURL error for ' . $path . ': ' . $err);
        $this->assertNotFalse($raw, 'No response for ' . $path);
        $json = json_decode((string)$raw, true);
        if (!is_array($json) && is_string($raw)) {
            if (preg_match('/(\{.*\})/s', $raw, $m)) {
                $json = json_decode($m[1], true);
            }
        }
        $this->assertIsArray($json, 'Invalid JSON for ' . $path . ': ' . $raw);
        $json['_http_code'] = $code;
        $json['_raw'] = (string)$raw;
        return $json;
    }

    protected function randomPhone(): string
    {
        return '9' . str_pad((string)random_int(0, 999999999), 9, '0', STR_PAD_LEFT);
    }

    private function ensureApiReachable(): void
    {
        $ok = false;
        for ($i = 0; $i < 20; $i++) {
            $ch = curl_init($this->baseUrl . '/health');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5
            ]);
            $raw = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($raw !== false && ($code === 200 || $code === 503)) {
                $ok = true;
                break;
            }
            usleep(500000);
        }

        if (!$ok) {
            $this->skipOrFailInfra('API is not reachable at ' . $this->baseUrl);
        }
    }

    protected function skipOrFailInfra(string $message): void
    {
        if ($this->strictIntegration) {
            $this->fail($message);
        }
        $this->markTestSkipped($message);
    }

    /**
     * @return array{0:mixed,1:int,2:string}
     */
    private function execWithRetry(string $url, array $curlOptions): array
    {
        $raw = false;
        $code = 0;
        $err = '';
        for ($i = 0; $i < 3; $i++) {
            $ch = curl_init($url);
            curl_setopt_array($ch, $curlOptions);
            $raw = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            if ($err === '') {
                return [$raw, $code, $err];
            }
            usleep(300000);
        }
        return [$raw, $code, $err];
    }
}


