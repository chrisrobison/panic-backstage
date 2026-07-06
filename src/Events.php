<?php
declare(strict_types=1);

namespace Panic;

use function Panic\boolish;
use function Panic\date_or_null;
use function Panic\log_activity;
use function Panic\slugify;

final class Events extends BaseEndpoint
{
    private const STATUSES = ['empty','proposed','confirmed','booked','needs_assets','ready_to_announce','published','advanced','completed','settled','canceled'];

    /** Statuses that represent a committed booking (conflict + transition checks apply). */
    private const BOOKING_CONFIRMED_STATUSES = ['confirmed','booked','needs_assets','ready_to_announce','published','advanced','completed','settled'];
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
        $sql = "SELECT e.*, u.name owner_name,
                  (SELECT title FROM event_blockers b WHERE b.event_id = e.id AND b.status IN ('open','waiting') ORDER BY due_date, id LIMIT 1) primary_blocker,
                  (SELECT COUNT(*) FROM event_tasks t WHERE t.event_id = e.id AND t.status NOT IN ('done','canceled')) incomplete_tasks,
                  (SELECT COUNT(*) FROM event_blockers b WHERE b.event_id = e.id AND b.status IN ('open','waiting')) open_items,
                  (SELECT COUNT(*) FROM event_assets a WHERE a.event_id = e.id AND a.asset_type = 'flyer' AND a.approval_status = 'approved') approved_flyers
                FROM events e LEFT JOIN users u ON u.id = e.owner_user_id";
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY e.date DESC, e.show_time DESC LIMIT 250';
        $events = array_map(fn ($event) => $event + ['capabilities' => $this->eventCapabilities((int) $event['id'])], $this->db->all($sql, $params));
        return $this->ok([
            'events' => $events,
            'users' => $this->accessibleUsers(),
            'venues' => $this->db->all('SELECT * FROM venues ORDER BY name'),
            'statuses' => self::STATUSES,
            'types' => self::TYPES,
            'range' => [
                'start_date' => $request->query('start_date'),
                'end_date' => $request->query('end_date'),
            ],
            'capabilities' => $this->globalCapabilities(),
        ]);
    }

    private function show(int $id): Response
    {
        if ($denied = $this->requireEventCapability($id, 'read_event')) {
            return $denied;
        }
        $event = $this->db->one(
            'SELECT e.*, v.name venue_name, v.address venue_address, v.city venue_city, v.state venue_state, u.name owner_name
             FROM events e JOIN venues v ON v.id = e.venue_id LEFT JOIN users u ON u.id = e.owner_user_id WHERE e.id = ?',
            [$id]
        );
        if (!$event) {
            return $this->notFound('Event not found');
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
                 ORDER BY es.call_time, FIELD(es.role,"manager","sound","lighting","security","door","bartender","barback","stagehand","runner","cleaner","other"), es.id',
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
            'taskTemplates' => $this->db->all("SELECT id, name FROM event_templates WHERE checklist_json IS NOT NULL AND checklist_json != '[]' ORDER BY name"),
            'nextAction' => $nextAction,
            'readiness' => $readiness,
            'access' => $this->eventAccess($id),
            'capabilities' => $this->eventCapabilities($id),
            'links' => [
                'public_page' => 'event.html?slug=' . rawurlencode((string) $event['slug']),
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

        // Private events are never publicly visible and auto-assign to Colleen.
        $publicVisibility = $isPrivate ? 0 : (boolish($body['public_visibility'] ?? false) ? 1 : 0);
        $ownerId = ($body['owner_user_id'] ?? null) ?: ($isPrivate ? $this->getPrivateEventHandlerId() : $this->userId());

        if (in_array($newStatus, self::BOOKING_CONFIRMED_STATUSES, true)) {
            if ($conflict = $this->checkRoomConflict((int) $body['venue_id'], $body['date'], date_or_null($body['doors_time'] ?? null), date_or_null($body['end_time'] ?? null), null, self::nullableDate($body['end_date'] ?? null, $body['date']))) {
                return $conflict;
            }
        }
        [$resourceId, $resourceError] = $this->resolveResourceId($body, (int) $body['venue_id']);
        if ($resourceError) {
            return $resourceError;
        }
        $id = $this->db->insert(
            'INSERT INTO events (venue_id, resource_id, title, slug, event_type, status, description_public, description_internal, av_requirements, catering_notes, date, end_date, doors_time, show_time, end_time, load_in_time, age_restriction, ticket_price, deposit_amount, potential_revenue, ticket_url, ticket_system, contract_url, venue_contract_url, walkthrough_done, settlement_doc_url, capacity, estimated_guests, public_visibility, owner_user_id, promoter_name, promoter_email, promoter_phone, client_org, booker_name, booker_email, booker_phone)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [(int) $body['venue_id'], $resourceId, $body['title'], $slug, $body['event_type'], $newStatus, $isPrivate ? null : self::nullableString($body['description_public'] ?? null), self::nullableString($body['description_internal'] ?? null), self::nullableString($body['av_requirements'] ?? null), self::nullableString($body['catering_notes'] ?? null), $body['date'], self::nullableDate($body['end_date'] ?? null, $body['date']), date_or_null($body['doors_time'] ?? null), date_or_null($body['show_time'] ?? null), date_or_null($body['end_time'] ?? null), date_or_null($body['load_in_time'] ?? null), $body['age_restriction'] ?? null, $isPrivate ? 0 : (float) ($body['ticket_price'] ?? 0), self::nullableDecimal($body['deposit_amount'] ?? null), self::nullableDecimal($body['potential_revenue'] ?? null), $isPrivate ? null : self::nullableString($body['ticket_url'] ?? null), $isPrivate ? null : self::nullableString($body['ticket_system'] ?? null), self::nullableString($body['contract_url'] ?? null), self::nullableString($body['venue_contract_url'] ?? null), boolish($body['walkthrough_done'] ?? false) ? 1 : 0, self::nullableString($body['settlement_doc_url'] ?? null), ($body['capacity'] ?? null) ?: null, ($body['estimated_guests'] ?? null) ?: null, $publicVisibility, $ownerId, self::nullableString($body['promoter_name'] ?? null), self::nullableString($body['promoter_email'] ?? null), self::nullableString($body['promoter_phone'] ?? null), self::nullableString($body['client_org'] ?? null), $isPrivate ? null : self::nullableString($body['booker_name'] ?? null), $isPrivate ? null : self::nullableString($body['booker_email'] ?? null), $isPrivate ? null : self::nullableString($body['booker_phone'] ?? null)]
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

    private function update(Request $request, int $id): Response
    {
        if (!$id) {
            return $this->notFound();
        }
        if ($denied = $this->requireEventCapability($id, 'edit_event')) {
            return $denied;
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
                if ($conflict = $this->checkRoomConflict((int) $existing['venue_id'], $existing['date'], $existing['doors_time'], $existing['end_time'], $id, $existing['end_date'] ?? null)) {
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
                $oldRow  = $this->db->one("SELECT `{$key}` FROM events WHERE id = ?", [$id]);
                $coerced = $partialAllowlist[$key]($body[$key]);
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
        // Validate status transition when the status is being changed
        if (isset($body['status']) && $body['status'] !== ($old['status'] ?? '')) {
            $merged = array_merge($old, array_filter((array) $body, fn ($v) => $v !== null && $v !== ''));
            if ($transitionError = $this->validateStatusTransition($body['status'], $merged)) {
                return $transitionError;
            }
        }
        // Room conflict check for committed bookings
        $checkStatus = $body['status'] ?? $old['status'];
        if (in_array($checkStatus, self::BOOKING_CONFIRMED_STATUSES, true)) {
            $checkVenueId  = (int) ($body['venue_id'] ?? $old['venue_id']);
            $checkDate     = $body['date'] ?? $old['date'];
            $checkEndDate  = self::nullableDate($body['end_date'] ?? $old['end_date'] ?? null, $checkDate);
            $checkDoors    = date_or_null($body['doors_time'] ?? $old['doors_time'] ?? null);
            $checkEnd      = date_or_null($body['end_time']   ?? $old['end_time']   ?? null);
            if ($conflict = $this->checkRoomConflict($checkVenueId, $checkDate, $checkDoors, $checkEnd, $id, $checkEndDate)) {
                return $conflict;
            }
        }
        $slug = (($old['title'] ?? '') !== ($body['title'] ?? '') || ($old['date'] ?? '') !== ($body['date'] ?? ''))
            ? $this->uniqueSlug(($body['title'] ?? $old['title']) . '-' . ($body['date'] ?? $old['date']), $id)
            : $old['slug'];
        $wasStatus = (string) $old['status'];
        $isPrivate = ($body['event_type'] ?? $old['event_type'] ?? '') === 'private_event';
        // Private events are never publicly visible.
        $updatePublicVis = $isPrivate ? 0 : (boolish($body['public_visibility'] ?? false) ? 1 : 0);

        $this->db->run(
            'UPDATE events SET venue_id=?, title=?, slug=?, event_type=?, status=?, description_public=?, description_internal=?, av_requirements=?, catering_notes=?, date=?, end_date=?, doors_time=?, show_time=?, end_time=?, load_in_time=?, age_restriction=?, ticket_price=?, deposit_amount=?, potential_revenue=?, ticket_url=?, ticket_system=?, contract_url=?, venue_contract_url=?, walkthrough_done=?, settlement_doc_url=?, capacity=?, estimated_guests=?, public_visibility=?, owner_user_id=?, promoter_name=?, promoter_email=?, promoter_phone=?, client_org=?, booker_name=?, booker_email=?, booker_phone=? WHERE id=?',
            [(int) $body['venue_id'], $body['title'], $slug, $body['event_type'], $body['status'], $isPrivate ? null : ($body['description_public'] ?? null), $body['description_internal'] ?? null, self::nullableString($body['av_requirements'] ?? $old['av_requirements']), self::nullableString($body['catering_notes'] ?? $old['catering_notes']), $body['date'], self::nullableDate($body['end_date'] ?? $old['end_date'] ?? null, $body['date']), date_or_null($body['doors_time'] ?? null), date_or_null($body['show_time'] ?? null), date_or_null($body['end_time'] ?? null), date_or_null($body['load_in_time'] ?? $old['load_in_time'] ?? null), $body['age_restriction'] ?? null, $isPrivate ? 0 : (float) ($body['ticket_price'] ?? 0), self::nullableDecimal($body['deposit_amount'] ?? null), self::nullableDecimal($body['potential_revenue'] ?? null), $isPrivate ? null : self::nullableString($body['ticket_url'] ?? $old['ticket_url']), $isPrivate ? null : self::nullableString($body['ticket_system'] ?? $old['ticket_system']), self::nullableString($body['contract_url'] ?? $old['contract_url']), self::nullableString($body['venue_contract_url'] ?? $old['venue_contract_url']), boolish($body['walkthrough_done'] ?? false) ? 1 : 0, self::nullableString($body['settlement_doc_url'] ?? $old['settlement_doc_url']), ($body['capacity'] ?? null) ?: null, isset($body['estimated_guests']) && $body['estimated_guests'] !== '' ? (int) $body['estimated_guests'] : ($old['estimated_guests'] ?? null), $updatePublicVis, ($body['owner_user_id'] ?? null) ?: null, self::nullableString($body['promoter_name'] ?? $old['promoter_name']), self::nullableString($body['promoter_email'] ?? $old['promoter_email']), self::nullableString($body['promoter_phone'] ?? $old['promoter_phone']), self::nullableString($body['client_org'] ?? $old['client_org']), $isPrivate ? null : self::nullableString($body['booker_name'] ?? $old['booker_name']), $isPrivate ? null : self::nullableString($body['booker_email'] ?? $old['booker_email']), $isPrivate ? null : self::nullableString($body['booker_phone'] ?? $old['booker_phone']), $id]
        );
        if (isset($body['status']) && $body['status'] !== $wasStatus) {
            $this->notifyStatusChange($id, $wasStatus, $body['status']);
            if ($body['status'] === 'published') {
                $this->maybeAutoPublish($id);
            }
        }
        log_activity($this->db, $id, $this->userId(), 'event updated', $this->diffEvent($old, $body));
        $this->pushToSheet($id);
        return $this->ok(['id' => $id]);
    }

    /**
     * Two-way sync: enqueue this event for write-back to the Google Sheet and
     * attempt an immediate push. Best-effort and non-blocking — any failure is
     * recorded as a pending row in sheet_sync_queue for the cron to retry, and
     * never affects the HTTP response (mirrors the Mailer's never-throw rule).
     */
    private function pushToSheet(int $id): void
    {
        try {
            // Full identity + app-owned field set so an unlinked event can be
            // appended as a complete Tracker row (not just updated in place).
            $cols = implode(', ', array_keys(GoogleSheets::APPEND_COLUMN));
            $ev = $this->db->one("SELECT {$cols} FROM events WHERE id = ? LIMIT 1", [$id]);
            if (!$ev) {
                return;
            }

            // Only NAMED events belong in the sheet. A nameless (untitled) event
            // is treated as an in-progress draft: keep it app-only and don't even
            // enqueue it, so it never appears in the Tracker until it's named.
            if (trim((string) ($ev['title'] ?? '')) === '') {
                return;
            }

            // One pending outbox row per event; repeated edits collapse into it.
            $this->db->run(
                'INSERT INTO sheet_sync_queue (event_id, status, attempts)
                 VALUES (?, \'pending\', 0)
                 ON DUPLICATE KEY UPDATE status = \'pending\', updated_at = NOW()',
                [$id]
            );

            $sheets = new GoogleSheets($this->root);
            if (!$sheets->isConfigured()) {
                return; // not set up yet — the cron sweep retries once the key lands
            }

            // Update the linked row, link+update a legacy EVT-N row, or append a
            // brand-new row for an app-created event with no sheet presence.
            $res = $sheets->syncEventRow($id, $ev);
            if ($res['ok']) {
                $this->db->run(
                    'UPDATE sheet_sync_queue
                     SET status = \'done\', attempts = attempts + 1, last_error = NULL, pushed_at = NOW()
                     WHERE event_id = ?',
                    [$id]
                );
            } else {
                $this->db->run(
                    'UPDATE sheet_sync_queue SET attempts = attempts + 1 WHERE event_id = ?',
                    [$id]
                );
            }
        } catch (\Throwable $e) {
            @error_log('sheet push failed for event ' . $id . ': ' . $e->getMessage());
        }
    }

    private function delete(int $id): Response
    {
        if ($denied = $this->requireEventCapability($id, 'delete_event')) {
            return $denied;
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
        [$resourceId, $resourceError] = $this->resolveResourceId($body, (int) $template['venue_id']);
        if ($resourceError) {
            return $resourceError;
        }
        $id = $this->db->insert(
            "INSERT INTO events (venue_id, resource_id, title, slug, event_type, status, description_public, date, end_date, doors_time, show_time, age_restriction, ticket_price, owner_user_id)
             VALUES (?, ?, ?, ?, ?, 'proposed', ?, ?, ?, ?, ?, ?, ?, ?)",
            [(int) $template['venue_id'], $resourceId, $title, $this->uniqueSlug($title . '-' . $date), $template['event_type'], $template['default_description_public'], $date, self::nullableDate($body['end_date'] ?? null, $date), ($body['doors_time'] ?? '') ?: '19:00', ($body['show_time'] ?? '') ?: '20:00', $template['default_age_restriction'], (float) $template['default_ticket_price'], $this->userId()]
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

    /**
     * Assign the next sequential human-facing code (EVT-N). Retried so the
     * unique-index race between concurrent creates can't collide silently.
     * Used by both the blank-create and create-from-template paths.
     */
    private function assignEventCode(int $id): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $row  = $this->db->one("SELECT COALESCE(MAX(CAST(SUBSTRING(external_id, 5) AS UNSIGNED)), 0) AS m FROM events WHERE external_id LIKE 'EVT-%'");
            $code = 'EVT-' . (((int) ($row['m'] ?? 0)) + 1);
            try {
                $this->db->run('UPDATE events SET external_id = ? WHERE id = ?', [$code, $id]);
                return;
            } catch (\Throwable $e) {
                if ($attempt === 4) {
                    @error_log('event code assignment failed for event ' . $id . ': ' . $e->getMessage());
                }
            }
        }
    }

    private function uniqueSlug(string $base, ?int $ignoreId = null): string
    {
        $root = slugify($base);
        $slug = $root;
        $i = 2;
        while ($this->db->one('SELECT id FROM events WHERE slug = ? AND (? IS NULL OR id != ?) LIMIT 1', [$slug, $ignoreId, $ignoreId])) {
            $slug = "$root-" . $i++;
        }
        return $slug;
    }

    private function nextAction(array $event, array $blockers, array $assets, ?array $settlement): string
    {
        foreach ($blockers as $blocker) {
            if (in_array($blocker['status'], ['open', 'waiting'], true)) return 'Complete open items';
        }
        $hasContract = !empty($event['contract_url']) || $this->db->one(
            "SELECT id FROM contracts WHERE event_id = ? AND status IN ('approved','sent','signed') LIMIT 1",
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
            $event['status'] === 'published' && !$event['ticket_url'] && (float) $event['ticket_price'] > 0 => 'Add ticketing link',
            $event['status'] === 'completed' && !$settlement => 'Complete settlement',
            default => 'Review event details',
        };
    }

    private function readiness(array $event, array $lineup, array $blockers, array $assets, ?array $settlement): array
    {
        $openBlockers = array_filter($blockers, fn ($b) => in_array($b['status'], ['open', 'waiting'], true));
        $isPrivate    = ($event['event_type'] ?? '') === 'private_event';

        if ($isPrivate) {
            $hasClient = !empty($event['promoter_name']) && !empty($event['promoter_email']);
            $hasContract = !empty($event['contract_url']) || $this->db->one(
                "SELECT id FROM contracts WHERE event_id = ? AND status IN ('approved','sent','signed') LIMIT 1",
                [(int) $event['id']]
            );
            return [
                ['label' => 'Client',       'state' => $hasClient ? 'On file' : 'Missing client contact', 'ok' => $hasClient],
                ['label' => 'Guest count',  'state' => !empty($event['estimated_guests']) ? $event['estimated_guests'] . ' estimated' : 'Not set', 'ok' => !empty($event['estimated_guests'])],
                ['label' => 'Run sheet',    'state' => $event['doors_time'] ? 'Timed' : 'Needs doors', 'ok' => (bool) $event['doors_time']],
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
            ['label' => 'Run sheet',   'state' => $event['doors_time'] ? 'Timed' : 'Needs doors', 'ok' => (bool) $event['doors_time']],
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

        // Statuses that private events may never use
        $privateDisallowed = ['needs_assets', 'ready_to_announce', 'published', 'advanced'];
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
                'doors_time'     => 'Start time (Doors)',
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
                'doors_time'     => 'Start time (Doors)',
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
                'age_restriction'   => 'Age restriction',
                'estimated_guests'  => 'Estimated guest count',
                'deposit_amount'    => 'Deposit amount (use 0 if none)',
            ];
        } else {
            $intakeRequired = [
                'age_restriction'   => 'Age restriction',
                'ticket_price'      => 'Ticket price (use 0 for free events)',
                'capacity'          => 'Capacity',
                'deposit_amount'    => 'Deposit amount (use 0 if none)',
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
            $hasContractUrl    = !empty($event['contract_url']);
            $hasExecutedContract = false;
            if (!empty($event['id'])) {
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
        $row = $this->db->one("SELECT id FROM users WHERE role = 'venue_admin' AND email NOT LIKE '%.local' ORDER BY id LIMIT 1");
        return $row ? (int) $row['id'] : $this->userId();
    }

    /**
     * Check whether the given venue + date + time window conflicts with any
     * existing booking (with a 30-minute buffer between events). Returns a
     * 409 Response describing the conflict, or null if the slot is clear.
     */
    private function checkRoomConflict(int $venueId, string $date, ?string $doorsTime, ?string $endTime, ?int $excludeId = null, ?string $endDate = null): ?Response
    {
        $venue = $this->db->one('SELECT zone, venue_group FROM venues WHERE id = ? LIMIT 1', [$venueId]);
        $zone  = $venue['zone']        ?? null;
        $group = $venue['venue_group'] ?? null;
        $ids   = [$venueId];
        // Generic group conflict: a 'both' (whole-building) booking conflicts
        // with every specific room in the same group, and a room booking also
        // conflicts with any whole-building event on the same day.
        // venue_group and zone are set in the DB (see migration 020_resources.sql).
        if ($group !== null) {
            if ($zone === 'both') {
                $others = $this->db->all(
                    "SELECT id FROM venues WHERE venue_group = ? AND zone != 'both'",
                    [$group]
                );
                foreach ($others as $r) { $ids[] = (int) $r['id']; }
            } else {
                $both = $this->db->one(
                    "SELECT id FROM venues WHERE venue_group = ? AND zone = 'both' LIMIT 1",
                    [$group]
                );
                if ($both) { $ids[] = (int) $both['id']; }
            }
        }
        $ph      = implode(',', array_fill(0, count($ids), '?'));
        $args    = array_values(array_map('intval', $ids));
        // Find events whose date range overlaps [date, endDate].
        // COALESCE(end_date, date) treats single-day events as a range of one day.
        $rangeEnd = $endDate ?: $date;
        $args[] = $rangeEnd; // existing.date <= rangeEnd
        $args[] = $date;     // COALESCE(existing.end_date, existing.date) >= date
        $excl    = $excludeId ? ' AND id != ?' : '';
        if ($excludeId) $args[] = $excludeId;
        $rows = $this->db->all(
            "SELECT id, title, date, end_date, doors_time, end_time FROM events WHERE venue_id IN ($ph) AND date <= ? AND COALESCE(end_date, date) >= ? AND status NOT IN ('canceled','empty')$excl",
            $args
        );
        $isMultiDayNew = $endDate && $endDate !== $date;
        foreach ($rows as $row) {
            $isMultiDayExisting = !empty($row['end_date']) && $row['end_date'] !== $row['date'];
            // Multi-day events block the entire date range — no time check needed.
            if ($isMultiDayNew || $isMultiDayExisting) {
                $conflictDate = $isMultiDayExisting
                    ? "{$row['date']}–{$row['end_date']}"
                    : $row['date'];
                return Response::json([
                    'error' => "Room conflict: \"{$row['title']}\" is already booked at this venue on {$conflictDate}.",
                    'conflict_event_id' => (int) $row['id'],
                ], 409);
            }
            // Both events are single-day: check the 30-minute time buffer.
            if ($this->timesOverlap($doorsTime, $endTime, $row['doors_time'], $row['end_time'])) {
                return Response::json([
                    'error' => "Room conflict: \"{$row['title']}\" is already booked at this venue on {$date}. Events must be at least 30 minutes apart.",
                    'conflict_event_id' => (int) $row['id'],
                ], 409);
            }
        }
        return null;
    }

    /** True if two event time windows overlap, accounting for a 30-minute buffer. */
    private function timesOverlap(?string $startA, ?string $endA, ?string $startB, ?string $endB): bool
    {
        // No times on either side → treat as full-day → always conflict
        if ((!$startA && !$endA) || (!$startB && !$endB)) return true;
        $mins = static function (?string $t): int {
            if (!$t) return 0;
            [$h, $m] = array_pad(explode(':', (string) $t), 2, '0');
            return (int) $h * 60 + (int) $m;
        };
        $buffer = 30;
        $sA = $mins($startA);
        $eA = $endA ? $mins($endA) : $sA + 300; // fallback 5 h show
        $sB = $mins($startB);
        $eB = $endB ? $mins($endB) : $sB + 300;
        if ($eA <= $sA) $eA += 1440; // past-midnight wrap
        if ($eB <= $sB) $eB += 1440;
        // Conflict if NOT (endA+buffer ≤ startB OR endB+buffer ≤ startA)
        return !($eA + $buffer <= $sB || $eB + $buffer <= $sA);
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
                'SELECT e.title, e.date, e.end_date, e.show_time, e.event_type,
                        e.promoter_name, e.promoter_email,
                        e.booker_name, e.booker_email, v.name AS venue_name
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
                    AND email IS NOT NULL AND email != '' AND email NOT LIKE '%.local'"
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
            $admins = $this->db->all("SELECT name, email, notify_event_updates FROM users WHERE role = 'venue_admin' AND email IS NOT NULL AND email != '' AND email NOT LIKE '%.local'");
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
