<?php
declare(strict_types=1);

/**
 * Generate (and optionally send) a "This week's shows" HTML email built from
 * the `events` table — one card per publicly-announced show in the window,
 * using each event's `description_public` copy.
 *
 * Usage:
 *   php scripts/generate-weekly-lineup-email.php [options]
 *
 * Options:
 *   --days=N        Size of the rolling window in days, starting today (default: 7).
 *   --venue=SLUG    Restrict to one venue (matches venues.slug). Default: all venues.
 *   --out=PATH      Write the rendered HTML preview to PATH.
 *                    Default: storage/mail/previews/weekly-lineup-{today}.html
 *   --no-preview    Skip writing the HTML preview file.
 *   --to=EMAILS     Comma-separated recipient list. When given, actually sends
 *                    the email via Panic\Mailer (multipart HTML + text). When
 *                    omitted (the default) the script only renders a preview —
 *                    nothing is sent. This keeps a first run safe to try.
 *   --subject=TEXT  Override the email subject line.
 *   --dry-run       Query and render only; print a summary and exit without
 *                    writing the preview file or sending anything.
 *
 * Selection gate mirrors src/Feed.php's public syndication feed, narrowed to
 * statuses that mean a show has actually been publicly announced:
 *   public_visibility = 1
 *   status IN ('published', 'advanced')
 *   date BETWEEN CURDATE() AND CURDATE() + (days-1)
 *
 * Examples:
 *   php scripts/generate-weekly-lineup-email.php
 *   php scripts/generate-weekly-lineup-email.php --days=10 --out=/tmp/preview.html
 *   php scripts/generate-weekly-lineup-email.php --to=you@example.com,me@example.com
 */

require __DIR__ . '/../src/bootstrap.php';

use Panic\Database;
use Panic\Env;
use Panic\EventEmailComposer;
use Panic\Mailer;

$root = dirname(__DIR__);
Env::load($root . '/.env');

// ── CLI args ─────────────────────────────────────────────────────────────────
$opts = [];
foreach (array_slice($argv, 1) as $arg) {
    if (!str_starts_with($arg, '--')) {
        continue;
    }
    $arg = substr($arg, 2);
    [$key, $value] = str_contains($arg, '=') ? explode('=', $arg, 2) : [$arg, true];
    $opts[$key] = $value;
}

$days       = max(1, (int) ($opts['days'] ?? 7));
$venueSlug  = trim((string) ($opts['venue'] ?? ''));
$dryRun     = isset($opts['dry-run']);
$skipPreview = isset($opts['no-preview']);
$recipients = array_filter(array_map('trim', explode(',', (string) ($opts['to'] ?? ''))));

$ts = fn () => date('Y-m-d H:i:s');

try {
    $db = new Database();
} catch (\Throwable $e) {
    fwrite(STDERR, '[weekly-lineup] DB connect failed: ' . $e->getMessage() . "\n");
    exit(1);
}

// ── Fetch events ─────────────────────────────────────────────────────────────
$events = EventEmailComposer::eligibleEventsInWindow($db, $days, $venueSlug);

printf("[%s] weekly-lineup: %d show(s) found in the next %d day(s)\n", $ts(), count($events), $days);

// ── Helpers ──────────────────────────────────────────────────────────────────
// (Event-query and card-rendering helpers now live in Panic\EventEmailComposer,
// shared with the in-app campaigns tool. weekRangeLabel() stays here — it's a
// "rolling window from today" concept the composer class doesn't need.)

function weekRangeLabel(int $days): string
{
    $start = new DateTime('today');
    $end   = (clone $start)->modify('+' . ($days - 1) . ' days');
    if ($start->format('Y-m') === $end->format('Y-m')) {
        return $start->format('F j') . ' – ' . $end->format('j, Y');
    }
    if ($start->format('Y') === $end->format('Y')) {
        return $start->format('F j') . ' – ' . $end->format('F j, Y');
    }
    return $start->format('F j, Y') . ' – ' . $end->format('F j, Y');
}

function esc(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

// ── Build the digest body ─────────────────────────────────────────────────────
['html' => $eventsHtml, 'text' => $eventsText] = EventEmailComposer::buildEventsFragment($db, $events);

$venueName = (string) (getenv('VENUE_NAME') ?: ($events[0]['venue_name'] ?? 'Backstage'));
$venueCity = (string) (getenv('VENUE_CITY') ?: ($events[0]['venue_city'] ?? ''));
$venueState = (string) (getenv('VENUE_STATE') ?: ($events[0]['venue_state'] ?? ''));
$venueAddress = (string) ($events[0]['venue_address'] ?? '');
$addressLine = implode(', ', array_filter([$venueAddress, trim(implode(', ', array_filter([$venueCity, $venueState])))]));

$weekRange = weekRangeLabel($days);
$eventCount = count($events);
$footerNote = $eventCount > 0
    ? "You're receiving this because you asked to hear about shows at {$venueName}."
    : 'Sent from Backstage.';

$addressLineRaw = $addressLine !== '' ? $addressLine : $venueName;
$preheaderRaw = $eventCount > 0
    ? "{$eventCount} show" . ($eventCount === 1 ? '' : 's') . " on stage this week at {$venueName}."
    : "See what's coming up at {$venueName}.";

// Two separate substitution maps — HTML values are entity-escaped, text values
// are left raw. Sharing one escaped map across both templates would leak
// entities like &#039; into the plain-text part, so each template only ever
// sees the variables it actually references, in the encoding it expects.
$htmlVars = [
    'venue_name'         => esc($venueName),
    'venue_address_line' => esc($addressLineRaw),
    'week_range'         => esc($weekRange),
    'preheader'          => esc($preheaderRaw),
    'events_html'        => $eventsHtml,
    'footer_note'        => esc($footerNote),
];
$textVars = [
    'venue_name'         => $venueName,
    'venue_address_line' => $addressLineRaw,
    'week_range'         => $weekRange,
    'events_text'        => $eventsText,
    'footer_note'        => $footerNote,
];

if ($dryRun) {
    printf("[%s] dry-run: rendered %d event card(s), no files written, nothing sent\n", $ts(), $eventCount);
    exit(0);
}

// ── Render templates (same {{key}} substitution Mailer::sendTemplate uses) ───
$templateDir = $root . '/storage/email-templates';
$html = (string) file_get_contents($templateDir . '/weekly-lineup.html');
$text = (string) file_get_contents($templateDir . '/weekly-lineup.txt');
foreach ($htmlVars as $key => $value) {
    $html = str_replace('{{' . $key . '}}', $value, $html);
}
foreach ($textVars as $key => $value) {
    $text = str_replace('{{' . $key . '}}', $value, $text);
}

// ── Preview file ──────────────────────────────────────────────────────────────
if (!$skipPreview) {
    $previewDir = $root . '/storage/mail/previews';
    if (!is_dir($previewDir)) {
        mkdir($previewDir, 0755, true);
    }
    $outPath = (string) ($opts['out'] ?? ($previewDir . '/weekly-lineup-' . date('Y-m-d') . '.html'));
    file_put_contents($outPath, $html);
    printf("[%s] preview written: %s\n", $ts(), $outPath);

    $textOutPath = preg_replace('/\.html$/', '.txt', $outPath) ?? ($outPath . '.txt');
    file_put_contents($textOutPath, $text);
    printf("[%s] text preview written: %s\n", $ts(), $textOutPath);
}

// ── Send (only when --to was given) ──────────────────────────────────────────
if (!$recipients) {
    printf("[%s] no --to recipients given — nothing sent (preview only)\n", $ts());
    exit(0);
}

$subject = (string) ($opts['subject'] ?? "This Week's Shows at {$venueName} — {$weekRange}");
$mailer  = new Mailer($root);

foreach ($recipients as $to) {
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        fwrite(STDERR, "[{$ts()}] skipping invalid recipient: {$to}\n");
        continue;
    }
    try {
        $mailer->send($to, $subject, $text, $html, 'weekly-lineup');
        printf("[%s] sent to %s\n", $ts(), $to);
    } catch (\Throwable $e) {
        fwrite(STDERR, "[{$ts()}] send failed for {$to}: " . $e->getMessage() . "\n");
    }
}
