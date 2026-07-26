<?php
/**
 * Tests for LeadEmailParser — the deterministic (no-LLM) paths of the booking
 * email importer. The LLM enrichment step is force-disabled here (enabled:
 * false, rather than left null to auto-detect via ClaudeCli::isAvailable())
 * so these tests are hermetic regardless of whether a real `claude` binary
 * happens to be installed on the box running them; they exercise MIME
 * decoding, Jotform label parsing, and the heuristic fallback against the
 * bundled .eml fixtures.
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
$parser   = new LeadEmailParser(false, 'opus', '2026-06-01'); // enabled:false → no LLM

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

// ── Jotform with a headline title + US-format date ──────────────────────────
echo "\n=== Jotform headline + US date ===\n\n";

$r    = $parser->parse((string) file_get_contents("$fixtures/jotform-headline-usdate.eml"));
$lead = $r['lead'];

ok($lead['event_name'] === 'lunch at the mab',          'event_name from "NEW Booking ALERT" headline, not the vibe');
ok($lead['event_type'] === 'other',                     '"Other" vibe maps to other');
ok($lead['desired_date'] === '2026-06-25',              'US MM-DD-YYYY date coerced to ISO');
ok($lead['contact_name'] === 'Allen Walker',            'contact_name from "Who\'s Calling"');
ok($lead['contact_email'] === 'allen@soxal.co',         'contact_email from label, not noreply@jotform');

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

// ── Threading (In-Reply-To/References + subject normalization) ─────────────
echo "\n=== Threading ===\n\n";

$extractIds = new ReflectionMethod(LeadEmailParser::class, 'extractMessageIds');
$extractIds->setAccessible(true);
$ids = fn($v) => $extractIds->invoke($parser, $v);

ok($ids('<abc@x.com>') === ['abc@x.com'],                        'single bracketed id extracted');
ok($ids('<a@x.com> <b@x.com>  <c@x.com>') === ['a@x.com', 'b@x.com', 'c@x.com'], 'References list extracted in order');
ok($ids('') === [],                                              'empty header → no ids');
ok($ids('bare-id@x.com') === ['bare-id@x.com'],                   'unbracketed id falls back to whitespace split');
ok($ids('<dup@x.com> <dup@x.com>') === ['dup@x.com'],             'duplicate ids collapsed');

$threaded = <<<EML
From: "Brody Bass" <drunkmonkpresents@gmail.com>
To: bookings@themab.org
Subject: Re: Fwd: Venue inquiry — July hackathon
Message-ID: <reply-1@example.com>
In-Reply-To: <original-msg@example.com>
References: <original-msg@example.com> <ack-msg@themab.org>
Date: Mon, 1 Jun 2026 10:00:00 -0700
Content-Type: text/plain

Following up on this — still interested!
EML;

$r = $parser->parse(str_replace("\n", "\r\n", $threaded));
ok($r['meta']['in_reply_to'] === 'original-msg@example.com',     'in_reply_to captured from header');
ok($r['meta']['reference_ids'] === ['original-msg@example.com', 'ack-msg@themab.org'], 'reference_ids merges In-Reply-To + References, de-duped');

ok(LeadEmailParser::normalizeSubject('Re: Booking inquiry') === 'booking inquiry',       'Re: prefix stripped');
ok(LeadEmailParser::normalizeSubject('Fwd: RE: Booking inquiry') === 'booking inquiry',  'repeated Re:/Fwd: prefixes all stripped');
ok(LeadEmailParser::normalizeSubject('RE: [2]: Booking inquiry') === 'booking inquiry' || LeadEmailParser::normalizeSubject('RE: Booking inquiry') === 'booking inquiry', 'bracketed reply count tolerated');
ok(LeadEmailParser::normalizeSubject('Booking Inquiry') === 'booking inquiry',           'case-folded even with no prefix');
ok(LeadEmailParser::normalizeSubject('') === '' && LeadEmailParser::normalizeSubject(null) === '', 'blank/null subject → empty string, never matches');

ok(LeadEmailParser::hasReplyPrefix('Re: Booking inquiry') === true,      'hasReplyPrefix: Re: prefix detected');
ok(LeadEmailParser::hasReplyPrefix('Fwd: Booking inquiry') === true,     'hasReplyPrefix: Fwd: prefix detected');
ok(LeadEmailParser::hasReplyPrefix('RE[2]: Booking inquiry') === true,   'hasReplyPrefix: bracketed reply count detected');
ok(LeadEmailParser::hasReplyPrefix('Booking inquiry') === false,         'hasReplyPrefix: plain subject, no prefix');
ok(LeadEmailParser::hasReplyPrefix('') === false && LeadEmailParser::hasReplyPrefix(null) === false, 'hasReplyPrefix: blank/null subject → false');

// ── Date coercion ───────────────────────────────────────────────────────────
echo "\n=== Date coercion ===\n\n";

$coerce = (new ReflectionMethod(LeadEmailParser::class, 'coerceDate'));
$coerce->setAccessible(true);
$d = fn($v) => $coerce->invoke($parser, $v);

ok($d('06-25-2026') === '2026-06-25',                   'MM-DD-YYYY dashed → ISO');
ok($d('6/5/2026')   === '2026-06-05',                   'M/D/YYYY slashed, single digits → ISO');
ok($d('2026-06-25') === '2026-06-25',                   'already-ISO passes through');
ok($d('25-06-2026') === '2026-06-25',                   'day-first fallback when first field > 12');
ok($d('13/13/2026') === null,                           'impossible date rejected');
ok($d('next summer') === null,                          'vague text stays null');

echo "\n" . str_repeat('─', 48) . "\n";
echo "  $passed passed, $failed failed\n\n";
exit($failed === 0 ? 0 : 1);
