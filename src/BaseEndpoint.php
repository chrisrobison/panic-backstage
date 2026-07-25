<?php
declare(strict_types=1);

namespace Panic;

abstract class BaseEndpoint implements Endpoint
{
    // Capability tables + the pure role/access logic that used to live here
    // now live in Panic\Capabilities, so a standalone process with no
    // Request/Endpoint/Auth (scripts/ai-mcp-server.php) can enforce the
    // exact same rules from a plain (user id, role) pair. Everything below
    // is a thin instance-context wrapper around those static methods —
    // pure refactor, no behavior change. See Capabilities.php's docblock.
    private array $eventAccessCache = [];

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

    protected function forbidden(string $message = 'Forbidden'): Response
    {
        return Response::json(['error' => $message], 403);
    }

    protected function role(): string
    {
        return (string) ($this->auth->user()['role'] ?? 'viewer');
    }

    protected function isVenueAdmin(): bool
    {
        return $this->role() === 'venue_admin';
    }

    protected function isGlobalViewer(): bool
    {
        return $this->role() === 'global_viewer';
    }

    protected function hasGlobalCapability(string $capability): bool
    {
        return Capabilities::hasGlobal($this->role(), $capability);
    }

    protected function globalCapabilities(): array
    {
        return Capabilities::globalCapabilities($this->role());
    }

    protected function requireAuth(string $message = 'Authentication required'): ?Response
    {
        return $this->userId() === null
            ? Response::json(['error' => $message], 401)
            : null;
    }

    protected function requireGlobalCapability(string $capability): ?Response
    {
        return $this->hasGlobalCapability($capability) ? null : $this->forbidden();
    }

    protected function eventAccess(int $eventId): ?array
    {
        if (array_key_exists($eventId, $this->eventAccessCache)) {
            return $this->eventAccessCache[$eventId];
        }

        return $this->eventAccessCache[$eventId] =
            Capabilities::eventAccess($this->db, $eventId, $this->userId(), $this->role());
    }

    protected function eventCapabilities(int $eventId): array
    {
        return $this->eventAccess($eventId)['capabilities'] ?? Capabilities::emptyEventCapabilities();
    }

    protected function hasEventCapability(int $eventId, string $capability): bool
    {
        $access = $this->eventAccess($eventId);
        return (bool) ($access['capabilities'][$capability] ?? false);
    }

    protected function requireEventCapability(int $eventId, string $capability): ?Response
    {
        $access = $this->eventAccess($eventId);
        if (!$access) {
            return $this->notFound('Event not found');
        }
        return ($access['capabilities'][$capability] ?? false) ? null : $this->forbidden();
    }

    protected function eventScopeSql(string $eventAlias = 'e'): array
    {
        if ($this->isVenueAdmin() || $this->isGlobalViewer()) {
            return ['1=1', []];
        }
        return [
            "($eventAlias.owner_user_id = ? OR EXISTS (SELECT 1 FROM event_collaborators ec_scope WHERE ec_scope.event_id = $eventAlias.id AND ec_scope.user_id = ?))",
            [$this->userId(), $this->userId()],
        ];
    }

    protected function assignmentUsersForEvent(int $eventId): array
    {
        if ($this->isVenueAdmin()) {
            return $this->db->all('SELECT id, name, email, role FROM users WHERE is_hidden = 0 ORDER BY name');
        }

        return $this->db->all(
            'SELECT DISTINCT u.id, u.name, u.email, COALESCE(ec.role, u.role) role
             FROM users u
             LEFT JOIN event_collaborators ec ON ec.user_id = u.id AND ec.event_id = ?
             JOIN events e ON e.id = ?
             WHERE u.id = e.owner_user_id OR ec.id IS NOT NULL
             ORDER BY u.name',
            [$eventId, $eventId]
        );
    }

    protected function accessibleUsers(): array
    {
        if ($this->isVenueAdmin()) {
            return $this->db->all('SELECT id, name, email, role FROM users WHERE is_hidden = 0 ORDER BY name');
        }

        return $this->db->all(
            'SELECT DISTINCT u.id, u.name, u.email, u.role
             FROM users u
             JOIN events e ON e.owner_user_id = u.id
             WHERE e.owner_user_id = ? OR EXISTS (SELECT 1 FROM event_collaborators ec WHERE ec.event_id = e.id AND ec.user_id = ?)
             UNION
             SELECT DISTINCT u.id, u.name, u.email, ec.role
             FROM users u
             JOIN event_collaborators ec ON ec.user_id = u.id
             JOIN events e ON e.id = ec.event_id
             WHERE e.owner_user_id = ? OR EXISTS (SELECT 1 FROM event_collaborators mine WHERE mine.event_id = e.id AND mine.user_id = ?)
             ORDER BY name',
            [$this->userId(), $this->userId(), $this->userId(), $this->userId()]
        );
    }
}
