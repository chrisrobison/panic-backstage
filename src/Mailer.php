<?php
declare(strict_types=1);

namespace Panic;

/**
 * Thin mailer: builds RFC 5322 multipart/alternative MIME messages and pipes
 * them directly into Exim via /usr/sbin/sendmail. When an HTML body (or a
 * named template) is provided the message is a proper multipart/alternative
 * envelope containing both a plain-text part and an HTML part. Plain-text-only
 * messages fall through to a simple text/plain message as before.
 *
 * A copy is always written to disk for local inspection:
 *   multi-tenant  →  clients/{slug}/mail/
 *   single-tenant →  storage/mail/
 * Delivery failures are logged but never thrown so a mail problem never breaks an auth flow.
 */
final class Mailer
{
    private const SENDMAIL = '/usr/sbin/sendmail';

    private string $logDir;
    private string $templateDir;
    private string $fromAddress;
    private string $fromName;
    /** @var string[] Extra envelope-only recipients (blind copies). */
    private array $bcc;
    private ?Database $db;

    /**
     * @param Database|null $db  When provided, each sent message is persisted
     *                           to the outbox table so admins can browse it.
     */
    public function __construct(string $root, ?Database $db = null)
    {
        // Multi-tenant: write mail copies to clients/{slug}/mail/
        // Single-tenant fallback: storage/mail/ (unchanged behaviour)
        $this->logDir      = \Panic\Tenant\TenantContext::clientDir($root) . '/mail';
        $this->templateDir = $root . '/storage/email-templates';
        $this->fromAddress = getenv('MAIL_FROM_ADDRESS') ?: ('noreply@' . (getenv('APP_HOST') ?: 'localhost'));
        $this->fromName    = getenv('MAIL_FROM_NAME') ?: 'Backstage';
        $this->bcc         = $this->parseBcc(getenv('MAIL_BCC') ?: '');
        $this->db          = $db;

        if (!is_dir($this->logDir)) {
            mkdir($this->logDir, 0755, true);
        }
    }

    /**
     * Parse a comma/semicolon-separated MAIL_BCC list into validated addresses.
     * These are delivered as envelope recipients only (no Bcc: header is added),
     * so the primary recipient never sees them.
     *
     * @return string[]
     */
    private function parseBcc(string $raw): array
    {
        $out = [];
        foreach (preg_split('/[,;]+/', $raw) ?: [] as $addr) {
            $addr = trim($addr);
            if ($addr !== '' && filter_var($addr, FILTER_VALIDATE_EMAIL)) {
                $out[] = $addr;
            }
        }
        return $out;
    }

    // ─── Public API ────────────────────────────────────────────────────────────

    /**
     * Send a message using a named template pair from storage/email-templates/.
     * Loads <template>.html and <template>.txt, replaces {{key}} placeholders
     * with values from $vars, then delivers as multipart/alternative MIME.
     *
     * If only a .txt file exists the message falls back to plain-text.
     *
     * @param array<string,string> $vars    Replacement map: key => value for {{key}} tokens.
     * @param array<string,string> $inline  Inline image attachments keyed by Content-ID
     *                                      (bare, without angle brackets). Values are raw
     *                                      image bytes. All parts are assumed image/png.
     *                                      Reference them in the HTML template as
     *                                      <img src="cid:{key}">. When provided the HTML
     *                                      part is wrapped in a multipart/related envelope
     *                                      so the images are always embedded regardless of
     *                                      whether the recipient's client loads remote images.
     */
    public function sendTemplate(string $to, string $subject, string $template, array $vars, array $inline = []): void
    {
        $htmlFile = "{$this->templateDir}/{$template}.html";
        $textFile = "{$this->templateDir}/{$template}.txt";

        $html = is_file($htmlFile) ? (string) file_get_contents($htmlFile) : null;
        $text = is_file($textFile) ? (string) file_get_contents($textFile) : '';

        foreach ($vars as $key => $value) {
            if ($html !== null) {
                $html = str_replace('{{' . $key . '}}', $value, $html);
            }
            $text = str_replace('{{' . $key . '}}', $value, $text);
        }

        $this->send($to, $subject, $text, $html, $template, $inline);
    }

    /**
     * Send an email directly.
     *
     * When $htmlBody is provided the message is wrapped in a multipart/alternative
     * MIME envelope (plain-text part first, HTML part second). When omitted the
     * message is a simple text/plain message.
     *
     * When $inline is non-empty the HTML alternative is itself wrapped in a
     * multipart/related envelope (RFC 2387) containing the HTML body followed
     * by the inline image parts, each identified by a Content-ID header. The
     * HTML should reference them as <img src="cid:{key}"> where {key} is the
     * bare Content-ID (without angle brackets).
     *
     * @param string|null          $template  Template name (if sent via sendTemplate).
     *                                        Stored in the outbox record for traceability.
     * @param array<string,string> $inline    Content-ID => raw image bytes map.
     */
    public function send(string $to, string $subject, string $textBody, ?string $htmlBody = null, ?string $template = null, array $inline = []): void
    {
        // Strip header injection attempts from anything that ends up in headers.
        $to      = $this->sanitizeHeaderValue($to);
        $subject = $this->sanitizeHeaderValue($subject);

        $message = $this->buildMessage($to, $subject, $textBody, $htmlBody, $inline);

        $this->writeToFile($to, $message);
        $this->pipeToSendmail($to, $message);
        $this->logToOutbox($to, $subject, $textBody, $htmlBody, $template, $inline);
    }

    // ─── Message builder ───────────────────────────────────────────────────────

    /**
     * Build the RFC 5322 / MIME message string.
     *
     * When $inline is non-empty the structure is:
     *
     *   multipart/mixed  (outer — carries both the message body and attachments)
     *     multipart/alternative
     *       text/plain
     *       multipart/related          (RFC 2387 — HTML + embedded images)
     *         text/html  (references images via <img src="cid:{id}">)
     *         image/png  Content-ID:<id>  Content-Disposition: inline
     *         …
     *     image/png  Content-Disposition: attachment; filename="ticket-qr-{n}.png"
     *     …
     *
     * This maximises client compatibility:
     *   • Outlook / Apple Mail / Thunderbird render the CID inline images.
     *   • Gmail (which strips/blocks CID references) receives the same PNG
     *     files as named attachments the recipient can download and scan.
     *
     * @param array<string,string> $inline  Content-ID (bare) => raw PNG bytes.
     */
    private function buildMessage(
        string  $to,
        string  $subject,
        string  $textBody,
        ?string $htmlBody,
        array   $inline = [],
    ): string {
        $domain = substr(strrchr($this->fromAddress, '@') ?: '@localhost', 1);
        $msgId  = sprintf('<%s.%s@%s>', date('YmdHis'), bin2hex(random_bytes(8)), $domain);

        $baseHeaders = [
            "From: {$this->fromName} <{$this->fromAddress}>",
            "To: {$to}",
            "Reply-To: {$this->fromName} <{$this->fromAddress}>",
            "Subject: {$subject}",
            "Message-ID: {$msgId}",
            'Date: ' . date('r'),
            'MIME-Version: 1.0',
            'Auto-Submitted: auto-generated',
            'X-Mailer: Backstage',
        ];

        if ($htmlBody !== null) {
            // Normalize line endings to CRLF for both parts.
            $textBody = (string) preg_replace("/\r\n|\r|\n/", "\r\n", $textBody);
            $htmlBody = (string) preg_replace("/\r\n|\r|\n/", "\r\n", $htmlBody);

            // ── Build the HTML alternative ────────────────────────────────────
            // When inline images are present, wrap HTML + images in multipart/related
            // so CID references resolve in clients that support them (Outlook, etc.).
            if ($inline !== []) {
                $relBoundary = '=_Rel_' . bin2hex(random_bytes(12));

                $relBody = "--{$relBoundary}\r\n"
                         . "Content-Type: text/html; charset=UTF-8\r\n"
                         . "Content-Transfer-Encoding: base64\r\n"
                         . "\r\n"
                         . chunk_split(base64_encode($htmlBody), 76, "\r\n");

                foreach ($inline as $cid => $imageBytes) {
                    $relBody .= "--{$relBoundary}\r\n"
                              . "Content-Type: image/png\r\n"
                              . "Content-Transfer-Encoding: base64\r\n"
                              . "Content-ID: <{$cid}>\r\n"
                              . "Content-Disposition: inline\r\n"
                              . "\r\n"
                              . chunk_split(base64_encode($imageBytes), 76, "\r\n");
                }
                $relBody .= "--{$relBoundary}--";

                $htmlPart = "Content-Type: multipart/related; boundary=\"{$relBoundary}\"\r\n"
                          . "\r\n"
                          . $relBody;
            } else {
                $htmlPart = "Content-Type: text/html; charset=UTF-8\r\n"
                          . "Content-Transfer-Encoding: 8bit\r\n"
                          . "\r\n"
                          . $htmlBody;
            }

            // ── Assemble multipart/alternative (text + html) ──────────────────
            $altBoundary = '=_Alt_' . bin2hex(random_bytes(12));

            $altBody = "--{$altBoundary}\r\n"
                     . "Content-Type: text/plain; charset=UTF-8\r\n"
                     . "Content-Transfer-Encoding: 8bit\r\n"
                     . "\r\n"
                     . $textBody . "\r\n"
                     . "\r\n"
                     . "--{$altBoundary}\r\n"
                     . $htmlPart . "\r\n"
                     . "\r\n"
                     . "--{$altBoundary}--";

            if ($inline !== []) {
                // ── Wrap in multipart/mixed so each QR PNG is ALSO a named ────
                // attachment.  Gmail and similar webmail clients that block CID
                // references will at least receive the QR images as downloadable
                // files the recipient can scan from their downloads/phone.
                $mixBoundary = '=_Mix_' . bin2hex(random_bytes(12));

                $mixBody = "--{$mixBoundary}\r\n"
                         . "Content-Type: multipart/alternative; boundary=\"{$altBoundary}\"\r\n"
                         . "\r\n"
                         . $altBody . "\r\n";

                $attIdx = 1;
                foreach ($inline as $imageBytes) {
                    $filename = 'ticket-qr-' . $attIdx . '.png';
                    $mixBody .= "\r\n--{$mixBoundary}\r\n"
                              . "Content-Type: image/png; name=\"{$filename}\"\r\n"
                              . "Content-Transfer-Encoding: base64\r\n"
                              . "Content-Disposition: attachment; filename=\"{$filename}\"\r\n"
                              . "\r\n"
                              . chunk_split(base64_encode($imageBytes), 76, "\r\n");
                    $attIdx++;
                }
                $mixBody .= "\r\n--{$mixBoundary}--";

                $headers = array_merge($baseHeaders, [
                    "Content-Type: multipart/mixed; boundary=\"{$mixBoundary}\"",
                ]);
                $body = $mixBody;
            } else {
                $headers = array_merge($baseHeaders, [
                    "Content-Type: multipart/alternative; boundary=\"{$altBoundary}\"",
                ]);
                $body = $altBody;
            }
        } else {
            // Plain-text only — normalize CRLF.
            $textBody = (string) preg_replace("/\r\n|\r|\n/", "\r\n", $textBody);

            $headers = array_merge($baseHeaders, [
                'Content-Type: text/plain; charset=UTF-8',
                'Content-Transfer-Encoding: 8bit',
            ]);

            $body = $textBody;
        }

        return implode("\r\n", $headers) . "\r\n\r\n" . $body;
    }

    // ─── Delivery ──────────────────────────────────────────────────────────────

    private function pipeToSendmail(string $to, string $message): void
    {
        if (!is_executable(self::SENDMAIL)) {
            $this->logError($to, -1, 'sendmail binary not executable: ' . self::SENDMAIL);
            return;
        }

        // -i  : do not treat a line containing only "." as end of input
        // -f  : envelope sender (SMTP MAIL FROM) — aligns with From: for SPF/DMARC
        // --  : end of options; recipients follow as positional args
        //
        // MAIL_BCC addresses are appended here as envelope recipients only.
        // They are NOT added as a Bcc: header: this path does not use sendmail
        // -t, so a Bcc: header would be transmitted verbatim and become visible
        // to the primary recipient. As envelope args they receive a blind copy.
        $cmd = array_merge(
            [
                self::SENDMAIL,
                '-i',
                '-f', $this->fromAddress,
                '--',
                $to,
            ],
            $this->bcc
        );

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $proc = @proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($proc)) {
            $this->logError($to, -1, 'proc_open failed');
            return;
        }

        fwrite($pipes[0], $message);
        fclose($pipes[0]);

        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exit = proc_close($proc);
        if ($exit !== 0) {
            $this->logError($to, $exit, trim($stderr));
        }
    }

    private function writeToFile(string $to, string $message): void
    {
        $timestamp = date('Ymd_His_') . substr((string) microtime(false), 2, 6);
        $safe      = preg_replace('/[^a-zA-Z0-9@._-]/', '_', $to);
        $path      = "{$this->logDir}/{$timestamp}_{$safe}.eml";

        @file_put_contents($path, $message);
    }

    // ─── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Persist the sent message to the outbox table.
     * Failures are silently swallowed so a DB issue never breaks a mail send.
     *
     * Inline images are embedded into the outgoing email as MIME parts referenced
     * by `cid:` URLs, but a browser rendering the stored HTML has no way to resolve
     * those references — so they appear as broken images in the admin outbox. To
     * keep the stored copy self-contained, any `cid:` reference whose bytes we hold
     * is inlined as a `data:` URI before the HTML is persisted.
     *
     * @param array<string,string> $inline  Content-ID (bare) => raw image bytes.
     */
    private function logToOutbox(string $to, string $subject, string $textBody, ?string $htmlBody, ?string $template, array $inline = []): void
    {
        if ($this->db === null) {
            return;
        }
        if ($htmlBody !== null && $htmlBody !== '' && $inline !== []) {
            $htmlBody = $this->inlineCidImages($htmlBody, $inline);
        }
        try {
            $this->db->run(
                'INSERT INTO outbox (to_address, subject, text_body, html_body, template) VALUES (?, ?, ?, ?, ?)',
                [$to, $subject, $textBody !== '' ? $textBody : null, $htmlBody, $template]
            );
        } catch (\Throwable) {
            // Never let outbox failures interrupt mail delivery.
        }
    }

    /**
     * Replace `cid:{id}` image references with self-contained `data:` URIs so the
     * stored HTML renders correctly in the admin outbox (where `cid:` can't resolve).
     *
     * @param array<string,string> $inline  Content-ID (bare) => raw image bytes.
     */
    private function inlineCidImages(string $html, array $inline): string
    {
        foreach ($inline as $cid => $bytes) {
            if ($bytes === '') {
                continue;
            }
            $mime    = $this->detectImageMime($bytes);
            $dataUri = 'data:' . $mime . ';base64,' . base64_encode($bytes);
            // Match cid:{id} with the id optionally wrapped in the surrounding quote.
            $pattern = '/cid:' . preg_quote($cid, '/') . '/';
            $html    = preg_replace($pattern, $dataUri, $html);
        }
        return $html;
    }

    /**
     * Best-effort image MIME sniff from the leading magic bytes; defaults to PNG.
     */
    private function detectImageMime(string $bytes): string
    {
        if (strncmp($bytes, "\x89PNG\r\n\x1a\n", 8) === 0) {
            return 'image/png';
        }
        if (strncmp($bytes, "\xFF\xD8\xFF", 3) === 0) {
            return 'image/jpeg';
        }
        if (strncmp($bytes, 'GIF8', 4) === 0) {
            return 'image/gif';
        }
        return 'image/png';
    }

    private function logError(string $to, int $exit, string $detail): void
    {
        $line = sprintf("[%s] to=%s exit=%d %s\n", date('c'), $to, $exit, $detail);
        @file_put_contents($this->logDir . '/_delivery-errors.log', $line, FILE_APPEND);
    }

    private function sanitizeHeaderValue(string $value): string
    {
        // Collapse any CR/LF into single spaces to defeat header injection.
        return trim((string) preg_replace('/[\r\n]+/', ' ', $value));
    }
}
