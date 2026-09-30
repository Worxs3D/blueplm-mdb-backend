<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/BundleManifest.php';

use BluePlm\BundleManifest;

$root = sys_get_temp_dir() . '/blueplm-manifest-' . bin2hex(random_bytes(5));
mkdir($root);
try {
    if (BundleManifest::read($root) !== null) throw new RuntimeException('missing manifest must be unknown');
    file_put_contents($root . '/' . BundleManifest::FILE_NAME, json_encode([
        'version' => 1, 'releaseVersion' => '4.4.4-beta.1', 'digest' => str_repeat('a', 64), 'fileCount' => 4,
    ], JSON_THROW_ON_ERROR));
    $manifest = BundleManifest::read($root);
    if ($manifest === null || $manifest['digest'] !== str_repeat('a', 64)) throw new RuntimeException('valid manifest was rejected');
    file_put_contents($root . '/' . BundleManifest::FILE_NAME, '{"version":1,"digest":"not-safe","fileCount":4}');
    if (BundleManifest::read($root) !== null) throw new RuntimeException('invalid manifest was accepted');
    fwrite(STDOUT, "bundle manifest: ok\n");
} finally {
    @unlink($root . '/' . BundleManifest::FILE_NAME);
    @rmdir($root);
}
