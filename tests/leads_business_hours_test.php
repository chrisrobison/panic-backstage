<?php
/**
 * Tests for src/Leads/BusinessHours.php — business-hours-aware SLA due-date
 * arithmetic for the Booking Inbox. Fully hermetic: pure date math, no DB.
 *
 * Run with: php tests/leads_business_hours_test.php
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Panic\Leads\BusinessHours;

$passed = 0;
$failed = 0;

function ok(bool $cond, string $label): void {
    global $passed, $failed;
    if ($cond) { echo "  ✓ $label\n"; $passed++; }
    else        { echo "  ✗ FAIL: $label\n"; $failed++; }
}

echo "\n=== Booking Inbox business-hours tests ===\n\n";

$tz = 'America/Los_Angeles';
$utc = new DateTimeZone('UTC');

// Monday 2026-07-20 10:00 America/Los_Angeles == 17:00 UTC.
$mondayMorningUtc = new DateTimeImmutable('2026-07-20 17:00:00', $utc);

// ── Fits within the same business day ────────────────────────────────────────

$r = BusinessHours::addBusinessHours($mondayMorningUtc, 2.0, $tz, '09:00:00', '18:00:00', '1,2,3,4,5');
ok($r->setTimezone(new DateTimeZone($tz))->format('Y-m-d H:i') === '2026-07-20 12:00',
   "10am + 2 business hours, same business day, within window => 12pm local");

// ── Landing after hours rolls to the next business day's start ─────────────

// Monday 2026-07-20 20:00 local (after the 18:00 close).
$mondayEveningUtc = (new DateTimeImmutable('2026-07-20 20:00:00', new DateTimeZone($tz)))->setTimezone($utc);
$r2 = BusinessHours::addBusinessHours($mondayEveningUtc, 1.0, $tz, '09:00:00', '18:00:00', '1,2,3,4,5');
ok($r2->setTimezone(new DateTimeZone($tz))->format('Y-m-d H:i') === '2026-07-21 10:00',
   "8pm Monday (after close) + 1hr => rolls to 9am Tuesday, then adds the hour => 10am");

// ── Landing before hours opens jumps to the day's start first ──────────────

$mondayEarlyUtc = (new DateTimeImmutable('2026-07-20 06:00:00', new DateTimeZone($tz)))->setTimezone($utc);
$r3 = BusinessHours::addBusinessHours($mondayEarlyUtc, 1.0, $tz, '09:00:00', '18:00:00', '1,2,3,4,5');
ok($r3->setTimezone(new DateTimeZone($tz))->format('Y-m-d H:i') === '2026-07-20 10:00',
   "6am Monday (before open) + 1hr => starts the clock at 9am, ends 10am");

// ── Overnight/weekend inquiries: Friday evening skips the weekend ──────────

// Friday 2026-07-24 19:00 local (after close).
$fridayEveningUtc = (new DateTimeImmutable('2026-07-24 19:00:00', new DateTimeZone($tz)))->setTimezone($utc);
$r4 = BusinessHours::addBusinessHours($fridayEveningUtc, 2.0, $tz, '09:00:00', '18:00:00', '1,2,3,4,5');
ok($r4->setTimezone(new DateTimeZone($tz))->format('Y-m-d H:i') === '2026-07-27 11:00',
   "Friday after close + 2hrs => skips Sat/Sun entirely, lands Monday 11am");

// ── Duration spanning multiple business days ────────────────────────────────

// Monday 9am + 20 business hours, 9-hour window (9am-6pm) => 2 full days (18h)
// consumed, 2h remainder on the 3rd business day => Wednesday 11am.
$mondayOpenUtc = (new DateTimeImmutable('2026-07-20 09:00:00', new DateTimeZone($tz)))->setTimezone($utc);
$r5 = BusinessHours::addBusinessHours($mondayOpenUtc, 20.0, $tz, '09:00:00', '18:00:00', '1,2,3,4,5');
ok($r5->setTimezone(new DateTimeZone($tz))->format('Y-m-d H:i') === '2026-07-22 11:00',
   "Monday 9am + 20 business hours (9h/day window) => Mon+Tue full (18h) + 2h => Wed 11am");

// ── Exactly at the window boundary stays on the same day ───────────────────

$r6 = BusinessHours::addBusinessHours($mondayOpenUtc, 9.0, $tz, '09:00:00', '18:00:00', '1,2,3,4,5');
ok($r6->setTimezone(new DateTimeZone($tz))->format('Y-m-d H:i') === '2026-07-20 18:00',
   "Monday 9am + exactly 9 business hours (a full day's window) => 6pm same day");

echo "\nBooking Inbox business-hours: $passed passed, $failed failed.\n";
exit($failed > 0 ? 1 : 0);
