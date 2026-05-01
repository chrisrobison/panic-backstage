<?php
declare(strict_types=1);

namespace Panic;

abstract class BaseEndpoint implements Endpoint
{
    private const EVENT_CAPABILITIES = [
        'venue_admin' => [
            'read_event', 'edit_event', 'publish_event', 'delete_event',
            'manage_lineup', 'manage_tasks', 'manage_schedule', 'manage_open_items',
            'upload_assets', 'manage_assets', 'manage_invites',
            'view_settlement', 'edit_settlement',
        ],
        'event_owner' => [
            'read_event', 'edit_event', 'publish_event', 'delete_event',
            'manage_lineup', 'manage_tasks', 'manage_schedule', 'manage_open_items',
            'upload_assets', 'manage_assets', 'manage_invites',
            'view_settlement', 'edit_settlement',
        ],
        'promoter' => [
            'read_event', 'manage_lineup', 'manage_tasks', 'manage_schedule',
            'manage_open_items', 'view_public_page',
        ],
        'band' => ['read_event', 'upload_assets', 'view_assigned_tasks'],
        'artist' => ['read_event', 'upload_assets', 'view_assigned_tasks'],
        'designer' => ['read_event', 'upload_assets', 'manage_assets'],
        'staff' => ['read_event', 'manage_tasks', 'manage_schedule', 'manage_open_items'],
        'viewer' => ['read_event'],
    ];

    private const EVENT_CAPABILITY_KEYS = [
        'read_event', 'edit_event', 'publish_event', 'delete_event',
        'manage_lineup', 'manage_tasks', 'manage_schedule', 'manage_open_items',
        'upload_assets', 'manage_assets', 'manage_invites',
        'view_settlement', 'edit_settlement', 'view_public_page', 'view_assigned_tasks',
    ];

    private const GLOBAL_CAPABILITIES = [
        'venue_admin' => ['view_all_events', 'create_events', 'manage_templates', 'manage_users'],
        'event_owner' => [],
        'promoter' => [],
        'band' => [],
        'artist' => [],
        'designer' => [],
        'staff' => [],
        'viewer' => [],
    ];

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

    protected function hasGlobalCapability(string $capability): bool
    {
        return in_array($capability, self::GLOBAL_CAPABILITIES[$this->role()] ?? [], true);
    }

    protected function globalCapabilities(): array
    {
        $roleCapabilities = self::GLOBAL_CAPABILITIES[$this->role()] ?? [];
        $capabilities = [];
        foreach (array_unique(array_merge(...array_values(self::GLOBAL_CAPABILITIES))) as $capability) {
            $capabilities[$capability] = in_array($capability, $roleCapabilities, true);
        }
        return $capabilities;
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

        $event = $this->db->one('SELECT id, owner_user_id FROM events WHERE id = ?', [$eventId]);
        if (!$event || !$this->userId()) {
            return $this->eventAccessCache[$eventId] = null;
        }

        $role = null;
        if ($this->isVenueAdmin()) {
            $role = 'venue_admin';
        } elseif ((int) ($event['owner_user_id'] ?? 0) === $this->userId()) {
            $role = 'event_owner';
        } else {
            $collaborator = $this->db->one('SELECT role FROM event_collaborators WHERE event_id = ? AND user_id = ? LIMIT 1', [$eventId, $this->userId()]);
            $role = $collaborator['role'] ?? null;
        }

        if (!$role) {
            return $this->eventAccessCache[$eventId] = null;
        }

        return $this->eventAccessCache[$eventId] = [
            'role' => $role,
            'capabilities' => $this->capabilitiesForEventRole($role),
        ];
    }

    protected function eventCapabilities(int $eventId): array
    {
        return $this->eventAccess($eventId)['capabilities'] ?? $this->emptyEventCapabilities();
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
        if ($this->isVenueAdmin()) {
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
            return $this->db->all('SELECT id, name, email, role FROM users ORDER BY name');
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
            return $this->db->all('SELECT id, name, email, role FROM users ORDER BY name');
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

    private function capabilitiesForEventRole(string $role): array
    {
        $allowed = self::EVENT_CAPABILITIES[$role] ?? [];
        $capabilities = $this->emptyEventCapabilities();
        foreach ($allowed as $capability) {
            $capabilities[$capability] = true;
        }
        if (!($capabilities['view_public_page'] ?? false) && ($capabilities['publish_event'] ?? false)) {
            $capabilities['view_public_page'] = true;
        }
        return $capabilities;
    }

    private function emptyEventCapabilities(): array
    {
        return array_fill_keys(self::EVENT_CAPABILITY_KEYS, false);
    }
}
