<?php

namespace App\Auth;

class AdminWebAuth
{
    public static function check(): bool
    {
        $user = $_SERVER['PHP_AUTH_USER'] ?? '';
        $pass = $_SERVER['PHP_AUTH_PW'] ?? '';
        $expectedUser = $_ENV['ADMIN_WEB_USER'] ?? 'admin';
        $expectedPass = $_ENV['ADMIN_WEB_PASS'] ?? 'admin123';

        return hash_equals($expectedUser, $user) && hash_equals($expectedPass, $pass);
    }

    public static function requireAuth(): void
    {
        header('WWW-Authenticate: Basic realm="Admin"');
        http_response_code(401);
        echo 'Unauthorized';
    }
}