<?php
declare(strict_types=1);

namespace Panic;

abstract class BaseEndpoint implements Endpoint
{
    public function __construct(
        protected readonly Database $db,
        protected readonly Auth $auth,
        protected readonly array $params = [],
        protected readonly string $root = ''
    ) {}

    protected function userId(): ?int
    {
        return isset($this->auth->user()['id']) ? (int) $this->auth->user()['id'] : null;
    }

    protected function requireEventId(): int
    {
        $id = $this->params['eventId'] ?? null;
        if (!$id) {
            throw new \InvalidArgumentException('Event id is required');
        }
        return (int) $id;
    }

    protected function ok(array $payload = []): Response
    {
        return Response::json($payload);
    }

    protected function notFound(string $message = 'Not found'): Response
    {
        return Response::json(['error' => $message], 404);
    }
}
