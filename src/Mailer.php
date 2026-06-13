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
 * A copy is always written to storage/mail/ for local inspection. Delivery
 * failures are logged but never thrown so a mail problem never breaks an auth flow.
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

    public function __construct(string $root)
    {
        $this->logDir      = $root . '/storage/mail';
        $this->templateDir = $root . '/storage/email-templates';
        $this->fromAddress = getenv('MAIL_FROM_ADDRESS') ?: ('noreply@' . (getenv('APP_HOST') ?: 'localhost'));
        $this->fromName    = getenv('MAIL_FROM_NAME') ?: 'Backstage';
        $this->bcc         = $this->parseBcc(getenv('MAIL_BCC') ?: '');

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
     * @param array<string,string> $vars  Replacement map: key => value for {{key}} tokens.
     */
    public function sendTemplate(string $to, string $subject, string $template, array $vars): void
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

        $this->send($to, $subject, $text, $html);
    }

    /**
     * Send an email directly.
     *
     * When $htmlBody is provided the message is wrapped in a multipart/alternative
     * MIME envelope (plain-text part first, HTML part second). When omitted the
     * message is a simple text/plain message.
     */
    public function send(string $to, string $subject, string $textBody, ?string $htmlBody = null): void
    {
        // Strip header injection attempts from anything that ends up in headers.
        $to      = $this->sanitizeHeaderValue($to);
        $subject = $this->sanitizeHeaderValue($subject);

        $message = $this->buildMessage($to, $subject, $textBody, $htmlBody);

        $this->writeToFile($to, $message);
        $this->pipeToSendmail($to, $message);
    }

    // ─── Message builder ───────────────────────────────────────────────────────

    private function buildMessage(
        string  $to,
        string  $subject,
        string  $textBody,
        ?string $htmlBody,
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
            // Unique boundary — random so it never accidentally appears in body text.
            $boundary = '=_Part_' . bin2hex(random_bytes(12));

            // Normalize line endings to CRLF for both parts.
            $textBody = (string) preg_replace("/\r\n|\r|\n/", "\r\n", $textBody);
            $htmlBody = (string) preg_replace("/\r\n|\r|\n/", "\r\n", $htmlBody);

            $headers = array_merge($baseHeaders, [
                "Content-Type: multipart/alternative; boundary=\"{$boundary}\"",
            ]);

            $body = "--{$boundary}\r\n"
                  . "Content-Type: text/plain; charset=UTF-8\r\n"
                  . "Content-Transfer-Encoding: 8bit\r\n"
                  . "\r\n"
                  . $textBody . "\r\n"
                  . "\r\n"
                  . "--{$boundary}\r\n"
                  . "Content-Type: text/html; charset=UTF-8\r\n"
                  . "Content-Transfer-Encoding: 8bit\r\n"
                  . "\r\n"
                  . $htmlBody . "\r\n"
                  . "\r\n"
                  . "--{$boundary}--";
        } else {
            // Plain-text only — normalize CRLF and dot-stuff for safety.
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
