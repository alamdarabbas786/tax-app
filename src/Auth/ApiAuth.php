<?php

namespace App\Auth;

use App\Db\Mysql;

class ApiAuth
{
    public static function tokenRow(): ?array
    {
        $auth = self::resolveAuthorizationHeader();
        if (!preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
            return null;
        }
        $token = trim($m[1]);
        if ($token === '') {
            return null;
        }

        $pdo = Mysql::connection();
        $stmt = $pdo->prepare('SELECT role, subject_id, phone FROM auth_tokens WHERE token = ? AND (expires_at IS NULL OR expires_at > NOW()) LIMIT 1');
        $stmt->execute([$token]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private static function resolveAuthorizationHeader(): string
    {
        $candidates = [
            (string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''),
            (string)($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''),
            (string)($_SERVER['Authorization'] ?? ''),
            (string)($_SERVER['HTTP_X_AUTH_TOKEN'] ?? ''),
            (string)($_SERVER['X_AUTH_TOKEN'] ?? ''),
        ];

        foreach ($candidates as $value) {
            if (trim($value) !== '') {
                $candidate = trim($value);
                if (stripos($candidate, 'Bearer ') === 0) {
                    return $candidate;
                }
                // Allow plain token values in fallback headers like X-Auth-Token.
                return 'Bearer ' . $candidate;
            }
        }

        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            if (is_array($headers)) {
                foreach ($headers as $key => $value) {
                    if (is_string($key) && strcasecmp($key, 'Authorization') === 0) {
                        return trim((string)$value);
                    }
                    if (is_string($key) && strcasecmp($key, 'X-Auth-Token') === 0) {
                        $token = trim((string)$value);
                        return $token !== '' ? ('Bearer ' . $token) : '';
                    }
                }
            }
        }

        if (function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            if (is_array($headers)) {
                foreach ($headers as $key => $value) {
                    if (is_string($key) && strcasecmp($key, 'Authorization') === 0) {
                        return trim((string)$value);
                    }
                    if (is_string($key) && strcasecmp($key, 'X-Auth-Token') === 0) {
                        $token = trim((string)$value);
                        return $token !== '' ? ('Bearer ' . $token) : '';
                    }
                }
            }
        }

        return '';
    }

    public static function requireRole(string $role): ?array
    {
        $row = self::tokenRow();
        if (!$row || $row['role'] !== $role) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
            return null;
        }
        return $row;
    }

    public static function requireAnyRole(array $roles): ?array
    {
        $row = self::tokenRow();
        if (!$row || !in_array($row['role'], $roles, true)) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
            return null;
        }
        return $row;
    }
}
