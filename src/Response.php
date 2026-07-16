<?php
declare(strict_types=1);

namespace Panic;

final class Response
{
    public function __construct(private readonly mixed $body, private readonly int $status = 200, private readonly array $headers = []) {}

    public static function json(mixed $body, int $status = 200): self
    {
        // No-store: these are dynamic, per-request API responses with no
        // Last-Modified/ETag, so there's no reason a browser (or an
        // intermediate proxy) should ever cache one. Cheap defense-in-depth
        // for every JSON endpoint.
        return new self($body, $status, [
            'Content-Type' => 'application/json; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    public static function noContent(): self
    {
        return new self(null, 204);
    }

    public static function methodNotAllowed(): self
    {
        return self::json(['error' => 'Method not allowed'], 405);
    }

    /** Browser redirect (e.g. handing off to a third-party OAuth authorize page, or back into the SPA). */
    public static function redirect(string $url, int $status = 302): self
    {
        return new self(null, $status, ['Location' => $url]);
    }

    public static function csv(string $content, string $filename): self
    {
        return self::download($content, $filename, 'text/csv; charset=utf-8');
    }

    /** Generic file-attachment response (used for CSV/XLS/SQL exports etc.). */
    public static function download(string $content, string $filename, string $contentType): self
    {
        return new self($content, 200, [
            'Content-Type'        => $contentType,
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header("$name: $value");
        }
        if ($this->status === 204 || isset($this->headers['Location'])) {
            return;
        }
        // Non-JSON responses (e.g. text/html ticket pages, image/svg+xml QR codes)
        // carry an already-rendered string/scalar body and must NOT be JSON-encoded.
        $contentType = '';
        foreach ($this->headers as $name => $value) {
            if (strcasecmp((string) $name, 'Content-Type') === 0) {
                $contentType = (string) $value;
                break;
            }
        }
        if ($contentType !== '' && stripos($contentType, 'application/json') === false && is_scalar($this->body)) {
            echo (string) $this->body;
            return;
        }
        echo json_encode($this->body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
