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

        // Revocation check: a stolen or otherwise-compromised bearer token
        // must not remain usable for its full 90-day life. Every access
        // token embeds the token_version that was current at issuance time;
        // if it no longer matches users.token_version (bumped on password
        // change — see AuthEndpoint::setPassword) the token is dead even
        // though it hasn't expired yet.
        if ($user = $this->auth->user()) {
            $row = $this->db->one('SELECT token_version FROM users WHERE id = ?', [$user['id']]);
            if ($row === null || (int) $row['token_version'] !== (int) ($user['token_version'] ?? 0)) {
                $this->auth->clearUser();
            }
        }

        // Attribute subsequent writes in db_history to the authenticated user
        // (falls back to the generic 'cli:'/anonymous actor set in the
        // Database constructor for unauthenticated/public endpoints).
        if ($user = $this->auth->user()) {
            $this->db->setActor('user:' . $user['id']);
        }

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

        // GDPR data-subject actions on the signed-in user's own account:
        //   GET  /api/account/export, POST /api/account/delete,
        //   POST /api/account/accept-privacy
        if ($segments[0] === 'account') {
            return [AccountData::class, ['action' => $segments[1] ?? '']];
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
        //   GET  /api/contacts/{id}/lists          which mailing lists this contact belongs to
        //   GET  /api/contacts/{id}/activity       audit trail
        //   GET/POST /api/contacts/{id}/tags       tags assigned to this contact
        //   DELETE   /api/contacts/{id}/tags/{tagId}
        //   POST /api/contacts/bulk-tag            assign one tag to many contacts at once
        if ($segments[0] === 'contacts') {
            if (($segments[1] ?? null) === 'bulk-tag') {
                return [Contacts::class, ['contactId' => null, 'action' => 'bulk-tag']];
            }
            return [Contacts::class, [
                'contactId' => $this->intOrNull($segments[1] ?? null),
                'action'    => $segments[2] ?? null,
                'subId'     => $this->intOrNull($segments[3] ?? null),
            ]];
        }

        // Tag definitions (admin; manage_campaigns gate inside endpoint) —
        // assigning/unassigning a tag to a contact goes through /contacts
        // above; this only manages the name/color definitions.
        if ($segments[0] === 'contact-tags') {
            return [ContactTags::class, ['tagId' => $this->intOrNull($segments[1] ?? null)]];
        }

        // "List storage: N of LIMIT contacts" meter (admin; manage_contacts gate inside endpoint)
        if ($segments[0] === 'contact-storage') {
            return [ContactStorage::class, []];
        }

        // Email campaigns (admin; manage_campaigns gate inside endpoint)
        //   GET    /api/campaigns                              list
        //   GET    /api/campaigns/eligible-events               events available for the "generate from events" picker
        //   POST   /api/campaigns/generate-from-events           create a campaign from picked events
        //   GET    /api/campaigns/{id}                           show one
        //   POST   /api/campaigns                                create a blank campaign
        //   PATCH  /api/campaigns/{id}                           update draft fields
        //   DELETE /api/campaigns/{id}                           delete a draft
        //   GET    /api/campaigns/{id}/recipients/preview         preview recipient resolution
        //   POST   /api/campaigns/{id}/send                       send to resolved recipients
        //   POST   /api/campaigns/{id}/send-test                  send a one-off test copy
        if ($segments[0] === 'campaigns') {
            $seg1 = $segments[1] ?? null;
            if ($seg1 === 'eligible-events' || $seg1 === 'generate-from-events') {
                return [Campaigns::class, ['action' => $seg1]];
            }
            return [Campaigns::class, [
                'campaignId' => $this->intOrNull($seg1),
                'action'     => $segments[2] ?? null,
                'subAction'  => $segments[3] ?? null,
            ]];
        }

        // Mailing lists (marketing; manage_campaigns gate inside endpoint)
        //   GET /api/mailing-lists/import-history | export-history  global
        //   history logs — checked first since 'import-history'/'export-history'
        //   aren't numeric ids and would otherwise be silently dropped by
        //   intOrNull() below, colliding with the plain index route.
        if ($segments[0] === 'mailing-lists') {
            $seg1 = $segments[1] ?? null;
            if ($seg1 === 'import-history' || $seg1 === 'export-history') {
                return [MailingLists::class, ['listId' => null, 'child' => $seg1, 'childId' => null]];
            }
            return [MailingLists::class, [
                'listId'  => $this->intOrNull($seg1),
                'child'   => $segments[2] ?? null,
                'childId' => $this->intOrNull($segments[3] ?? null),
            ]];
        }

        // Public event pages (unauthenticated)
        if ($segments[0] === 'public' && ($segments[1] ?? '') === 'events') {
            return [PublicEvents::class, ['idOrSlug' => $segments[2] ?? null]];
        }

        // Public ticket purchase (unauthenticated):
        //   GET  /api/public/tickets/{eventId}                    -> list on-sale tiers
        //   POST /api/public/tickets/{eventId}/checkout           -> create checkout session
        //   GET  /api/public/tickets/{eventId}/orders/{orderId}   -> poll a checkout's fulfillment
        //                                                             (requires ?receipt=<token>)
        if ($segments[0] === 'public' && ($segments[1] ?? '') === 'tickets') {
            return [PublicTickets::class, [
                'eventId' => $this->intOrNull($segments[2] ?? null),
                'action'  => $segments[3] ?? null,
                'orderId' => $this->intOrNull($segments[4] ?? null),
            ]];
        }

        // Payment provider webhooks (unauthenticated; verified by signature):
        //   POST /api/webhooks/stripe       → ticketing (online checkout)
        //   POST /api/webhooks/square       → ticketing (online checkout)
        //   POST /api/webhooks/square-pos   → POS bar/merch sales → ledger
        if ($segments[0] === 'webhooks') {
            if (($segments[1] ?? '') === 'square-pos') {
                return [PosWebhook::class, []];
            }
            return [Webhooks::class, ['provider' => $segments[1] ?? null]];
        }

        // POS location mapping (admin; manage_users gate inside endpoint):
        //   GET    /api/pos-location-map
        //   POST   /api/pos-location-map
        //   PATCH  /api/pos-location-map/{id}
        //   DELETE /api/pos-location-map/{id}
        //   POST   /api/pos-location-map/{id}/set-active
        //   POST   /api/pos-location-map/{id}/clear-active
        if ($segments[0] === 'pos-location-map') {
            return [PosLocationMap::class, [
                'mappingId' => $this->intOrNull($segments[1] ?? null),
                'sub'       => $segments[2] ?? null,
            ]];
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

        // Venue-wide reporting: /reports[/settlements]
        if ($segments[0] === 'reports') {
            return [Reports::class, ['action' => $segments[1] ?? null]];
        }

        // Asset library — cross-event read-only asset browser
        if ($segments[0] === 'asset-library') {
            return [AssetLibrary::class, []];
        }

        // Leads pipeline
        if ($segments[0] === 'leads') {
            $leadId  = $this->intOrNull($segments[1] ?? null);
            $child   = $segments[2] ?? null;
            $childId = $this->intOrNull($segments[3] ?? null);
            return [Leads::class, ['leadId' => $leadId, 'child' => $child, 'childId' => $childId]];
        }

        // CRM profiles
        if ($segments[0] === 'crm-profiles') {
            $profileId = $this->intOrNull($segments[1] ?? null);
            $child     = $segments[2] ?? null;
            $childId   = $this->intOrNull($segments[3] ?? null);
            return [CrmProfiles::class, ['profileId' => $profileId, 'child' => $child, 'childId' => $childId]];
        }

        // CRM follow-up reminder cron endpoint
        if ($segments[0] === 'crm-followups') {
            return [CrmFollowups::class, []];
        }

        // Client portal — token-gated read-only event view for promoters/clients
        //   GET  /api/portal/view?token=...        (public)
        //   POST /api/portal/{eventId}/create-link
        //   GET  /api/portal/{eventId}/list-links
        //   POST /api/portal/{tokenId}/revoke
        if ($segments[0] === 'portal') {
            $sub = $segments[1] ?? 'view';
            if ($sub === 'view') {
                $action  = 'view';
                $tokenId = null;
                $eventId = null;
            } else {
                $action  = $segments[2] ?? '';
                $id      = $this->intOrNull($sub);
                $tokenId = $action === 'revoke' ? $id : null;
                $eventId = in_array($action, ['create-link', 'list-links'], true) ? $id : null;
            }
            return [Portal::class, ['action' => $action, 'tokenId' => $tokenId, 'eventId' => $eventId]];
        }

        // Venue policy
        if ($segments[0] === 'venue-policy') {
            $policyId = $this->intOrNull($segments[1] ?? null);
            $sub      = ($segments[1] ?? '') === 'history' ? 'history' : null;
            return [VenuePolicy::class, ['policyId' => $policyId, 'sub' => $sub]];
        }

        // Systems inventory
        if ($segments[0] === 'systems-inventory') {
            return [SystemsInventory::class, ['itemId' => $this->intOrNull($segments[1] ?? null)]];
        }

        // Outbox — sent-mail log (admin; manage_users gate inside endpoint)
        if ($segments[0] === 'outbox') {
            return [Outbox::class, ['outboxId' => $this->intOrNull($segments[1] ?? null)]];
        }

        // Database browser — read-only tenant DB inspector (admin; manage_users gate inside endpoint)
        //   GET /api/db-browser                 → list tables
        //   GET /api/db-browser/{table}         → paginated rows + columns (?page=&limit=&sort=&dir=&filter[col]=)
        //   GET /api/db-browser/{table}/export  → download matching rows (?format=csv|xls|sql)
        if ($segments[0] === 'db-browser') {
            return [DatabaseBrowser::class, ['table' => $segments[1] ?? null, 'action' => $segments[2] ?? null]];
        }

        // DB history — browse the audit-trigger log and undo/redo entries
        // (admin; manage_db_history gate inside endpoint)
        //   GET  /api/db-history                → paginated list (?table=&pk=&actor=&action=&from=&to=&undone=&page=&limit=)
        //   GET  /api/db-history/{id}            → one entry (full old/new JSON + undo SQL)
        //   POST /api/db-history/{id}/undo       → execute the undo (also how you "redo": undo the undo's own entry)
        if ($segments[0] === 'db-history') {
            return [DbHistory::class, ['id' => $this->intOrNull($segments[1] ?? null), 'action' => $segments[2] ?? null]];
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
            // GET|PATCH /api/promote/auto-publish — global auto-publish settings
            if (($segments[1] ?? '') === 'auto-publish') {
                return [Promote\AutoPublishSettings::class, []];
            }
            // GET /api/promote/eventbrite/org — one-time setup helper
            if (($segments[1] ?? '') === 'eventbrite' && ($segments[2] ?? '') === 'org') {
                return [Promote\EventbriteSetup::class, []];
            }
            // POST /api/promote/oauth/twitter/start ; GET /api/promote/oauth/twitter/callback
            // — in-app "Connect X account" OAuth 2.0 PKCE flow.
            if (($segments[1] ?? '') === 'oauth' && ($segments[2] ?? '') === 'twitter') {
                return [Promote\TwitterOAuth::class, ['action' => $segments[3] ?? '']];
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
        // PATCH  /api/venues/{id}                      — update venue details (venue_admin only)
        // GET/POST/PATCH/DELETE /api/venues/{id}/resources[/{rid}] — manage rooms (venue_admin)
        if ($segments[0] === 'venues') {
            $params = ['venueId' => $this->intOrNull($segments[1] ?? null)];
            if (($segments[2] ?? '') === 'resources') {
                $params['child']      = 'resources';
                $params['resourceId'] = $this->intOrNull($segments[3] ?? null);
            }
            return [Venues::class, $params];
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
            // Auto-populate staffing from capacity tiers (also accepts /preview and /export)
            if ($child === 'staffing' && in_array($segments[3] ?? '', ['from-capacity','preview','export'], true)) {
                return [Events\Staffing::class, ['eventId' => $eventId, 'action' => $segments[3]]];
            }
            // Populate the run sheet from other event data, or stamp in a standard preset
            if ($child === 'schedule' && in_array($segments[3] ?? '', ['from-event-data', 'from-preset'], true)) {
                return [Events\Schedule::class, ['eventId' => $eventId, 'action' => $segments[3]]];
            }
            // Payments: /events/{id}/payments[/{pid}[/waive-deposit]]
            if ($child === 'payments') {
                $payAction = ($segments[4] ?? '') !== '' ? $segments[4] : null;
                return [Events\Payments::class, [
                    'eventId'   => $eventId,
                    'paymentId' => $childId,
                    'action'    => $payAction,
                ]];
            }
            // Ledger: /events/{id}/ledger[/summary|/finalize|/reopen|/{eid}]
            if ($child === 'ledger') {
                $sub     = $segments[3] ?? null;
                $lAction = in_array($sub, ['summary','finalize','reopen'], true) ? $sub : null;
                $entryId = $lAction === null ? $this->intOrNull($sub) : null;
                return [Events\Ledger::class, [
                    'eventId' => $eventId,
                    'entryId' => $entryId,
                    'action'  => $lAction,
                ]];
            }
            // Vendors: /events/{id}/vendors[/{vid}]
            if ($child === 'vendors') {
                return [Events\Vendors::class, ['eventId' => $eventId, 'vendorId' => $childId]];
            }
            // Execution records: /events/{id}/execution[/{rid}]
            if ($child === 'execution') {
                return [Events\Execution::class, ['eventId' => $eventId, 'recordId' => $childId]];
            }
            // AI flyer generation: POST /events/{id}/assets/generate-flyer
            if ($child === 'assets' && ($segments[3] ?? '') === 'generate-flyer') {
                return [Events\GenerateFlyer::class, ['eventId' => $eventId]];
            }
            // Public-page QR code: GET/POST /events/{id}/assets/generate-qr
            if ($child === 'assets' && ($segments[3] ?? '') === 'generate-qr') {
                return [Events\GenerateQr::class, ['eventId' => $eventId]];
            }
            // Recurring events: GET/POST/DELETE /events/{id}/series
            if ($child === 'series') {
                return [Events\Series::class, ['eventId' => $eventId]];
            }
            return match ($child) {
                'tasks'      => [Events\Tasks::class,    ['eventId' => $eventId, 'taskId'     => $childId]],
                'blockers',
                'open-items' => [Events\Blockers::class, ['eventId' => $eventId, 'blockerId'  => $childId]],
                'lineup'     => [Events\Lineup::class,   ['eventId' => $eventId, 'lineupId'   => $childId]],
                'schedule'   => [Events\Schedule::class, ['eventId' => $eventId, 'scheduleId' => $childId]],
                'sessions'   => [Events\Sessions::class, ['eventId' => $eventId, 'sessionId'  => $childId]],
                'assets'     => [Events\Assets::class,   ['eventId' => $eventId, 'assetId'    => $childId]],
                'settlement' => [Events\Settlement::class, ['eventId' => $eventId]],
                'report'     => [Events\Report::class,     ['eventId' => $eventId]],
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
            Webhooks::class,            // payment provider webhooks (ticketing), authenticated by signature
            PosWebhook::class,          // Square POS webhook (bar/merch ledger), authenticated by signature
            Portal::class,              // Client portal — public view gated by signed token (no JWT)
            ContractWebhooks::class,    // contract provider webhooks, authenticated by signature
            ContractSigningEndpoint::class, // public signing flow, authenticated by token hash
            TicketView::class,          // public ticket page, looked up by token hash
            Scanner::class,             // /api/scan/redeem (scanner-token); JWT mgmt paths
                                        // still gated via requireEventCapability (null user => denied)
            QrCode::class,              // /assets/qr.svg — public QR image generator
            Promote\TwitterOAuth::class, // /api/promote/oauth/twitter/callback (browser redirect from X, no JWT);
                                        // the sibling /start action self-gates via requireGlobalCapability
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
