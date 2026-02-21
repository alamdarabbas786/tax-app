<?php

namespace App\Utils;

class Uuid
{
    public static function v4Binary(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return $data;
    }

    public static function fromString(string $uuid): string
    {
        $hex = str_replace('-', '', strtolower(trim($uuid)));
        if (!preg_match('/^[0-9a-f]{32}$/', $hex)) {
            throw new \InvalidArgumentException('Invalid UUID');
        }
        return pack('H*', $hex);
    }

    public static function toString(string $bin): string
    {
        $hex = bin2hex($bin);
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }
}