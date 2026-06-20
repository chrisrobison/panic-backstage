<?php
/**
 * Send a realistic test ticket email (with embedded QR code) to verify the
 * multipart/related CID-inline + attachment MIME structure end-to-end.
 *
 * Usage:
 *   php scripts/send-test-ticket-email.php
 *
 * No database required — everything is fabricated.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/src/bootstrap.php';
Panic\Env::load($root . '/.env');

// Derive APP_HOST from APP_URL when not set explicitly.
if (!getenv('APP_HOST')) {
    $appUrl = getenv('APP_URL') ?: 'https://localhost';
    $host   = parse_url($appUrl, PHP_URL_HOST) ?: 'localhost';
    putenv('APP_HOST=' . $host);
}

$appUrl = rtrim((string)(getenv('APP_URL') ?: ''), '/');
$host   = (string)(getenv('APP_HOST') ?: 'localhost');

// ── Fake ticket data ──────────────────────────────────────────────────────────
// Generates a realistic-looking token and ticket code without touching the DB.
$token   = bin2hex(random_bytes(16));               // e.g. 3f8a91b2…
$code    = 'TKT-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
$viewUrl = $appUrl . '/t/' . rawurlencode($token);

$eventTitle = 'Punk Night ft. The Mutants &amp; Urban Assault — TEST EMAIL';
$buyerName  = 'Test Customer';

// ── Generate QR PNG server-side ───────────────────────────────────────────────
$cid      = 'qr-1-' . bin2hex(random_bytes(6)) . '@' . $host;
$pngBytes = Panic\QrCode::generatePng($token, 300);

$inline = [];
if ($pngBytes !== '') {
    $inline[$cid] = $pngBytes;
    $qrSrc = 'cid:' . $cid;
    echo "✅ QR PNG generated (" . strlen($pngBytes) . " bytes)\n";
} else {
    // GD unavailable — fall back to external URL.
    $qrSrc = htmlspecialchars(
        $appUrl . '/assets/qr.png?text=' . rawurlencode($token) . '&size=300',
        ENT_QUOTES, 'UTF-8'
    );
    echo "⚠️  QR PNG generation failed — falling back to external URL\n";
}

$safeView  = htmlspecialchars($viewUrl, ENT_QUOTES, 'UTF-8');
$codeHtml  = htmlspecialchars($code,    ENT_QUOTES, 'UTF-8');
$safeName  = htmlspecialchars($buyerName, ENT_QUOTES, 'UTF-8');

// ── Build ticket HTML block (matches production format) ───────────────────────
$ticketsHtml = '<div style="padding:16px 0;border-bottom:1px solid #2e2929;">'
    . '<div style="font-size:13px;color:#a9a097;letter-spacing:1px;text-transform:uppercase;">Ticket 1</div>'
    . '<div style="margin-top:4px;font-size:16px;font-weight:bold;color:#fff;">' . $codeHtml . '</div>'
    . '<div style="margin-top:14px;text-align:center;">'
    . '<a href="' . $safeView . '" style="display:inline-block;line-height:0;border:2px solid #3a3434;border-radius:4px;">'
    . '<img src="' . $qrSrc . '" alt="QR code — tap to open your ticket" width="200" height="200"'
    . ' style="display:block;background:#ffffff;padding:10px;">'
    . '</a>'
    . '</div>'
    . '<div style="margin-top:8px;font-size:13px;color:#b5aba2;text-align:center;">'
    . 'Screenshot or save this QR &mdash; show it at the door to get in.'
    . '</div>'
    . '<div style="margin-top:10px;font-size:13px;">'
    . '<a href="' . $safeView . '" style="color:#c9b27e;font-weight:bold;">View your ticket &amp; QR &rarr;</a>'
    . '</div></div>';

$ticketsText = "  {$code}  View ticket + QR:  {$viewUrl}\n"
             . "\n"
             . "  (This is a test email — the token above is not a real ticket.)\n";

$greeting     = 'Hi <strong style="color:#fff;">' . $safeName . '</strong>,';

// ── Send ──────────────────────────────────────────────────────────────────────
$mailer = new Panic\Mailer($root);

$recipients = [
    'christopher.robison@gmail.com',
    'cdr@cdr2.com',
];

$subject = '[TEST] Your tickets for Punk Night ft. The Mutants & Urban Assault';

foreach ($recipients as $to) {
    $mailer->sendTemplate(
        $to,
        $subject,
        'ticket-purchase',
        [
            'event_title'  => $eventTitle,
            'greeting'     => $greeting,
            'tickets_html' => $ticketsHtml,
            'tickets_text' => $ticketsText,
        ],
        $inline
    );
    echo "📧 Sent to: {$to}\n";
}

echo "\n";
echo "Token : {$token}\n";
echo "Code  : {$code}\n";
echo "QR CID: {$cid}\n";
echo "\nDone.\n";
