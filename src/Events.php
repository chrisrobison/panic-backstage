<?php
declare(strict_types=1);

namespace Panic;

use function Panic\boolish;
use function Panic\date_or_null;
use function Panic\log_activity;
use function Panic\slugify;

final class Events extends BaseEndpoint
{
    private const STATUSES = ['empty','proposed','hold','confirmed','needs_assets','ready_to_announce','published','advanced','completed','settled','canceled'];
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
        $id = $this->db->insert(
            'INSERT INTO events (venue_id, title, slug, event_type, status, description_public, description_internal, date, doors_time, show_time, end_time, age_restriction, ticket_price, ticket_url, capacity, public_visibility, owner_user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [(int) $body['venue_id'], $body['title'], $slug, $body['event_type'], $body['status'] ?? 'proposed', $body['description_public'] ?? null, $body['description_internal'] ?? null, $body['date'], date_or_null($body['doors_time'] ?? null), date_or_null($body['show_time'] ?? null), date_or_null($body['end_time'] ?? null), $body['age_restriction'] ?? null, (float) ($body['ticket_price'] ?? 0), $body['ticket_url'] ?? null, $body['capacity'] ?: null, boolish($body['public_visibility'] ?? false), $body['owner_user_id'] ?: $this->userId()]
        );
        log_activity($this->db, $id, $this->userId(), 'event created', ['title' => $body['title']]);
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
            $this->db->run('UPDATE events SET status = ? WHERE id = ?', [$body['status'], $id]);
            log_activity($this->db, $id, $this->userId(), 'status changed', ['status' => $body['status']]);
            return $this->ok(['ok' => true]);
        }
        $old = $this->db->one('SELECT * FROM events WHERE id = ?', [$id]);
        if (!$old) {
            return $this->notFound();
        }
        $slug = (($old['title'] ?? '') !== ($body['title'] ?? '') || ($old['date'] ?? '') !== ($body['date'] ?? ''))
            ? $this->uniqueSlug(($body['title'] ?? $old['title']) . '-' . ($body['date'] ?? $old['date']), $id)
            : $old['slug'];
        $this->db->run(
            'UPDATE events SET venue_id=?, title=?, slug=?, event_type=?, status=?, description_public=?, description_internal=?, date=?, doors_time=?, show_time=?, end_time=?, age_restriction=?, ticket_price=?, ticket_url=?, capacity=?, public_visibility=?, owner_user_id=? WHERE id=?',
            [(int) $body['venue_id'], $body['title'], $slug, $body['event_type'], $body['status'], $body['description_public'] ?? null, $body['description_internal'] ?? null, $body['date'], date_or_null($body['doors_time'] ?? null), date_or_null($body['show_time'] ?? null), date_or_null($body['end_time'] ?? null), $body['age_restriction'] ?? null, (float) ($body['ticket_price'] ?? 0), $body['ticket_url'] ?? null, $body['capacity'] ?: null, boolish($body['public_visibility'] ?? false), $body['owner_user_id'] ?: null, $id]
        );
        log_activity($this->db, $id, $this->userId(), 'event updated');
        return $this->ok(['id' => $id]);
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
        foreach ($this->jsonList($template['checklist_json']) as $task) {
            $this->db->run('INSERT INTO event_tasks (event_id, title, priority) VALUES (?, ?, ?)', [$id, $task['title'] ?? $task, $task['priority'] ?? 'normal']);
        }
        foreach ($this->jsonList($template['schedule_json']) as $item) {
            $this->db->run('INSERT INTO event_schedule_items (event_id, title, item_type, start_time, end_time) VALUES (?, ?, ?, ?, ?)', [$id, $item['title'], $item['item_type'] ?? 'other', $item['start_time'] ?? null, $item['end_time'] ?? null]);
        }
        log_activity($this->db, $id, $this->userId(), 'event created from template', ['template_id' => $templateId]);
        return $this->ok(['id' => $id]);
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
            $event['status'] === 'hold' => 'Confirm event or release hold',
            $event['status'] === 'confirmed' && !$hasFlyer => 'Upload or approve flyer',
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
        return [
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
}
