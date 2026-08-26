<?php
declare(strict_types=1);

namespace Panic\Opportunities;

use Panic\BaseEndpoint;
use Panic\Request;
use Panic\Response;

use function Panic\boolish;
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
    public const NOTE_TYPES  = ['general', 'meeting', 'call', 'research', 'internal'];
    public const LINKED_TYPES = ['conference', 'company', 'contact', 'opportunity'];

    public function handle(Request $request): Response
    {
        $noteId     = $this->params['noteId'] ?? null;
        $linkedType = $this->params['linkedType'] ?? null;
        $linkedId   = isset($this->params['linkedId']) ? (int) $this->params['linkedId'] : null;

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

        if (!in_array($linkedType, self::LINKED_TYPES, true) || !$linkedId) {
            return Response::json(['error' => 'linked_type and linked_id are required'], 422);
        }

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

        return $this->ok(['notes' => $this->hydrate($notes), 'note_types' => self::NOTE_TYPES]);
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

        return $this->ok(['note' => $this->hydrate([$note])[0]]);
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

        return $this->ok(['note' => $this->hydrate([$this->db->one('SELECT n.*, u.name AS created_by_name FROM opportunity_notes n LEFT JOIN users u ON u.id = n.created_by WHERE n.id = ?', [$id])])[0]]);
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
            $sets[]   = '`body` = ?';
            $params[] = (string) $b['body'];
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
            $params[] = $id;
            $this->db->run('UPDATE opportunity_notes SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);
        }

        if (array_key_exists('tags', $b)) {
            $this->replaceTags($id, $b['tags']);
        }

        $note = $this->db->one(
            'SELECT n.*, u.name AS created_by_name FROM opportunity_notes n LEFT JOIN users u ON u.id = n.created_by WHERE n.id = ?',
            [$id]
        );
        return $this->ok(['note' => $this->hydrate([$note])[0]]);
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
}
