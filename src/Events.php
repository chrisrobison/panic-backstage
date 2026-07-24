<?php
declare(strict_types=1);

namespace Panic;

use function Panic\boolish;
use function Panic\date_or_null;
use function Panic\event_public_path;
use function Panic\log_activity;

final class Events extends BaseEndpoint
{
    use Events\EventRowHelpers;

    private const STATUSES = ['empty','proposed','confirmed','booked','needs_assets','assets_approved','ready_to_announce','published','advanced','completed','settled','canceled'];

    /** Statuses that represent a committed booking (conflict + transition checks apply). */
    private const BOOKING_CONFIRMED_STATUSES = ['confirmed','booked','needs_assets','assets_approved','ready_to_announce','published','advanced','completed','settled'];
    private const TYPES = ['live_music','karaoke','open_mic','promoter_night','dj_night','comedy','private_event','special_event'];

    /** Human-readable labels for event fields — used in activity-log diff entries. */
    private const EVENT_FIELD_LABELS = [
        'title'                => 'Title',
        'event_type'           => 'Event Type',
        'status'               => 'Status',
        'date'                 => 'Date',
        'end_date'             => 'End Date',
        'doors_time'           => 'Doors Time',
        'show_time'            => 'Show Time',
        'end_time'             => 'End Time',
        'load_in_time'         => 'Load-In Time',
        'is_non_music'         => 'Non-Music Event',
        'age_restriction'      => 'Age Restriction',
        'capacity'             => 'Capacity',
        'estimated_guests'     => 'Estimated Guests',
        'description_public'   => 'Public Description',
        'description_internal' => 'Internal Notes',
        'av_requirements'      => 'A/V Requirements',
        'catering_notes'       => 'Catering Notes',
        'ticket_price'         => 'Ticket Price',
        'ticket_url'           => 'Ticket URL',
        'ticket_system'        => 'Ticket System',
        'deposit_amount'       => 'Deposit Amount',
        'potential_revenue'    => 'Potential Revenue',
        'contract_url'         => 'Contract URL',
        'venue_contract_url'   => 'Venue Contract URL',
        'settlement_doc_url'   => 'Settlement Doc URL',
        'walkthrough_done'     => 'Walkthrough Done',
        'public_visibility'    => 'Public Visibility',
        'owner_user_id'        => 'Owner',
        'venue_id'             => 'Venue',
        'resource_id'          => 'Room',
        'promoter_name'        => 'Producer / Artist',
        'promoter_email'       => 'Producer Email',
        'promoter_phone'       => 'Producer Phone',
        'client_org'           => 'Client Organization',
        'booker_name'          => 'Booker',
        'booker_email'         => 'Booker Email',
        'booker_phone'         => 'Booker Phone',
    ];

    public function handle(Request $request): Response
    {
        if ($this->params['fromTemplateId'] ?? null) {
            return $this->fromTemplate($request, (int) $this->params['fromTemplateId']);
        }
        $eventId = $this->params['eventId'] ?? null;
        return match ($request->method()) {
            'GET' => $eventId ? $this->show((int) $eventId) : $this->index($request),
            'POST' => $this->create($request),
            'PATCH' => $this->update($request, (int) $eventId),
            'DELETE' => $this->delete((int) $eventId),
            default => Response::methodNotAllowed()
        };
    }

    private function index(Request $request): Response
    {
        $where = [];
        $params = [];
        [$scopeSql, $scopeParams] = $this->eventScopeSql('e');
        $where[] = $scopeSql;
        $params = array_merge($params, $scopeParams);
        foreach (['status', 'event_type', 'owner_user_id', 'public_visibility'] as $field) {
            $value = $request->query($field);
            if ($value !== null && $value !== '') {
                $where[] = "e.$field = ?";
                $params[] = $value;
            }
        }
        if ($request->query('start_date')) {
            // COALESCE so a multi-day event that started before the requested
            // window but is still running (end_date within/after it) is still
            // returned — a plain `e.date >= ?` would drop it entirely once its
            // start date scrolled out of the visible range.
            $where[] = 'COALESCE(e.end_date, e.date) >= ?';
            $params[] = $request->query('start_date');
        }
        if ($request->query('end_date')) {
            $where[] = 'e.date <= ?';
            $params[] = $request->query('end_date');
        }
        $sql = "SELECT e.*, u.name owner_name, v.name venue_name, v.city venue_city, v.state venue_state, r.capacity resource_capacity,
                  (SELECT title FROM event_blockers b WHERE b.event_id = e.id AND b.status IN ('open','waiting') ORDER BY due_date, id LIMIT 1) primary_blocker,
                  (SELECT COUNT(*) FROM event_tasks t WHERE t.event_id = e.id AND t.status NOT IN ('done','canceled')) incomplete_tasks,
                  (SELECT COUNT(*) FROM event_blockers b WHERE b.event_id = e.id AND b.status IN ('open','waiting')) open_items,
                  (SELECT COUNT(*) FROM event_assets a WHERE a.event_id = e.id AND a.asset_type = 'flyer' AND a.approval_status = 'approved') approved_flyers,
                  (SELECT file_path FROM event_assets a WHERE a.event_id = e.id AND a.asset_type IN ('flyer','poster') AND a.approval_status = 'approved' ORDER BY created_at DESC LIMIT 1) flyer_path
                FROM events e
                LEFT JOIN users u ON u.id = e.owner_user_id
                LEFT JOIN venues v ON v.id = e.venue_id
                LEFT JOIN resources r ON r.id = e.resource_id";
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY e.date DESC, e.show_time DESC LIMIT 250';
        $events = $this->db->all($sql, $params);
        $events = $this->attachListExtras($events);

        $result = [
            'events' => $events,
            'users' => $this->accessibleUsers(),
            'venues' => $this->db->all('SELECT * FROM venues ORDER BY name'),
            // Rooms within each venue — the Calendar's zone-split rendering keys
            // off each event's resource_id (not venue_id, now that a venue can
            // have multiple rooms) to decide which half of a day cell it lands in.
            'resources' => $this->db->all('SELECT * FROM resources WHERE active = 1 ORDER BY venue_id, sort_order, name'),
            'statuses' => self::STATUSES,
            'types' => self::TYPES,
            'range' => [
                'start_date' => $request->query('start_date'),
                'end_date' => $request->query('end_date'),
            ],
            'capabilities' => $this->globalCapabilities(),
        ];

        // Opt-in: the "Upcoming" card view needs a stats summary of the
        // currently-filtered set (tickets sold, est. gross revenue, avg
        // capacity); the plain List/Dashboard/Calendar callers of this same
        // endpoint don't, so skip the extra work for them.
        if ($request->query('with_stats') === '1') {
            $result['stats'] = $this->upcomingStats($events);
        }

        return $this->ok($result);
    }

    /**
     * Enrich raw event rows with the per-event fields the card-based
     * "Upcoming" view needs (and that other list consumers simply ignore):
     * support-act names (from the lineup, batched to avoid N+1 queries),
     * ticket sales figures, and a derived on-sale/low/sold-out/free state.
     *
     * @param array<int,array<string,mixed>> $events
     * @return array<int,array<string,mixed>>
     */
    private function attachListExtras(array $events): array
    {
        $ids = array_column($events, 'id');
        $lineupByEvent = [];
        $ticketsByEvent = [];
        if ($ids) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            foreach ($this->db->all(
                "SELECT event_id, display_name FROM event_lineup WHERE event_id IN ($placeholders) AND status != 'canceled' ORDER BY event_id, billing_order, set_time",
                $ids
            ) as $row) {
                $lineupByEvent[(int) $row['event_id']][] = $row['display_name'];
            }
            foreach ($this->db->all(
                "SELECT event_id, price_cents, quantity_sold FROM ticket_types WHERE event_id IN ($placeholders)",
                $ids
            ) as $row) {
                $ticketsByEvent[(int) $row['event_id']][] = $row;
            }
        }

        return array_map(function ($event) use ($lineupByEvent, $ticketsByEvent) {
            $id = (int) $event['id'];
            $event['capabilities'] = $this->eventCapabilities($id);

            // events.capacity is the source of truth when set (e.g. a private
            // buyout smaller than the room); fall back to the booked room's
            // capacity otherwise.
            $capacity = $event['capacity'] !== null && $event['capacity'] !== ''
                ? (int) $event['capacity']
                : (isset($event['resource_capacity']) && $event['resource_capacity'] !== null ? (int) $event['resource_capacity'] : null);
            $event['capacity'] = $capacity;
            unset($event['resource_capacity']);

            $tiers = $ticketsByEvent[$id] ?? [];
            $ticketsSoldRaw = array_sum(array_column($tiers, 'quantity_sold'));
            $revenueCents = array_sum(array_map(fn ($t) => (int) $t['price_cents'] * (int) $t['quantity_sold'], $tiers));
            $priceCents = array_column($tiers, 'price_cents');

            // Tickets-sold is only meaningful when we're the ones selling —
            // external ticketing (Eventbrite, etc.) has no local sales count.
            $event['tickets_sold'] = $event['ticketing_mode'] === 'internal' ? (int) $ticketsSoldRaw : null;
            $event['ticket_revenue'] = $event['ticketing_mode'] === 'internal' ? round($revenueCents / 100, 2) : null;
            $event['price_min'] = $priceCents ? round(min($priceCents) / 100, 2) : null;
            $event['price_max'] = $priceCents ? round(max($priceCents) / 100, 2) : null;

            // Support-act line for the list subtitle ("with X, Y") — the
            // headliner (lowest billing_order) is dropped since the event
            // title already carries that name.
            $names = $lineupByEvent[$id] ?? [];
            $event['support_acts'] = count($names) > 1 ? array_slice($names, 1) : [];

            $event['sales_state'] = $this->salesState($event, count($tiers));
            return $event;
        }, $events);
    }

    /** Statuses public/announced enough to show a ticket sales state badge. */
    private const SALES_STATE_STATUSES = ['ready_to_announce', 'published', 'advanced', 'completed', 'settled'];

    /**
     * Derive the ticket-sales badge for the Upcoming list: 'free', 'on_sale',
     * 'low_tickets', 'sold_out', or null (no badge — event isn't announced
     * yet, or "canceled" is shown via event.status directly instead).
     */
    private function salesState(array $event, int $ticketTierCount): ?string
    {
        if (!in_array($event['status'], self::SALES_STATE_STATUSES, true)) {
            return null;
        }
        $isFree = ($ticketTierCount > 0 && $event['price_max'] !== null && (float) $event['price_max'] === 0.0)
            || ($ticketTierCount === 0 && $event['ticketing_mode'] === 'external' && empty($event['ticket_url']) && (float) ($event['ticket_price'] ?? 0) === 0.0);
        if ($isFree) {
            return 'free';
        }
        $sold = $event['tickets_sold'];
        $capacity = $event['capacity'];
        if ($sold !== null && $capacity) {
            if ($sold >= $capacity) {
                return 'sold_out';
            }
            if ($sold / $capacity >= 0.75) {
                return 'low_tickets';
            }
        }
        return 'on_sale';
    }

    /**
     * Summary stats for the Upcoming view's footer bar, computed over the
     * (already date/status/type-filtered) event set: how many shows, tickets
     * sold, an estimated gross (from ticket sales, not the full settlement
     * ledger — hence "Est."), and average capacity utilization.
     *
     * @param array<int,array<string,mixed>> $events
     */
    private function upcomingStats(array $events): array
    {
        $active = array_values(array_filter($events, fn ($e) => $e['status'] !== 'canceled'));
        $ticketsSold = 0;
        $revenue = 0.0;
        $capacityPcts = [];
        foreach ($active as $event) {
            if ($event['tickets_sold'] !== null) {
                $ticketsSold += $event['tickets_sold'];
                $revenue += (float) ($event['ticket_revenue'] ?? 0);
                if ($event['capacity']) {
                    $capacityPcts[] = min(100, ($event['tickets_sold'] / $event['capacity']) * 100);
                }
            }
        }
        return [
            'upcoming_count' => count($active),
            'tickets_sold' => $ticketsSold,
            'gross_revenue' => round($revenue, 2),
            'avg_capacity_pct' => $capacityPcts ? (int) round(array_sum($capacityPcts) / count($capacityPcts)) : 0,
        ];
    }

    private function show(int $id): Response
    {
        if ($denied = $this->requireEventCapability($id, 'read_event')) {
            return $denied;
        }
        $event = $this->db->one(
            'SELECT e.*, v.name venue_name, v.address venue_address, v.city venue_city, v.state venue_state, u.name owner_name,
                    r.name room_name, r.capacity room_capacity
             FROM events e JOIN venues v ON v.id = e.venue_id LEFT JOIN users u ON u.id = e.owner_user_id
             LEFT JOIN resources r ON r.id = e.resource_id WHERE e.id = ?',
            [$id]
        );
        if (!$event) {
            return $this->notFound('Event not found');
        }
        // Live tickets-sold count for the compact header — only meaningful (and
        // only queried) when we're the ones selling tickets in-house; external
        // ticketing has no local sales data to total up.
        if ($event['ticketing_mode'] === 'internal') {
            $event['tickets_sold'] = (int) ($this->db->one(
                'SELECT COALESCE(SUM(quantity_sold), 0) AS n FROM ticket_types WHERE event_id = ?',
                [$id]
            )['n'] ?? 0);
        }
        $lineup = $this->db->all('SELECT el.*, b.name band_name FROM event_lineup el LEFT JOIN bands b ON b.id = el.band_id WHERE el.event_id = ? ORDER BY billing_order, set_time', [$id]);
        $tasks = $this->db->all('SELECT t.*, u.name assigned_name FROM event_tasks t LEFT JOIN users u ON u.id = t.assigned_user_id WHERE t.event_id = ? ORDER BY FIELD(t.status,"blocked","todo","in_progress","done","canceled"), due_date', [$id]);
        if ($this->hasEventCapability($id, 'view_assigned_tasks') && !$this->hasEventCapability($id, 'manage_tasks')) {
            $tasks = array_values(array_filter($tasks, fn ($task) => (int) ($task['assigned_user_id'] ?? 0) === $this->userId()));
        }
        $blockers = $this->db->all('SELECT b.*, u.name owner_name FROM event_blockers b LEFT JOIN users u ON u.id = b.owner_user_id WHERE b.event_id = ? ORDER BY FIELD(b.status,"open","waiting","resolved","canceled"), due_date', [$id]);
        $assets = $this->db->all('SELECT * FROM event_assets WHERE event_id = ? ORDER BY created_at DESC', [$id]);
        $canViewSettlement = $this->hasEventCapability($id, 'view_settlement');
        $settlement = $canViewSettlement ? $this->db->one('SELECT * FROM event_settlements WHERE event_id = ? LIMIT 1', [$id]) : null;
        $readiness = $this->readiness($event, $lineup, $blockers, $assets, $settlement);
        if (!$canViewSettlement) {
            $readiness = array_values(array_filter($readiness, fn ($item) => $item['label'] !== 'Settlement'));
        }
        $nextAction = $this->nextAction($event, $blockers, $assets, $settlement);
        if (!$canViewSettlement && $nextAction === 'Complete settlement') {
            $nextAction = 'Review event details';
        }
        return $this->ok([
            'event' => $event,
            'lineup' => $lineup,
            'tasks' => $tasks,
            'blockers' => $blockers,
            'schedule' => $this->db->all('SELECT * FROM event_schedule_items WHERE event_id = ? ORDER BY start_time, id', [$id]),
            'sessions' => $this->db->all('SELECT * FROM event_sessions WHERE event_id = ? ORDER BY session_date, start_time, sort_order, id', [$id]),
            'assets' => $assets,
            'collaborators' => $this->db->all(
                'SELECT ec.id, ec.user_id, ec.role event_role, u.name, u.email
                 FROM event_collaborators ec JOIN users u ON u.id = ec.user_id
                 WHERE ec.event_id = ?
                 ORDER BY FIELD(ec.role,"venue_admin","event_owner","promoter","staff","designer","band","artist","viewer"), u.name',
                [$id]
            ),
            'guests' => \Panic\Events\GuestList::attachCompTickets($this->db, $this->db->all(
                'SELECT g.*, u.name created_by_name
                 FROM event_guest_list g LEFT JOIN users u ON u.id = g.created_by_user_id
                 WHERE g.event_id = ?
                 ORDER BY g.list_type, g.name',
                [$id]
            )),
            'staffing' => $this->db->all(
                'SELECT es.*, sm.name staff_name, sm.email staff_email, sm.phone staff_phone, sm.default_role staff_default_role
                 FROM event_staffing es
                 LEFT JOIN staff_members sm ON sm.id = es.staff_member_id
                 WHERE es.event_id = ?
                 ORDER BY es.shift_date, es.call_time, FIELD(es.role,"manager","sound","lighting","security","door","bartender","barback","stagehand","runner","cleaner","other"), es.id',
                [$id]
            ),
            'staffRoster' => $this->hasEventCapability($id, 'manage_staffing')
                ? $this->db->all('SELECT id, name, email, phone, default_role, hourly_rate FROM staff_members WHERE active = 1 ORDER BY name')
                : [],
            'staffRoles' => \Panic\StaffMembers::ROLES,
            'staffingStatuses' => ['scheduled','confirmed','declined','no_show','completed','canceled'],
            'invites' => $this->hasEventCapability($id, 'manage_invites') ? $this->db->all('SELECT id, email, role, token, used_at, expires_at, created_at FROM event_invites WHERE event_id = ? ORDER BY created_at DESC', [$id]) : [],
            'settlement' => $settlement,
            'activity' => $this->db->all('SELECT a.*, u.name user_name FROM event_activity_log a LEFT JOIN users u ON u.id = a.user_id WHERE a.event_id = ? ORDER BY a.created_at DESC LIMIT 80', [$id]),
            'users' => $this->assignmentUsersForEvent($id),
            'venues' => $this->db->all('SELECT * FROM venues ORDER BY name'),
            // Rooms within each venue, for the Details tab's Venue/Room fields
            // (Venue is omitted client-side when there's only one venue).
            'resources' => $this->db->all('SELECT * FROM resources WHERE active = 1 ORDER BY venue_id, sort_order, name'),
            'taskTemplates' => $this->db->all("SELECT id, name FROM event_templates WHERE checklist_json IS NOT NULL AND checklist_json != '[]' ORDER BY name"),
            'nextAction' => $nextAction,
            'readiness' => $readiness,
            'access' => $this->eventAccess($id),
            'capabilities' => $this->eventCapabilities($id),
            'links' => [
                'public_page' => event_public_path($event),
                'invite_base' => 'invite.html?token=',
            ],
        ]);
    }

    private function create(Request $request): Response
    {
        if ($denied = $this->requireGlobalCapability('create_events')) {
            return $denied;
        }
        $body = $request->body();

        // Pre-populate booker fields from the logged-in user unless the caller
        // already supplied them (non-empty values always win).
        $currentUser = $this->auth->user();
        if (empty($body['booker_name']))  $body['booker_name']  = $currentUser['name']  ?? null;
        if (empty($body['booker_email'])) $body['booker_email'] = $currentUser['email'] ?? null;
        if (empty($body['booker_phone'])) {
            $userRow = $this->db->one('SELECT phone FROM users WHERE id = ?', [$this->userId()]);
            $body['booker_phone'] = $userRow['phone'] ?? null;
        }

        foreach (['title', 'date', 'venue_id', 'event_type'] as $required) {
            if (empty($body[$required])) {
                return Response::json(['error' => "$required is required"], 422);
            }
        }
        $slug = $this->uniqueSlug($body['title'] . '-' . $body['date']);
        $newStatus  = $body['status'] ?? 'proposed';
        $isPrivate  = ($body['event_type'] ?? '') === 'private_event';
        $newEndDate = self::nullableDate($body['end_date'] ?? null, $body['date']);
        if ($err = self::endDateBeforeStartError($newEndDate, $body['date'])) {
            return $err;
        }

        // Private events are never publicly visible and auto-assign to Colleen.
        $publicVisibility = $isPrivate ? 0 : (boolish($body['public_visibility'] ?? false) ? 1 : 0);
        $ownerId = ($body['owner_user_id'] ?? null) ?: ($isPrivate ? $this->getPrivateEventHandlerId() : $this->userId());

        [$resourceId, $resourceError] = $this->resolveResourceId($body, (int) $body['venue_id']);
        if ($resourceError) {
            return $resourceError;
        }
        if (in_array($newStatus, self::BOOKING_CONFIRMED_STATUSES, true)) {
            // Non-music events (workshops/comedy/etc.) hide Doors from the form, so
            // fall back to Show/Start as the conflict window's start time — see
            // timesOverlap()'s doc comment for why a null start would otherwise
            // silently widen the window to midnight.
            $conflictStart = date_or_null($body['doors_time'] ?? null) ?: date_or_null($body['show_time'] ?? null);
            if ($conflict = $this->checkRoomConflict((int) $body['venue_id'], $body['date'], $conflictStart, date_or_null($body['end_time'] ?? null), null, $newEndDate, $resourceId)) {
                return $conflict;
            }
        }
        $id = $this->db->insert(
            'INSERT INTO events (venue_id, resource_id, title, slug, event_type, status, description_public, description_internal, av_requirements, catering_notes, date, end_date, doors_time, show_time, end_time, load_in_time, is_non_music, age_restriction, ticket_price, deposit_amount, potential_revenue, ticket_url, ticket_system, contract_url, venue_contract_url, walkthrough_done, settlement_doc_url, capacity, estimated_guests, public_visibility, owner_user_id, promoter_name, promoter_email, promoter_phone, client_org, booker_name, booker_email, booker_phone)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [(int) $body['venue_id'], $resourceId, $body['title'], $slug, $body['event_type'], $newStatus, $isPrivate ? null : self::nullableString($body['description_public'] ?? null), self::nullableString($body['description_internal'] ?? null), self::nullableString($body['av_requirements'] ?? null), self::nullableString($body['catering_notes'] ?? null), $body['date'], $newEndDate, date_or_null($body['doors_time'] ?? null), date_or_null($body['show_time'] ?? null), date_or_null($body['end_time'] ?? null), date_or_null($body['load_in_time'] ?? null), boolish($body['is_non_music'] ?? false) ? 1 : 0, $body['age_restriction'] ?? null, $isPrivate ? 0 : (float) ($body['ticket_price'] ?? 0), self::nullableDecimal($body['deposit_amount'] ?? null), self::nullableDecimal($body['potential_revenue'] ?? null), $isPrivate ? null : self::nullableString($body['ticket_url'] ?? null), $isPrivate ? null : self::nullableString($body['ticket_system'] ?? null), self::nullableString($body['contract_url'] ?? null), self::nullableString($body['venue_contract_url'] ?? null), boolish($body['walkthrough_done'] ?? false) ? 1 : 0, self::nullableString($body['settlement_doc_url'] ?? null), ($body['capacity'] ?? null) ?: null, ($body['estimated_guests'] ?? null) ?: null, $publicVisibility, $ownerId, self::nullableString($body['promoter_name'] ?? null), self::nullableString($body['promoter_email'] ?? null), self::nullableString($body['promoter_phone'] ?? null), self::nullableString($body['client_org'] ?? null), $isPrivate ? null : self::nullableString($body['booker_name'] ?? null), $isPrivate ? null : self::nullableString($body['booker_email'] ?? null), $isPrivate ? null : self::nullableString($body['booker_phone'] ?? null)]
        );
        $this->assignEventCode($id);
        log_activity($this->db, $id, $this->userId(), 'event created', ['title' => $body['title']]);

        // Notify all admins immediately when a private event inquiry comes in.
        if ($isPrivate) {
            $this->notifyPrivateEventCreated($id);
        }

        // Push the freshly-created event to the sheet so it appears in the Tracker
        // immediately. pushToSheet() no-ops for a nameless event (draft).
        $this->pushToSheet($id);
        return $this->ok(['id' => $id]);
    }

    /** Statuses that lock the core event record once reached — see guardArchivedEdit(). */
    private const LOCKED_EDIT_STATUSES = ['completed', 'settled'];

    /**
     * Once an event is auto-archived (status=completed, "Settlement Needed")
     * or fully Settled, block further edits/deletes from everyone except a
     * venue admin / event owner. Per issue #19 the nightly auto-complete flip
     * should make the record read-only for ordinary collaborators; per #11
     * (same reporter, filed an hour earlier) admins still need an escape
     * hatch to fix mistakes on an archived event, so the gate is capability-
     * based (edit_settlement) rather than an absolute lock.
     */
    private function guardArchivedEdit(int $id): ?Response
    {
        $row = $this->db->one('SELECT status FROM events WHERE id = ?', [$id]);
        if (!$row || !in_array($row['status'], self::LOCKED_EDIT_STATUSES, true)) {
            return null;
        }
        if ($this->hasEventCapability($id, 'edit_settlement')) {
            return null;
        }
        return Response::json([
            'error' => 'This event is archived and settlement is in progress — only a venue admin can make changes now.',
        ], 403);
    }

    private function update(Request $request, int $id): Response
    {
        if (!$id) {
            return $this->notFound();
        }
        if ($denied = $this->requireEventCapability($id, 'edit_event')) {
            return $denied;
        }
        if ($lockError = $this->guardArchivedEdit($id)) {
            return $lockError;
        }
        $body = $request->body();
        if (isset($body['status']) && !in_array($body['status'], self::STATUSES, true)) {
            return Response::json(['error' => 'Invalid status value: ' . $body['status'] . '. Allowed: ' . implode(', ', self::STATUSES)], 422);
        }
        if (isset($body['status']) && count($body) === 1) {
            $existing = $this->db->one('SELECT * FROM events WHERE id = ?', [$id]);
            if (!$existing) return $this->notFound();
            $newStatus = $body['status'];
            if ($transitionError = $this->validateStatusTransition($newStatus, $existing)) {
                return $transitionError;
            }
            if (in_array($newStatus, self::BOOKING_CONFIRMED_STATUSES, true)) {
                $conflictStart = $existing['doors_time'] ?: $existing['show_time'];
                if ($conflict = $this->checkRoomConflict((int) $existing['venue_id'], $existing['date'], $conflictStart, $existing['end_time'], $id, $existing['end_date'] ?? null, self::nullableInt($existing['resource_id'] ?? null))) {
                    return $conflict;
                }
            }
            $this->db->run('UPDATE events SET status = ? WHERE id = ?', [$newStatus, $id]);
            log_activity($this->db, $id, $this->userId(), 'status changed', [
                'changes' => [['field' => 'Status', 'from' => (string) $existing['status'], 'to' => $newStatus]],
            ]);
            $this->notifyStatusChange($id, (string) $existing['status'], $newStatus);
            if ($newStatus === 'published') {
                $this->maybeAutoPublish($id);
            }
            $this->pushToSheet($id);
            return $this->ok(['ok' => true]);
        }
        // Allowlist of single-field partial updates so the UI can PATCH a single
        // detail (settlement doc link, walkthrough flag, etc.) without re-sending
        // every required field. Anything outside this list falls through to the
        // full-row UPDATE below.
        $partialAllowlist = [
            'settlement_doc_url'  => fn ($v) => self::nullableString($v),
            'contract_url'        => fn ($v) => self::nullableString($v),
            'venue_contract_url'  => fn ($v) => self::nullableString($v),
            'ticket_url'          => fn ($v) => self::nullableString($v),
            'ticket_system'       => fn ($v) => self::nullableString($v),
            'walkthrough_done'    => fn ($v) => boolish($v) ? 1 : 0,
            'deposit_amount'      => fn ($v) => self::nullableDecimal($v),
            'potential_revenue'   => fn ($v) => self::nullableDecimal($v),
            'load_in_time'        => fn ($v) => date_or_null($v),
            'end_date'            => fn ($v) => self::nullableString($v),
            'estimated_guests'    => fn ($v) => $v !== null && $v !== '' ? (int) $v : null,
            'av_requirements'     => fn ($v) => self::nullableString($v),
            'catering_notes'      => fn ($v) => self::nullableString($v),
            'client_org'          => fn ($v) => self::nullableString($v),
        ];
        if (count($body) === 1) {
            $key = array_key_first($body);
            if (isset($partialAllowlist[$key])) {
                $oldRow  = $key === 'end_date'
                    ? $this->db->one('SELECT `end_date`, `date` FROM events WHERE id = ?', [$id])
                    : $this->db->one("SELECT `{$key}` FROM events WHERE id = ?", [$id]);
                $coerced = $partialAllowlist[$key]($body[$key]);
                if ($key === 'end_date' && ($err = self::endDateBeforeStartError($coerced, (string) $oldRow['date']))) {
                    return $err;
                }
                $this->db->run("UPDATE events SET {$key} = ? WHERE id = ?", [$coerced, $id]);
                $labels  = self::EVENT_FIELD_LABELS;
                $label   = $labels[$key] ?? $key;
                $oldStr  = (string) ($oldRow[$key] ?? '');
                $newStr  = (string) ($coerced ?? '');
                log_activity($this->db, $id, $this->userId(), 'event updated', [
                    'changes' => [['field' => $label, 'from' => $oldStr, 'to' => $newStr]],
                ]);
                $this->pushToSheet($id);
                return $this->ok(['ok' => true]);
            }
        }
        $old = $this->db->one('SELECT * FROM events WHERE id = ?', [$id]);
        if (!$old) {
            return $this->notFound();
        }
        // These seven columns used to be read straight off $body with no
        // `?? $old[...]` fallback anywhere below, unlike every other field on
        // this row. That's fine as long as every caller re-sends the whole
        // form (the main app's edit form always does) — but it means any
        // caller that PATCHes a single other field (e.g. just booker_email)
        // silently blanks title/event_type/status/date and resets
        // walkthrough_done/public_visibility/owner_user_id to falsy/null.
        // Resolve them once up front, the same way every other column
        // already does, so omitting them from the body means "leave alone"
        // rather than "clear".
        $updateTitle       = $body['title'] ?? $old['title'];
        $updateEventType   = $body['event_type'] ?? $old['event_type'];
        $updateStatus      = $body['status'] ?? $old['status'];
        $updateDate        = $body['date'] ?? $old['date'];
        $updateWalkthrough = boolish($body['walkthrough_done'] ?? $old['walkthrough_done']) ? 1 : 0;
        $updateIsNonMusic  = boolish($body['is_non_music'] ?? $old['is_non_music'] ?? false) ? 1 : 0;
        // owner_user_id follows the same array_key_exists convention as
        // resource_id below: omit the key to leave it alone, send '' or null
        // to explicitly clear it.
        $updateOwnerUserId = array_key_exists('owner_user_id', $body)
            ? self::nullableInt($body['owner_user_id'])
            : self::nullableInt($old['owner_user_id'] ?? null);
        // Validate status transition when the status is being changed
        if (isset($body['status']) && $body['status'] !== ($old['status'] ?? '')) {
            $merged = array_merge($old, array_filter((array) $body, fn ($v) => $v !== null && $v !== ''));
            if ($transitionError = $this->validateStatusTransition($body['status'], $merged)) {
                return $transitionError;
            }
        }
        // Resolve venue/room together: room_id must belong to venue_id (guards
        // against a stale/mismatched Room dropdown if the venue was also just
        // changed). Missing `resource_id` in the body keeps the existing room;
        // an explicit '' clears it.
        $updateVenueId    = (int) ($body['venue_id'] ?? $old['venue_id']);
        $updateResourceId = array_key_exists('resource_id', $body)
            ? self::nullableInt($body['resource_id'])
            : self::nullableInt($old['resource_id'] ?? null);
        if ($updateResourceId !== null) {
            $resourceRow = $this->db->one('SELECT id FROM resources WHERE id = ? AND venue_id = ?', [$updateResourceId, $updateVenueId]);
            if (!$resourceRow) {
                return Response::json(['error' => 'Selected room does not belong to the chosen venue.'], 422);
            }
        }
        // Room conflict check for committed bookings
        if (in_array($updateStatus, self::BOOKING_CONFIRMED_STATUSES, true)) {
            $checkEndDate  = self::nullableDate($body['end_date'] ?? $old['end_date'] ?? null, $updateDate);
            // Non-music events hide Doors from the form and leave it null forever —
            // fall back to Show/Start as the conflict window's start time (see
            // timesOverlap()'s doc comment for why a null start would otherwise
            // silently widen the window to midnight).
            $checkDoors    = date_or_null($body['doors_time'] ?? $old['doors_time'] ?? null) ?? date_or_null($body['show_time'] ?? $old['show_time'] ?? null);
            $checkEnd      = date_or_null($body['end_time']   ?? $old['end_time']   ?? null);
            if ($conflict = $this->checkRoomConflict($updateVenueId, $updateDate, $checkDoors, $checkEnd, $id, $checkEndDate, $updateResourceId)) {
                return $conflict;
            }
        }
        $slug = ($old['title'] !== $updateTitle || $old['date'] !== $updateDate)
            ? $this->uniqueSlug($updateTitle . '-' . $updateDate, $id)
            : $old['slug'];
        $wasStatus = (string) $old['status'];
        $isPrivate = $updateEventType === 'private_event';
        // Private events are never publicly visible.
        $updatePublicVis = $isPrivate ? 0 : (boolish($body['public_visibility'] ?? $old['public_visibility']) ? 1 : 0);
        $updateEndDate = self::nullableDate($body['end_date'] ?? $old['end_date'] ?? null, $updateDate);
        if ($err = self::endDateBeforeStartError($updateEndDate, $updateDate)) {
            return $err;
        }

        $this->db->run(
            'UPDATE events SET venue_id=?, resource_id=?, title=?, slug=?, event_type=?, status=?, description_public=?, description_internal=?, av_requirements=?, catering_notes=?, date=?, end_date=?, doors_time=?, show_time=?, end_time=?, load_in_time=?, is_non_music=?, age_restriction=?, ticket_price=?, deposit_amount=?, potential_revenue=?, ticket_url=?, ticket_system=?, contract_url=?, venue_contract_url=?, walkthrough_done=?, settlement_doc_url=?, capacity=?, estimated_guests=?, public_visibility=?, owner_user_id=?, promoter_name=?, promoter_email=?, promoter_phone=?, client_org=?, booker_name=?, booker_email=?, booker_phone=? WHERE id=?',
            [$updateVenueId, $updateResourceId, $updateTitle, $slug, $updateEventType, $updateStatus, $isPrivate ? null : ($body['description_public'] ?? $old['description_public']), $body['description_internal'] ?? $old['description_internal'], self::nullableString($body['av_requirements'] ?? $old['av_requirements']), self::nullableString($body['catering_notes'] ?? $old['catering_notes']), $updateDate, $updateEndDate, date_or_null($body['doors_time'] ?? $old['doors_time'] ?? null), date_or_null($body['show_time'] ?? $old['show_time'] ?? null), date_or_null($body['end_time'] ?? $old['end_time'] ?? null), date_or_null($body['load_in_time'] ?? $old['load_in_time'] ?? null), $updateIsNonMusic, $body['age_restriction'] ?? $old['age_restriction'], $isPrivate ? 0 : (float) ($body['ticket_price'] ?? $old['ticket_price'] ?? 0), self::nullableDecimal($body['deposit_amount'] ?? $old['deposit_amount']), self::nullableDecimal($body['potential_revenue'] ?? $old['potential_revenue']), $isPrivate ? null : self::nullableString($body['ticket_url'] ?? $old['ticket_url']), $isPrivate ? null : self::nullableString($body['ticket_system'] ?? $old['ticket_system']), self::nullableString($body['contract_url'] ?? $old['contract_url']), self::nullableString($body['venue_contract_url'] ?? $old['venue_contract_url']), $updateWalkthrough, self::nullableString($body['settlement_doc_url'] ?? $old['settlement_doc_url']), ($body['capacity'] ?? $old['capacity']) ?: null, isset($body['estimated_guests']) && $body['estimated_guests'] !== '' ? (int) $body['estimated_guests'] : ($old['estimated_guests'] ?? null), $updatePublicVis, $updateOwnerUserId, self::nullableString($body['promoter_name'] ?? $old['promoter_name']), self::nullableString($body['promoter_email'] ?? $old['promoter_email']), self::nullableString($body['promoter_phone'] ?? $old['promoter_phone']), self::nullableString($body['client_org'] ?? $old['client_org']), $isPrivate ? null : self::nullableString($body['booker_name'] ?? $old['booker_name']), $isPrivate ? null : self::nullableString($body['booker_email'] ?? $old['booker_email']), $isPrivate ? null : self::nullableString($body['booker_phone'] ?? $old['booker_phone']), $id]
        );
        if ($updateStatus !== $wasStatus) {
            $this->notifyStatusChange($id, $wasStatus, $updateStatus);
            if ($updateStatus === 'published') {
                $this->maybeAutoPublish($id);
            }
        }
        log_activity($this->db, $id, $this->userId(), 'event updated', $this->diffEvent($old, $body));
        $this->pushToSheet($id);
        return $this->ok(['id' => $id]);
    }

    private function delete(int $id): Response
    {
        if ($denied = $this->requireEventCapability($id, 'delete_event')) {
            return $denied;
        }
        if ($lockError = $this->guardArchivedEdit($id)) {
            return $lockError;
        }
        $this->db->run('DELETE FROM events WHERE id = ?', [$id]);
        return Response::noContent();
    }

    private function fromTemplate(Request $request, int $templateId): Response
    {
        if ($request->method() !== 'POST') {
            return Response::methodNotAllowed();
        }
        if ($denied = $this->requireGlobalCapability('create_events')) {
            return $denied;
        }
        $template = $this->db->one('SELECT * FROM event_templates WHERE id = ?', [$templateId]);
        if (!$template) {
            return $this->notFound('Template not found');
        }
        $body = $request->body();
        $date = $body['date'] ?? null;
        if (!$date) {
            return Response::json(['error' => 'date is required'], 422);
        }
        $title = ($body['title'] ?? '') !== '' ? $body['title'] : ($template['default_title'] ?: $template['name']);
        $templateEndDate = self::nullableDate($body['end_date'] ?? null, $date);
        if ($err = self::endDateBeforeStartError($templateEndDate, $date)) {
            return $err;
        }
        [$resourceId, $resourceError] = $this->resolveResourceId($body, (int) $template['venue_id']);
        if ($resourceError) {
            return $resourceError;
        }
        $id = $this->db->insert(
            "INSERT INTO events (venue_id, resource_id, title, slug, event_type, status, description_public, date, end_date, doors_time, show_time, age_restriction, ticket_price, owner_user_id)
             VALUES (?, ?, ?, ?, ?, 'proposed', ?, ?, ?, ?, ?, ?, ?, ?)",
            [(int) $template['venue_id'], $resourceId, $title, $this->uniqueSlug($title . '-' . $date), $template['event_type'], $template['default_description_public'], $date, $templateEndDate, ($body['doors_time'] ?? '') ?: '19:00', ($body['show_time'] ?? '') ?: '20:00', $template['default_age_restriction'], (float) $template['default_ticket_price'], $this->userId()]
        );
        $this->assignEventCode($id);
        foreach ($this->jsonList($template['checklist_json']) as $task) {
            $this->db->run('INSERT INTO event_tasks (event_id, title, priority) VALUES (?, ?, ?)', [$id, $task['title'] ?? $task, $task['priority'] ?? 'normal']);
        }
        foreach ($this->jsonList($template['schedule_json']) as $item) {
            $this->db->run('INSERT INTO event_schedule_items (event_id, title, item_type, start_time, end_time) VALUES (?, ?, ?, ?, ?)', [$id, $item['title'], $item['item_type'] ?? 'other', $item['start_time'] ?? null, $item['end_time'] ?? null]);
        }
        $staffingEntries = $this->jsonList($template['staffing_json'] ?? '');
        if ($staffingEntries) {
            (new Events\Staffing($this->db, $this->auth, []))->createFromTemplate($id, $staffingEntries);
        }
        log_activity($this->db, $id, $this->userId(), 'event created from template', ['template_id' => $templateId]);
        $this->pushToSheet($id);
        return $this->ok(['id' => $id]);
    }

    private function nextAction(array $event, array $blockers, array $assets, ?array $settlement): string
    {
        foreach ($blockers as $blocker) {
            if (in_array($blocker['status'], ['open', 'waiting'], true)) return 'Complete open items';
        }
        // Must match the actual "Booked" gate in validateStatusTransition()
        // (status IN ('signed','fully_executed') there) — a contract that's
        // merely 'approved' or 'sent' has NOT been signed yet and does not
        // satisfy that gate, so it must not be reported here as "on file"
        // either. Using a looser list caused this banner to tell users a
        // contract was on file (ready to advance) when advancing would
        // actually fail with "contract must be signed, not just sent or
        // approved."
        $hasContract = self::hasContractUrl($event) || $this->db->one(
            "SELECT id FROM contracts WHERE event_id = ? AND status IN ('signed','fully_executed') LIMIT 1",
            [(int) $event['id']]
        );
        $isPrivate = ($event['event_type'] ?? '') === 'private_event';

        if ($isPrivate) {
            return match (true) {
                $event['status'] === 'proposed' => 'Collect client contact info, dates, and guest count — then advance to Intake Complete',
                $event['status'] === 'confirmed' && !$hasContract => 'Send rental contract to client (Contracts tab) to advance to Booked',
                $event['status'] === 'confirmed' => 'Rental contract on file — advance status to Booked',
                $event['status'] === 'booked'    => 'Confirm deposit received and coordinate event logistics with client',
                $event['status'] === 'completed' && !$settlement => 'Complete settlement',
                default => 'Review event details',
            };
        }

        $hasFlyer = array_filter($assets, fn ($a) => $a['asset_type'] === 'flyer' && $a['approval_status'] === 'approved');
        return match (true) {
            $event['status'] === 'proposed' => 'Fill in all contact details and event times, then advance to Intake Complete',
            $event['status'] === 'confirmed' && !$hasContract => 'Complete all intake fields then obtain a signed contract (Contracts tab) to advance to Booked',
            $event['status'] === 'confirmed' => 'Contract on file — advance status to Booked',
            $event['status'] === 'booked' && !$hasFlyer => 'Upload or approve flyer, then advance to Needs Assets',
            $event['status'] === 'needs_assets' => 'Collect all promo materials (flyer, photos, bio)',
            $event['status'] === 'ready_to_announce' && !(int) $event['public_visibility'] => 'Publish public event page',
            // Only nag for an external ticket link when we're *not* handling
            // ticketing ourselves — an internal (in-house) event has no
            // ticket_url by design, since sales happen through our own
            // ticket types instead of an outbound link.
            $event['status'] === 'published' && $event['ticketing_mode'] !== 'internal' && !$event['ticket_url'] && (float) $event['ticket_price'] > 0 => 'Add ticketing link',
            $event['status'] === 'completed' && !$settlement => 'Complete settlement',
            default => 'Review event details',
        };
    }

    private function readiness(array $event, array $lineup, array $blockers, array $assets, ?array $settlement): array
    {
        $openBlockers = array_filter($blockers, fn ($b) => in_array($b['status'], ['open', 'waiting'], true));
        $isPrivate    = ($event['event_type'] ?? '') === 'private_event';
        // Non-music events hide Doors from the form, so their run sheet is
        // "timed" once Show/Start (relabeled "Start" in the UI) is set instead.
        $isNonMusic   = boolish($event['is_non_music'] ?? false);
        $runSheetTime = $isNonMusic ? $event['show_time'] : $event['doors_time'];
        $runSheetGap  = $isNonMusic ? 'Needs start time' : 'Needs doors';

        if ($isPrivate) {
            $hasClient = !empty($event['promoter_name']) && !empty($event['promoter_email']);
            // Same "signed/fully_executed only" list as nextAction() and the
            // Booked-transition gate — see the comment there for why
            // 'approved'/'sent' must not count as "on file".
            $hasContract = self::hasContractUrl($event) || $this->db->one(
                "SELECT id FROM contracts WHERE event_id = ? AND status IN ('signed','fully_executed') LIMIT 1",
                [(int) $event['id']]
            );
            return [
                ['label' => 'Client',       'state' => $hasClient ? 'On file' : 'Missing client contact', 'ok' => $hasClient],
                ['label' => 'Guest count',  'state' => !empty($event['estimated_guests']) ? $event['estimated_guests'] . ' estimated' : 'Not set', 'ok' => !empty($event['estimated_guests'])],
                ['label' => 'Run sheet',    'state' => $runSheetTime ? 'Timed' : $runSheetGap, 'ok' => (bool) $runSheetTime],
                ['label' => 'Open items',   'state' => $openBlockers ? count($openBlockers) . ' open' : 'Clear', 'ok' => !$openBlockers],
                ['label' => 'Contract',     'state' => $hasContract ? 'On file' : 'Not yet sent', 'ok' => (bool) $hasContract],
                ['label' => 'Settlement',   'state' => $settlement ? 'Saved' : 'Not started', 'ok' => $event['status'] !== 'completed' || (bool) $settlement],
            ];
        }

        $hasApprovedFlyer = array_filter($assets, fn ($a) => $a['asset_type'] === 'flyer' && $a['approval_status'] === 'approved');
        $hasContacts = !empty($event['promoter_name']) && !empty($event['booker_name']);
        return [
            ['label' => 'Contacts',    'state' => $hasContacts ? 'On file' : 'Missing producer/booker', 'ok' => $hasContacts],
            ['label' => 'Lineup',      'state' => $lineup ? 'Ready' : 'Missing', 'ok' => (bool) $lineup],
            ['label' => 'Run sheet',   'state' => $runSheetTime ? 'Timed' : $runSheetGap, 'ok' => (bool) $runSheetTime],
            ['label' => 'Open items',  'state' => $openBlockers ? count($openBlockers) . ' open' : 'Clear', 'ok' => !$openBlockers],
            ['label' => 'Flyer',       'state' => $hasApprovedFlyer ? 'Approved' : 'Needs approval', 'ok' => (bool) $hasApprovedFlyer],
            ['label' => 'Public page', 'state' => (int) $event['public_visibility'] ? 'Live' : 'Hidden', 'ok' => (bool) (int) $event['public_visibility']],
            ['label' => 'Settlement',  'state' => $settlement ? 'Saved' : 'Not started', 'ok' => $event['status'] !== 'completed' || (bool) $settlement],
        ];
    }

    private function jsonList(?string $json): array
    {
        $data = json_decode($json ?: '[]', true);
        return is_array($data) ? $data : [];
    }

    /**
     * True only when the legacy `contract_url` field actually looks like a
     * link (http/https), not some unrelated note typed into that field.
     *
     * In production this field turned out to hold values like "TIXR",
     * "Door", "Venmo & Door", "Eventbrite", "Internal" — apparently a
     * leftover ticketing/payment-method note from before the Contracts
     * table existed — for every single non-empty row currently in the
     * database (zero of them are actual URLs). Treating any non-empty value
     * as "a contract is on file" made the "Contract on file" next-action
     * banner (and the Booked-status contract gate) fire for events that
     * have neither a contract row nor an uploaded/signed document — just
     * stray text in this field.
     */
    private static function hasContractUrl(array $event): bool
    {
        $value = $event['contract_url'] ?? null;
        return is_string($value) && preg_match('#^https?://#i', trim($value)) === 1;
    }

    /**
     * Coerce an optional money input ('', null, '0.00', '500') to either a
     * float for storage or null when blank. We distinguish '' (cleared) from
     * '0' (zero deposit on record) — both round-trip as NULL/0.00.
     */
    private static function nullableDecimal($value): ?float
    {
        if ($value === null) return null;
        if (is_string($value) && trim($value) === '') return null;
        return (float) $value;
    }

    /** Trim and return null for empty/whitespace strings; otherwise the trimmed string. */
    private static function nullableString($value): ?string
    {
        if ($value === null) return null;
        $s = trim((string) $value);
        return $s === '' ? null : $s;
    }

    /** Coerce an optional id input ('', null, '3') to an int or null. */
    private static function nullableInt($value): ?int
    {
        if ($value === null) return null;
        if (is_string($value) && trim($value) === '') return null;
        return (int) $value;
    }

    /**
     * Resolve the optional `resource_id` (a room within the given venue) from
     * the request body, validating it actually belongs to that venue so a
     * stale/mismatched dropdown selection can't attach the wrong room.
     */
    private function resolveResourceId(array $body, int $venueId): array
    {
        $resourceId = self::nullableInt($body['resource_id'] ?? null);
        if ($resourceId === null) {
            return [null, null];
        }
        $resource = $this->db->one('SELECT id FROM resources WHERE id = ? AND venue_id = ?', [$resourceId, $venueId]);
        if (!$resource) {
            return [null, Response::json(['error' => 'Selected room does not belong to the chosen venue.'], 422)];
        }
        return [$resourceId, null];
    }

    /**
     * Coerce an optional end-date value: returns null when blank or equal to
     * $startDate (single-day event), otherwise the trimmed date string.
     * Prevents storing a redundant end_date that equals the start date.
     */
    /**
     * Guard against an end_date that precedes the event's start date — the
     * `min` attribute on the date-picker inputs is a soft UI hint only; a
     * stray keystroke/arrow-key/scroll on the native <input type=date>, or a
     * direct API call, can still produce an out-of-order value with nothing
     * client-side stopping it. Without this, the bad end_date silently saves
     * and then makes the event vanish from Calendar/Upcoming (both filter on
     * `COALESCE(end_date, date) >= window_start`), even though List/Dashboard
     * (unfiltered) still show it — see issue where "multi-day event stopped
     * appearing on the calendar".
     */
    private static function endDateBeforeStartError(?string $endDate, string $startDate): ?Response
    {
        if ($endDate !== null && $endDate < $startDate) {
            return Response::json(['error' => 'End date cannot be before the start date.'], 422);
        }
        return null;
    }

    private static function nullableDate($value, string $startDate): ?string
    {
        if ($value === null) return null;
        $s = trim((string) $value);
        if ($s === '' || $s === $startDate) return null;
        return $s;
    }

    /**
     * Validate that all required intake fields are present before the event
     * can be advanced to a given status.
     *
     * Private events follow a compressed workflow and have different required
     * fields at each stage (no booker, no ticket price, no public assets).
     *
     * Public events:
     *   Hold (proposed):        12 fields — title, date, type, venue, times, producer/artist + booker contacts
     *   Intake Complete (confirmed): + age, ticket price, capacity, deposit
     *   Booked:                 + contract on file
     *
     * Private events:
     *   Hold (proposed):        title, date, type, venue, times, client name/email/phone
     *   Intake Complete (confirmed): + estimated_guests, age restriction, deposit
     *   Booked:                 + contract on file
     *   NOT allowed:            needs_assets, ready_to_announce, published, advanced
     *
     * Returns a 422 Response if anything is missing or disallowed, null if OK.
     */
    private function validateStatusTransition(string $newStatus, array $event): ?Response
    {
        $isPrivate = ($event['event_type'] ?? '') === 'private_event';
        // Non-music events (workshops/comedy/etc.) hide Doors from the form —
        // Show/Start (relabeled "Start" in the UI) is the only start-time field
        // they can fill in, so require that one instead.
        $isNonMusic = boolish($event['is_non_music'] ?? false);
        $startField = $isNonMusic ? 'show_time' : 'doors_time';
        $startLabel = $isNonMusic ? 'Start time' : 'Start time (Doors)';

        // Statuses that private events may never use
        $privateDisallowed = ['needs_assets', 'assets_approved', 'ready_to_announce', 'published', 'advanced'];
        if ($isPrivate && in_array($newStatus, $privateDisallowed, true)) {
            return Response::json([
                'error' => 'Private events do not use the "' . ucwords(str_replace('_', ' ', $newStatus)) . '" status. Use: Hold → Intake Complete → Booked → Archived → Settled.',
            ], 422);
        }

        // ── Minimum required fields at Hold and above ────────────────────────
        if ($isPrivate) {
            $holdRequired = [
                'title'          => 'Event name',
                'date'           => 'Date',
                'event_type'     => 'Event type',
                'venue_id'       => 'Venue / location',
                $startField      => $startLabel,
                'end_time'       => 'End time',
                'promoter_name'  => 'Client name',
                'promoter_email' => 'Client email',
                'promoter_phone' => 'Client phone',
            ];
        } else {
            $holdRequired = [
                'title'          => 'Event name',
                'date'           => 'Date',
                'event_type'     => 'Event type',
                'venue_id'       => 'Venue / location',
                $startField      => $startLabel,
                'end_time'       => 'End time',
                'promoter_name'  => 'Producer/Artist name',
                'promoter_email' => 'Producer/Artist email',
                'promoter_phone' => 'Producer/Artist phone',
                'booker_name'    => 'Booker name',
                'booker_email'   => 'Booker email',
                'booker_phone'   => 'Booker phone',
            ];
        }

        // ── Additional fields required at Intake Complete and beyond ─────────
        if ($isPrivate) {
            $intakeRequired = [
                'age_restriction'     => 'Age restriction',
                'estimated_guests'    => 'Estimated guest count',
                'deposit_amount'      => 'Deposit amount (use 0 if none)',
                'description_internal' => 'Internal notes',
            ];
        } else {
            $intakeRequired = [
                'age_restriction'     => 'Age restriction',
                'ticket_price'        => 'Ticket price (use 0 for free events)',
                'capacity'            => 'Capacity',
                'deposit_amount'      => 'Deposit amount (use 0 if none)',
                'description_internal' => 'Internal notes',
            ];
        }

        $missing = [];

        // Hold and beyond: check minimum required fields
        if (in_array($newStatus, array_merge(['proposed'], self::BOOKING_CONFIRMED_STATUSES), true)) {
            foreach ($holdRequired as $field => $label) {
                if (empty($event[$field])) {
                    $missing[] = $label;
                }
            }
        }

        // Intake Complete and beyond: check additional fields
        if (in_array($newStatus, self::BOOKING_CONFIRMED_STATUSES, true)) {
            foreach ($intakeRequired as $field => $label) {
                if (!isset($event[$field]) || $event[$field] === '' || $event[$field] === null) {
                    $missing[] = $label;
                }
            }
        }

        // Booked: requires BOTH an executed contract AND a received/waived deposit.
        // "Sent", "approved", or "draft" contract does NOT satisfy the contract requirement.
        if ($newStatus === 'booked') {
            // ── Contract gate ───────────────────────────────────────────────
            $hasContractUrl    = self::hasContractUrl($event);
            $hasExecutedContract = false;
            if (!empty($event['id'])) {
                // Also matches a contract that was signed outside the system and
                // attached as an event asset via the Contracts tab's "Contract
                // signed and attached" picker — that flow deliberately writes a
                // normal contracts row (provider='manual_upload', status='signed',
                // asset_id set) rather than a separate flag, so it satisfies this
                // same query. See ContractService::attachUploaded().
                $contractRow = $this->db->one(
                    "SELECT id, status FROM contracts WHERE event_id = ? AND status IN ('signed','fully_executed') LIMIT 1",
                    [(int) $event['id']]
                );
                $hasExecutedContract = (bool) $contractRow;
            }
            // Also accept the legacy contract_url as a stand-in for small installs
            if (!$hasContractUrl && !$hasExecutedContract) {
                $missing[] = $isPrivate
                    ? 'Signed rental contract (use the Contracts tab or paste a Contract URL; sent/approved contracts are not enough)'
                    : 'Fully executed contract (use the Contracts tab; contract must be signed, not just sent or approved)';
            }

            // ── Deposit gate ────────────────────────────────────────────────
            $depositStatus = (string) ($event['deposit_status'] ?? 'not_required');
            if (!in_array($depositStatus, ['received', 'waived', 'not_required'], true)) {
                // Check whether there's even a deposit required
                $depositRequired = ($event['deposit_amount'] ?? 0) > 0;
                if ($depositRequired) {
                    $missing[] = match ($depositStatus) {
                        'requested'           => 'Deposit requested but not yet received (use Payments tab to record receipt, or waive it)',
                        'partially_received'  => 'Deposit only partially received (record full payment or waive the remainder)',
                        'refunded'            => 'Deposit was refunded — a new deposit is required before booking',
                        default               => 'Deposit required before booking (use Payments tab)',
                    };
                }
            }
        }

        // Assets Approved: promo materials (poster/flyer, public description,
        // ticket link) must actually be gathered and approved before the team
        // gets fanned out to add the event to the website/linktree/newsletter.
        if ($newStatus === 'assets_approved' && !$isPrivate) {
            if (empty($event['description_public'])) {
                $missing[] = 'Public description';
            }
            if (empty($event['ticket_url'])) {
                $missing[] = 'Ticket link';
            }
            $hasApprovedPoster = false;
            if (!empty($event['id'])) {
                $posterRow = $this->db->one(
                    "SELECT id FROM event_assets WHERE event_id = ? AND asset_type IN ('poster','flyer') AND approval_status = 'approved' LIMIT 1",
                    [(int) $event['id']]
                );
                $hasApprovedPoster = (bool) $posterRow;
            }
            if (!$hasApprovedPoster) {
                $missing[] = 'Approved poster/flyer (upload and approve it in the Assets tab)';
            }
        }

        if ($missing) {
            $label = match ($newStatus) {
                'proposed'  => 'Hold',
                'confirmed' => 'Intake Complete',
                default     => ucwords(str_replace(['_', '-'], ' ', $newStatus)),
            };
            return Response::json([
                'error' => 'Cannot advance to "' . $label . '": missing ' . implode(', ', $missing) . '.',
            ], 422);
        }
        return null;
    }

    /** Return the user ID configured as the private event handler (Colleen). */
    private function getPrivateEventHandlerId(): int
    {
        $envId = (int) (getenv('PRIVATE_EVENT_HANDLER_USER_ID') ?: 0);
        if ($envId > 0) return $envId;
        // Fallback: first venue_admin with a real email
        $row = $this->db->one("SELECT id FROM users WHERE role = 'venue_admin' AND email NOT LIKE '%.local' AND is_hidden = 0 ORDER BY id LIMIT 1");
        return $row ? (int) $row['id'] : $this->userId();
    }

    /**
     * Compare an old events DB row to the incoming request body and return a
     * `['changes' => [...]]` array suitable for log_activity().
     * Only fields present in EVENT_FIELD_LABELS are considered.
     * Status changes are skipped here — they are logged separately in the
     * status-only path.
     */
    private function diffEvent(array $old, array $body): array
    {
        $timeFields = ['doors_time', 'show_time', 'end_time', 'load_in_time'];
        $boolFields = ['walkthrough_done', 'public_visibility'];
        $changes    = [];

        foreach (self::EVENT_FIELD_LABELS as $key => $label) {
            if ($key === 'status') continue; // logged separately
            if (!array_key_exists($key, $body)) continue;

            $oldVal = (string) ($old[$key] ?? '');
            $newVal = (string) ($body[$key] ?? '');

            // DB stores HH:MM:SS; form sends HH:MM — normalise to 5 chars
            if (in_array($key, $timeFields, true)) {
                $oldVal = substr($oldVal, 0, 5);
                $newVal = substr($newVal, 0, 5);
            }

            // Normalise booleans to '0'/'1'
            if (in_array($key, $boolFields, true)) {
                $oldVal = $oldVal === '1' || $oldVal === 'true'  ? '1' : '0';
                $newVal = boolish($body[$key]) ? '1' : '0';
            }

            if ($oldVal === $newVal) continue;
            $changes[] = ['field' => $label, 'from' => $oldVal, 'to' => $newVal];
        }

        return $changes ? ['changes' => $changes] : [];
    }

    /**
     * Send email notifications when an event status changes.
     *
     * Admin (venue_admins + VENUE_MANAGER_EMAIL) is notified on EVERY status
     * change via the status-changed template. Two additional external sidecars fire
     * for specific transitions only:
     * - booked (private events): also notify the client that their event is confirmed.
     * - needs_assets (public events): notify producer/artist + booker to submit promo materials.
     *
     * Best-effort — never throws.
     */
    private function notifyStatusChange(int $eventId, string $oldStatus, string $newStatus): void
    {
        try {
            $event = $this->db->one(
                'SELECT e.id, e.title, e.date, e.end_date, e.show_time, e.event_type,
                        e.promoter_name, e.promoter_email,
                        e.booker_name, e.booker_email, e.description_public, e.ticket_url,
                        v.name AS venue_name
                   FROM events e
              LEFT JOIN venues v ON v.id = e.venue_id
                  WHERE e.id = ? LIMIT 1',
                [$eventId]
            );
            if (!$event) return;

            $isPrivate = ($event['event_type'] ?? '') === 'private_event';
            $link      = rtrim((string) (getenv('APP_URL') ?: ''), '/') . "/#event-{$eventId}";

            $showTime = '';
            if (!empty($event['show_time'])) {
                $t = strtotime((string) $event['show_time']);
                $showTime = $t ? date('g:i A', $t) : (string) $event['show_time'];
            }

            $mailer = new Mailer($this->root, $this->db);

            // ── Human-readable status labels ─────────────────────────────────
            $statusLabels = [
                'empty'              => 'Empty',
                'proposed'          => 'Hold',
                'confirmed'         => 'Intake Complete',
                'booked'            => 'Booked',
                'needs_assets'      => 'Needs Assets',
                'assets_approved'   => 'Assets Approved',
                'ready_to_announce' => 'Ready to Announce',
                'published'         => 'Published',
                'advanced'          => 'Advanced',
                'completed'         => 'Completed',
                'settled'           => 'Settled',
                'canceled'          => 'Canceled',
            ];
            $statusColors = [
                'empty'              => '#9ca3af',
                'proposed'          => '#6b7280',
                'confirmed'         => '#2563eb',
                'booked'            => '#16a34a',
                'needs_assets'      => '#d97706',
                'assets_approved'   => '#16a34a',
                'ready_to_announce' => '#7c3aed',
                'published'         => '#0891b2',
                'advanced'          => '#0891b2',
                'completed'         => '#16a34a',
                'settled'           => '#16a34a',
                'canceled'          => '#dc2626',
            ];
            $newLabel   = $statusLabels[$newStatus] ?? ucwords(str_replace('_', ' ', $newStatus));
            $oldLabel   = $statusLabels[$oldStatus] ?? ucwords(str_replace('_', ' ', $oldStatus));
            $statusColor = $statusColors[$newStatus] ?? '#6b7280';

            // ── Always notify admins on any status change ─────────────────────
            $admins = $this->db->all(
                "SELECT name, email, notify_event_updates FROM users
                  WHERE role = 'venue_admin'
                    AND email IS NOT NULL AND email != '' AND email NOT LIKE '%.local'
                    AND is_hidden = 0"
            );

            // Include VENUE_MANAGER_EMAIL in the admin recipient list, deduped.
            $adminRecipients = [];
            foreach ($admins as $a) {
                $adminRecipients[strtolower(trim((string) $a['email']))] = $a;
            }
            $mgEmail = trim((string) (getenv('VENUE_MANAGER_EMAIL') ?: ''));
            $mgName  = trim((string) (getenv('VENUE_MANAGER_NAME') ?: 'Venue Manager'));
            if ($mgEmail && filter_var($mgEmail, FILTER_VALIDATE_EMAIL)) {
                $adminRecipients[strtolower($mgEmail)] ??= ['name' => $mgName, 'email' => $mgEmail];
            }

            if ($adminRecipients) {
                $eventLabel  = $isPrivate ? "Private Event — {$newLabel}" : $newLabel;
                $subject     = "[Backstage] Status changed to {$eventLabel}: {$event['title']}";
                $adminVars   = [
                    'event_name'      => htmlspecialchars((string) $event['title'],                                 ENT_QUOTES, 'UTF-8'),
                    'old_status'      => htmlspecialchars($oldLabel,                                                ENT_QUOTES, 'UTF-8'),
                    'new_status'      => htmlspecialchars($eventLabel,                                              ENT_QUOTES, 'UTF-8'),
                    'status_color'    => $statusColor,
                    'event_date'      => htmlspecialchars(!empty($event['end_date']) ? "{$event['date']} – {$event['end_date']}" : (string) $event['date'], ENT_QUOTES, 'UTF-8'),
                    'event_time'      => $showTime !== '' ? htmlspecialchars($showTime, ENT_QUOTES, 'UTF-8') : '—',
                    'event_venue'     => htmlspecialchars((string) ($event['venue_name'] ?? getenv('VENUE_NAME') ?: 'Venue'),     ENT_QUOTES, 'UTF-8'),
                    'promoter_name'   => htmlspecialchars((string) ($event['promoter_name'] ?? '—'),               ENT_QUOTES, 'UTF-8'),
                    'booker_name'     => $isPrivate
                        ? 'N/A (private event)'
                        : htmlspecialchars((string) ($event['booker_name'] ?? '—'), ENT_QUOTES, 'UTF-8'),
                    'event_admin_url' => htmlspecialchars($link,                                                    ENT_QUOTES, 'UTF-8'),
                ];
                foreach ($adminRecipients as $recipient) {
                    if (!NotificationPreferences::wants($recipient, NotificationPreferences::EVENT_UPDATES)) {
                        continue;
                    }
                    $mailer->sendTemplate($recipient['email'], $subject, 'status-changed', $adminVars);
                }
            }

            // ── Booked: also notify the client for private events ─────────────
            if ($newStatus === 'booked' && $isPrivate
                && !empty($event['promoter_email'])
                && filter_var($event['promoter_email'], FILTER_VALIDATE_EMAIL)
            ) {
                $clientVars = [
                    'event_name'      => htmlspecialchars((string) $event['title'],                             ENT_QUOTES, 'UTF-8'),
                    'old_status'      => 'Pending',
                    'new_status'      => 'Confirmed & Booked',
                    'status_color'    => '#16a34a',
                    'event_date'      => htmlspecialchars(!empty($event['end_date']) ? "{$event['date']} – {$event['end_date']}" : (string) $event['date'], ENT_QUOTES, 'UTF-8'),
                    'event_time'      => $showTime !== '' ? htmlspecialchars($showTime, ENT_QUOTES, 'UTF-8') : '—',
                    'event_venue'     => htmlspecialchars((string) ($event['venue_name'] ?? getenv('VENUE_NAME') ?: 'Venue'), ENT_QUOTES, 'UTF-8'),
                    'promoter_name'   => htmlspecialchars((string) ($event['promoter_name'] ?? 'You'),         ENT_QUOTES, 'UTF-8'),
                    'booker_name'     => getenv('VENUE_NAME') ?: 'Venue',
                    'event_admin_url' => htmlspecialchars($link,                                                ENT_QUOTES, 'UTF-8'),
                ];
                $mailer->sendTemplate(
                    $event['promoter_email'],
                    '[' . (getenv('VENUE_NAME') ?: 'Backstage') . "] Your event is confirmed: {$event['title']}",
                    'status-changed',
                    $clientVars
                );
            }

            // ── Needs Assets: notify producer/artist + booker (public events only) ──
            if ($newStatus === 'needs_assets' && !$isPrivate) {
                $assetsVars = [
                    'event_name'      => htmlspecialchars((string) $event['title'],                             ENT_QUOTES, 'UTF-8'),
                    'event_date'      => htmlspecialchars(!empty($event['end_date']) ? "{$event['date']} – {$event['end_date']}" : (string) $event['date'], ENT_QUOTES, 'UTF-8'),
                    'event_time'      => $showTime !== '' ? htmlspecialchars($showTime, ENT_QUOTES, 'UTF-8') : '—',
                    'event_venue'     => htmlspecialchars((string) ($event['venue_name'] ?? getenv('VENUE_NAME') ?: 'Venue'), ENT_QUOTES, 'UTF-8'),
                    'event_admin_url' => htmlspecialchars($link,                                                ENT_QUOTES, 'UTF-8'),
                ];
                $externalRecipients = array_filter([
                    $event['promoter_email'] ? ['name' => $event['promoter_name'] ?? 'Producer/Artist', 'email' => $event['promoter_email']] : null,
                    $event['booker_email']   ? ['name' => $event['booker_name']   ?? 'Booker',          'email' => $event['booker_email']]   : null,
                ]);
                foreach ($externalRecipients as $recipient) {
                    if (!filter_var($recipient['email'], FILTER_VALIDATE_EMAIL)) continue;
                    $mailer->sendTemplate(
                        $recipient['email'],
                        '[' . (getenv('VENUE_NAME') ?: 'Backstage') . "] Promo materials needed: {$event['title']}",
                        'needs-assets',
                        $assetsVars + ['recipient_name' => htmlspecialchars((string) $recipient['name'], ENT_QUOTES, 'UTF-8')]
                    );
                }
            }

            // ── Assets Approved: send Molly a ready-to-post linktree packet ───
            // Andres and Colleen are venue_admins and already got the generic
            // "always notify admins" email above; Molly (global_viewer) is not
            // a venue admin, so she needs her own dedicated notification with
            // the full promo packet — poster, description, ticket link — per #14.
            if ($newStatus === 'assets_approved' && !$isPrivate) {
                $linktreeEmail = trim((string) (getenv('LINKTREE_MANAGER_EMAIL') ?: 'molly.graton@gmail.com'));
                $linktreeName  = trim((string) (getenv('LINKTREE_MANAGER_NAME') ?: 'Molly'));
                if ($linktreeEmail && filter_var($linktreeEmail, FILTER_VALIDATE_EMAIL)) {
                    $posterUrl = '';
                    $posterRow = $this->db->one(
                        "SELECT file_path FROM event_assets WHERE event_id = ? AND asset_type IN ('poster','flyer') AND approval_status = 'approved' ORDER BY updated_at DESC LIMIT 1",
                        [$eventId]
                    );
                    if ($posterRow && !empty($posterRow['file_path'])) {
                        $posterUrl = rtrim((string) (getenv('APP_URL') ?: ''), '/') . '/' . ltrim((string) $posterRow['file_path'], '/');
                    }
                    $linktreeVars = [
                        'event_name'        => htmlspecialchars((string) $event['title'], ENT_QUOTES, 'UTF-8'),
                        'event_date'        => htmlspecialchars(!empty($event['end_date']) ? "{$event['date']} – {$event['end_date']}" : (string) $event['date'], ENT_QUOTES, 'UTF-8'),
                        'event_time'        => $showTime !== '' ? htmlspecialchars($showTime, ENT_QUOTES, 'UTF-8') : '—',
                        'event_venue'       => htmlspecialchars((string) ($event['venue_name'] ?? getenv('VENUE_NAME') ?: 'Venue'), ENT_QUOTES, 'UTF-8'),
                        'event_description'      => (string) ($event['description_public'] ?? ''),
                        'event_description_html' => nl2br(htmlspecialchars((string) ($event['description_public'] ?? ''), ENT_QUOTES, 'UTF-8')),
                        'ticket_url'        => (string) ($event['ticket_url'] ?? ''),
                        'ticket_url_html'   => htmlspecialchars((string) ($event['ticket_url'] ?? ''), ENT_QUOTES, 'UTF-8'),
                        'poster_url'        => $posterUrl,
                        'poster_url_html'   => htmlspecialchars($posterUrl, ENT_QUOTES, 'UTF-8'),
                        'event_admin_url'   => htmlspecialchars($link, ENT_QUOTES, 'UTF-8'),
                        'recipient_name'    => htmlspecialchars($linktreeName, ENT_QUOTES, 'UTF-8'),
                    ];
                    $mailer->sendTemplate(
                        $linktreeEmail,
                        '[' . (getenv('VENUE_NAME') ?: 'Backstage') . "] Ready for linktree/Instagram: {$event['title']}",
                        'assets-approved-linktree',
                        $linktreeVars
                    );
                }
            }
        } catch (\Throwable $e) {
            @error_log("status-change notification failed for event {$eventId}: {$e->getMessage()}");
        }
    }

    /**
     * If auto-publish is enabled in promote_auto_publish_settings, create a
     * broadcast for the event's most recent promote post and dispatch it to
     * every configured destination.
     *
     * Mirrors the logic in Promote\Broadcasts::create() but runs internally
     * (no HTTP round-trip) and is attributed as trigger_source=auto_publish
     * in the activity log.  Best-effort — never throws.
     */
    private function maybeAutoPublish(int $eventId): void
    {
        try {
            $settings = $this->db->one(
                'SELECT auto_publish_enabled, auto_publish_destinations
                 FROM promote_auto_publish_settings
                 LIMIT 1'
            );
            if (!$settings || !(int) $settings['auto_publish_enabled']) {
                return;
            }

            $destinations = $settings['auto_publish_destinations']
                ? json_decode((string) $settings['auto_publish_destinations'], true)
                : [];

            if (empty($destinations) || !is_array($destinations)) {
                error_log("Auto-publish: enabled but no destinations configured for event {$eventId}. Skipping.");
                return;
            }

            // Use the most recently created post for this event.
            $post = $this->db->one(
                "SELECT * FROM promote_posts WHERE event_id = ? ORDER BY created_at DESC LIMIT 1",
                [$eventId]
            );
            if (!$post) {
                error_log("Auto-publish: event {$eventId} reached published status but has no promote post. Skipping.");
                return;
            }

            $postId = (int) $post['id'];

            // Load the full event row (with venue join) as adapters expect it.
            $event = $this->db->one(
                'SELECT e.*, v.name venue_name, v.city venue_city, v.state venue_state
                 FROM events e LEFT JOIN venues v ON v.id = e.venue_id WHERE e.id = ?',
                [$eventId]
            ) ?? [];

            // Build destination map for group lookup.
            $placeholders = implode(',', array_fill(0, count($destinations), '?'));
            $destRecords  = $this->db->all(
                "SELECT * FROM promote_destinations WHERE destination_key IN ($placeholders)",
                array_values($destinations)
            );
            $destMap = [];
            foreach ($destRecords as $d) {
                $destMap[(string) $d['destination_key']] = $d;
            }

            $pdo = $this->db->pdo();
            $pdo->beginTransaction();
            try {
                $broadcastId = $this->db->insert(
                    'INSERT INTO promote_broadcasts (event_id, post_id, created_by_user_id, send_mode, status)
                     VALUES (?, ?, NULL, ?, ?)',
                    [$eventId, $postId, 'now', 'queued']
                );

                $adapter = new Promote\BroadcastAdapters($this->db);
                $statuses = [];

                foreach ($destinations as $destKey) {
                    $dest      = $destMap[$destKey] ?? null;
                    $destGroup = $dest ? (string) $dest['destination_group'] : 'unknown';
                    $destStatus = $dest ? (string) $dest['status'] : 'manual_submission';

                    $dispatched = $adapter->dispatch($destKey, $destStatus, 'now', $event, $post);

                    $this->db->insert(
                        'INSERT INTO promote_broadcast_results
                            (broadcast_id, destination_key, destination_group, status, external_url, error_message, response_json)
                         VALUES (?, ?, ?, ?, ?, ?, ?)',
                        [
                            $broadcastId,
                            $destKey,
                            $destGroup,
                            $dispatched['status'],
                            $dispatched['external_url'],
                            $dispatched['error_message'],
                            $dispatched['response_json'],
                        ]
                    );
                    $statuses[] = $dispatched['status'];
                }

                $anyFailed = in_array('failed', $statuses, true);
                $allFailed = count($statuses) > 0
                    && count(array_filter($statuses, fn ($s) => $s === 'failed')) === count($statuses);
                $broadcastStatus = match (true) {
                    $allFailed  => 'failed',
                    $anyFailed  => 'partial_failure',
                    default     => 'completed',
                };
                $this->db->run(
                    'UPDATE promote_broadcasts SET status = ? WHERE id = ?',
                    [$broadcastStatus, $broadcastId]
                );

                $pdo->commit();
            } catch (\Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }

            log_activity($this->db, $eventId, $this->userId(), 'auto-publish triggered', [
                'broadcast_id' => $broadcastId,
                'post_id'      => $postId,
                'destinations' => $destinations,
            ]);
        } catch (\Throwable $e) {
            @error_log("auto-publish failed for event {$eventId}: {$e->getMessage()}");
        }
    }

    /**
     * Notify all venue_admins immediately when a new private event inquiry is created.
     * Best-effort — never throws.
     */
    private function notifyPrivateEventCreated(int $eventId): void
    {
        try {
            $admins = $this->db->all("SELECT name, email, notify_event_updates FROM users WHERE role = 'venue_admin' AND email IS NOT NULL AND email != '' AND email NOT LIKE '%.local' AND is_hidden = 0");
            if (!$admins) return;

            $event = $this->db->one(
                'SELECT e.title, e.date, e.end_date, e.doors_time, e.show_time, e.end_time,
                        e.promoter_name, e.promoter_email, e.promoter_phone,
                        e.client_org, e.estimated_guests, e.capacity,
                        e.av_requirements, e.catering_notes,
                        v.name AS venue_name
                   FROM events e
              LEFT JOIN venues v ON v.id = e.venue_id
                  WHERE e.id = ? LIMIT 1',
                [$eventId]
            );
            if (!$event) return;

            $link    = rtrim((string) (getenv('APP_URL') ?: ''), '/') . "/#event-{$eventId}";
            $subject = "[Backstage] New private event inquiry: {$event['title']}";

            $showTime = '';
            if (!empty($event['doors_time'])) {
                $t = strtotime((string) $event['doors_time']);
                $showTime = $t ? date('g:i A', $t) : (string) $event['doors_time'];
            }

            $vars = [
                'event_name'       => htmlspecialchars((string) $event['title'],                       ENT_QUOTES, 'UTF-8'),
                'event_date'       => htmlspecialchars(!empty($event['end_date']) ? "{$event['date']} – {$event['end_date']}" : (string) $event['date'], ENT_QUOTES, 'UTF-8'),
                'event_time'       => $showTime !== '' ? htmlspecialchars($showTime, ENT_QUOTES, 'UTF-8') : '—',
                'event_venue'      => htmlspecialchars((string) ($event['venue_name'] ?? getenv('VENUE_NAME') ?: 'Venue'), ENT_QUOTES, 'UTF-8'),
                'client_name'      => htmlspecialchars((string) ($event['promoter_name'] ?? '—'),     ENT_QUOTES, 'UTF-8'),
                'client_email'     => htmlspecialchars((string) ($event['promoter_email'] ?? '—'),    ENT_QUOTES, 'UTF-8'),
                'client_phone'     => htmlspecialchars((string) ($event['promoter_phone'] ?? '—'),    ENT_QUOTES, 'UTF-8'),
                'client_org'       => htmlspecialchars((string) ($event['client_org'] ?? '—'),        ENT_QUOTES, 'UTF-8'),
                'estimated_guests' => htmlspecialchars((string) ($event['estimated_guests'] ?? '—'),  ENT_QUOTES, 'UTF-8'),
                'av_requirements'  => htmlspecialchars((string) ($event['av_requirements'] ?? 'None noted'), ENT_QUOTES, 'UTF-8'),
                'catering_notes'   => htmlspecialchars((string) ($event['catering_notes'] ?? 'None noted'),  ENT_QUOTES, 'UTF-8'),
                'event_admin_url'  => htmlspecialchars($link,                                         ENT_QUOTES, 'UTF-8'),
            ];

            $mailer = new Mailer($this->root, $this->db);
            foreach ($admins as $admin) {
                if (!NotificationPreferences::wants($admin, NotificationPreferences::EVENT_UPDATES)) {
                    continue;
                }
                $mailer->sendTemplate($admin['email'], $subject, 'private-event-inquiry', $vars);
            }
        } catch (\Throwable $e) {
            @error_log("private-event-created notification failed for event {$eventId}: {$e->getMessage()}");
        }
    }
}
