<?php
declare(strict_types=1);

namespace BluePlm;

/**
 * Minimal RFC 6238 TOTP implementation. Keeping this module dependency-free is
 * deliberate: it runs on All-Inkl shared hosting without Composer extensions.
 */
final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateSecret(int $bytes = 20): string
    {
        if ($bytes < 10 || $bytes > 64) throw new \InvalidArgumentException('Invalid TOTP secret length.');
        return self::base32Encode(random_bytes($bytes));
    }

    public static function provisioningUri(string $issuer, string $account, string $secret): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer . ':' . $account)
            . '?secret=' . rawurlencode($secret)
            . '&issuer=' . rawurlencode($issuer)
            . '&algorithm=SHA1&digits=6&period=30';
    }

    public static function verify(string $secret, string $code, int $window = 1, ?int $timestamp = null): bool
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (!preg_match('/^\d{6}$/', $code) || $window < 0 || $window > 2) return false;
        $counter = intdiv($timestamp ?? time(), 30);
        for ($offset = -$window; $offset <= $window; $offset++) {
            if (hash_equals(self::code($secret, $counter + $offset), $code)) return true;
        }
        return false;
    }

    private static function code(string $secret, int $counter): string
    {
        $key = self::base32Decode($secret);
        if ($key === '') throw new \InvalidArgumentException('Invalid TOTP secret.');
        $counterBytes = pack('N2', 0, $counter);
        $hash = hash_hmac('sha1', $counterBytes, $key, true);
        $offset = ord($hash[19]) & 0x0f;
        $value = ((ord($hash[$offset]) & 0x7f) << 24)
            | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8)
            | ord($hash[$offset + 3]);
        return str_pad((string)($value % 1000000), 6, '0', STR_PAD_LEFT);
    }

    private static function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $byte) $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        $output = '';
        foreach (str_split($bits, 5) as $group) {
            if (strlen($group) < 5) $group = str_pad($group, 5, '0', STR_PAD_RIGHT);
            $output .= self::ALPHABET[bindec($group)];
        }
        return $output;
    }

    private static function base32Decode(string $value): string
    {
        $value = strtoupper(preg_replace('/[^A-Z2-7]/', '', $value) ?? '');
        if ($value === '') return '';
        $bits = '';
        foreach (str_split($value) as $character) {
            $position = strpos(self::ALPHABET, $character);
            if ($position === false) return '';
            $bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
        }
        $output = '';
        foreach (str_split($bits, 8) as $group) {
            if (strlen($group) === 8) $output .= chr(bindec($group));
        }
        return $output;
    }
}
