<?php

namespace App\Auth;

use App\Config\Env;

class AdminAuth
{
    public static function check(): bool
    {
        $apiKey = Env::get('ADMIN_API_KEY');
        $basicUser = Env::get('ADMIN_BASIC_USER');
        $basicPass = Env::get('ADMIN_BASIC_PASS');
        $bearer = Env::get('ADMIN_BEARER_TOKEN');

        $hasAny = ($apiKey !== null) || ($basicUser !== null && $basicPass !== null) || ($bearer !== null);
        if (!$hasAny) {
            return false;
        }

        $validCount = 0;

        if ($apiKey !== null) {
            $headerKey = $_SERVER['HTTP_X_ADMIN_KEY'] ?? '';
            if (hash_equals($apiKey, $headerKey)) {
                $validCount++;
            }
        }

        if ($basicUser !== null && $basicPass !== null) {
            $user = $_SERVER['PHP_AUTH_USER'] ?? '';
            $pass = $_SERVER['PHP_AUTH_PW'] ?? '';
            if (hash_equals($basicUser, $user) && hash_equals($basicPass, $pass)) {
                $validCount++;
            }
        }

        if ($bearer !== null) {
            $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
            $xAdminBearer = $_SERVER['HTTP_X_ADMIN_BEARER'] ?? ($_SERVER['HTTP_X_ADMIN_TOKEN'] ?? '');
            if ($xAdminBearer !== '' && hash_equals($bearer, trim($xAdminBearer))) {
                $validCount++;
            }
            if (preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
                $token = trim($m[1]);
                if (hash_equals($bearer, $token)) {
                    $validCount++;
                }
            }
        }

        return $validCount >= 2;
    }

    public static function unauthorized(): void
    {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    }
}
