<?php
declare(strict_types=1);

namespace Panic;

/**
 * Public ticket view: GET /t/{token}
 *
 * The {token} is the plaintext secret delivered once by email. We never store
 * it — only sha256(token) is persisted in tickets.token_hash — so this looks
 * the ticket up by hashing the supplied token and matching the hash.
 *
 * Renders a standalone HTML page (no app shell, no auth) showing the holder
 * name, event, status, and a scannable QR. The QR is produced by the existing
 * self-rendering SVG at /assets/qr.svg?text=<bare token>, kept deliberately
 * short (the bare token) so the encoded payload stays scannable. At the door,
 * the scanner reads the same bare token and POSTs it to the redeem endpoint.
 *
 * Returns an HTML Response (text/html) rather than JSON: this URL is opened
 * directly in a browser / wallet, and is also what the QR encodes.
 */
final class TicketView extends BaseEndpoint
{
    public function handle(Request $request): Response
    {
        if ($request->method() !== 'GET') {
            return Response::methodNotAllowed();
        }

        $token = (string) ($this->params['token'] ?? $request->query('token', ''));
        $token = trim($token);
        if ($token === '') {
            return $this->html($this->errorPage('Ticket not found'), 404);
        }

        $hash = hash('sha256', $token);
        $ticket = $this->db->one(
            "SELECT t.id, t.code, t.status, t.holder_name, t.holder_email,
                    t.redeemed_at, t.event_id,
                    tt.name  AS ticket_type_name,
                    e.title  AS event_title, e.slug AS event_slug,
                    e.date AS event_date, e.doors_time, e.show_time,
                    v.name AS venue_name, v.city AS venue_city, v.state AS venue_state
               FROM tickets t
               JOIN ticket_types tt ON tt.id = t.ticket_type_id
               JOIN events e        ON e.id  = t.event_id
               LEFT JOIN venues v   ON v.id  = e.venue_id
              WHERE t.token_hash = ?",
            [$hash]
        );

        if ($ticket === null) {
            return $this->html($this->errorPage('Ticket not found'), 404);
        }

        return $this->html($this->ticketPage($ticket, $token), 200);
    }

    private function ticketPage(array $ticket, string $token): string
    {
        $status   = (string) $ticket['status'];
        $statusUi = match ($status) {
            'redeemed' => ['Already scanned', 'redeemed'],
            'void'     => ['Void', 'void'],
            default    => ['Valid', 'issued'],
        };

        $eventTitle = $this->e((string) $ticket['event_title']);
        $typeName   = $this->e((string) $ticket['ticket_type_name']);
        $holder     = $this->e((string) ($ticket['holder_name'] ?? 'Ticket holder'));
        $code       = $this->e((string) $ticket['code']);
        $venueLine  = $this->venueLine($ticket);
        $dateLine   = $this->dateLine($ticket);

        // QR encodes the bare secret token (short + scannable). Door scanners
        // submit this exact value to the redeem endpoint.
        $qrSrc = $this->appUrl() . '/assets/qr.svg?text=' . rawurlencode($token);

        $statusBadge = '<span class="tk-badge tk-' . $this->e($statusUi[1]) . '">'
            . $this->e($statusUi[0]) . '</span>';

        $redeemedNote = '';
        if ($status === 'redeemed' && !empty($ticket['redeemed_at'])) {
            $redeemedNote = '<p class="tk-note">Scanned at the door on '
                . $this->e((string) $ticket['redeemed_at']) . '.</p>';
        } elseif ($status === 'void') {
            $redeemedNote = '<p class="tk-note">This ticket has been voided and will not admit entry.</p>';
        }

        $qrBlock = $status === 'issued'
            ? '<div class="tk-qr"><img src="' . $this->e($qrSrc) . '" alt="Entry QR code" width="240" height="240"></div>'
            : '<div class="tk-qr tk-qr-disabled"><img src="' . $this->e($qrSrc) . '" alt="Entry QR code" width="240" height="240"></div>';

        return <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow, noarchive">
<title>{$eventTitle} — Ticket</title>
<link rel="stylesheet" href="{$this->appUrl()}/assets/app.css">
<style>
  body.tk-body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#0d0d12;color:#f4f4f6;font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;padding:24px;}
  .tk-card{width:100%;max-width:420px;background:#16161d;border:1px solid #2a2a36;border-radius:18px;overflow:hidden;box-shadow:0 18px 50px rgba(0,0,0,.5);}
  .tk-head{padding:24px 24px 16px;border-bottom:1px dashed #2a2a36;}
  .tk-head h1{margin:0 0 4px;font-size:1.35rem;line-height:1.2;}
  .tk-head .tk-type{margin:0;color:#a8a8b8;font-size:.95rem;}
  .tk-meta{padding:16px 24px;display:grid;gap:6px;font-size:.9rem;color:#cfcfda;}
  .tk-meta strong{color:#f4f4f6;}
  .tk-qr{display:flex;justify-content:center;padding:20px 24px;background:#fff;}
  .tk-qr img{display:block;}
  .tk-qr-disabled{filter:grayscale(1);opacity:.4;}
  .tk-foot{padding:16px 24px 24px;text-align:center;}
  .tk-badge{display:inline-block;padding:4px 12px;border-radius:999px;font-size:.8rem;font-weight:600;letter-spacing:.02em;}
  .tk-issued{background:#15351f;color:#74e29a;}
  .tk-redeemed{background:#3a2a12;color:#e2b074;}
  .tk-void{background:#3a1518;color:#e27480;}
  .tk-code{margin-top:12px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:.08em;color:#a8a8b8;font-size:.85rem;}
  .tk-note{margin:12px 0 0;color:#a8a8b8;font-size:.85rem;}
</style>
</head>
<body class="tk-body">
  <main class="tk-card">
    <header class="tk-head">
      <h1>{$eventTitle}</h1>
      <p class="tk-type">{$typeName}</p>
    </header>
    <section class="tk-meta">
      <div><strong>{$holder}</strong></div>
      {$dateLine}
      {$venueLine}
    </section>
    {$qrBlock}
    <footer class="tk-foot">
      {$statusBadge}
      <div class="tk-code">{$code}</div>
      {$redeemedNote}
    </footer>
  </main>
</body>
</html>
HTML;
    }

    private function dateLine(array $ticket): string
    {
        $date = (string) ($ticket['event_date'] ?? '');
        if ($date === '') {
            return '';
        }
        $show = (string) ($ticket['show_time'] ?? '');
        $line = $this->e($date) . ($show !== '' ? ' &middot; ' . $this->e($show) : '');
        return '<div>' . $line . '</div>';
    }

    private function venueLine(array $ticket): string
    {
        $venue = trim((string) ($ticket['venue_name'] ?? ''));
        if ($venue === '') {
            return '';
        }
        $loc = array_filter([
            (string) ($ticket['venue_city'] ?? ''),
            (string) ($ticket['venue_state'] ?? ''),
        ], 'strlen');
        $suffix = $loc ? ', ' . implode(', ', $loc) : '';
        return '<div>' . $this->e($venue . $suffix) . '</div>';
    }

    private function errorPage(string $message): string
    {
        $msg = $this->e($message);
        return <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Ticket</title>
<style>
  body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#0d0d12;color:#f4f4f6;font-family:system-ui,sans-serif;padding:24px;text-align:center;}
  .box{max-width:360px;}
  h1{font-size:1.2rem;margin:0 0 8px;}
  p{color:#a8a8b8;margin:0;}
</style>
</head>
<body><div class="box"><h1>{$msg}</h1><p>This ticket link is invalid or no longer available.</p></div></body>
</html>
HTML;
    }

    private function appUrl(): string
    {
        return rtrim((string) (getenv('APP_URL') ?: ''), '/');
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    private function html(string $body, int $status): Response
    {
        return new Response($body, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }
}
