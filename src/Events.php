<?php
declare(strict_types=1);

namespace Panic;

use function Panic\boolish;
use function Panic\date_or_null;
use function Panic\log_activity;
use function Panic\slugify;

final class Events extends BaseEndpoint
{
    private const STATUSES = ['empty','proposed','hold','confirmed','booked','needs_assets','ready_to_announce','published','advanced','completed','settled','canceled'];

    /** Statuses that represent a committed booking (conflict + transition checks apply). */
    private const BOOKING_CONFIRMED_STATUSES = ['confirmed','booked','needs_assets','ready_to_announce','published','advanced','completed','settled'];
    private const TYPES = ['live_music','karaoke','open_mic','promoter_night','dj_night','comedy','private_event','special_event'];

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
            $where[] = 'e.date >= ?';
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
        foreach (['title', 'date', 'venue_id', 'event_type'] as $required) {
            if (empty($body[$required])) {
                return Response::json(['error' => "$required is required"], 422);
            }
        }
        $slug = $this->uniqueSlug($body['title'] . '-' . $body['date']);
        $newStatus = $body['status'] ?? 'proposed';
        if (in_array($newStatus, self::BOOKING_CONFIRMED_STATUSES, true)) {
            if ($conflict = $this->checkRoomConflict((int) $body['venue_id'], $body['date'], date_or_null($body['doors_time'] ?? null), date_or_null($body['end_time'] ?? null))) {
                return $conflict;
            }
        }
        $id = $this->db->insert(
            'INSERT INTO events (venue_id, title, slug, event_type, status, description_public, description_internal, date, doors_time, show_time, end_time, age_restriction, ticket_price, deposit_amount, potential_revenue, ticket_url, ticket_system, contract_url, walkthrough_done, settlement_doc_url, capacity, public_visibility, owner_user_id, promoter_name, promoter_email, promoter_phone, booker_name, booker_email, booker_phone)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [(int) $body['venue_id'], $body['title'], $slug, $body['event_type'], $newStatus, $body['description_public'] ?? null, $body['description_internal'] ?? null, $body['date'], date_or_null($body['doors_time'] ?? null), date_or_null($body['show_time'] ?? null), date_or_null($body['end_time'] ?? null), $body['age_restriction'] ?? null, (float) ($body['ticket_price'] ?? 0), self::nullableDecimal($body['deposit_amount'] ?? null), self::nullableDecimal($body['potential_revenue'] ?? null), self::nullableString($body['ticket_url'] ?? null), self::nullableString($body['ticket_system'] ?? null), self::nullableString($body['contract_url'] ?? null), boolish($body['walkthrough_done'] ?? false) ? 1 : 0, self::nullableString($body['settlement_doc_url'] ?? null), $body['capacity'] ?: null, boolish($body['public_visibility'] ?? false), $body['owner_user_id'] ?: $this->userId(), self::nullableString($body['promoter_name'] ?? null), self::nullableString($body['promoter_email'] ?? null), self::nullableString($body['promoter_phone'] ?? null), self::nullableString($body['booker_name'] ?? null), self::nullableString($body['booker_email'] ?? null), self::nullableString($body['booker_phone'] ?? null)]
        );
        $this->assignEventCode($id);
        log_activity($this->db, $id, $this->userId(), 'event created', ['title' => $body['title']]);
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
        if (isset($body['status']) && count($body) === 1) {
            $existing = $this->db->one('SELECT * FROM events WHERE id = ?', [$id]);
            if (!$existing) return $this->notFound();
            $newStatus = $body['status'];
            if ($transitionError = $this->validateStatusTransition($newStatus, $existing)) {
                return $transitionError;
            }
            if (in_array($newStatus, self::BOOKING_CONFIRMED_STATUSES, true)) {
                if ($conflict = $this->checkRoomConflict((int) $existing['venue_id'], $existing['date'], $existing['doors_time'], $existing['end_time'], $id)) {
                    return $conflict;
                }
            }
            $this->db->run('UPDATE events SET status = ? WHERE id = ?', [$newStatus, $id]);
            log_activity($this->db, $id, $this->userId(), 'status changed', ['status' => $newStatus]);
            $this->notifyStatusChange($id, (string) $existing['status'], $newStatus);
            $this->pushToSheet($id);
            return $this->ok(['ok' => true]);
        }
        // Allowlist of single-field partial updates so the UI can PATCH a single
        // detail (settlement doc link, walkthrough flag, etc.) without re-sending
        // every required field. Anything outside this list falls through to the
        // full-row UPDATE below.
        $partialAllowlist = [
            'settlement_doc_url' => fn ($v) => self::nullableString($v),
            'contract_url'       => fn ($v) => self::nullableString($v),
            'ticket_url'         => fn ($v) => self::nullableString($v),
            'ticket_system'      => fn ($v) => self::nullableString($v),
            'walkthrough_done'   => fn ($v) => boolish($v) ? 1 : 0,
            'deposit_amount'     => fn ($v) => self::nullableDecimal($v),
            'potential_revenue'  => fn ($v) => self::nullableDecimal($v),
        ];
        if (count($body) === 1) {
            $key = array_key_first($body);
            if (isset($partialAllowlist[$key])) {
                $coerced = $partialAllowlist[$key]($body[$key]);
                $this->db->run("UPDATE events SET {$key} = ? WHERE id = ?", [$coerced, $id]);
                log_activity($this->db, $id, $this->userId(), "field updated: {$key}");
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
            $checkVenueId = (int) ($body['venue_id'] ?? $old['venue_id']);
            $checkDate    = $body['date'] ?? $old['date'];
            $checkDoors   = date_or_null($body['doors_time'] ?? $old['doors_time'] ?? null);
            $checkEnd     = date_or_null($body['end_time']   ?? $old['end_time']   ?? null);
            if ($conflict = $this->checkRoomConflict($checkVenueId, $checkDate, $checkDoors, $checkEnd, $id)) {
                return $conflict;
            }
        }
        $slug = (($old['title'] ?? '') !== ($body['title'] ?? '') || ($old['date'] ?? '') !== ($body['date'] ?? ''))
            ? $this->uniqueSlug(($body['title'] ?? $old['title']) . '-' . ($body['date'] ?? $old['date']), $id)
            : $old['slug'];
        $wasStatus = (string) $old['status'];
        $this->db->run(
            'UPDATE events SET venue_id=?, title=?, slug=?, event_type=?, status=?, description_public=?, description_internal=?, date=?, doors_time=?, show_time=?, end_time=?, age_restriction=?, ticket_price=?, deposit_amount=?, potential_revenue=?, ticket_url=?, ticket_system=?, contract_url=?, walkthrough_done=?, settlement_doc_url=?, capacity=?, public_visibility=?, owner_user_id=?, promoter_name=?, promoter_email=?, promoter_phone=?, booker_name=?, booker_email=?, booker_phone=? WHERE id=?',
            [(int) $body['venue_id'], $body['title'], $slug, $body['event_type'], $body['status'], $body['description_public'] ?? null, $body['description_internal'] ?? null, $body['date'], date_or_null($body['doors_time'] ?? null), date_or_null($body['show_time'] ?? null), date_or_null($body['end_time'] ?? null), $body['age_restriction'] ?? null, (float) ($body['ticket_price'] ?? 0), self::nullableDecimal($body['deposit_amount'] ?? null), self::nullableDecimal($body['potential_revenue'] ?? null), self::nullableString($body['ticket_url'] ?? $old['ticket_url']), self::nullableString($body['ticket_system'] ?? $old['ticket_system']), self::nullableString($body['contract_url'] ?? $old['contract_url']), boolish($body['walkthrough_done'] ?? false) ? 1 : 0, self::nullableString($body['settlement_doc_url'] ?? $old['settlement_doc_url']), $body['capacity'] ?: null, boolish($body['public_visibility'] ?? false), $body['owner_user_id'] ?: null, self::nullableString($body['promoter_name'] ?? $old['promoter_name']), self::nullableString($body['promoter_email'] ?? $old['promoter_email']), self::nullableString($body['promoter_phone'] ?? $old['promoter_phone']), self::nullableString($body['booker_name'] ?? $old['booker_name']), self::nullableString($body['booker_email'] ?? $old['booker_email']), self::nullableString($body['booker_phone'] ?? $old['booker_phone']), $id]
        );
        if (isset($body['status']) && $body['status'] !== $wasStatus) {
            $this->notifyStatusChange($id, $wasStatus, $body['status']);
        }
        log_activity($this->db, $id, $this->userId(), 'event updated');
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
        $id = $this->db->insert(
            "INSERT INTO events (venue_id, title, slug, event_type, status, description_public, date, doors_time, show_time, age_restriction, ticket_price, owner_user_id)
             VALUES (?, ?, ?, ?, 'proposed', ?, ?, ?, ?, ?, ?, ?)",
            [(int) $template['venue_id'], $title, $this->uniqueSlug($title . '-' . $date), $template['event_type'], $template['default_description_public'], $date, ($body['doors_time'] ?? '') ?: '19:00', ($body['show_time'] ?? '') ?: '20:00', $template['default_age_restriction'], (float) $template['default_ticket_price'], $this->userId()]
        );
        $this->assignEventCode($id);
        foreach ($this->jsonList($template['checklist_json']) as $task) {
            $this->db->run('INSERT INTO event_tasks (event_id, title, priority) VALUES (?, ?, ?)', [$id, $task['title'] ?? $task, $task['priority'] ?? 'normal']);
        }
        foreach ($this->jsonList($template['schedule_json']) as $item) {
            $this->db->run('INSERT INTO event_schedule_items (event_id, title, item_type, start_time, end_time) VALUES (?, ?, ?, ?, ?)', [$id, $item['title'], $item['item_type'] ?? 'other', $item['start_time'] ?? null, $item['end_time'] ?? null]);
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
        $hasFlyer = array_filter($assets, fn ($a) => $a['asset_type'] === 'flyer' && $a['approval_status'] === 'approved');
        return match (true) {
            $event['status'] === 'proposed' => 'Confirm date, owner, and event type',
            $event['status'] === 'hold' => 'Confirm event details then advance to Intake Complete',
            $event['status'] === 'confirmed' => empty($event['contract_url'])
                ? 'Complete all intake fields then obtain signed contract to advance to Booked'
                : 'Contract on file — advance status to Booked',
            $event['status'] === 'booked' && !$hasFlyer => 'Upload or approve flyer',
            $event['status'] === 'needs_assets' => 'Complete required assets',
            $event['status'] === 'ready_to_announce' && !(int) $event['public_visibility'] => 'Publish public event page',
            $event['status'] === 'published' && !$event['ticket_url'] && (float) $event['ticket_price'] > 0 => 'Add ticketing link',
            $event['status'] === 'completed' && !$settlement => 'Complete settlement',
            default => 'Review event details',
        };
    }

    private function readiness(array $event, array $lineup, array $blockers, array $assets, ?array $settlement): array
    {
        $openBlockers = array_filter($blockers, fn ($b) => in_array($b['status'], ['open', 'waiting'], true));
        $hasApprovedFlyer = array_filter($assets, fn ($a) => $a['asset_type'] === 'flyer' && $a['approval_status'] === 'approved');
        $hasContacts = !empty($event['promoter_name']) && !empty($event['booker_name']);
        return [
            ['label' => 'Contacts', 'state' => $hasContacts ? 'On file' : 'Missing producer/booker', 'ok' => $hasContacts],
            ['label' => 'Lineup', 'state' => $lineup ? 'Ready' : 'Missing', 'ok' => (bool) $lineup],
            ['label' => 'Run sheet', 'state' => $event['doors_time'] ? 'Timed' : 'Needs doors', 'ok' => (bool) $event['doors_time']],
            ['label' => 'Open items', 'state' => $openBlockers ? count($openBlockers) . ' open' : 'Clear', 'ok' => !$openBlockers],
            ['label' => 'Flyer', 'state' => $hasApprovedFlyer ? 'Approved' : 'Needs approval', 'ok' => (bool) $hasApprovedFlyer],
            ['label' => 'Public page', 'state' => (int) $event['public_visibility'] ? 'Live' : 'Hidden', 'ok' => (bool) (int) $event['public_visibility']],
            ['label' => 'Settlement', 'state' => $settlement ? 'Saved' : 'Not started', 'ok' => $event['status'] !== 'completed' || (bool) $settlement],
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

    /**
     * Validate that all required intake fields are present before the event
     * can be advanced to confirmed (Intake Complete), booked, or beyond.
     * Returns a 422 Response if anything is missing, or null if all good.
     */
    private function validateStatusTransition(string $newStatus, array $event): ?Response
    {
        if (!in_array($newStatus, self::BOOKING_CONFIRMED_STATUSES, true)) {
            return null; // no extra checks for hold / proposed / empty / canceled
        }
        $required = [
            'doors_time'     => 'Start time (Doors)',
            'end_time'       => 'End time',
            'promoter_name'  => 'Producer/promoter name',
            'promoter_email' => 'Producer/promoter email',
            'promoter_phone' => 'Producer/promoter phone',
            'booker_name'    => 'Booker name',
            'booker_email'   => 'Booker email',
            'booker_phone'   => 'Booker phone',
        ];
        $missing = [];
        foreach ($required as $field => $label) {
            if (empty($event[$field])) {
                $missing[] = $label;
            }
        }
        if ($newStatus === 'booked' && empty($event['contract_url'])) {
            $missing[] = 'Contract URL (required before marking as Booked)';
        }
        if ($missing) {
            return Response::json([
                'error' => 'Cannot advance to "' . ucfirst(str_replace(['_', '-'], ' ', $newStatus)) . '": missing ' . implode(', ', $missing) . '.',
            ], 422);
        }
        return null;
    }

    /**
     * Check whether the given venue + date + time window conflicts with any
     * existing booking (with a 30-minute buffer between events). Returns a
     * 409 Response describing the conflict, or null if the slot is clear.
     */
    private function checkRoomConflict(int $venueId, string $date, ?string $doorsTime, ?string $endTime, ?int $excludeId = null): ?Response
    {
        $venue = $this->db->one('SELECT slug FROM venues WHERE id = ? LIMIT 1', [$venueId]);
        $slug  = $venue['slug'] ?? '';
        $ids   = [$venueId];
        // "Both rooms" booking conflicts with all floors; a single-floor booking
        // also conflicts with any "both rooms" event on the same day.
        if ($slug === 'mabuhay-both') {
            $others = $this->db->all("SELECT id FROM venues WHERE slug IN ('mabuhay-upstairs','mabuhay-gardens')");
            foreach ($others as $r) { $ids[] = (int) $r['id']; }
        } elseif (in_array($slug, ['mabuhay-upstairs', 'mabuhay-gardens'], true)) {
            $both = $this->db->one("SELECT id FROM venues WHERE slug = 'mabuhay-both' LIMIT 1");
            if ($both) $ids[] = (int) $both['id'];
        }
        $ph   = implode(',', array_fill(0, count($ids), '?'));
        $args = array_values(array_map('intval', $ids));
        $args[] = $date;
        $excl   = $excludeId ? ' AND id != ?' : '';
        if ($excludeId) $args[] = $excludeId;
        $rows = $this->db->all(
            "SELECT id, title, doors_time, end_time FROM events WHERE venue_id IN ($ph) AND date = ? AND status NOT IN ('canceled','empty')$excl",
            $args
        );
        foreach ($rows as $row) {
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

    /** Email all venue_admins when an event reaches Intake Complete or Booked. Best-effort — never throws. */
    private function notifyStatusChange(int $eventId, string $oldStatus, string $newStatus): void
    {
        if (!in_array($newStatus, ['confirmed', 'booked'], true)) return;
        try {
            $admins = $this->db->all("SELECT name, email FROM users WHERE role = 'venue_admin' AND email IS NOT NULL AND email != '' AND email NOT LIKE '%.local'");
            if (!$admins) return;
            $event = $this->db->one('SELECT title, date, promoter_name, booker_name FROM events WHERE id = ? LIMIT 1', [$eventId]);
            if (!$event) return;
            $label   = $newStatus === 'confirmed' ? 'Intake Complete' : 'Booked (contract signed)';
            $subject = "[Backstage] {$label}: {$event['title']}";
            $link    = rtrim((string) (getenv('APP_URL') ?: ''), '/') . "/#event-{$eventId}";
            $body    = '<p>Event <strong>' . htmlspecialchars((string) $event['title'], ENT_QUOTES, 'UTF-8') . '</strong>'
                     . ' (' . htmlspecialchars((string) $event['date'], ENT_QUOTES, 'UTF-8') . ') is now <strong>' . $label . '</strong>.</p>'
                     . ($event['promoter_name'] ? '<p>Producer: ' . htmlspecialchars((string) $event['promoter_name'], ENT_QUOTES, 'UTF-8') . '</p>' : '')
                     . ($event['booker_name']   ? '<p>Booked by: ' . htmlspecialchars((string) $event['booker_name'],   ENT_QUOTES, 'UTF-8') . '</p>' : '')
                     . '<p><a href="' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '">View in Backstage</a></p>';
            $mailer  = new Mailer($this->root);
            foreach ($admins as $admin) {
                $mailer->send($admin['email'], $subject, $body);
            }
        } catch (\Throwable $e) {
            @error_log("status-change notification failed for event {$eventId}: {$e->getMessage()}");
        }
    }
}
