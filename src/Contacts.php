<?php
declare(strict_types=1);

namespace Panic;

use function Panic\boolish;

/**
 * Marketing / CRM contacts — the audience that buys tickets and receives event
 * emails. Seeded from the ticketing provider's "Fan View" export
 * (scripts/import-fanview.php) and manageable in-app.
 *
 *   GET    /api/contacts                 list (?q= &opted=0|1 &sort= &dir= &page= &limit=)
 *   GET    /api/contacts/{id}            show one (includes assigned tags)
 *   GET    /api/contacts/{id}/lists      which mailing lists this contact belongs to
 *   GET    /api/contacts/{id}/activity   audit trail (list joins/leaves, tag changes, edits — see log_contact_activity())
 *   GET    /api/contacts/{id}/tags       tags assigned to this contact
 *   POST   /api/contacts/{id}/tags       assign a tag {tag_id} or {name} (creates the tag if `name` doesn't exist yet)
 *   DELETE /api/contacts/{id}/tags/{tagId} unassign
 *   POST   /api/contacts/bulk-tag        {contact_ids: int[], tag_id | name} assign one tag to many contacts at once
 *   POST   /api/contacts                 create (manual)
 *   PATCH  /api/contacts/{id}            update editable fields
 *   DELETE /api/contacts/{id}            delete
 *
 * Gated by the manage_contacts global capability (venue_admin). Note: writes
 * to list membership (add/toggle/remove) are NOT duplicated here — the
 * contact-side "Mailing Lists" UI calls MailingLists' own member endpoints
 * directly (gated by manage_campaigns), so there is exactly one place that
 * writes list_membership rows. Tag *definitions* (name/color) similarly live
 * in ContactTags.php — this class only writes the per-contact assignment.
 */
final class Contacts extends BaseEndpoint
{
    private const SORTS = [
        'last_name'        => 'last_name',
        'first_name'       => 'first_name',
        'email'            => 'email',
        'usd_spend'        => 'usd_spend',
        'tickets_count'    => 'tickets_count',
        'events_count'     => 'events_count',
        'last_interaction' => 'last_interaction',
        'created_at'       => 'created_at',
    ];

    public function handle(Request $request): Response
    {
        if ($denied = $this->requireGlobalCapability('manage_contacts')) {
            return $denied;
        }
        $id     = $this->params['contactId'] ?? null;
        $action = $this->params['action'] ?? null;
        $subId  = $this->params['subId'] ?? null;

        if ($id === null && $action === 'bulk-tag' && $request->method() === 'POST') {
            return $this->bulkTag($request);
        }
        if ($id && $action === 'lists' && $request->method() === 'GET') {
            return $this->contactLists((int) $id);
        }
        if ($id && $action === 'activity' && $request->method() === 'GET') {
            return $this->activity((int) $id);
        }
        if ($id && $action === 'tags') {
            return match ($request->method()) {
                'GET'    => $this->listTags((int) $id),
                'POST'   => $this->assignTag($request, (int) $id),
                'DELETE' => $subId ? $this->unassignTag((int) $id, (int) $subId) : Response::json(['error' => 'tagId is required'], 422),
                default  => Response::methodNotAllowed(),
            };
        }

        return match ($request->method()) {
            'GET'    => $id ? $this->show((int) $id) : $this->index($request),
            'POST'   => $this->create($request),
            'PATCH'  => $this->update($request, (int) $id),
            'DELETE' => $this->delete((int) $id),
            default  => Response::methodNotAllowed(),
        };
    }

    private function index(Request $request): Response
    {
        // Also accepts min_spend/min_events/min_tickets (see ContactFilters) —
        // not surfaced in the Contacts page UI yet, but shared with the
        // mailing-list bulk-add/segment-list filter criteria so both sides
        // stay in lockstep with a single WHERE-building implementation.
        ['where' => $whereSql, 'params' => $params] = ContactFilters::buildWhere($request->query());

        $sortKey = (string) $request->query('sort');
        $sortCol = self::SORTS[$sortKey] ?? 'last_name';
        $dir = strtolower((string) $request->query('dir')) === 'desc' ? 'DESC' : 'ASC';
        // Stable, sensible secondary ordering.
        $orderSql = $sortCol === 'last_name'
            ? "last_name {$dir}, first_name {$dir}"
            : "{$sortCol} {$dir}, last_name ASC";

        $limit = (int) ($request->query('limit') ?: 50);
        $limit = max(1, min(200, $limit));
        $page = max(1, (int) ($request->query('page') ?: 1));
        $offset = ($page - 1) * $limit;

        $total = (int) ($this->db->one("SELECT COUNT(*) n FROM contacts{$whereSql}", $params)['n'] ?? 0);
        $contacts = $this->db->all(
            "SELECT * FROM contacts{$whereSql} ORDER BY {$orderSql} LIMIT {$limit} OFFSET {$offset}",
            $params
        );

        // Overall KPIs across the whole table (independent of the active filter).
        $stats = $this->db->one('SELECT COUNT(*) total, SUM(marketing_opted_in) opted_in, COALESCE(SUM(usd_spend),0) total_spend, COALESCE(SUM(tickets_count),0) total_tickets FROM contacts');

        return $this->ok([
            'contacts' => $contacts,
            'total'    => $total,
            'page'     => $page,
            'limit'    => $limit,
            'pages'    => (int) ceil($total / $limit),
            'sort'     => ['key' => array_search($sortCol, self::SORTS, true) ?: 'last_name', 'dir' => strtolower($dir)],
            'stats'    => [
                'total'         => (int) ($stats['total'] ?? 0),
                'opted_in'      => (int) ($stats['opted_in'] ?? 0),
                'total_spend'   => (float) ($stats['total_spend'] ?? 0),
                'total_tickets' => (int) ($stats['total_tickets'] ?? 0),
            ],
        ]);
    }

    private function show(int $id): Response
    {
        $row = $this->db->one('SELECT * FROM contacts WHERE id = ?', [$id]);
        if (!$row) {
            return $this->notFound('Contact not found');
        }
        $row['tags'] = $this->db->all(
            'SELECT ct.id, ct.name, ct.color FROM contact_tag_assignments cta
             JOIN contact_tags ct ON ct.id = cta.tag_id
             WHERE cta.contact_id = ? ORDER BY ct.name',
            [$id]
        );
        return $this->ok(['contact' => $row]);
    }

    /** GET /contacts/{id}/activity — the contact's audit trail (see log_contact_activity()). */
    private function activity(int $id): Response
    {
        $contact = $this->db->one('SELECT id FROM contacts WHERE id = ?', [$id]);
        if (!$contact) {
            return $this->notFound('Contact not found');
        }
        $rows = $this->db->all(
            'SELECT ca.*, u.name AS user_name
             FROM contact_activity ca
             LEFT JOIN users u ON u.id = ca.user_id
             WHERE ca.contact_id = ?
             ORDER BY ca.created_at DESC, ca.id DESC
             LIMIT 200',
            [$id]
        );
        $rows = array_map(static function (array $r): array {
            $r['details'] = $r['details_json'] !== null ? (json_decode((string) $r['details_json'], true) ?: null) : null;
            unset($r['details_json']);
            return $r;
        }, $rows);
        return $this->ok(['activity' => $rows]);
    }

    private function listTags(int $id): Response
    {
        $contact = $this->db->one('SELECT id FROM contacts WHERE id = ?', [$id]);
        if (!$contact) {
            return $this->notFound('Contact not found');
        }
        $tags = $this->db->all(
            'SELECT ct.id, ct.name, ct.color FROM contact_tag_assignments cta
             JOIN contact_tags ct ON ct.id = cta.tag_id
             WHERE cta.contact_id = ? ORDER BY ct.name',
            [$id]
        );
        return $this->ok(['tags' => $tags]);
    }

    /** Assign a tag by {tag_id}, or by {name} (creating the tag definition on first use). */
    private function assignTag(Request $request, int $id): Response
    {
        $contact = $this->db->one('SELECT id, first_name, last_name, email FROM contacts WHERE id = ?', [$id]);
        if (!$contact) {
            return $this->notFound('Contact not found');
        }

        $tag = $this->resolveOrCreateTag($request);
        if ($tag instanceof Response) {
            return $tag;
        }

        $this->db->run(
            'INSERT IGNORE INTO contact_tag_assignments (contact_id, tag_id) VALUES (?, ?)',
            [$id, $tag['id']]
        );
        log_contact_activity($this->db, $id, $this->userId(), 'tag_added', 'Tagged "' . $tag['name'] . '"', ['tag_id' => $tag['id']]);

        return $this->listTags($id);
    }

    private function unassignTag(int $id, int $tagId): Response
    {
        $tag = $this->db->one('SELECT id, name FROM contact_tags WHERE id = ?', [$tagId]);
        if (!$tag) {
            return $this->notFound('Tag not found');
        }
        $this->db->run('DELETE FROM contact_tag_assignments WHERE contact_id = ? AND tag_id = ?', [$id, $tagId]);
        log_contact_activity($this->db, $id, $this->userId(), 'tag_removed', 'Removed tag "' . $tag['name'] . '"', ['tag_id' => $tagId]);
        return $this->listTags($id);
    }

    /**
     * "Assign Tags" bulk action in the ListMaster member table — one tag onto
     * many contacts in one request, same {tag_id}|{name} resolution as
     * assignTag(). Skips (does not error on) contact ids that don't exist,
     * matching MailingLists::addMembers()' tolerant style for bulk ops driven
     * by a checkbox selection.
     */
    private function bulkTag(Request $request): Response
    {
        $contactIds = $request->body('contact_ids');
        if (!is_array($contactIds) || $contactIds === []) {
            return Response::json(['error' => 'contact_ids must be a non-empty array'], 422);
        }
        $contactIds = array_values(array_unique(array_filter(array_map(
            static fn ($v) => is_numeric($v) ? (int) $v : null,
            $contactIds
        ), static fn ($v) => $v !== null && $v > 0)));
        if ($contactIds === []) {
            return Response::json(['error' => 'contact_ids must be a non-empty array of integers'], 422);
        }

        $tag = $this->resolveOrCreateTag($request);
        if ($tag instanceof Response) {
            return $tag;
        }

        $placeholders = implode(',', array_fill(0, count($contactIds), '?'));
        $validIds = array_map(
            static fn ($r) => (int) $r['id'],
            $this->db->all("SELECT id FROM contacts WHERE id IN ({$placeholders})", $contactIds)
        );

        $rowSql = [];
        $params = [];
        foreach ($validIds as $cid) {
            $rowSql[] = '(?, ?)';
            array_push($params, $cid, $tag['id']);
        }
        if ($rowSql !== []) {
            $this->db->run('INSERT IGNORE INTO contact_tag_assignments (contact_id, tag_id) VALUES ' . implode(', ', $rowSql), $params);
            foreach ($validIds as $cid) {
                log_contact_activity($this->db, $cid, $this->userId(), 'tag_added', 'Tagged "' . $tag['name'] . '"', ['tag_id' => $tag['id']]);
            }
        }

        return $this->ok(['tagged' => count($validIds), 'skipped' => count($contactIds) - count($validIds), 'tag' => $tag]);
    }

    /** @return array{id:int,name:string,color:string}|Response */
    private function resolveOrCreateTag(Request $request): array|Response
    {
        $tagId = $request->body('tag_id');
        if ($tagId !== null && is_numeric($tagId)) {
            $tag = $this->db->one('SELECT id, name, color FROM contact_tags WHERE id = ?', [(int) $tagId]);
            if (!$tag) {
                return Response::json(['error' => 'Tag not found'], 422);
            }
            return $tag;
        }

        $name = trim((string) $request->body('name', ''));
        if ($name === '') {
            return Response::json(['error' => 'tag_id or name is required'], 422);
        }
        $existing = $this->db->one('SELECT id, name, color FROM contact_tags WHERE name = ?', [$name]);
        if ($existing) {
            return $existing;
        }
        $newId = $this->db->insert('INSERT INTO contact_tags (name, color) VALUES (?, ?)', [$name, '#2563eb']);
        return ['id' => $newId, 'name' => $name, 'color' => '#2563eb'];
    }

    /**
     * Read-only: which mailing lists this contact is on. Writes go through
     * MailingLists' own /members endpoints (see class docblock) — this just
     * lets the Contacts UI show/pick from that data without a second copy of
     * the membership-editing logic.
     */
    private function contactLists(int $id): Response
    {
        $contact = $this->db->one('SELECT id FROM contacts WHERE id = ?', [$id]);
        if (!$contact) {
            return $this->notFound('Contact not found');
        }

        $memberships = $this->db->all(
            'SELECT ml.id AS list_id, ml.name AS list_name, ml.list_type,
                    lm.status, lm.added_at, lm.added_via
             FROM list_membership lm
             JOIN mailing_lists ml ON ml.id = lm.list_id
             WHERE lm.contact_id = ?
             ORDER BY ml.name',
            [$id]
        );

        return $this->ok([
            'memberships' => $memberships,
            'can_manage'  => $this->hasGlobalCapability('manage_campaigns'),
        ]);
    }

    private function create(Request $request): Response
    {
        [$payload, $error] = $this->payload($request);
        if ($error) return $error;
        $id = $this->db->insert(
            'INSERT INTO contacts (source, first_name, last_name, email, phone, gender, birthday, marketing_opted_in, opt_in_date, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                'manual',
                $payload['first_name'], $payload['last_name'], $payload['email'], $payload['phone'],
                $payload['gender'], $payload['birthday'], $payload['marketing_opted_in'],
                $payload['marketing_opted_in'] ? date('Y-m-d') : null, $payload['notes'],
            ]
        );
        log_contact_activity($this->db, $id, $this->userId(), 'contact_created', 'Contact created');
        return $this->ok(['id' => $id]);
    }

    private function update(Request $request, int $id): Response
    {
        if (!$id) return $this->notFound();
        $existing = $this->db->one('SELECT id, marketing_opted_in, opt_in_date FROM contacts WHERE id = ?', [$id]);
        if (!$existing) return $this->notFound('Contact not found');
        [$payload, $error] = $this->payload($request);
        if ($error) return $error;
        // Stamp an opt-in date the first time someone is opted in.
        $optDate = $existing['opt_in_date'];
        if ($payload['marketing_opted_in'] && !(int) $existing['marketing_opted_in'] && !$optDate) {
            $optDate = date('Y-m-d');
        }
        $this->db->run(
            'UPDATE contacts SET first_name=?, last_name=?, email=?, phone=?, gender=?, birthday=?, marketing_opted_in=?, opt_in_date=?, notes=? WHERE id=?',
            [
                $payload['first_name'], $payload['last_name'], $payload['email'], $payload['phone'],
                $payload['gender'], $payload['birthday'], $payload['marketing_opted_in'], $optDate,
                $payload['notes'], $id,
            ]
        );
        log_contact_activity($this->db, $id, $this->userId(), 'contact_updated', 'Contact details updated');
        return $this->ok(['ok' => true]);
    }

    private function delete(int $id): Response
    {
        if (!$id) return $this->notFound();
        $this->db->run('DELETE FROM contacts WHERE id = ?', [$id]);
        return Response::noContent();
    }

    /** @return array{0: array, 1: ?Response} */
    private function payload(Request $request): array
    {
        $first = trim((string) $request->body('first_name', ''));
        $last  = trim((string) $request->body('last_name', ''));
        if ($first === '' && $last === '') {
            return [[], Response::json(['error' => 'A first or last name is required'], 422)];
        }
        $email = trim((string) $request->body('email', ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return [[], Response::json(['error' => 'Invalid email'], 422)];
        }
        $birthday = trim((string) $request->body('birthday', ''));
        $birthday = ($birthday !== '' && ($ts = strtotime($birthday)) !== false) ? date('Y-m-d', $ts) : null;

        return [[
            'first_name'         => $first ?: null,
            'last_name'          => $last ?: null,
            'email'              => $email !== '' ? strtolower($email) : null,
            'phone'              => trim((string) $request->body('phone', '')) ?: null,
            'gender'             => trim((string) $request->body('gender', '')) ?: null,
            'birthday'           => $birthday,
            'marketing_opted_in' => boolish($request->body('marketing_opted_in', 0)) ? 1 : 0,
            'notes'              => trim((string) $request->body('notes', '')) ?: null,
        ], null];
    }
}
