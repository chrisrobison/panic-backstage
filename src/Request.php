<?php
declare(strict_types=1);

namespace Panic;

final class Request
{
    public function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly array $query,
        private readonly array $body,
        private readonly array $files,
        private readonly array $headers
    ) {}

    public static function fromGlobals(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        $body = $_POST;
        if (str_contains($contentType, 'application/json')) {
            $json = json_decode(file_get_contents('php://input') ?: '{}', true);
            $body = is_array($json) ? $json : [];
        } elseif (in_array($method, ['PATCH', 'PUT', 'DELETE'], true)) {
            parse_str(file_get_contents('php://input') ?: '', $body);
        }
        return new self($method, $path, $_GET, $body, $_FILES, function_exists('getallheaders') ? getallheaders() : []);
    }

    public function method(): string { return $this->method; }
    public function path(): string { return $this->path; }
    public function query(?string $key = null, mixed $default = null): mixed { return $key === null ? $this->query : ($this->query[$key] ?? $default); }
    public function body(?string $key = null, mixed $default = null): mixed { return $key === null ? $this->body : ($this->body[$key] ?? $default); }
    public function files(): array { return $this->files; }
    public function header(string $name): ?string
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }
        return null;
    }
    public function isSafeMethod(): bool { return in_array($this->method, ['GET', 'HEAD', 'OPTIONS'], true); }
}
