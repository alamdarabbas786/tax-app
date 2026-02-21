<?php

function randomToken(int $bytes = 32): string
{
    return bin2hex(random_bytes($bytes));
}

$apiKey = randomToken(16);
$basicUser = 'admin';
$basicPass = randomToken(12);
$bearer = randomToken(24);

echo "ADMIN_API_KEY={$apiKey}\n";
echo "ADMIN_BASIC_USER={$basicUser}\n";
echo "ADMIN_BASIC_PASS={$basicPass}\n";
echo "ADMIN_BEARER_TOKEN={$bearer}\n";