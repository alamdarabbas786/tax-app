<?php

function randomToken(int $bytes = 32): string
{
    return bin2hex(random_bytes($bytes));
}

$root = dirname(__DIR__);
$envFile = $root . DIRECTORY_SEPARATOR . '.env';
const MAX_BACKUPS = 5;
if (file_exists($envFile)) {
    $timestamp = date('Ymd-His');
    $backup = $envFile . '.bak.' . $timestamp;
    copy($envFile, $backup);

    $pattern = $envFile . '.bak.*';
    $backups = glob($pattern);
    if (is_array($backups) && count($backups) > MAX_BACKUPS) {
        usort($backups, function ($a, $b) {
            return filemtime($a) <=> filemtime($b);
        });
        $toDelete = array_slice($backups, 0, count($backups) - MAX_BACKUPS);
        foreach ($toDelete as $file) {
            @unlink($file);
        }
    }
}

$apiKey = randomToken(16);
$basicUser = 'admin';
$basicPass = randomToken(12);
$bearer = randomToken(24);

$vars = [
    'ADMIN_API_KEY' => $apiKey,
    'ADMIN_BASIC_USER' => $basicUser,
    'ADMIN_BASIC_PASS' => $basicPass,
    'ADMIN_BEARER_TOKEN' => $bearer
];

if (!file_exists($envFile)) {
    file_put_contents($envFile, "");
}

$content = file_get_contents($envFile);
if ($content === false) {
    throw new RuntimeException('Unable to read .env');
}

foreach ($vars as $key => $value) {
    $pattern = '/^' . preg_quote($key, '/') . '=.*/m';
    $line = $key . '=' . $value;
    if (preg_match($pattern, $content)) {
        $content = preg_replace($pattern, $line, $content);
    } else {
        $content .= (substr($content, -1) === "\n" || $content === '' ? '' : "\n") . $line;
    }
}

file_put_contents($envFile, $content . "\n");

echo "Updated .env with new admin secrets. Backup: .env.bak.$timestamp\n";
