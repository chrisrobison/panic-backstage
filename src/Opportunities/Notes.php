<?php
declare(strict_types=1);

namespace Panic\Opportunities;

use Panic\BaseEndpoint;
use Panic\Request;
use Panic\Response;

use function Panic\boolish;
use function Panic\date_or_null;
use function Panic\log_opportunity_activity;

/**
 * First-class Opportunities notes — polymorphic: one note can link a
 * conference, a company, a contact, and/or an opportunity at once (e.g. a
 * "Dreamforce 2026 Sponsorship Strategy" note that spans all four). See
 * docs/OPPORTUNITIES-IMPLEMENTATION.md §1.12/§3.1 for why this can't be a
 * single FK column like `lead_notes.lead_id`.
 *
 * Reachable two ways, both handled by this one class (see src/Kernel.php):
 *
 *   GET/POST /api/opportunities/{id}/notes[/{noteId}]              nested convenience
 *   GET/POST /api/opportunity-conferences/{id}/notes[/{noteId}]    (linkedType/linkedId
 *   GET/POST /api/opportunity-companies/{id}/notes[/{noteId}]       come from Kernel params)
 *   GET/POST/PATCH/DELETE /api/opportunity-notes[/{id}]             cross-cutting
 *     (linked_type/linked_id come from the query string on GET, the body on POST)
 *
 * `linked_type=contact` (Phase 4+) validates against `opportunity_contacts`,
 * reached through the top-level `/api/opportunity-notes` cross-cutting
 * family or an `additional_links` entry — there is no
 * `/opportunity-companies/{id}/contacts/{contactId}/notes` nested route,
 * matching every other "contact of a company" resource's flat shape.
 *
 * Capabilities: view_opportunities (read), manage_opportunities (write).
 */
final class Notes extends BaseEndpoint
{
    public const NOTE_TYPES  = ['general', 'meeting', 'call', 'research', 'internal', 'strategy'];
    public const LINKED_TYPES = ['conference', 'company', 'contact', 'opportunity'];

    public function handle(Request $request): Response
    {
        $noteId     = $this->params['noteId'] ?? null;
        $linkedType = $this->params['linkedType'] ?? null;
        $linkedId   = isset($this->params['linkedId']) ? (int) $this->params['linkedId'] : null;
        $action     = $this->params['action'] ?? null;

        if ($action === 'versions' && $noteId) {
            return $request->method() === 'GET' ? $this->versions((int) $noteId) : Response::methodNotAllowed();
        }

        return match ($request->method()) {
            'GET'    => $noteId ? $this->show((int) $noteId) : $this->index($request, $linkedType, $linkedId),
            'POST'   => $this->create($request, $linkedType, $linkedId),
            'PATCH'  => $noteId ? $this->update($request, (int) $noteId) : Response::methodNotAllowed(),
            'DELETE' => $noteId ? $this->deleteNote((int) $noteId) : Response::methodNotAllowed(),
            default  => Response::methodNotAllowed(),
        };
    }

    private function index(Request $request, ?string $linkedType, ?int $linkedId): Response
    {
        if ($denied = $this->requireGlobalCapability('view_opportunities')) {
            return $denied;
        }

        $linkedType ??= (string) $request->query('linked_type', '');
        $linkedId   ??= $request->query('linked_id') ? (int) $request->query('linked_id') : null;
        $linkedType = $linkedType !== '' ? $linkedType : null;

        if ($linkedId !== null) {
            if (!$linkedType || !in_array($linkedType, self::LINKED_TYPES, true)) {
                return Response::json(['error' => 'linked_type and linked_id are required'], 422);
            }
            return $this->scopedIndex($linkedType, $linkedId);
        }

        // No specific record given — the cross-cutting Notes workspace's
        // general search/filter mode (Phase 6). A bare linked_type (no id)
        // narrows to "any note linked to a record of this type" rather than
        // one specific record.
        return $this->generalIndex(
            $request,
            $linkedType && in_array($linkedType, self::LINKED_TYPES, true) ? $linkedType : null
        );
    }

    private function scopedIndex(string $linkedType, int $linkedId): Response
    {
        $notes = $this->db->all(
            'SELECT n.*, u.name AS created_by_name
             FROM opportunity_notes n
             JOIN opportunity_note_links l ON l.note_id = n.id
             LEFT JOIN users u ON u.id = n.created_by
             WHERE l.linked_type = ? AND l.linked_id = ?
             ORDER BY n.is_pinned DESC, n.created_at DESC
             LIMIT 200',
            [$linkedType, $linkedId]
        );

        $hydrated = $this->hydrate($notes);
        $this->attachContexts($hydrated);
        return $this->ok(['notes' => $hydrated, 'note_types' => self::NOTE_TYPES]);
    }

    /**
     * The Notes workspace's own list: every note across the whole module,
     * filterable by free-text search, type, pinned, AI-generated, author,
     * tag, date range, and (optionally) linked-record type — never scoped
     * to one specific record. Reachable only through the cross-cutting
     * `/api/opportunity-notes` family (no linked_id in the request).
     * `LIMIT 300` is a coarse cap consistent with every other list endpoint
     * in this module (real pagination is Phase 9 scope).
     */
    private function generalIndex(Request $request, ?string $linkedTypeOnly): Response
    {
        $where  = ['1=1'];
        $params = [];

        $q = trim((string) $request->query('q', ''));
        if ($q !== '') {
            $where[]  = 'n.body LIKE ?';
            $params[] = '%' . $q . '%';
        }

        $noteType = (string) $request->query('note_type', '');
        if ($noteType !== '' && in_array($noteType, self::NOTE_TYPES, true)) {
            $where[]  = 'n.note_type = ?';
            $params[] = $noteType;
        }

        $pinned = $request->query('is_pinned');
        if ($pinned === '1') {
            $where[] = 'n.is_pinned = 1';
        } elseif ($pinned === '0') {
            $where[] = 'n.is_pinned = 0';
        }

        $aiGenerated = $request->query('is_ai_generated');
        if ($aiGenerated === '1') {
            $where[] = 'n.is_ai_generated = 1';
        } elseif ($aiGenerated === '0') {
            $where[] = 'n.is_ai_generated = 0';
        }

        $createdBy = $request->query('created_by');
        if ($createdBy) {
            $where[]  = 'n.created_by = ?';
            $params[] = (int) $createdBy;
        }

        $dateFrom = date_or_null($request->query('date_from'));
        if ($dateFrom) {
            $where[]  = 'n.created_at >= ?';
            $params[] = $dateFrom;
        }
        $dateTo = date_or_null($request->query('date_to'));
        if ($dateTo) {
            $where[]  = 'n.created_at < DATE_ADD(?, INTERVAL 1 DAY)';
            $params[] = $dateTo;
        }

        $tag = trim((string) $request->query('tag', ''));
        if ($tag !== '') {
            $where[]  = 'EXISTS (SELECT 1 FROM opportunity_note_tags t WHERE t.note_id = n.id AND t.tag = ?)';
            $params[] = $tag;
        }

        if ($linkedTypeOnly !== null) {
            $where[]  = 'EXISTS (SELECT 1 FROM opportunity_note_links l2 WHERE l2.note_id = n.id AND l2.linked_type = ?)';
            $params[] = $linkedTypeOnly;
        }

        $notes = $this->db->all(
            'SELECT n.*, u.name AS created_by_name
             FROM opportunity_notes n
             LEFT JOIN users u ON u.id = n.created_by
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY n.is_pinned DESC, n.created_at DESC
             LIMIT 300',
            $params
        );

        $hydrated = $this->hydrate($notes);
        $this->attachContexts($hydrated);

        return $this->ok([
            'notes'      => $hydrated,
            'note_types' => self::NOTE_TYPES,
            // Every user who has ever authored a note — the workspace's
            // Author filter. Cheap (one small DISTINCT query), not per-note.
            'authors'    => $this->db->all(
                'SELECT DISTINCT u.id, u.name FROM users u
                 JOIN opportunity_notes n2 ON n2.created_by = u.id
                 ORDER BY u.name'
            ),
        ]);
    }

    private function show(int $id): Response
    {
        if ($denied = $this->requireGlobalCapability('view_opportunities')) {
            return $denied;
        }

        $note = $this->db->one(
            'SELECT n.*, u.name AS created_by_name FROM opportunity_notes n
             LEFT JOIN users u ON u.id = n.created_by WHERE n.id = ?',
            [$id]
        );
        if (!$note) {
            return $this->notFound('Note not found');
        }

        $hydrated = $this->hydrate([$note]);
        $this->attachContexts($hydrated);
        return $this->ok(['note' => $hydrated[0]]);
    }

    private function create(Request $request, ?string $linkedType, ?int $linkedId): Response
    {
        if ($denied = $this->requireGlobalCapability('manage_opportunities')) {
            return $denied;
        }

        $b    = $request->body();
        $body = trim((string) ($b['body'] ?? ''));
        if ($body === '') {
            return Response::json(['error' => 'body is required'], 422);
        }

        $linkedType ??= (string) ($b['linked_type'] ?? '');
        $linkedId   ??= isset($b['linked_id']) ? (int) $b['linked_id'] : null;

        $links = array_merge(
            [['type' => $linkedType, 'id' => $linkedId]],
            $this->normalizeAdditionalLinks($b['additional_links'] ?? [])
        );
        // Dedup (type, id) pairs.
        $seen = [];
        $links = array_values(array_filter($links, function ($l) use (&$seen) {
            $key = $l['type'] . ':' . $l['id'];
            if (isset($seen[$key])) {
                return false;
            }
            $seen[$key] = true;
            return true;
        }));

        if (count($links) < 1 || $links[0]['type'] === '' || !$links[0]['id']) {
            return Response::json(['error' => 'linked_type and linked_id are required'], 422);
        }
        if ($error = $this->validateLinks($links)) {
            return $error;
        }

        $noteType = (string) ($b['note_type'] ?? 'general');
        if (!in_array($noteType, self::NOTE_TYPES, true)) {
            $noteType = 'general';
        }
        $isAi = boolish($b['is_ai_generated'] ?? false);

        $id = $this->db->insert(
            'INSERT INTO opportunity_notes (body, note_type, is_pinned, is_ai_generated, ai_model, ai_prompt_version, created_by)
             VALUES (?,?,?,?,?,?,?)',
            [
                $body,
                $noteType,
                boolish($b['is_pinned'] ?? false),
                $isAi,
                $isAi ? ($b['ai_model'] ?? null) : null,
                $isAi ? ($b['ai_prompt_version'] ?? null) : null,
                $this->userId(),
            ]
        );

        foreach ($links as $link) {
            $this->db->run(
                'INSERT INTO opportunity_note_links (note_id, linked_type, linked_id) VALUES (?,?,?)',
                [$id, $link['type'], $link['id']]
            );
        }

        $this->replaceTags($id, $b['tags'] ?? null);
        $this->logIfOpportunityLinked($links, $id, 'note_added', ['note_type' => $noteType]);

        $hydrated = $this->hydrate([$this->db->one('SELECT n.*, u.name AS created_by_name FROM opportunity_notes n LEFT JOIN users u ON u.id = n.created_by WHERE n.id = ?', [$id])]);
        $this->attachContexts($hydrated);
        return $this->ok(['note' => $hydrated[0]]);
    }

    private function update(Request $request, int $id): Response
    {
        if ($denied = $this->requireGlobalCapability('manage_opportunities')) {
            return $denied;
        }

        $existing = $this->db->one('SELECT * FROM opportunity_notes WHERE id = ?', [$id]);
        if (!$existing) {
            return $this->notFound('Note not found');
        }

        $b      = $request->body();
        $sets   = [];
        $params = [];

        if (array_key_exists('body', $b)) {
            $newBody = (string) $b['body'];
            if ($newBody !== $existing['body']) {
                // Archive the PRE-edit body before overwriting it (§3.1
                // opportunity_note_versions — append-only, mirrors
                // lead_classifications' versioned-row spirit). edited_by/
                // edited_at describe who authored the version being
                // archived and when it stopped being current, not who is
                // making this edit.
                $this->db->run(
                    'INSERT INTO opportunity_note_versions (note_id, body, edited_by, edited_at) VALUES (?,?,?,?)',
                    [$id, $existing['body'], $existing['updated_by'] ?? $existing['created_by'], $existing['updated_at']]
                );
            }
            $sets[]   = '`body` = ?';
            $params[] = $newBody;
        }
        if (array_key_exists('note_type', $b)) {
            if (!in_array($b['note_type'], self::NOTE_TYPES, true)) {
                return Response::json(['error' => 'Invalid note_type'], 422);
            }
            $sets[]   = '`note_type` = ?';
            $params[] = $b['note_type'];
        }
        if (array_key_exists('is_pinned', $b)) {
            $sets[]   = '`is_pinned` = ?';
            $params[] = boolish($b['is_pinned']);
        }

        if (!empty($sets)) {
            $sets[]   = '`updated_by` = ?';
            $params[] = $this->userId();
            $params[] = $id;
            $this->db->run('UPDATE opportunity_notes SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);
        }

        if (array_key_exists('tags', $b)) {
            $this->replaceTags($id, $b['tags']);
        }

        // "Link record" / unlink actions (Phase 6) — add/remove links on an
        // EXISTING note, distinct from `additional_links` at create time.
        if (array_key_exists('add_links', $b)) {
            foreach ($this->normalizeAdditionalLinks($b['add_links']) as $link) {
                if ($error = $this->validateLinks([$link])) {
                    return $error;
                }
                $already = $this->db->one(
                    'SELECT id FROM opportunity_note_links WHERE note_id = ? AND linked_type = ? AND linked_id = ?',
                    [$id, $link['type'], $link['id']]
                );
                if (!$already) {
                    $this->db->run(
                        'INSERT INTO opportunity_note_links (note_id, linked_type, linked_id) VALUES (?,?,?)',
                        [$id, $link['type'], $link['id']]
                    );
                }
            }
        }
        if (array_key_exists('remove_links', $b)) {
            foreach ($this->normalizeAdditionalLinks($b['remove_links']) as $link) {
                $this->db->run(
                    'DELETE FROM opportunity_note_links WHERE note_id = ? AND linked_type = ? AND linked_id = ?',
                    [$id, $link['type'], $link['id']]
                );
            }
        }

        $note = $this->db->one(
            'SELECT n.*, u.name AS created_by_name FROM opportunity_notes n LEFT JOIN users u ON u.id = n.created_by WHERE n.id = ?',
            [$id]
        );
        $hydrated = $this->hydrate([$note]);
        $this->attachContexts($hydrated);
        return $this->ok(['note' => $hydrated[0]]);
    }

    /**
     * Immutable revision history — every prior body, newest first. See
     * `update()`'s archiving step above.
     */
    private function versions(int $id): Response
    {
        if ($denied = $this->requireGlobalCapability('view_opportunities')) {
            return $denied;
        }
        if (!$this->db->one('SELECT id FROM opportunity_notes WHERE id = ?', [$id])) {
            return $this->notFound('Note not found');
        }

        $versions = $this->db->all(
            'SELECT v.*, u.name AS edited_by_name
             FROM opportunity_note_versions v
             LEFT JOIN users u ON u.id = v.edited_by
             WHERE v.note_id = ?
             ORDER BY v.edited_at DESC, v.id DESC',
            [$id]
        );

        return $this->ok(['versions' => $versions]);
    }

    private function deleteNote(int $id): Response
    {
        if ($denied = $this->requireGlobalCapability('manage_opportunities')) {
            return $denied;
        }

        $links = $this->db->all('SELECT linked_type, linked_id FROM opportunity_note_links WHERE note_id = ?', [$id]);
        if (!$this->db->one('SELECT id FROM opportunity_notes WHERE id = ?', [$id])) {
            return $this->notFound('Note not found');
        }

        $this->db->run('DELETE FROM opportunity_notes WHERE id = ?', [$id]);

        $this->logIfOpportunityLinked(
            array_map(fn ($l) => ['type' => $l['linked_type'], 'id' => (int) $l['linked_id']], $links),
            null,
            'note_deleted',
            []
        );

        return Response::noContent();
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function normalizeAdditionalLinks(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $item) {
            if (!is_array($item) || empty($item['type']) || empty($item['id'])) {
                continue;
            }
            $out[] = ['type' => (string) $item['type'], 'id' => (int) $item['id']];
        }
        return $out;
    }

    private function validateLinks(array $links): ?Response
    {
        foreach ($links as $link) {
            if (!in_array($link['type'], self::LINKED_TYPES, true)) {
                return Response::json(['error'=> "Invalid linked_type: {$link['type']}"], 422);
            }
            $table = match ($link['type']) {
                'conference'  => 'opportunity_conferences',
                'company'     => 'opportunity_companies',
                'contact'     => 'opportunity_contacts',
                'opportunity' => 'opportunities',
            };
            if (!$this->db->one("SELECT id FROM `$table` WHERE id = ?", [$link['id']])) {
                return Response::json(['error' => "linked {$link['type']} {$link['id']} does not exist"], 422);
            }
        }
        return null;
    }

    private function replaceTags(int $noteId, mixed $tags): void
    {
        if ($tags === null) {
            return;
        }
        $this->db->run('DELETE FROM opportunity_note_tags WHERE note_id = ?', [$noteId]);
        if (!is_array($tags)) {
            return;
        }
        $seen = [];
        foreach ($tags as $tag) {
            $tag = trim((string) $tag);
            if ($tag === '' || isset($seen[$tag])) {
                continue;
            }
            $seen[$tag] = true;
            $this->db->run('INSERT INTO opportunity_note_tags (note_id, tag) VALUES (?, ?)', [$noteId, substr($tag, 0, 64)]);
        }
    }

    private function logIfOpportunityLinked(array $links, ?int $noteId, string $action, array $details): void
    {
        foreach ($links as $link) {
            if ($link['type'] === 'opportunity' && $link['id']) {
                $payload = $noteId ? array_merge($details, ['note_id' => $noteId]) : $details;
                log_opportunity_activity($this->db, (int) $link['id'], $this->userId(), $action, $payload);
            }
        }
    }

    /** Attach `links` and `tags` arrays to each note row. */
    private function hydrate(array $notes): array
    {
        if (empty($notes)) {
            return [];
        }
        $ids = array_column($notes, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $links = $this->db->all("SELECT * FROM opportunity_note_links WHERE note_id IN ($placeholders)", $ids);
        $tags  = $this->db->all("SELECT * FROM opportunity_note_tags WHERE note_id IN ($placeholders)", $ids);

        $linksByNote = [];
        foreach ($links as $l) {
            $linksByNote[$l['note_id']][] = ['type' => $l['linked_type'], 'id' => (int) $l['linked_id']];
        }
        $tagsByNote = [];
        foreach ($tags as $t) {
            $tagsByNote[$t['note_id']][] = $t['tag'];
        }

        foreach ($notes as &$note) {
            $note['links'] = $linksByNote[$note['id']] ?? [];
            $note['tags']  = $tagsByNote[$note['id']] ?? [];
        }
        return $notes;
    }

    /**
     * Resolves each note's `links` (type+id pairs) into human-readable
     * `contexts` (type+id+label) — e.g. so the Notes workspace can show
     * "NVIDIA · GTC DC · Jane Smith" instead of raw ids. Two bulk queries
     * per linked type actually present across the whole note set (never
     * one query per note), same batch-resolve shape as
     * Opportunities::recentNotes()'s nameMap() — this version additionally
     * covers `contact` links, which the Discover dashboard's narrower
     * "company — conference" label didn't need.
     */
    private function attachContexts(array &$notes): void
    {
        if (!$notes) {
            return;
        }

        $idsByType = [];
        foreach ($notes as $note) {
            foreach ($note['links'] as $link) {
                $idsByType[$link['type']][] = $link['id'];
            }
        }

        $tableByType = [
            'conference'  => 'opportunity_conferences',
            'company'     => 'opportunity_companies',
            'opportunity' => 'opportunities',
            'contact'     => 'opportunity_contacts',
        ];
        $names = [];
        foreach ($tableByType as $type => $table) {
            if (empty($idsByType[$type])) {
                continue;
            }
            $ids = array_values(array_unique($idsByType[$type]));
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            foreach ($this->db->all("SELECT id, name FROM `$table` WHERE id IN ($placeholders)", $ids) as $row) {
                $names[$type][(int) $row['id']] = $row['name'];
            }
        }

        foreach ($notes as &$note) {
            $contexts = [];
            foreach ($note['links'] as $link) {
                $label = $names[$link['type']][$link['id']] ?? null;
                // A link whose target has since been deleted (no SQL FK
                // covers `linked_id` — it's polymorphic) simply resolves to
                // nothing rather than a broken entry.
                if ($label !== null) {
                    $contexts[] = ['type' => $link['type'], 'id' => $link['id'], 'label' => $label];
                }
            }
            $note['contexts'] = $contexts;
        }
        unset($note);
    }
}
