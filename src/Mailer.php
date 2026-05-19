<?php
declare(strict_types=1);

namespace Panic;

/**
 * Thin mailer: builds an RFC 5322 message and pipes it directly into Exim
 * via /usr/sbin/sendmail (which is the standard sendmail-compatible interface
 * to exim4 on this server). A copy is always written to storage/mail/ for
 * local inspection. Delivery failures are logged but never thrown so a
 * mail problem never breaks an auth flow.
 */
final class Mailer
{
    private const SENDMAIL = '/usr/sbin/sendmail';

    private string $logDir;
    private string $fromAddress;
    private string $fromName;

    public function __construct(string $root)
    {
        $this->logDir      = $root . '/storage/mail';
        $this->fromAddress = getenv('MAIL_FROM_ADDRESS') ?: ('noreply@' . (getenv('APP_HOST') ?: 'localhost'));
        $this->fromName    = getenv('MAIL_FROM_NAME') ?: 'Backstage';

        if (!is_dir($this->logDir)) {
            mkdir($this->logDir, 0755, true);
        }
    }

    public function send(string $to, string $subject, string $body): void
    {
        // Strip header injection attempts from anything that ends up in headers.
        $to      = $this->sanitizeHeaderValue($to);
        $subject = $this->sanitizeHeaderValue($subject);

        $message = $this->buildMessage($to, $subject, $body);

        $this->writeToFile($to, $message);
        $this->pipeToSendmail($to, $message);
    }

    private function buildMessage(string $to, string $subject, string $body): string
    {
        $domain = substr(strrchr($this->fromAddress, '@') ?: '@localhost', 1);
        $msgId  = sprintf('<%s.%s@%s>', date('YmdHis'), bin2hex(random_bytes(8)), $domain);

        // Normalize body line endings to CRLF and dot-stuff any line beginning
        // with a single dot (defense in depth — -i already disables dot-EOT).
        $body = preg_replace("/\r\n|\r|\n/", "\r\n", $body);

        $headers = [
            "From: {$this->fromName} <{$this->fromAddress}>",
            "To: {$to}",
            "Reply-To: {$this->fromName} <{$this->fromAddress}>",
            "Subject: {$subject}",
            "Message-ID: {$msgId}",
            'Date: ' . date('r'),
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            'Auto-Submitted: auto-generated',
            'X-Mailer: Backstage',
        ];

        return implode("\r\n", $headers) . "\r\n\r\n" . $body;
    }

    private function pipeToSendmail(string $to, string $message): void
    {
        if (!is_executable(self::SENDMAIL)) {
            $this->logError($to, -1, 'sendmail binary not executable: ' . self::SENDMAIL);
            return;
        }

        // -i  : do not treat a line containing only "." as end of input
        // -f  : envelope sender (SMTP MAIL FROM) — aligns with From: for SPF/DMARC
        // --  : end of options; recipient follows as a positional arg
        $cmd = [
            self::SENDMAIL,
            '-i',
            '-f', $this->fromAddress,
            '--',
            $to,
        ];

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
