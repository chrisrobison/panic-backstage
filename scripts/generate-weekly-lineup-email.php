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
$where  = [
    'e.public_visibility = 1',
    "e.status IN ('published', 'advanced')",
    'e.date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)',
];
$params = [$days - 1];

if ($venueSlug !== '') {
    $where[]  = 'v.slug = ?';
    $params[] = $venueSlug;
}

$events = $db->all(
    'SELECT e.*, v.name AS venue_name, v.address AS venue_address,
            v.city AS venue_city, v.state AS venue_state,
            (SELECT a.file_path FROM event_assets a
               WHERE a.event_id = e.id AND a.asset_type = \'flyer\'
                 AND a.approval_status = \'approved\'
               ORDER BY a.created_at DESC LIMIT 1) AS flyer_path
     FROM events e
     JOIN venues v ON v.id = e.venue_id
     WHERE ' . implode(' AND ', $where) . '
     ORDER BY e.date ASC, e.show_time ASC',
    $params
);

printf("[%s] weekly-lineup: %d show(s) found in the next %d day(s)\n", $ts(), count($events), $days);

// ── Helpers ──────────────────────────────────────────────────────────────────

function appUrl(): string
{
    return rtrim((string) (getenv('APP_URL') ?: ''), '/');
}

function eventUrl(array $event): string
{
    return appUrl() . '/event.html?slug=' . rawurlencode((string) $event['slug']);
}

function flyerUrl(array $event): string
{
    $path = (string) ($event['flyer_path'] ?? '');
    if ($path === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }
    return appUrl() . '/' . ltrim($path, '/');
}

function fmtTime(?string $time): string
{
    if (!$time) {
        return '';
    }
    $tstamp = strtotime('1970-01-01 ' . $time);
    return $tstamp ? date('g:i A', $tstamp) : '';
}

function humanWhen(array $event): string
{
    $date = strtotime((string) $event['date']);
    if ($date === false) {
        return '';
    }
    $out   = date('D, M j', $date);
    $doors = fmtTime($event['doors_time'] ?? null);
    $show  = fmtTime($event['show_time'] ?? null);
    if ($doors && $show) {
        $out .= ' · Doors ' . $doors . ' / Show ' . $show;
    } elseif ($show) {
        $out .= ' · Show ' . $show;
    } elseif ($doors) {
        $out .= ' · Doors ' . $doors;
    }
    return $out;
}

function humanRoom(array $event): string
{
    $room = (string) ($event['room'] ?? '');
    return $room === '' ? '' : ucwords(str_replace('_', ' ', $room));
}

function humanPrice(array $event): string
{
    $price = $event['ticket_price'] ?? null;
    if ($price === null || $price === '' || (float) $price <= 0.0) {
        return 'Free';
    }
    return '$' . number_format((float) $price, (float) $price == floor((float) $price) ? 0 : 2);
}

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

/** Truncate to a max length on a word boundary, appending an ellipsis. */
function clip(string $text, int $max): string
{
    $text = trim($text);
    if (mb_strlen($text) <= $max) {
        return $text;
    }
    $clipped = mb_substr($text, 0, $max);
    $lastSpace = mb_strrpos($clipped, ' ');
    if ($lastSpace !== false) {
        $clipped = mb_substr($clipped, 0, $lastSpace);
    }
    return $clipped . '…';
}

/** @param array<int, array<string,mixed>> $lineup */
function lineupLine(array $lineup, string $headliner): string
{
    $support = array_values(array_filter(
        array_map(static fn ($row) => (string) $row['display_name'], $lineup),
        static fn ($name) => $name !== '' && strcasecmp($name, $headliner) !== 0
    ));
    return $support ? 'With ' . implode(', ', $support) : '';
}

// ── Render one event card (HTML) ─────────────────────────────────────────────
function renderEventCardHtml(array $event, array $lineup): string
{
    $title    = trim((string) $event['title']);
    $when     = humanWhen($event);
    $room     = humanRoom($event);
    $price    = humanPrice($event);
    $ageR     = trim((string) ($event['age_restriction'] ?? ''));
    $desc     = clip((string) ($event['description_public'] ?? ''), 320);
    $support  = lineupLine($lineup, $title);
    $flyer    = flyerUrl($event);
    $ticketUrl = trim((string) ($event['ticket_url'] ?? ''));
    $ctaUrl   = $ticketUrl !== '' ? $ticketUrl : eventUrl($event);
    $ctaLabel = $ticketUrl !== '' ? 'Get Tickets' : 'Event Details';

    $flyerHtml = '';
    if ($flyer !== '') {
        $flyerHtml = '<img src="' . esc($flyer) . '" alt="' . esc($title) . ' flyer" width="100%" '
            . 'style="display:block;width:100%;max-width:100%;height:auto;border-radius:14px 14px 0 0;">';
    }

    $tags = array_filter([$room, $ageR, $price]);
    $tagsHtml = '';
    foreach ($tags as $tag) {
        $tagsHtml .= '<span style="display:inline-block;margin:0 6px 6px 0;padding:3px 10px;'
            . 'font-size:11px;letter-spacing:0.5px;text-transform:uppercase;color:#c9b27e;'
            . 'border:1px solid #4a4340;border-radius:999px;">' . esc($tag) . '</span>';
    }

    $supportHtml = $support !== ''
        ? '<div style="margin-top:6px;font-size:13px;color:#9b8e82;">' . esc($support) . '</div>'
        : '';

    $descHtml = $desc !== ''
        ? '<div style="margin-top:10px;font-size:14px;line-height:1.55;color:#d8d1c8;">' . nl2br(esc($desc)) . '</div>'
        : '';

    return '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" '
        . 'style="margin:0 0 20px;background:#171717;border:1px solid #3b3636;border-radius:14px;overflow:hidden;">'
        . '<tr><td>' . $flyerHtml . '</td></tr>'
        . '<tr><td style="padding:20px 22px;">'
        . '<div style="font-size:13px;font-weight:bold;color:#fff;letter-spacing:0.3px;">' . esc($when) . '</div>'
        . '<h2 style="margin:6px 0 8px;font-size:20px;line-height:1.3;color:#fff;font-weight:800;">' . esc($title) . '</h2>'
        . $supportHtml
        . '<div style="margin-top:10px;">' . $tagsHtml . '</div>'
        . $descHtml
        . '<table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin-top:16px;">'
        . '<tr><td bgcolor="#c1121f" style="border-radius:999px;">'
        . '<a href="' . esc($ctaUrl) . '" style="display:inline-block;padding:11px 22px;color:#ffffff;'
        . 'text-decoration:none;font-weight:bold;font-size:13px;border-radius:999px;">' . esc($ctaLabel) . '</a>'
        . '</td></tr></table>'
        . '</td></tr></table>';
}

// ── Render one event block (plain text) ──────────────────────────────────────
function renderEventBlockText(array $event, array $lineup): string
{
    $lines = [];
    $lines[] = strtoupper(trim((string) $event['title']));
    $lines[] = humanWhen($event);

    $tags = array_filter([humanRoom($event), trim((string) ($event['age_restriction'] ?? '')), humanPrice($event)]);
    if ($tags) {
        $lines[] = implode(' · ', $tags);
    }

    $support = lineupLine($lineup, trim((string) $event['title']));
    if ($support !== '') {
        $lines[] = $support;
    }

    $desc = clip((string) ($event['description_public'] ?? ''), 320);
    if ($desc !== '') {
        $lines[] = '';
        $lines[] = $desc;
    }

    $ticketUrl = trim((string) ($event['ticket_url'] ?? ''));
    $lines[] = '';
    $lines[] = ($ticketUrl !== '' ? 'Tickets: ' : 'Details: ') . ($ticketUrl !== '' ? $ticketUrl : eventUrl($event));

    return implode("\n", $lines);
}

// ── Build the digest body ─────────────────────────────────────────────────────
$eventsHtml = '';
$eventsText = '';

if (!$events) {
    $eventsHtml = '<div style="padding:24px 0;text-align:center;font-size:15px;color:#9b8e82;">'
        . 'No public shows are on the books for this window yet — check back soon.</div>';
    $eventsText = 'No public shows are on the books for this window yet — check back soon.';
} else {
    $htmlCards = [];
    $textBlocks = [];
    foreach ($events as $event) {
        $lineup = $db->all(
            "SELECT display_name FROM event_lineup WHERE event_id = ? AND status <> 'canceled' ORDER BY billing_order, set_time",
            [(int) $event['id']]
        );
        $htmlCards[]  = renderEventCardHtml($event, $lineup);
        $textBlocks[] = renderEventBlockText($event, $lineup);
    }
    $eventsHtml = implode('', $htmlCards);
    $eventsText = implode("\n\n----\n\n", $textBlocks);
}

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
