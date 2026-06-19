<?php
/**
 * One-time script: send a brief apology to ticket buyers who received
 * duplicate confirmation emails due to a webhook retry bug (now fixed).
 *
 * Run once:
 *   php scripts/send-apology-emails.php
 *   php scripts/send-apology-emails.php --dry-run   # preview only, no sends
 *
 * Affected customers (all received 5 copies of their "I am a Snail" tickets):
 *   jabeard@gmail.com
 *   meganelizabethmackey@gmail.com
 *   trashbucket@protonmail.com
 *
 * Note: jamie@example.com appears in logs as a test address (no-op).
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/src/bootstrap.php';

Panic\Env::load($root . '/.env');

$dryRun = in_array('--dry-run', $_SERVER['argv'] ?? [], true);

$affected = [
    ['email' => 'jabeard@gmail.com',            'name' => null],
    ['email' => 'meganelizabethmackey@gmail.com','name' => null],
    ['email' => 'trashbucket@protonmail.com',    'name' => null],
];

$mailer = new Panic\Mailer($root);

foreach ($affected as ['email' => $email, 'name' => $name]) {
    $greeting = $name ? "Hi {$name}," : 'Hi there,';
    $greetingHtml = $name
        ? 'Hi <strong style="color:#fff;">' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</strong>,'
        : 'Hi there,';

    $textBody = <<<TEXT
{$greeting}

We owe you an apology.

Due to a bug in our payment processing system you received several duplicate
confirmation emails for your "I am a Snail" tickets over the past few days.
That was entirely our fault — we're sorry for the inbox clutter.

The good news: your tickets are perfectly valid. You only need the one ticket
link you received (any copy will work — they all contain the same QR code).
Nothing has changed on your end, and you're all set for the show.

The bug has been fixed and this won't happen again.

See you at the door!

—
Mabuhay Gardens · 443 Broadway · San Francisco, CA
Punk rock, paperwork, and the occasional miracle.
TEXT;

    $htmlBody = <<<HTML
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
</head>
<body style="margin:0;padding:0;background:#151515;font-family:Arial,Helvetica,sans-serif;color:#f5f0e8;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#151515;padding:24px 0;">
    <tr>
      <td align="center" style="padding:0 14px;">
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:600px;background:#211f1f;border:1px solid #3a3434;border-radius:18px;overflow:hidden;">
          <tr>
            <td style="background:#111;padding:24px 32px;border-bottom:4px solid #c1121f;">
              <div style="font-size:13px;letter-spacing:2px;text-transform:uppercase;color:#c9b27e;font-weight:bold;">Mabuhay Gardens</div>
              <h1 style="margin:10px 0 0;font-size:22px;line-height:1.2;color:#fff;font-weight:800;">A quick apology</h1>
            </td>
          </tr>
          <tr>
            <td style="padding:28px 32px;">
              <p style="margin:0 0 16px;font-size:16px;line-height:1.6;color:#f5f0e8;">{$greetingHtml}</p>
              <p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#f5f0e8;">
                We owe you an apology. Due to a bug in our payment processing system you received
                <strong style="color:#fff;">several duplicate confirmation emails</strong> for your
                <em>&ldquo;I am a Snail&rdquo;</em> tickets over the past few days.
                That was entirely our fault &mdash; we&rsquo;re sorry for the inbox clutter.
              </p>
              <p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#f5f0e8;">
                <strong style="color:#74e29a;">The good news:</strong> your tickets are perfectly valid.
                You only need the one ticket link you received (any copy will work &mdash; they all
                contain the same QR code). Nothing has changed on your end, and you&rsquo;re all set for the show.
              </p>
              <p style="margin:0;font-size:15px;line-height:1.6;color:#b5aba2;">
                The bug has been fixed and this won&rsquo;t happen again. See you at the door!
              </p>
            </td>
          </tr>
          <tr>
            <td style="background:#171717;padding:16px 32px;border-top:1px solid #342e2e;">
              <div style="font-size:12px;line-height:1.5;color:#80766e;">
                Mabuhay Gardens &middot; 443 Broadway &middot; San Francisco, CA<br>
                Punk rock, paperwork, and the occasional miracle.
              </div>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;

    if ($dryRun) {
        echo "[DRY-RUN] Would send apology to: {$email}\n";
        continue;
    }

    try {
        $mailer->send(
            $email,
            'A quick apology from Mabuhay Gardens',
            $textBody,
            $htmlBody
        );
        echo "Sent apology to: {$email}\n";
    } catch (\Throwable $e) {
        echo "ERROR sending to {$email}: " . $e->getMessage() . "\n";
    }
}

echo "Done.\n";
