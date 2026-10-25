<?php
declare(strict_types=1);

namespace BluePlm;

use InvalidArgumentException;
use UnexpectedValueException;

/** Validation for the organization folder list used to declutter metadata views. */
final class AdminOnlyFolders
{
    /** @return list<string> */
    public static function fromRequest(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidArgumentException('adminOnlyFolders must be a JSON array of folder paths.');
        }

        return self::normalizeList($value, InvalidArgumentException::class);
    }

    /** @return list<string> */
    public static function fromStored(?string $value): array
    {
        if ($value === null || $value === '') {
            throw new UnexpectedValueException('Organization folder policy is not initialized.');
        }

        try {
            $decoded = json_decode($value, false, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new UnexpectedValueException('Stored organization folder policy is invalid.', previous: $error);
        }
        if (!is_array($decoded)) {
            throw new UnexpectedValueException('Stored organization folder policy must be a JSON array.');
        }

        try {
            return self::normalizeList($decoded, UnexpectedValueException::class);
        } catch (InvalidArgumentException $error) {
            throw new UnexpectedValueException('Stored organization folder policy is invalid.', previous: $error);
        }
    }

    /** @param list<mixed> $paths
     *  @return list<string>
     *  @param class-string<InvalidArgumentException|UnexpectedValueException> $errorClass
     */
    private static function normalizeList(array $paths, string $errorClass): array
    {
        $normalized = [];
        $seen = [];
        foreach ($paths as $path) {
            if (!is_string($path)) throw new $errorClass('Folder paths must be strings.');
            $path = VaultRelativePath::normalize($path);
            if ($path === null || strlen($path) > 2048) throw new $errorClass('Folder paths must be safe vault-relative paths up to 2048 bytes.');
            $key = strtolower($path);
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $normalized[] = $path;
        }
        return $normalized;
    }
}
