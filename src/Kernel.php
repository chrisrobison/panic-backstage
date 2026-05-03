<?php
declare(strict_types=1);

namespace Panic;

final class Kernel
{
    public function __construct(
        private readonly string $root,
        private readonly Database $db,
        private readonly Auth $auth
    ) {}

    public static function boot(string $root): self
    {
        Env::load($root . '/.env');
        return new self($root, new Database(), new Auth());
    }

    public function handle(): Response
    {
        $request = Request::fromGlobals();

        // Populate $auth->user() from Bearer token if present
        $this->auth->authenticate($request);

        try {
            [$class, $params] = $this->resolve($request->path());
            if (!class_exists($class)) {
                return Response::json(['error' => 'Endpoint not found'], 404);
            }

            if (!$this->isPublic($class) && !$this->auth->user()) {
                return Response::json(['error' => 'Authentication required'], 401);
            }

            /** @var Endpoint $endpoint */
            $endpoint = new $class($this->db, $this->auth, $params, $this->root);
            return $endpoint->handle($request);
        } catch (\Throwable $error) {
            error_log((string) $error);
            return Response::json(['error' => 'Server error', 'detail' => $error->getMessage()], 500);
        }
    }

    private function resolve(string $path): array
    {
        $path = $this->stripBasePath($path);
        if (str_starts_with($path, '/public/')) {
            $path = substr($path, strlen('/public')) ?: '/';
        }
        $segments = array_values(array_filter(explode('/', trim($path, '/')), 'strlen'));
        if (($segments[0] ?? '') === 'api') {
            array_shift($segments);
        }

        // Auth (all actions are POST; public at kernel level)
        if ($segments[0] === 'auth') {
            return [AuthEndpoint::class, ['action' => $segments[1] ?? '']];
        }

        // Current user info
        if ($segments === [] || $segments[0] === 'me') {
            return [Me::class, []];
        }

        // Event templates
        if ($segments[0] === 'templates') {
            return [Templates::class, ['templateId' => $this->intOrNull($segments[1] ?? null)]];
        }

        // Public event pages (unauthenticated)
        if ($segments[0] === 'public' && ($segments[1] ?? '') === 'events') {
            return [PublicEvents::class, ['slug' => $segments[2] ?? null]];
        }

        // Invite acceptance (unauthenticated)
        if ($segments[0] === 'invite') {
            return [Invites::class, ['token' => $segments[1] ?? null]];
        }

        // Dashboard
        if ($segments[0] === 'dashboard') {
            return [Dashboard::class, []];
        }

        // Events + sub-resources
        if ($segments[0] === 'events') {
            if (($segments[1] ?? '') === 'from-template') {
                return [Events::class, ['fromTemplateId' => $this->intOrNull($segments[2] ?? null)]];
            }
            $eventId = $this->intOrNull($segments[1] ?? null);
            $child   = $segments[2] ?? null;
            $childId = $this->intOrNull($segments[3] ?? null);
            return match ($child) {
                'tasks'      => [Events\Tasks::class,    ['eventId' => $eventId, 'taskId'     => $childId]],
                'blockers',
                'open-items' => [Events\Blockers::class, ['eventId' => $eventId, 'blockerId'  => $childId]],
                'lineup'     => [Events\Lineup::class,   ['eventId' => $eventId, 'lineupId'   => $childId]],
                'schedule'   => [Events\Schedule::class, ['eventId' => $eventId, 'scheduleId' => $childId]],
                'assets'     => [Events\Assets::class,   ['eventId' => $eventId, 'assetId'    => $childId]],
                'settlement' => [Events\Settlement::class, ['eventId' => $eventId]],
                'invites'    => [Events\Invites::class,  ['eventId' => $eventId, 'inviteId'   => $childId]],
                'stream'     => [Events\Stream::class,   ['eventId' => $eventId]],
                default      => [Events::class,          ['eventId' => $eventId]],
            };
        }

        $name = preg_replace('/[^A-Za-z0-9]/', '', ucwords($segments[0], '-_'));
        return ["Panic\\$name", []];
    }

    /**
     * Endpoints that do not require an authenticated user.
     * AuthEndpoint handles its own token validation internally.
     */
    private function isPublic(string $class): bool
    {
        return in_array($class, [
            AuthEndpoint::class,
            PublicEvents::class,
            Invites::class,
            Me::class,          // returns null user gracefully when unauthenticated
        ], true);
    }

    private function intOrNull(?string $value): ?int
    {
        return ctype_digit((string) $value) ? (int) $value : null;
    }

    private function stripBasePath(string $path): string
    {
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        $apiPrefix  = preg_replace('#/api/index\.php$#', '', $scriptName);
        $basePath   = rtrim((string) (($_SERVER['APP_BASE_PATH'] ?? '') ?: getenv('APP_BASE_PATH') ?: $apiPrefix), '/');
        if ($basePath !== '' && $basePath !== '/' && str_starts_with($path, $basePath . '/')) {
            return substr($path, strlen($basePath)) ?: '/';
        }
        return $path;
    }
}
