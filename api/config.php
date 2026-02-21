<?php

declare(strict_types=1);

function env_value(string $key, ?string $default = null): ?string
{
    static $loaded = false;
    static $values = [];

    if (!$loaded) {
        $loaded = true;
        $envPath = dirname(__DIR__) . '/.env';
        if (is_file($envPath)) {
            $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                    continue;
                }
                [$k, $v] = explode('=', $line, 2);
                $values[trim($k)] = trim($v);
            }
        }
    }

    $runtime = getenv($key);
    if ($runtime !== false && $runtime !== '') {
        return $runtime;
    }
    if (array_key_exists($key, $values)) {
        return $values[$key];
    }
    return $default;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = env_value('MYSQL_DSN', 'mysql:host=127.0.0.1;port=3306;dbname=airport_taxi;charset=utf8mb4');
    $user = env_value('MYSQL_USER', 'appuser');
    $pass = env_value('MYSQL_PASSWORD', 'AppPass123!');

    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    return $pdo;
}

function api_guard(): void
{
    $required = env_value('API_SHARED_KEY', null);
    if ($required === null || $required === '') {
        return;
    }
    $given = $_SERVER['HTTP_X_API_KEY'] ?? '';
    if (!hash_equals($required, $given)) {
        json_response(401, ['status' => 'error', 'message' => 'Unauthorized']);
        exit;
    }
}

function json_input(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '', true);
    return is_array($data) ? $data : [];
}

function json_response(int $status, array $payload): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload);
}

function require_fields(array $data, array $required): ?array
{
    $missing = [];
    foreach ($required as $field) {
        if (!array_key_exists($field, $data) || $data[$field] === '' || $data[$field] === null) {
            $missing[] = $field;
        }
    }
    return $missing === [] ? null : $missing;
}

function haversine_sql_expr(string $latCol, string $lngCol): string
{
    return '(6371 * acos(cos(radians(:pickup_lat)) * cos(radians(' . $latCol . ')) * cos(radians(' . $lngCol . ') - radians(:pickup_lng)) + sin(radians(:pickup_lat)) * sin(radians(' . $latCol . '))))';
}
