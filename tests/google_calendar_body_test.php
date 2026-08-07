<?php
/**
 * Tests for GoogleCalendar::eventBody() — the Backstage row -> Calendar API
 * resource mapping used by the nightly mirror (docs/google-calendar-sync.md).
 *
 * These cover the cases that actually bit during development:
 *   - the `start`/`end` keys being built but never merged into the body
 *     (caught only because a --dry-run printed empty times);
 *   - holds with no times becoming a fabricated 7pm slot instead of all-day
 *     (commit f469666 deliberately stopped the app inventing times);
 *   - end_time == show_time rendering as a 24-hour block;
 *   - shows running past midnight losing their +1 day roll.
 *
 * Pure function, no DB or network. Run with:
 *   php tests/google_calendar_body_test.php
 */

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use Panic\GoogleCalendar;

$passed = 0;
$failed = 0;

function ok(bool $cond, string $label): void {
    global $passed, $failed;
    if ($cond) { echo "  ✓ $label\n"; $passed++; }
    else        { echo "  ✗ FAIL: $label\n"; $failed++; }
}

const APP_URL = 'https://example.test/backstage';

/** @param array<string,mixed> $overrides */
function row(array $overrides = []): array {
    return $overrides + [
        'id'              => 42,
        'title'           => 'Test Show',
        'status'          => 'confirmed',
        'event_type'      => 'live_music',
        'date'            => '2026-09-10',
        'end_date'        => null,
        'doors_time'      => null,
        'show_time'       => null,
        'end_time'        => null,
        'load_in_time'    => null,
        'room'            => null,
        'capacity'        => null,
        'promoter_name'   => null,
        'booker_name'     => null,
        'venue_name'      => 'Mabuhay Gardens',
        'venue_address'   => '443 Broadway',
        'venue_timezone'  => 'America/Los_Angeles',
    ];
}

echo "\nGoogleCalendar::eventBody()\n";

// ── The body is actually complete ───────────────────────────────────────────
$b = GoogleCalendar::eventBody(row(['show_time' => '20:00:00']), APP_URL);
ok(isset($b['start']) && isset($b['end']), 'start/end are merged into the body');
ok(($b['summary'] ?? '') === 'Test Show', 'plain status renders a bare title');
ok(($b['status'] ?? '') === 'confirmed', 'always posted as a confirmed Google event');

// ── Tagging (this is what protects hand-made staff entries) ────────────────
$priv = $b['extendedProperties']['private'] ?? [];
ok(($priv[GoogleCalendar::TAG_KEY] ?? null) === GoogleCalendar::TAG_VALUE, 'carries the panicApp marker');
ok(($priv[GoogleCalendar::ID_KEY] ?? null) === '42', 'carries the Backstage event id');

// ── Title prefixes ──────────────────────────────────────────────────────────
$hold = GoogleCalendar::eventBody(row(['status' => 'proposed', 'show_time' => '20:00:00']), APP_URL);
ok(($hold['summary'] ?? '') === 'HOLD — Test Show', 'proposed renders a HOLD prefix');

$canc = GoogleCalendar::eventBody(row(['status' => 'canceled', 'show_time' => '20:00:00']), APP_URL);
ok(($canc['summary'] ?? '') === 'CANCELED — Test Show', 'canceled renders a CANCELED prefix');
ok(($canc['status'] ?? '') === 'confirmed', 'canceled stays visible (not Google status=cancelled)');
ok(($canc['transparency'] ?? '') === 'transparent', 'canceled shows as Free, not blocking time');
ok(($b['transparency'] ?? '') === 'opaque', 'live events do block time');

// ── All-day holds: no invented times ────────────────────────────────────────
$allDay = GoogleCalendar::eventBody(row(['status' => 'proposed']), APP_URL);
ok(($allDay['start']['date'] ?? null) === '2026-09-10', 'no times -> all-day start');
ok(($allDay['end']['date'] ?? null) === '2026-09-11', "all-day end is exclusive (+1 day)");
ok(!isset($allDay['start']['dateTime']), 'no times -> no fabricated dateTime');

// ── Timed events ────────────────────────────────────────────────────────────
$timed = GoogleCalendar::eventBody(row(['show_time' => '20:00:00', 'end_time' => '23:00:00']), APP_URL);
ok(($timed['start']['dateTime'] ?? '') === '2026-09-10T20:00:00', 'show_time drives the start');
ok(($timed['end']['dateTime'] ?? '')   === '2026-09-10T23:00:00', 'end_time drives the end');
ok(($timed['start']['timeZone'] ?? '') === 'America/Los_Angeles', 'sends local time + venue tz, not UTC');

$doorsOnly = GoogleCalendar::eventBody(row(['doors_time' => '18:00:00']), APP_URL);
ok(($doorsOnly['start']['dateTime'] ?? '') === '2026-09-10T18:00:00', 'doors_time is the fallback start');
ok(($doorsOnly['end']['dateTime'] ?? '')   === '2026-09-10T21:00:00', 'missing end_time defaults to +3h');

// ── The two time edge cases ─────────────────────────────────────────────────
$midnight = GoogleCalendar::eventBody(row(['show_time' => '21:00:00', 'end_time' => '02:00:00']), APP_URL);
ok(($midnight['end']['dateTime'] ?? '') === '2026-09-11T02:00:00', 'past-midnight end rolls to the next day');

$degenerate = GoogleCalendar::eventBody(row(['show_time' => '19:00:00', 'end_time' => '19:00:00']), APP_URL);
ok(($degenerate['end']['dateTime'] ?? '') === '2026-09-10T22:00:00', 'end == start defaults to +3h, not a 24h block');

// ── Misc ────────────────────────────────────────────────────────────────────
$untitled = GoogleCalendar::eventBody(row(['title' => '  ', 'show_time' => '20:00:00']), APP_URL);
ok(($untitled['summary'] ?? '') === '(untitled event)', 'blank title gets a placeholder');

ok(str_contains((string) ($b['description'] ?? ''), 'event.html?id=42'), 'description deep-links back to Backstage');
ok(($b['location'] ?? '') === 'Mabuhay Gardens, 443 Broadway', 'location joins venue name + address');

$noVenue = GoogleCalendar::eventBody(row(['venue_name' => null, 'venue_address' => null, 'show_time' => '20:00:00']), APP_URL);
ok(!isset($noVenue['location']), 'empty location is omitted rather than sent blank');

$badTz = GoogleCalendar::eventBody(row(['venue_timezone' => 'Not/AZone', 'show_time' => '20:00:00']), APP_URL);
ok(($badTz['start']['timeZone'] ?? '') === 'America/Los_Angeles', 'unparseable venue timezone falls back');

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
