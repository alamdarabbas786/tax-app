<?php

namespace App\Services;

use App\Config\Env;

class FcmService
{
    private string $logPath;

    public function __construct()
    {
        $configured = Env::get('FCM_LOG_PATH', '');
        if (is_string($configured) && trim($configured) !== '') {
            $this->logPath = trim($configured);
            return;
        }
        $this->logPath = dirname(__DIR__, 2) . '/logs/fcm.log';
    }

    public function sendToTokens(array $tokens, array $notification, array $data = [], array $options = []): array
    {
        $tokens = array_values(array_filter($tokens));
        if (count($tokens) === 0) {
            return ['status' => 'skipped', 'message' => 'No tokens'];
        }

        $serviceAccountPath = Env::get('FCM_SERVICE_ACCOUNT_PATH', '');
        $serviceAccountPath = is_string($serviceAccountPath) ? trim($serviceAccountPath) : '';
        if ($serviceAccountPath === '') {
            return ['status' => 'skipped', 'message' => 'FCM_SERVICE_ACCOUNT_PATH not set'];
        }

        // Support both absolute path and project-relative service account path.
        if (!file_exists($serviceAccountPath)) {
            $candidate = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $serviceAccountPath), DIRECTORY_SEPARATOR);
            if (file_exists($candidate)) {
                $serviceAccountPath = $candidate;
            }
        }

        if (!file_exists($serviceAccountPath)) {
            return ['status' => 'skipped', 'message' => 'FCM service account file missing'];
        }

        $sa = json_decode(file_get_contents($serviceAccountPath) ?: '', true);
        if (!is_array($sa) || empty($sa['client_email']) || empty($sa['private_key']) || empty($sa['project_id'])) {
            return ['status' => 'error', 'message' => 'Invalid service account JSON'];
        }

        $accessToken = $this->getAccessToken($sa['client_email'], $sa['private_key']);
        if (!$accessToken) {
            $this->log('access_token_error', [
                'message' => 'Failed to get access token',
                'project_id' => $sa['project_id'] ?? null
            ]);
            return ['status' => 'error', 'message' => 'Failed to get access token'];
        }

        $projectId = $sa['project_id'];
        $endpoint = 'https://fcm.googleapis.com/v1/projects/' . $projectId . '/messages:send';

        $results = [];
        foreach ($tokens as $token) {
            $messageBody = [
                'token' => $token,
                'notification' => $notification,
                'data' => $this->normalizeDataValues($data)
            ];

            if (!empty($options['android']) && is_array($options['android'])) {
                $messageBody['android'] = $options['android'];
            }

            $message = [
                'message' => $messageBody
            ];

            $ch = curl_init($endpoint);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json'
            ]);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($ch, CURLOPT_TIMEOUT, 20);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($message));
            $response = curl_exec($ch);
            $error = curl_error($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $decoded = is_string($response) ? json_decode($response, true) : null;
            $fcmErrorCode = $this->extractFcmErrorCode($decoded);
            $fcmMessageId = is_array($decoded) ? ($decoded['name'] ?? null) : null;

            $this->log('send', [
                'status_code' => $status,
                'fcm_error_code' => $fcmErrorCode,
                'fcm_message_id' => $fcmMessageId,
                'curl_error' => $error ?: null,
                'token_suffix' => substr($token, -12),
                'notification_title' => $notification['title'] ?? null,
                'data_ride_id' => $data['ride_id'] ?? null
            ]);

            $results[] = [
                'token' => $token,
                'status_code' => $status,
                'error' => $error,
                'response' => $response,
                'fcm_error_code' => $fcmErrorCode,
                'fcm_message_id' => $fcmMessageId
            ];
        }

        return ['status' => 'ok', 'results' => $results];
    }

    private function extractFcmErrorCode($decoded): ?string
    {
        if (!is_array($decoded)) {
            return null;
        }
        if (isset($decoded['error']['status']) && is_string($decoded['error']['status'])) {
            return $decoded['error']['status'];
        }
        if (!empty($decoded['error']['details']) && is_array($decoded['error']['details'])) {
            foreach ($decoded['error']['details'] as $detail) {
                if (is_array($detail) && isset($detail['errorCode']) && is_string($detail['errorCode'])) {
                    return $detail['errorCode'];
                }
            }
        }
        return null;
    }

    private function log(string $event, array $payload): void
    {
        try {
            $dir = dirname($this->logPath);
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            $line = json_encode([
                'ts' => date('c'),
                'event' => $event,
                'payload' => $payload
            ], JSON_UNESCAPED_SLASHES);
            if (is_string($line)) {
                @file_put_contents($this->logPath, $line . PHP_EOL, FILE_APPEND);
            }
        } catch (\Throwable $e) {
            // Do not break ride flow because of logging errors.
        }
    }

    private function normalizeDataValues(array $data): array
    {
        $normalized = [];
        foreach ($data as $key => $value) {
            if (is_bool($value)) {
                $normalized[(string) $key] = $value ? 'true' : 'false';
                continue;
            }
            if ($value === null) {
                $normalized[(string) $key] = '';
                continue;
            }
            $normalized[(string) $key] = (string) $value;
        }
        return $normalized;
    }

    private function getAccessToken(string $clientEmail, string $privateKey): ?string
    {
        $now = time();
        $claims = [
            'iss' => $clientEmail,
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600
        ];

        $jwt = $this->buildJwt($claims, $privateKey);
        if (!$jwt) {
            return null;
        }

        $body = http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt
        ]);

        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error || !$response) {
            return null;
        }
        $json = json_decode($response, true);
        return is_array($json) && isset($json['access_token']) ? $json['access_token'] : null;
    }

    private function buildJwt(array $claims, string $privateKey): ?string
    {
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $segments = [
            $this->base64UrlEncode(json_encode($header)),
            $this->base64UrlEncode(json_encode($claims))
        ];
        $signingInput = implode('.', $segments);
        $signature = '';

        $ok = openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        if (!$ok) {
            return null;
        }

        $segments[] = $this->base64UrlEncode($signature);
        return implode('.', $segments);
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
