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

    /**
     * @param Database|null $db  Pre-built database (multi-tenant path; PDO injected
     *                           from TenantContext). When null, creates a new Database
     *                           using DB_* environment variables (single-tenant path —
     *                           existing behavior unchanged).
     */
    public static function boot(string $root, ?Database $db = null): self
    {
        Env::load($root . '/.env');
        return new self($root, $db ?? new Database(), new Auth());
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
        if (($segments[0] ?? '') === 'auth') {
            return [AuthEndpoint::class, ['action' => $segments[1] ?? '']];
        }

        // Current user info (also handles bare /api with no further segments)
        if ($segments === [] || $segments[0] === 'me') {
            return [Me::class, []];
        }

        // Event templates
        if ($segments[0] === 'templates') {
            return [Templates::class, ['templateId' => $this->intOrNull($segments[1] ?? null)]];
        }

        // User accounts (admin)
        if ($segments[0] === 'users') {
            // Duplicate detection + account merge (admin; manage_users gate inside endpoint).
            //   GET  /api/users/duplicates  -> suggested duplicate pairs
            //   POST /api/users/merge       -> fold loser into survivor (atomic)
            if (($segments[1] ?? null) === 'duplicates') {
                return [Duplicates::class, ['action' => 'duplicates']];
            }
            if (($segments[1] ?? null) === 'merge') {
                return [Duplicates::class, ['action' => 'merge']];
            }
            // Alias self-management: /api/users/{id}/emails[/resend|/primary]
            if (($segments[2] ?? null) === 'emails') {
                return [UserEmails::class, [
                    'userId' => $this->intOrNull($segments[1] ?? null),
                    'sub'    => $segments[3] ?? null,
                ]];
            }
            return [Users::class, [
                'userId' => $this->intOrNull($segments[1] ?? null),
                'action' => $segments[2] ?? null,
            ]];
        }

        // Contract clause library (admin)
        if ($segments[0] === 'contract-modules') {
            return [ContractModules::class, ['moduleId' => $this->intOrNull($segments[1] ?? null)]];
        }

        // Contract templates (admin)
        if ($segments[0] === 'contract-templates') {
            return [ContractTemplates::class, ['templateId' => $this->intOrNull($segments[1] ?? null)]];
        }

        // Contracts (deal builder)
        if ($segments[0] === 'contracts') {
            // Contract webhooks (unauthenticated; verified by provider signature):
            //   POST /api/contracts/webhook/{provider}
            if (($segments[1] ?? '') === 'webhook') {
                return [ContractWebhooks::class, ['provider' => $segments[2] ?? null]];
            }
            return [Contracts::class, [
                'contractId' => $this->intOrNull($segments[1] ?? null),
                'child'      => $segments[2] ?? null,
                'childId'    => $this->intOrNull($segments[3] ?? null),
            ]];
        }

        // Public contract signing (unauthenticated; token-protected):
        //   GET  /api/signing/{token}          load contract for signing page
        //   POST /api/signing/{token}/viewed   mark viewed
        //   POST /api/signing/{token}/sign     submit signature
        //   POST /api/signing/{token}/decline  decline to sign
        if ($segments[0] === 'signing') {
            return [ContractSigningEndpoint::class, [
                'token'  => $segments[1] ?? null,
                'action' => $segments[2] ?? null,
            ]];
        }

        // Staff roster (admin)
        if ($segments[0] === 'staff-members') {
            return [StaffMembers::class, ['staffId' => $this->intOrNull($segments[1] ?? null)]];
        }

        // Marketing / CRM contacts (admin)
        if ($segments[0] === 'contacts') {
            return [Contacts::class, ['contactId' => $this->intOrNull($segments[1] ?? null)]];
        }

        // Public event pages (unauthenticated)
        if ($segments[0] === 'public' && ($segments[1] ?? '') === 'events') {
            return [PublicEvents::class, ['slug' => $segments[2] ?? null]];
        }

        // Public ticket purchase (unauthenticated):
        //   GET  /api/public/tickets/{eventId}           -> list on-sale tiers
        //   POST /api/public/tickets/{eventId}/checkout  -> create checkout session
        if ($segments[0] === 'public' && ($segments[1] ?? '') === 'tickets') {
            return [PublicTickets::class, [
                'eventId' => $this->intOrNull($segments[2] ?? null),
                'action'  => $segments[3] ?? null,
            ]];
        }

        // Payment provider webhooks (unauthenticated; verified by signature):
        //   POST /api/webhooks/stripe | /api/webhooks/square
        if ($segments[0] === 'webhooks') {
            return [Webhooks::class, ['provider' => $segments[1] ?? null]];
        }

        // Door scanner redeem (scanner-token auth, NOT JWT):
        //   POST /api/scan/redeem
        if ($segments[0] === 'scan' && ($segments[1] ?? '') === 'redeem') {
            return [Scanner::class, ['scan' => 'redeem']];
        }

        // Public ticket view (pretty URL, no /api prefix, no JWT):
        //   GET /t/{token}  (router.php forwards /t/* to the API kernel)
        if ($segments[0] === 't') {
            return [TicketView::class, ['token' => $segments[1] ?? null]];
        }

        // Dynamically generated QR image (no JWT).
        //   /assets/qr.svg?text=...  → SVG (ticket view pages)
        //   /assets/qr.png?text=...  → PNG (HTML emails; Gmail/Outlook don't support SVG)
        if ($segments[0] === 'assets' && in_array($segments[1] ?? '', ['qr.svg', 'qr.png'], true)) {
            return [QrCode::class, ['format' => ($segments[1] ?? '') === 'qr.png' ? 'png' : 'svg']];
        }

        // Global payment settings (admin; manage_users gate inside endpoint)
        if ($segments[0] === 'payment-settings') {
            return [PaymentSettings::class, []];
        }

        // Invite acceptance (unauthenticated)
        if ($segments[0] === 'invite') {
            return [Invites::class, ['token' => $segments[1] ?? null]];
        }

        // Dashboard
        if ($segments[0] === 'dashboard') {
            return [Dashboard::class, []];
        }

        // Outbox — sent-mail log (admin; manage_users gate inside endpoint)
        if ($segments[0] === 'outbox') {
            return [Outbox::class, ['outboxId' => $this->intOrNull($segments[1] ?? null)]];
        }

        // Messages — in-app staff messaging (Inbox / Archive / Outbox)
        //   /api/messages
        //   /api/messages/recipients | /api/messages/unread-count
        //   /api/messages/{id}[/archive|/unarchive|/read|/unread]
        if ($segments[0] === 'messages') {
            return [Messages::class, [
                'sub'       => $segments[1] ?? null,
                'messageId' => $this->intOrNull($segments[1] ?? null),
                'action'    => $segments[2] ?? null,
            ]];
        }

        // Wizard defaults — admin-configurable defaults for the event wizard
        if ($segments[0] === 'wizard-defaults') {
            return [WizardDefaults::class, []];
        }

        // Panic Promote — /api/promote/...
        if ($segments[0] === 'promote') {
            // GET /api/promote/eventbrite/org — one-time setup helper
            if (($segments[1] ?? '') === 'eventbrite' && ($segments[2] ?? '') === 'org') {
                return [Promote\EventbriteSetup::class, []];
            }
            // GET|PUT|DELETE /api/promote/credentials[/{destKey}]
            if (($segments[1] ?? '') === 'credentials') {
                return [Promote\CredentialSettings::class, ['destKey' => $segments[2] ?? null]];
            }
            if (($segments[1] ?? '') === 'events') {
                $eventId = $this->intOrNull($segments[2] ?? null);
                $child   = $segments[3] ?? null;
                $childId = $this->intOrNull($segments[4] ?? null);
                // posts: .../posts/{postId}/variants[/generate|/{variantId}]
                //        .../posts/{postId}/action/{destKey}[/send]
                if ($child === 'posts') {
                    $sub   = $segments[5] ?? null;   // 'variants', 'action', or null
                    if ($sub === 'action') {
                        return [Promote\ActionEndpoint::class, [
                            'eventId'   => $eventId,
                            'postId'    => $childId,
                            'destKey'   => $segments[6] ?? null,  // e.g. 'sf_chronicle'
                            'subAction' => $segments[7] ?? null,  // 'send' or null
                        ]];
                    }
                    $subId = $this->intOrNull($segments[6] ?? null);
                    return [Promote\Posts::class, [
                        'eventId' => $eventId,
                        'postId'  => $childId,
                        'sub'     => $sub,
                        'subId'   => $subId,
                    ]];
                }
                return match ($child) {
                    'broadcasts'   => [Promote\Broadcasts::class,    ['eventId' => $eventId, 'broadcastId' => $childId]],
                    'health'       => [Promote\HealthEndpoint::class, ['eventId' => $eventId]],
                    'analytics'    => [Promote\Analytics::class,      ['eventId' => $eventId]],
                    'destinations' => [Promote\Destinations::class,   ['eventId' => $eventId]],
                    default        => [Promote::class,                ['eventId' => $eventId]],
                };
            }
            // /api/promote/events (no id) → list
            return [Promote::class, ['eventId' => null]];
        }

        // Public syndication feeds (unauthenticated):
        //   GET /api/feed                → JSON index of available feeds
        //   GET /api/feed/events.ics     → iCalendar subscription
        //   GET /api/feed/events.rss     → RSS 2.0
        if ($segments[0] === 'feed') {
            return [Feed::class, ['format' => $segments[1] ?? '']];
        }

        // Venues + resources listing (lightweight; used by the calendar zone map and sidebar)
        if ($segments[0] === 'venues') {
            return [Venues::class, []];
        }

        // Events + sub-resources
        if ($segments[0] === 'events') {
            if (($segments[1] ?? '') === 'from-template') {
                return [Events::class, ['fromTemplateId' => $this->intOrNull($segments[2] ?? null)]];
            }
            $eventId = $this->intOrNull($segments[1] ?? null);
            $child   = $segments[2] ?? null;
            $childId = $this->intOrNull($segments[3] ?? null);
            // Apply a task template to an existing event
            if ($child === 'tasks' && ($segments[3] ?? '') === 'from-template') {
                return [Events\Tasks::class, ['eventId' => $eventId, 'fromTemplateId' => $this->intOrNull($segments[4] ?? null)]];
            }
            // Auto-populate staffing from capacity tiers
            if ($child === 'staffing' && ($segments[3] ?? '') === 'from-capacity') {
                return [Events\Staffing::class, ['eventId' => $eventId, 'action' => 'from-capacity']];
            }
            return match ($child) {
                'tasks'      => [Events\Tasks::class,    ['eventId' => $eventId, 'taskId'     => $childId]],
                'blockers',
                'open-items' => [Events\Blockers::class, ['eventId' => $eventId, 'blockerId'  => $childId]],
                'lineup'     => [Events\Lineup::class,   ['eventId' => $eventId, 'lineupId'   => $childId]],
                'schedule'   => [Events\Schedule::class, ['eventId' => $eventId, 'scheduleId' => $childId]],
                'assets'     => [Events\Assets::class,   ['eventId' => $eventId, 'assetId'    => $childId]],
                'settlement' => [Events\Settlement::class, ['eventId' => $eventId]],
                'invites'    => [Events\Invites::class,  ['eventId' => $eventId, 'inviteId'   => $childId]],
                'guest-list' => [Events\GuestList::class, ['eventId' => $eventId, 'guestId'   => $childId]],
                'staffing'   => [Events\Staffing::class,  ['eventId' => $eventId, 'staffingId' => $childId]],
                'contracts'  => [Events\Contracts::class, ['eventId' => $eventId, 'contractId' => $childId]],
                'stream'     => [Events\Stream::class,   ['eventId' => $eventId]],
                'ticketing'  => [Events\Ticketing::class, [
                    'eventId' => $eventId,
                    'child'   => $segments[3] ?? '',
                    'childId' => $this->intOrNull($segments[4] ?? null),
                ]],
                'scanner-links' => [Scanner::class, ['eventId' => $eventId, 'linkId' => $childId]],
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
            Feed::class,                // public ICS/RSS syndication of public_visibility events
            Invites::class,
            Me::class,                  // returns null user gracefully when unauthenticated
            PublicTickets::class,        // public ticket browse + checkout
            Webhooks::class,            // payment provider webhooks, authenticated by signature
            ContractWebhooks::class,    // contract provider webhooks, authenticated by signature
            ContractSigningEndpoint::class, // public signing flow, authenticated by token hash
            TicketView::class,          // public ticket page, looked up by token hash
            Scanner::class,             // /api/scan/redeem (scanner-token); JWT mgmt paths
                                        // still gated via requireEventCapability (null user => denied)
            QrCode::class,              // /assets/qr.svg — public QR image generator
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
