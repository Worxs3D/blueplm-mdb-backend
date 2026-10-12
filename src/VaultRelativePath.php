<?php
declare(strict_types=1);

namespace BluePlm;

/** Shared validation and normalization for vault-relative filesystem paths. */
final class VaultRelativePath
{
    public static function normalize(string $value): ?string
    {
        if (preg_match('/[\x00-\x1f\x7f<>:"|?*]/', $value)) return null;

        $rawNormalized = str_replace('\\', '/', $value);
        foreach (explode('/', $rawNormalized) as $segment) {
            if (rtrim($segment, ' .') !== $segment) return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '' || str_starts_with($trimmed, '/') || str_starts_with($trimmed, '\\')) return null;

        $normalized = str_replace('\\', '/', $trimmed);
        if (preg_match('/^[a-z]:/i', $normalized)) return null;

        foreach (explode('/', $normalized) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || rtrim($segment, ' .') !== $segment) return null;
            $deviceName = explode('.', $segment, 2)[0];
            if (preg_match('/^(?:con|prn|aux|nul|com[1-9]|lpt[1-9]|com[¹²³]|lpt[¹²³])$/iu', $deviceName)) return null;
        }

        return $normalized;
    }

    public static function isNormalized(string $value): bool
    {
        return self::normalize($value) === $value;
    }
}
