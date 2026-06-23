<?php
declare(strict_types=1);

namespace Panic;

use function Panic\date_or_null;
use function Panic\boolish;

/**
 * Client / promoter CRM profiles.
 *
 *   GET    /api/crm-profiles                  list profiles
 *   POST   /api/crm-profiles                  create
 *   GET    /api/crm-profiles/{id}             detail with event history + notes
 *   PATCH  /api/crm-profiles/{id}             update
 *   DELETE /api/crm-profiles/{id}             delete (admin only)
 *   POST   /api/crm-profiles/{id}/notes       add note/task
 *   PATCH  /api/crm-profiles/{id}/notes/{nid} update note
 *   DELETE /api/crm-profiles/{id}/notes/{nid} delete note
 *   POST   /api/crm-profiles/{id}/link-event  link to an event
 *
 * Capability: manage_crm_profiles (global, venue_admin only)
 */
final class CrmProfiles extends BaseEndpoint
{
    private const TYPES              = ['promoter','client','artist','company','venue','other'];
    private const RELATIONSHIP_STATUSES = ['prospect','active','paused','ended','vip'];
    private const REVENUE_TIERS      = ['unknown','low','medium','high','vip'];
    private const REBOOK             = ['unknown','unlikely','possible','likely','confirmed'];

    public function handle(Request $request): Response
    {
        if ($denied = $this->requireGlobalCapability('manage_crm_profiles')) {
            return $denied;
        }

        $profileId = $this->params['profileId'] ?? null;
        $child     = $this->params['child']     ?? null;
        $childId   = $this->params['childId']   ?? null;

        if ($child === 'notes') {
            return $this->handleNotes($request, (int) $profileId, $childId ? (int) $childId : null);
        }

        if ($child === 'link-event' && $request->method() === 'POST') {
            return $this->linkEvent($request, (int) $profileId);
        }

        return match ($request->method()) {
            'GET'    => $profileId ? $this->show((int) $profileId) : $this->index($request),
            'POST'   => $this->create($request),
            'PATCH'  => $this->update($request, (int) $profileId),
            'DELETE' => $this->deleteProfile((int) $profileId),
            default  => Response::methodNotAllowed(),
        };
    }

    private function index(Request $request): Response
    {
        $where  = ['1=1'];
        $params = [];

        if ($request->query('status')) {
            $where[]  = 'cp.relationship_status = ?';
            $params[] = $request->query('status');
        }

        if ($request->query('type')) {
            $where[]  = 'cp.type = ?';
            $params[] = $request->query('type');
        }

        $profiles = $this->db->all(
            "SELECT cp.*, u.name relationship_owner_name
             FROM client_profiles cp
             LEFT JOIN users u ON u.id = cp.relationship_owner_id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY cp.last_event_date DESC, cp.name",
            $params
        );

        return $this->ok([
            'profiles'     => $profiles,
            'types'        => self::TYPES,
            'statuses'     => self::RELATIONSHIP_STATUSES,
            'revenue_tiers' => self::REVENUE_TIERS,
        ]);
    }

    private function show(int $id): Response
    {
        $profile = $this->db->one(
            "SELECT cp.*, u.name relationship_owner_name
             FROM client_profiles cp
             LEFT JOIN users u ON u.id = cp.relationship_owner_id
             WHERE cp.id = ?",
            [$id]
        );
        if (!$profile) {
            return $this->notFound('Profile not found');
        }

        $events = $this->db->all(
            "SELECT ce.*, e.title event_title, e.date event_date, e.status event_status,
                    e.event_type
             FROM client_events ce
             JOIN events e ON e.id = ce.event_id
             WHERE ce.profile_id = ?
             ORDER BY e.date DESC",
            [$id]
        );

        $notes = $this->db->all(
            "SELECT n.*, u.name user_name FROM client_notes n
             LEFT JOIN users u ON u.id = n.user_id
             WHERE n.profile_id = ? ORDER BY n.created_at DESC",
            [$id]
        );

        return $this->ok([
            'profile' => $profile,
            'events'  => $events,
            'notes'   => $notes,
        ]);
    }

    private function create(Request $request): Response
    {
        $b = $request->body();

        $type = (string) ($b['type'] ?? 'client');
        if (!in_array($type, self::TYPES, true)) {
            $type = 'client';
        }

        $id = $this->db->insert(
            'INSERT INTO client_profiles
             (type, name, org_name, email, phone, website, instagram_url,
              relationship_owner_id, relationship_status, revenue_tier, rebook_potential,
              preferred_room, preferred_event_types, tags, notes,
              consent_marketing, consent_date, contact_id, created_by_id)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [
                $type,
                trim((string) ($b['name'] ?? 'Unknown')),
                $b['org_name']           ?? null,
                $b['email']              ?? null,
                $b['phone']              ?? null,
                $b['website']            ?? null,
                $b['instagram_url']      ?? null,
                isset($b['relationship_owner_id']) ? (int) $b['relationship_owner_id'] : $this->userId(),
                in_array($b['relationship_status'] ?? '', self::RELATIONSHIP_STATUSES, true) ? $b['relationship_status'] : 'prospect',
                in_array($b['revenue_tier'] ?? '', self::REVENUE_TIERS, true) ? $b['revenue_tier'] : 'unknown',
                in_array($b['rebook_potential'] ?? '', self::REBOOK, true) ? $b['rebook_potential'] : 'unknown',
                $b['preferred_room']     ?? null,
                $b['preferred_event_types'] ?? null,
                $b['tags']               ?? null,
                $b['notes']              ?? null,
                boolish($b['consent_marketing'] ?? false),
                $b['consent_marketing'] ? date('Y-m-d') : null,
                isset($b['contact_id']) ? (int) $b['contact_id'] : null,
                $this->userId(),
            ]
        );

        return $this->ok(['id' => $id]);
    }

    private function update(Request $request, int $id): Response
    {
        if (!$this->db->one('SELECT id FROM client_profiles WHERE id = ?', [$id])) {
            return $this->notFound();
        }

        $b      = $request->body();
        $sets   = [];
        $params = [];

        $fields = [
            'type','name','org_name','email','phone','website','instagram_url',
            'relationship_owner_id','relationship_status','revenue_tier','rebook_potential',
            'preferred_room','preferred_event_types','tags','notes',
            'consent_marketing','consent_date',
        ];

        foreach ($fields as $f) {
            if (!array_key_exists($f, $b)) continue;
            $sets[]   = "$f = ?";
            $params[] = $b[$f];
        }

        if (empty($sets)) {
            return $this->ok(['ok' => true]);
        }

        $params[] = $id;
        $this->db->run('UPDATE client_profiles SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);

        return $this->ok(['ok' => true]);
    }

    private function deleteProfile(int $id): Response
    {
        if (!$this->isVenueAdmin()) {
            return $this->forbidden('Only venue admins can delete CRM profiles');
        }
        $this->db->run('DELETE FROM client_profiles WHERE id = ?', [$id]);
        return Response::noContent();
    }

    private function handleNotes(Request $request, int $profileId, ?int $noteId): Response
    {
        if (!$this->db->one('SELECT id FROM client_profiles WHERE id = ?', [$profileId])) {
            return $this->notFound('Profile not found');
        }

        if ($request->method() === 'POST' && !$noteId) {
            $b = $request->body();
            $id = $this->db->insert(
                'INSERT INTO client_notes (profile_id, user_id, type, body, due_date) VALUES (?,?,?,?,?)',
                [
                    $profileId,
                    $this->userId(),
                    in_array($b['type'] ?? '', ['note','task','followup','communication','audit'], true) ? $b['type'] : 'note',
                    (string) ($b['body'] ?? ''),
                    date_or_null($b['due_date'] ?? null),
                ]
            );
            return $this->ok(['id' => $id]);
        }

        if ($request->method() === 'PATCH' && $noteId) {
            $b = $request->body();
            $this->db->run(
                'UPDATE client_notes SET body = COALESCE(?, body), is_done = COALESCE(?, is_done) WHERE id = ? AND profile_id = ?',
                [$b['body'] ?? null, isset($b['is_done']) ? boolish($b['is_done']) : null, $noteId, $profileId]
            );
            return $this->ok(['ok' => true]);
        }

        if ($request->method() === 'DELETE' && $noteId) {
            $this->db->run('DELETE FROM client_notes WHERE id = ? AND profile_id = ?', [$noteId, $profileId]);
            return Response::noContent();
        }

        return Response::methodNotAllowed();
    }

    private function linkEvent(Request $request, int $profileId): Response
    {
        $b       = $request->body();
        $eventId = (int) ($b['event_id'] ?? 0);
        if (!$eventId) {
            return Response::json(['error' => 'event_id required'], 422);
        }

        $this->db->run(
            'INSERT IGNORE INTO client_events (profile_id, event_id, role, revenue)
             VALUES (?,?,?,?)',
            [
                $profileId,
                $eventId,
                in_array($b['role'] ?? '', ['client','promoter','artist','co_promoter','other'], true) ? $b['role'] : 'client',
                isset($b['revenue']) ? (float) $b['revenue'] : null,
            ]
        );

        // Update aggregate counters on the profile
        $this->updateProfileAggregates($profileId);

        return $this->ok(['ok' => true]);
    }

    /**
     * Called after settlement/closeout to auto-create follow-up tasks.
     * Creates tasks in client_notes for thank-you, feedback, rebooking, etc.
     */
    public static function createFollowupTasks(
        Database $db,
        int $eventId,
        int $profileId,
        ?int $assignedUserId
    ): void {
        $tasks = [
            ['type' => 'followup', 'body' => 'Send thank-you message to client/promoter', 'days' => 1],
            ['type' => 'task',     'body' => 'Request feedback / satisfaction check-in',  'days' => 3],
            ['type' => 'task',     'body' => 'Discuss rebooking interest',                'days' => 14],
            ['type' => 'task',     'body' => 'Request testimonial or review (if applicable)', 'days' => 7],
        ];

        foreach ($tasks as $t) {
            $due = date('Y-m-d', strtotime("+{$t['days']} days"));
            $db->run(
                'INSERT INTO client_notes (profile_id, user_id, type, body, due_date) VALUES (?,?,?,?,?)',
                [$profileId, $assignedUserId, $t['type'], $t['body'] . " (Event #$eventId)", $due]
            );
        }
    }

    private function updateProfileAggregates(int $profileId): void
    {
        $agg = $this->db->one(
            "SELECT COUNT(*) event_count,
                    COALESCE(SUM(ce.revenue), 0) total_revenue,
                    MAX(e.date) last_event_date
             FROM client_events ce
             JOIN events e ON e.id = ce.event_id
             WHERE ce.profile_id = ?",
            [$profileId]
        );
        if ($agg) {
            $this->db->run(
                'UPDATE client_profiles SET event_count=?, total_revenue=?, last_event_date=? WHERE id=?',
                [$agg['event_count'], $agg['total_revenue'], $agg['last_event_date'], $profileId]
            );
        }
    }
}
