<?php
declare(strict_types=1);

namespace BluePlm;

/** Reads and validates the non-secret deployment identity written by the installer. */
final class BundleManifest
{
    public const FILE_NAME = 'bundle-manifest.json';

    /** @return array{version:int,digest:string,fileCount:int}|null */
    public static function read(string $root): ?array
    {
        $path = rtrim($root, '/\\') . '/' . self::FILE_NAME;
        if (!is_file($path)) return null;
        $decoded = json_decode((string)file_get_contents($path), true);
        if (!is_array($decoded)
            || ($decoded['version'] ?? null) !== 1
            || !is_string($decoded['digest'] ?? null)
            || !preg_match('/^[a-f0-9]{64}$/', $decoded['digest'])
            || !is_int($decoded['fileCount'] ?? null)
            || $decoded['fileCount'] < 0) {
            return null;
        }
        return [
            'version' => 1,
            'digest' => $decoded['digest'],
            'fileCount' => $decoded['fileCount'],
        ];
    }
}
