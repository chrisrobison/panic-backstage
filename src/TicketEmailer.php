<?php
declare(strict_types=1);

namespace Panic;

/**
 * Shared ticket-URL construction and QR-embedded delivery email — extracted
 * from Events\Ticketing::emailTickets()/ticketUrl() and Webhooks::emailTickets(),
 * which had drifted into two near-identical copies of the same QR/MIME
 * assembly. Both call sites (comp/resend from the admin UI, and the
 * post-payment webhook) now share one implementation.
 */
final class TicketEmailer
{
    /** Public ticket-view URL (carries the scannable token) for a ticket. */
    public static function ticketUrl(string $token): string
    {
        return rtrim((string) (getenv('APP_URL') ?: ''), '/') . '/t/' . rawurlencode($token);
    }

    /**
     * Build the QR-embedded HTML/text fragments for a list of tickets. Tickets
     * without a stored token are skipped (idempotent re-issue: no plaintext
     * token left to deliver).
     *
     * @param list<array{code:string,token:?string}> $tickets
     * @return array{count:int, html:string, text:string, inline:array<string,string>}
     */
    private static function buildFragments(array $tickets): array
    {
        $appUrl    = rtrim((string) (getenv('APP_URL') ?: ''), '/');
        $textLines = [];
        $htmlItems = [];
        $inline    = []; // Content-ID => raw PNG bytes for MIME multipart/related
        $n         = 0;

        foreach ($tickets as $t) {
            $token = (string) ($t['token'] ?? '');
            if ($token === '') {
                continue;
            }
            $n++;
            $link     = $appUrl . '/t/' . rawurlencode($token);
            $code     = htmlspecialchars((string) $t['code'], ENT_QUOTES, 'UTF-8');
            $safeLink = htmlspecialchars($link, ENT_QUOTES, 'UTF-8');

            // Generate QR PNG bytes directly (no HTTP round-trip) and embed as a
            // MIME CID attachment so the image is always present regardless of
            // whether the recipient's email client loads remote images.
            $cid      = 'qr-' . $n . '-' . bin2hex(random_bytes(6)) . '@' . (getenv('APP_HOST') ?: 'localhost');
            $pngBytes = QrCode::generatePng($token, 300);
            if ($pngBytes !== '') {
                $inline[$cid] = $pngBytes;
                $qrSrc = 'cid:' . $cid;
            } else {
                // Fallback: external URL (e.g. if GD unavailable).
                $qrSrc = htmlspecialchars(
                    $appUrl . '/assets/qr.png?text=' . rawurlencode($token) . '&size=300',
                    ENT_QUOTES, 'UTF-8'
                );
            }

            $textLines[] = 'Ticket ' . $n . '  (' . (string) $t['code'] . ')';
            $textLines[] = '  View ticket + QR: ' . $link;
            $textLines[] = '';

            $htmlItems[] = '<div style="padding:16px 0;border-bottom:1px solid #2e2929;">'
                . '<div style="font-size:13px;color:#a9a097;letter-spacing:1px;text-transform:uppercase;">Ticket ' . $n . '</div>'
                . '<div style="margin-top:4px;font-size:16px;font-weight:bold;color:#fff;">' . $code . '</div>'
                . '<div style="margin-top:14px;text-align:center;">'
                . '<a href="' . $safeLink . '" style="display:inline-block;line-height:0;border:2px solid #3a3434;border-radius:4px;">'
                . '<img src="' . $qrSrc . '" alt="QR code — tap to open your ticket" width="200" height="200"'
                . ' style="display:block;background:#ffffff;padding:10px;">'
                . '</a>'
                . '</div>'
                . '<div style="margin-top:8px;font-size:13px;color:#b5aba2;text-align:center;">'
                . 'Screenshot or save this QR &mdash; show it at the door to get in.'
                . '</div>'
                . '<div style="margin-top:10px;font-size:13px;">'
                . '<a href="' . $safeLink . '" style="color:#c9b27e;font-weight:bold;">View your ticket &amp; QR &rarr;</a>'
                . '</div></div>';
        }

        return [
            'count'  => $n,
            'html'   => implode('', $htmlItems),
            'text'   => implode("\n", $textLines),
            'inline' => $inline,
        ];
    }

    /**
     * Email a QR-embedded ticket list via a named Mailer template. Returns the
     * number of tickets actually included (0 when none carried a token, in
     * which case no email is sent).
     *
     * @param list<array{code:string,token:?string}> $tickets
     */
    public static function send(
        Database $db,
        string $root,
        string $to,
        ?string $recipientName,
        string $subject,
        string $template,
        string $eventTitle,
        array $tickets
    ): int {
        $frag = self::buildFragments($tickets);
        if ($frag['count'] === 0) {
            return 0;
        }

        $greetingHtml = $recipientName
            ? 'Hi <strong style="color:#fff;">' . htmlspecialchars($recipientName, ENT_QUOTES, 'UTF-8') . '</strong>,'
            : 'Hello,';

        (new Mailer($root, $db))->sendTemplate(
            $to,
            $subject,
            $template,
            [
                'event_title'  => htmlspecialchars($eventTitle, ENT_QUOTES, 'UTF-8'),
                'greeting'     => $greetingHtml,
                'tickets_html' => $frag['html'],
                'tickets_text' => $frag['text'] . "\n",
            ],
            $frag['inline']
        );

        return $frag['count'];
    }
}
