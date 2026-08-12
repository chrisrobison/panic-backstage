<?php
declare(strict_types=1);

namespace Panic;

final class Response
{
    public function __construct(
        private readonly mixed $body,
        private readonly int $status = 200,
        private readonly array $headers = [],
        private readonly bool $stream = false
    ) {}

    public static function json(mixed $body, int $status = 200, array $headers = []): self
    {
        // No-store: these are dynamic, per-request API responses with no
        // Last-Modified/ETag, so there's no reason a browser (or an
        // intermediate proxy) should ever cache one. Cheap defense-in-depth
        // for every JSON endpoint.
        return new self($body, $status, array_merge([
            'Content-Type' => 'application/json; charset=utf-8',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
        ], $headers));
    }

    public static function noContent(): self
    {
        return new self(null, 204);
    }

    /**
     * A long-lived, incrementally-flushed response (currently: the
     * realtime SSE endpoint — see src/Realtime.php). $emitter is invoked
     * by send() in place of the normal echo/json_encode body handling; it
     * is responsible for its own echo/flush calls and for returning once
     * the connection should end (client disconnect, TTL elapsed, etc.).
     *
     * Kept generic (not SSE-specific) so this stays a small, reusable
     * extension of the existing Response abstraction rather than one-off
     * procedural code bypassing Kernel — see the Realtime-related section
     * of docs/realtime-data.md for why streaming needed a first-class
     * Response variant instead of `echo`-ing directly from the endpoint.
     */
    public static function stream(callable $emitter, array $headers = []): self
    {
        return new self($emitter, 200, array_merge([
            'Content-Type'      => 'text/event-stream; charset=utf-8',
            'Cache-Control'     => 'no-cache',
            // Tell reverse proxies (nginx) not to buffer this response —
            // without it, chunks can sit in the proxy buffer instead of
            // reaching the browser, defeating the whole point of SSE. See
            // docs/realtime-data.md's proxy configuration notes.
            'X-Accel-Buffering' => 'no',
            'Connection'        => 'keep-alive',
        ], $headers), true);
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

    public function withHeader(string $name, string|array $value): self
    {
        return new self($this->body, $this->status, [...$this->headers, $name => $value], $this->stream);
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            if (is_array($value)) {
                foreach ($value as $headerValue) {
                    header("$name: $headerValue", false);
                }
            } else {
                header("$name: $value", true);
            }
        }
        if ($this->stream) {
            // $body is the emitter callable passed to Response::stream();
            // everything from here on (echo/flush loop, connection-abort
            // checks, return-to-end-the-request) is its responsibility.
            ($this->body)();
            return;
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
