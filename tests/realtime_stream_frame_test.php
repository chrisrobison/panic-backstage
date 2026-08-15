<?php
/**
 * Tests for Panic\Realtime::buildFrame() — the pure SSE frame-formatting
 * half of the realtime endpoint (src/Realtime.php). The streaming loop
 * itself (DB polling, connection lifecycle) needs a live Database and a
 * running server, so it's covered by tests/realtime_stream_db_test.php and
 * the bash integration suite instead — this file exercises only the wire
 * format, hermetically.
 *
 * Pure — no DB, no bootstrap beyond the autoloader. Picked up automatically
 * by tests/run-php-tests.sh.
 *
 * Run with: php tests/realtime_stream_frame_test.php
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Panic\Realtime;

$passed = 0;
$failed = 0;

function ok(bool $cond, string $label): void {
    global $passed, $failed;
    if ($cond) { echo "  ✓ $label\n"; $passed++; }
    else        { echo "  ✗ FAIL: $label\n"; $failed++; }
}

echo "\n=== Realtime::buildFrame ===\n\n";

$frame = Realtime::buildFrame(48291, ['entity' => 'event', 'id' => 123]);
ok(str_starts_with($frame, "id: 48291\n"), "frame starts with the SSE id: line (the db_history revision)");
ok(str_contains($frame, "event: invalidate\n"), "frame declares event: invalidate");
ok(str_contains($frame, 'data: {"entity":"event","id":123,"revision":48291}'), "data: line is exactly entity/id/revision, in spec order");
ok(str_ends_with($frame, "\n\n"), "frame ends with a blank line (SSE frame terminator)");

// 'global' invalidations omit the id field entirely (spec example:
// {"entity":"global","revision":48293} — no "id" key at all).
$frame = Realtime::buildFrame(48293, ['entity' => 'global']);
ok(str_contains($frame, 'data: {"entity":"global","revision":48293}'), "global invalidation frame omits the id key entirely");
ok(!str_contains($frame, '"id"'), "global invalidation frame contains no id key at all");

// The frame is well-formed JSON on the data: line regardless of shape.
$frame = Realtime::buildFrame(1, ['entity' => 'lead', 'id' => 9999]);
if (preg_match('/^data: (.+)$/m', $frame, $m)) {
    $decoded = json_decode($m[1], true);
    ok(is_array($decoded) && $decoded['entity'] === 'lead' && $decoded['id'] === 9999 && $decoded['revision'] === 1,
        "data: payload round-trips through json_decode with entity/id/revision intact");
} else {
    ok(false, "data: line present and parseable");
}

// SECURITY: buildFrame() has no way to receive old_row/new_row (its
// signature only accepts int + the already-mapped {entity,id} array), so
// there is no code path here that could ever leak row contents onto the
// wire — this is a structural guarantee, not just a test of current inputs.

echo "\nRealtime::buildFrame: $passed passed, $failed failed.\n";
exit($failed > 0 ? 1 : 0);
