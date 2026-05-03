<?php
declare(strict_types=1);

namespace Panic;

/**
 * Thin mailer: always writes a .eml file to storage/mail/ for local inspection,
 * then attempts delivery via the system mail() (exim/sendmail).
 * Delivery failures are silently swallowed so local dev never breaks.
 */
final class Mailer
{
    private string $logDir;
    private string $fromAddress;
    private string $fromName;

    public function __construct(string $root)
    {
        $this->logDir     = $root . '/storage/mail';
        $this->fromAddress = getenv('MAIL_FROM_ADDRESS') ?: ('noreply@' . (getenv('APP_HOST') ?: 'localhost'));
        $this->fromName    = getenv('MAIL_FROM_NAME') ?: 'Backstage';

        if (!is_dir($this->logDir)) {
            mkdir($this->logDir, 0755, true);
        }
    }

    public function send(string $to, string $subject, string $body): void
    {
        $this->writeToFile($to, $subject, $body);

        $headers = implode("\r\n", [
            "From: {$this->fromName} <{$this->fromAddress}>",
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ]);

        // Suppress errors — if sendmail isn't configured, the .eml file is enough for dev
        @mail($to, $subject, $body, $headers);
    }

    private function writeToFile(string $to, string $subject, string $body): void
    {
        $timestamp = date('Ymd_His_') . substr((string) microtime(false), 2, 6);
        $safe      = preg_replace('/[^a-zA-Z0-9@._-]/', '_', $to);
        $path      = "{$this->logDir}/{$timestamp}_{$safe}.eml";

        $content = implode("\r\n", [
            "To: {$to}",
            "From: {$this->fromName} <{$this->fromAddress}>",
            "Subject: {$subject}",
            'Date: ' . date('r'),
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            '',
        ]) . "\r\n" . $body;

        file_put_contents($path, $content);
    }
}
