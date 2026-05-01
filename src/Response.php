<?php
declare(strict_types=1);

namespace Panic;

final class Response
{
    public function __construct(private readonly mixed $body, private readonly int $status = 200, private readonly array $headers = []) {}

    public static function json(mixed $body, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => 'application/json; charset=utf-8']);
    }

    public static function noContent(): self
    {
        return new self(null, 204);
    }

    public static function methodNotAllowed(): self
    {
        return self::json(['error' => 'Method not allowed'], 405);
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header("$name: $value");
        }
        if ($this->status !== 204) {
            echo json_encode($this->body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
    }
}
