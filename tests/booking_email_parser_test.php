<?php
/**
 * Tests for LeadEmailParser — the deterministic (no-LLM) paths of the booking
 * email importer. The LLM enrichment step is disabled here (null API key) so
 * these tests are hermetic; they exercise MIME decoding, Jotform label parsing,
 * and the heuristic fallback against the bundled .eml fixtures.
 *
 * Run with: php tests/booking_email_parser_test.php
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Panic\LeadEmailParser;

$passed = 0;
$failed = 0;

function ok(bool $cond, string $label): void {
    global $passed, $failed;
    if ($cond) { echo "  ✓ $label\n"; $passed++; }
    else        { echo "  ✗ FAIL: $label\n"; $failed++; }
}

$fixtures = __DIR__ . '/fixtures/booking-emails';
$parser   = new LeadEmailParser(null, 'claude-opus-4-8', '2026-06-01'); // null key → no LLM

// ── Structured Jotform notification ─────────────────────────────────────────
echo "\n=== Jotform structured email ===\n\n";

$r    = $parser->parse((string) file_get_contents("$fixtures/jotform-structured.eml"));
$lead = $r['lead'];
$meta = $r['meta'];

ok($meta['parse_method'] === 'jotform',                 'detected as jotform');
ok($lead['contact_name'] === 'Brody Bass',              'contact_name from "Who\'s Calling" label');
ok($lead['contact_email'] === 'drunkmonkpresents@gmail.com', 'contact_email pulled from label, not noreply@jotform');
ok($meta['reply_to'] === 'drunkmonkpresents@gmail.com', 'Reply-To captured');
ok($lead['event_type'] === 'concert',                   'umbrella vibe maps to concert');
ok($lead['source'] === 'email' && $lead['status'] === 'new', 'source=email, status=new');
ok($meta['message_id'] === '0qtb4ORGD119Gz03Ti2Bm9zpLqzX9DjCF0yP8Kp0E@go-workers-w6vk', 'message-id extracted (angle brackets stripped)');
ok(str_contains($lead['notes'], 'Bastardane'),          'full vision preserved in notes');
ok($meta['received_at'] === '2026-05-03 12:24:09',      'Date header parsed');

// ── Freeform prose (heuristic fallback, no LLM) ─────────────────────────────
echo "\n=== Freeform prose email (heuristic) ===\n\n";

$r    = $parser->parse((string) file_get_contents("$fixtures/freeform-hackathon.eml"));
$lead = $r['lead'];
$meta = $r['meta'];

ok($meta['parse_method'] === 'heuristic',               'no labels → heuristic path');
ok($lead['contact_name'] === 'Dilano Milheiro',         'name from From header');
ok($lead['contact_email'] === 'dilano@usenaive.ai',     'email from From header (forwarder skipped)');
ok($lead['contact_phone'] === '+1 9102406729',          'phone extracted from signature');
ok($lead['projected_attendance'] === 50,                'attendance "about 50 builders" → 50');
ok($lead['is_private'] === 1,                           'hackathon flagged private');
ok(stripos((string) $lead['alcohol_plan'], 'no alcohol') !== false, 'alcohol plan captured');
ok($meta['subject'] === 'Venue inquiry — July 11–12 hackathon', 'RFC2047 subject decoded');
ok(str_contains($lead['notes'], 'Naïve'),               'quoted-printable UTF-8 decoded in notes');
ok(!str_starts_with((string) $meta['summary'], 'Hi'),   'summary skips the greeting line');

// ── MIME edge handling ──────────────────────────────────────────────────────
echo "\n=== MIME handling ===\n\n";

$mime = $parser->parseMime((string) file_get_contents("$fixtures/freeform-hackathon.eml"));
ok($mime['text'] !== '' && str_contains($mime['text'], 'hackathon'), 'text/plain part extracted');
ok(str_contains($mime['html'], 'Dilano'),               'text/html part extracted');
ok(($mime['headers']['from'] ?? '') !== '',             'headers parsed');

ok($parser->htmlToText('<p>One</p><br>Two') === "One\nTwo" || str_contains($parser->htmlToText('<p>One</p><br>Two'), 'Two'), 'htmlToText strips tags & breaks lines');

echo "\n" . str_repeat('─', 48) . "\n";
echo "  $passed passed, $failed failed\n\n";
exit($failed === 0 ? 0 : 1);
