<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

function fcm_send_to_token(
    ?string $token,
    array $notification,
    array $data = [],
    int $ttlSeconds = 60,
    array $options = []
): array
{
    if (!$token) {
        return ['status' => 'skipped', 'message' => 'No token'];
    }

    $servicePath = env_value('FCM_SERVICE_ACCOUNT_PATH', '');
    if ($servicePath === '' || !is_file($servicePath)) {
        return ['status' => 'skipped', 'message' => 'FCM service account missing'];
    }

    $sa = json_decode((string) file_get_contents($servicePath), true);
    if (!is_array($sa) || empty($sa['client_email']) || empty($sa['private_key']) || empty($sa['project_id'])) {
        return ['status' => 'error', 'message' => 'Invalid service account json'];
    }

    $accessToken = fcm_access_token($sa['client_email'], $sa['private_key']);
    if (!$accessToken) {
        return ['status' => 'error', 'message' => 'Failed to get access token'];
    }

    $message = fcm_build_message($token, $notification, $data, $ttlSeconds);
    $send = fcm_send_with_retry($sa['project_id'], $accessToken, $message, $options);

    if (!empty($send['is_invalid_token'])) {
        fcm_handle_invalid_token($token, $options['role'] ?? null, isset($options['recipient_id']) ? (int)$options['recipient_id'] : null);
    }

    $result = [
        'status' => $send['status'],
        'status_code' => $send['status_code'],
        'error' => $send['error'],
        'response' => $send['response'],
        'attempts' => $send['attempts'],
        'is_invalid_token' => $send['is_invalid_token'],
        'token_suffix' => substr($token, -12)
    ];

    fcm_log_result($result, $notification, $data);
    return $result;
}

function fcm_send_to_entity(
    PDO $pdo,
    string $role,
    int $entityId,
    array $notification,
    array $data = [],
    int $ttlSeconds = 60,
    array $options = []
): array {
    $token = fcm_resolve_target_token($pdo, $role, $entityId);
    $options['role'] = $role;
    $options['recipient_id'] = $entityId;
    return fcm_send_to_token($token, $notification, $data, $ttlSeconds, $options);
}

function fcm_resolve_target_token(PDO $pdo, string $role, int $entityId, ?string $fallback = null): ?string
{
    try {
        $stmt = $pdo->prepare('SELECT fcm_token
            FROM fcm_device_tokens
            WHERE role = ? AND entity_id = ? AND is_active = 1
            ORDER BY last_seen_at DESC, id DESC
            LIMIT 1');
        $stmt->execute([$role, $entityId]);
        $row = $stmt->fetch();
        if (!empty($row['fcm_token'])) {
            return (string)$row['fcm_token'];
        }
    } catch (Throwable $e) {
        // Fallback to legacy token columns when new table is unavailable.
    }

    if ($fallback) {
        return $fallback;
    }

    $table = $role === 'driver' ? 'drivers' : 'users';
    try {
        $stmt = $pdo->prepare('SELECT fcm_token FROM ' . $table . ' WHERE id = ? LIMIT 1');
        $stmt->execute([$entityId]);
        $row = $stmt->fetch();
        return !empty($row['fcm_token']) ? (string)$row['fcm_token'] : null;
    } catch (Throwable $e) {
        return null;
    }
}

function fcm_build_message(?string $token, array $notification, array $data, int $ttlSeconds): array
{
    $safeTtl = max(15, min(300, $ttlSeconds));
    return [
        'message' => [
            'token' => $token,
            'notification' => $notification,
            'data' => normalize_fcm_data($data),
            'android' => [
                'priority' => 'HIGH',
                'ttl' => $safeTtl . 's',
                'notification' => [
                    'channel_id' => 'ride_requests',
                    'sound' => 'ride_request',
                    'click_action' => 'OPEN_RIDE_REQUEST'
                ]
            ],
            'apns' => [
                'headers' => [
                    'apns-priority' => '10'
                ],
                'payload' => [
                    'aps' => [
                        'sound' => 'default',
                        'content-available' => 1
                    ]
                ]
            ]
        ]
    ];
}

function fcm_send_with_retry(string $projectId, string $accessToken, array $message, array $options): array
{
    $maxRetries = isset($options['max_retries']) ? (int)$options['max_retries'] : (int)(env_value('FCM_MAX_RETRIES', '3') ?? '3');
    if ($maxRetries < 0) {
        $maxRetries = 0;
    }
    $baseBackoffMs = isset($options['backoff_base_ms']) ? (int)$options['backoff_base_ms'] : (int)(env_value('FCM_BACKOFF_BASE_MS', '400') ?? '400');
    if ($baseBackoffMs < 100) {
        $baseBackoffMs = 100;
    }

    $endpoint = 'https://fcm.googleapis.com/v1/projects/' . $projectId . '/messages:send';
    $attempts = [];
    $final = [
        'status' => 'error',
        'status_code' => 0,
        'error' => 'unknown',
        'response' => null,
        'attempts' => [],
        'is_invalid_token' => false
    ];

    for ($attempt = 1; $attempt <= ($maxRetries + 1); $attempt++) {
        $raw = fcm_send_once($endpoint, $accessToken, $message);
        $raw['attempt'] = $attempt;
        $attempts[] = $raw;
        $final = [
            'status' => $raw['status'],
            'status_code' => $raw['status_code'],
            'error' => $raw['error'],
            'response' => $raw['response'],
            'attempts' => $attempts,
            'is_invalid_token' => fcm_is_invalid_token($raw)
        ];

        if ($raw['status'] === 'ok') {
            return $final;
        }
        if ($final['is_invalid_token']) {
            return $final;
        }
        if (!fcm_is_retryable($raw)) {
            return $final;
        }
        if ($attempt > $maxRetries) {
            break;
        }

        $delayMs = (int)($baseBackoffMs * (2 ** ($attempt - 1))) + random_int(0, 100);
        usleep($delayMs * 1000);
    }

    return $final;
}

function fcm_send_once(string $endpoint, string $accessToken, array $message): array
{
    $ch = curl_init($endpoint);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($message));
    $response = curl_exec($ch);
    $error = curl_error($ch);
    $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'status' => ($error || $statusCode >= 300) ? 'error' : 'ok',
        'status_code' => $statusCode,
        'error' => $error ?: null,
        'response' => $response ?: null
    ];
}

function fcm_is_retryable(array $result): bool
{
    $statusCode = (int)($result['status_code'] ?? 0);
    if (!empty($result['error'])) {
        return true;
    }
    if (in_array($statusCode, [429, 500, 502, 503, 504], true)) {
        return true;
    }
    $raw = strtoupper((string)($result['response'] ?? ''));
    return str_contains($raw, 'UNAVAILABLE') || str_contains($raw, 'INTERNAL');
}

function fcm_is_invalid_token(array $result): bool
{
    $statusCode = (int)($result['status_code'] ?? 0);
    $raw = strtoupper((string)($result['response'] ?? ''));
    if (str_contains($raw, 'UNREGISTERED') || str_contains($raw, 'INVALID_ARGUMENT') || str_contains($raw, 'SENDER_ID_MISMATCH')) {
        return true;
    }
    return $statusCode === 404 && str_contains($raw, 'REQUESTED ENTITY WAS NOT FOUND');
}

function fcm_handle_invalid_token(string $token, ?string $role = null, ?int $recipientId = null): void
{
    try {
        $pdo = db();
        try {
            $stmt = $pdo->prepare('UPDATE fcm_device_tokens SET is_active = 0, updated_at = NOW() WHERE fcm_token = ?');
            $stmt->execute([$token]);
        } catch (Throwable $e) {
            // optional table
        }

        if ($role && $recipientId) {
            $table = $role === 'driver' ? 'drivers' : 'users';
            $stmt = $pdo->prepare('UPDATE ' . $table . ' SET fcm_token = NULL, updated_at = NOW() WHERE id = ? AND fcm_token = ?');
            $stmt->execute([$recipientId, $token]);
        }
    } catch (Throwable $e) {
        // invalid-token cleanup should never break request path
    }
}

function normalize_fcm_data(array $data): array
{
    $out = [];
    foreach ($data as $k => $v) {
        if ($v === null) {
            $out[(string) $k] = '';
        } elseif (is_bool($v)) {
            $out[(string) $k] = $v ? 'true' : 'false';
        } else {
            $out[(string) $k] = (string) $v;
        }
    }
    return $out;
}

function fcm_access_token(string $clientEmail, string $privateKey): ?string
{
    $now = time();
    $jwt = build_jwt([
        'iss' => $clientEmail,
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        'aud' => 'https://oauth2.googleapis.com/token',
        'iat' => $now,
        'exp' => $now + 3600
    ], $privateKey);

    if (!$jwt) {
        return null;
    }

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion' => $jwt
    ]));
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err || !$raw) {
        return null;
    }
    $json = json_decode($raw, true);
    return is_array($json) ? ($json['access_token'] ?? null) : null;
}

function build_jwt(array $claims, string $privateKey): ?string
{
    $header = ['alg' => 'RS256', 'typ' => 'JWT'];
    $segments = [
        base64url(json_encode($header)),
        base64url(json_encode($claims))
    ];
    $signingInput = implode('.', $segments);
    $signature = '';
    if (!openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
        return null;
    }
    $segments[] = base64url($signature);
    return implode('.', $segments);
}

function base64url(string $v): string
{
    return rtrim(strtr(base64_encode($v), '+/', '-_'), '=');
}

function fcm_log_result(array $result, array $notification, array $data): void
{
    $logPath = env_value('FCM_LOG_PATH', dirname(__DIR__) . '/logs/fcm.log') ?? (dirname(__DIR__) . '/logs/fcm.log');
    $dir = dirname($logPath);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $line = json_encode([
        'ts' => date('c'),
        'status' => $result['status'] ?? null,
        'status_code' => $result['status_code'] ?? null,
        'error' => $result['error'] ?? null,
        'attempts' => count($result['attempts'] ?? []),
        'is_invalid_token' => $result['is_invalid_token'] ?? false,
        'token_suffix' => $result['token_suffix'] ?? null,
        'title' => $notification['title'] ?? null,
        'ride_id' => $data['ride_id'] ?? null,
        'response' => $result['response'] ?? null
    ], JSON_UNESCAPED_SLASHES);
    if (is_string($line) && $line !== '') {
        @file_put_contents($logPath, $line . PHP_EOL, FILE_APPEND);
    }
}
