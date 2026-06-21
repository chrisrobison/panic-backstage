<?php
declare(strict_types=1);

namespace Panic;

/**
 * WelcomeMessage — the one-time system greeting that lands in every staff
 * member's in-app Inbox (no email is sent).
 *
 * ensureFor() is idempotent: it inserts the welcome only when the user does not
 * already have a row with template = 'welcome'. That makes it safe to call from
 * both the backfill script (existing users) and the Me endpoint (every user, on
 * first app load — covering all account-creation paths without scattering hooks).
 *
 * The body is self-contained inline-styled HTML so it renders correctly inside
 * the sandboxed message-preview iframe, which has no access to the app stylesheet.
 * Links use absolute APP_URL targets and open in a new tab.
 */
final class WelcomeMessage
{
    public const TEMPLATE = 'welcome';

    /**
     * Insert the welcome message into a user's inbox if they don't have it yet.
     *
     * @return bool true when a message was inserted, false when it already
     *              existed or the insert failed (never throws).
     */
    public static function ensureFor(Database $db, int $userId, ?string $name = null, ?string $email = null): bool
    {
        if ($userId <= 0) {
            return false;
        }
        try {
            $exists = $db->one(
                'SELECT 1 FROM messages WHERE recipient_user_id = ? AND template = ? LIMIT 1',
                [$userId, self::TEMPLATE]
            );
            if ($exists) {
                return false;
            }

            [$subject, $html, $text] = self::content($name);
            $db->run(
                'INSERT INTO messages
                    (sender_user_id, recipient_user_id, recipient_email, subject, body_text, body_html, template)
                 VALUES (NULL, ?, ?, ?, ?, ?, ?)',
                [$userId, (string) ($email ?? ''), $subject, $text, $html, self::TEMPLATE]
            );
            return true;
        } catch (\Throwable) {
            // Never let a greeting failure break login / app load / provisioning.
            return false;
        }
    }

    /**
     * Build the welcome message content.
     *
     * @return array{0:string,1:string,2:string} [subject, html, text]
     */
    private static function content(?string $name): array
    {
        $base    = rtrim((string) (getenv('APP_URL') ?: ''), '/');
        $helpUrl = $base . '/#help';
        $opsUrl  = $base . '/docs/ops-manual.html';

        $first   = trim((string) (explode(' ', trim((string) $name))[0] ?? ''));
        $hi      = $first !== '' ? 'Welcome aboard, ' . htmlspecialchars($first, ENT_QUOTES) . '!' : 'Welcome aboard!';

        $subject = 'Welcome to Panic Backstage';

        $html = <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
</head>
<body style="margin:0;padding:0;background:#f4f4f7;-webkit-font-smoothing:antialiased;font-family:system-ui,-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
  <div style="max-width:640px;margin:0 auto;padding:24px 16px;">
    <div style="background:#16182b;border-radius:14px 14px 0 0;padding:30px 30px 24px;">
      <div style="font-size:12px;font-weight:800;letter-spacing:.14em;text-transform:uppercase;color:#ff4d5e;">⚡ Panic Backstage</div>
      <h1 style="margin:10px 0 0;font-size:24px;line-height:1.25;color:#ffffff;font-weight:800;">{$hi}</h1>
      <p style="margin:8px 0 0;font-size:15px;color:#b9bbcc;">Good shows. No surprises.</p>
    </div>
    <div style="background:#ffffff;border-radius:0 0 14px 14px;padding:26px 30px 30px;color:#23252f;font-size:15px;line-height:1.62;border:1px solid #e7e7ee;border-top:none;">
      <p style="margin:0 0 14px;">This is your home base for running every show — from first hold through settlement. A few quick pointers to get you going:</p>
      <ul style="margin:0 0 18px;padding-left:20px;">
        <li style="margin:0 0 8px;"><strong>Dashboard</strong> — your at-a-glance view of upcoming shows, open items, and what needs attention next.</li>
        <li style="margin:0 0 8px;"><strong>Events</strong> — open any show to manage its lineup, tasks, run sheet, assets, ticketing, and settlement.</li>
        <li style="margin:0 0 8px;"><strong>Messages</strong> — you're reading one now. System notifications and staff messages land in your <strong>Inbox</strong>; reply or compose from here, and tidy up with <strong>Archive</strong>.</li>
        <li style="margin:0;"><strong>Help</strong> — searchable, in-app guidance for every screen.</li>
      </ul>
      <div style="text-align:center;margin:24px 0 6px;">
        <a href="{$helpUrl}" target="_blank" rel="noopener" style="display:inline-block;margin:6px;padding:12px 22px;background:#ff4d5e;color:#ffffff;text-decoration:none;font-weight:700;font-size:14px;border-radius:8px;">Open in-app Help</a>
        <a href="{$opsUrl}" target="_blank" rel="noopener" style="display:inline-block;margin:6px;padding:12px 22px;background:#ffffff;color:#16182b;text-decoration:none;font-weight:700;font-size:14px;border-radius:8px;border:1.5px solid #d6d6e0;">Read the Ops Manual</a>
      </div>
      <p style="margin:20px 0 0;color:#7c7f8c;font-size:13px;">Questions or something not working? Reach out to your venue admin — they can help with access and getting set up.</p>
    </div>
    <p style="text-align:center;color:#a6a8b4;font-size:12px;margin:18px 0 0;">Panic Backstage · Built for venues. Run by humans.</p>
  </div>
</body>
</html>
HTML;

        $textHi = $first !== '' ? "Welcome aboard, {$first}!" : 'Welcome aboard!';
        $text = <<<TEXT
{$textHi}

Panic Backstage is your home base for running every show — from first hold
through settlement. A few quick pointers:

- Dashboard — upcoming shows, open items, and what needs attention next.
- Events — open any show to manage lineup, tasks, run sheet, assets,
  ticketing, and settlement.
- Messages — system notifications and staff messages land in your Inbox;
  reply or compose from here, and tidy up with Archive.
- Help — searchable, in-app guidance for every screen.

Open the in-app Help:  {$helpUrl}
Read the Ops Manual:   {$opsUrl}

Questions or something not working? Reach out to your venue admin.

Panic Backstage · Built for venues. Run by humans.
TEXT;

        return [$subject, $html, $text];
    }
}
