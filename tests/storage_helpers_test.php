<?php
/**
 * Tests for Panic\ensure_dir() and Panic\write_file() — the shared storage
 * helpers introduced after flyer generation, contract PDFs, signature PNGs
 * and asset uploads were all found silently swallowing mkdir()/
 * file_put_contents() failures. A failed write used to be recorded as a
 * success: contracts.final_pdf_path and contract_signers.signature_image_path
 * pointed at files that had never been written.
 *
 * Hermetic — works entirely inside its own directory under the system temp
 * dir and removes it afterward. Needs no server and no DB.
 *
 * Run with: php tests/storage_helpers_test.php
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use function Panic\ensure_dir;
use function Panic\write_file;

$passed = 0;
$failed = 0;

function ok(bool $cond, string $label): void {
    global $passed, $failed;
    if ($cond) { echo "  ✓ $label\n"; $passed++; }
    else        { echo "  ✗ FAIL: $label\n"; $failed++; }
}

/** Assert that $fn throws RuntimeException whose message contains $needle. */
function throws(callable $fn, string $needle, string $label): void {
    try {
        $fn();
    } catch (\RuntimeException $e) {
        ok(str_contains($e->getMessage(), $needle), $label . ' (message names the path/cause)');
        return;
    }
    ok(false, $label . ' — expected a RuntimeException, none thrown');
}

$base = sys_get_temp_dir() . '/pb-storage-helpers-' . bin2hex(random_bytes(4));

/** Recursively remove a directory tree, restoring write permission as needed. */
function cleanup(string $dir): void {
    if (!is_dir($dir)) {
        return;
    }
    @chmod($dir, 0755);
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . '/' . $entry;
        is_dir($path) ? cleanup($path) : @unlink($path);
    }
    @rmdir($dir);
}

try {
    // ── ensure_dir ────────────────────────────────────────────────────────────
    ensure_dir($base);
    ok(is_dir($base), 'ensure_dir creates a directory');

    // Nested creation in one call, mirroring storage/contracts/{id}/signatures.
    $nested = $base . '/a/b/c';
    ensure_dir($nested);
    ok(is_dir($nested), 'ensure_dir creates intermediate directories recursively');

    // Must be idempotent: every caller runs this on each request.
    ensure_dir($nested);
    ok(is_dir($nested), 'ensure_dir on an existing directory is a no-op, not an error');

    // The failure that started all this: a parent the process cannot write to.
    // Skipped as root, which bypasses the permission check entirely.
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        echo "  – skipped unwritable-parent check (running as root)\n";
    } else {
        $locked = $base . '/locked';
        mkdir($locked, 0500, true);
        throws(
            static fn() => ensure_dir($locked . '/child'),
            $locked . '/child',
            'ensure_dir throws when the parent is not writable'
        );
        chmod($locked, 0755);
    }

    // ── write_file ────────────────────────────────────────────────────────────
    $target = $base . '/out.bin';
    $bytes  = random_bytes(2048);
    write_file($target, $bytes);
    ok(file_get_contents($target) === $bytes, 'write_file writes the exact bytes given');

    write_file($target, 'replaced');
    ok(file_get_contents($target) === 'replaced', 'write_file truncates/overwrites an existing file');

    // Empty writes are legitimate and must not be mistaken for failure
    // (file_put_contents returns int(0), which is falsy).
    $empty = $base . '/empty.bin';
    write_file($empty, '');
    ok(is_file($empty) && file_get_contents($empty) === '', 'write_file accepts an empty payload');

    // Writing into a directory that does not exist must throw, not return false
    // and let the caller record the path as if it had been saved.
    throws(
        static fn() => write_file($base . '/missing-dir/out.bin', 'x'),
        'missing-dir',
        'write_file throws when the target directory does not exist'
    );
} finally {
    cleanup($base);
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
