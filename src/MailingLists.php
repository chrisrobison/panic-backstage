<?php
declare(strict_types=1);

namespace Panic;

/**
 * Reusable, named mailing lists layered on top of `contacts` — the audience
 * targeting mechanism for email campaigns (see `src/Campaigns.php`).
 *
 *   GET    /api/mailing-lists                        list (?q=)
 *   GET    /api/mailing-lists/{id}                    show one (+ member/active/unsubscribed/bounced counts)
 *   POST   /api/mailing-lists                         create {name, description, list_type?, segment_rules?}
 *   PATCH  /api/mailing-lists/{id}                     update {name?, description?, segment_rules?}
 *   DELETE /api/mailing-lists/{id}                     delete (membership cascades)
 *   GET    /api/mailing-lists/{id}/members             list members (?q=&status=&tag=&page=&limit=) — each row includes its tags + how many lists it's on
 *   POST   /api/mailing-lists/{id}/members             {contact_ids: int[]} bulk upsert-subscribe
 *   PATCH  /api/mailing-lists/{id}/members             {contact_ids: int[], status} bulk status change (subscribe/unsubscribe/mark bounced)
 *   DELETE /api/mailing-lists/{id}/members             {contact_ids: int[]} bulk remove membership rows entirely
 *   PATCH  /api/mailing-lists/{id}/members/{contactId} {status: subscribed|unsubscribed|bounced}
 *   DELETE /api/mailing-lists/{id}/members/{contactId} remove the membership row entirely
 *   GET    /api/mailing-lists/{id}/export-members      CSV of every matching member (?q=&status=&tag=, same filters as the members list — unpaginated)
 *   POST   /api/mailing-lists/{id}/add-by-filter       {q?, opted?, min_spend?, min_events?, min_tickets?} add every matching contact
 *   POST   /api/mailing-lists/{id}/import              multipart CSV upload (field "csv") — create/match contacts by email, add to list
 *   POST   /api/mailing-lists/{id}/refresh             re-run a segment list's saved rules and sync membership
 *   GET    /api/mailing-lists/import-history           global CSV import log across all lists (?list_id=&page=&limit=)
 *   GET    /api/mailing-lists/export-history           global CSV export log across all lists (?list_id=&page=&limit=)
 *
 * A list's `list_type` is either 'static' (membership edited by hand/filter/
 * CSV) or 'segment' (membership fully computed from `segment_rules`, synced
 * only via /refresh). `list_type` cannot be changed once a list is created —
 * make a new list instead of converting one, since there's no good answer
 * for what should happen to existing members on a type change.
 *
 * A member's status is 'subscribed', 'unsubscribed', or 'bounced'. Bounced is
 * manually set (Mark as bounced / bulk action) — this app has no outbound
 * email provider bounce-webhook feed, so nothing flips it automatically;
 * it exists so a hard-bounce an admin learns about by other means can be
 * recorded and excluded from future sends, same as unsubscribing.
 *
 * Gated by the manage_campaigns global capability (venue_admin) — Lists is
 * part of the same nav group/feature as Campaigns, so it reuses that grant
 * rather than minting a separate capability.
 */
final class MailingLists extends BaseEndpoint
{
    /** Safety cap on any single bulk-membership write (manual, filter, CSV, or segment refresh). */
    private const MAX_BULK_CONTACTS = 2000;
    /** CSV import runs synchronously in one request — no job queue exists in this app. */
    private const MAX_CSV_ROWS = 5000;
    private const MAX_CSV_ERRORS = 50;
    private const MAX_CSV_BYTES = 5 * 1024 * 1024;

    public function handle(Request $request): Response
    {
        if ($denied = $this->requireGlobalCapability('manage_campaigns')) {
            return $denied;
        }

        $id      = $this->params['listId'] ?? null;
        $child   = $this->params['child'] ?? null;
        $childId = $this->params['childId'] ?? null;

        // Global (cross-list) history — routed by Kernel with listId=null,
        // child='import-history'|'export-history' before the numeric-id
        // parsing below would otherwise swallow the word as a non-numeric id.
        if ($id === null && $child === 'import-history' && $request->method() === 'GET') {
            return $this->importHistory($request);
        }
        if ($id === null && $child === 'export-history' && $request->method() === 'GET') {
            return $this->exportHistory($request);
        }

        if ($id && $child === 'members') {
            return $childId
                ? $this->handleMember($request, (int) $id, (int) $childId)
                : $this->handleMembers($request, (int) $id);
        }
        if ($id && $child === 'add-by-filter' && $request->method() === 'POST') {
            return $this->addMembersByFilter($request, (int) $id);
        }
        if ($id && $child === 'import' && $request->method() === 'POST') {
            return $this->importCsv($request, (int) $id);
        }
        if ($id && $child === 'refresh' && $request->method() === 'POST') {
            return $this->refreshSegment((int) $id);
        }
        if ($id && $child === 'export-members' && $request->method() === 'GET') {
            return $this->exportMembers($request, (int) $id);
        }

        return match ($request->method()) {
            'GET'    => $id ? $this->show((int) $id) : $this->index($request),
            'POST'   => $this->create($request),
            'PATCH'  => $this->update($request, (int) $id),
            'DELETE' => $this->delete((int) $id),
            default  => Response::methodNotAllowed(),
        };
    }

    private function handleMembers(Request $request, int $listId): Response
    {
        return match ($request->method()) {
            'GET'    => $this->listMembers($request, $listId),
            'POST'   => $this->addMembers($request, $listId),
            'PATCH'  => $this->bulkUpdateMembers($request, $listId),
            'DELETE' => $this->bulkRemoveMembers($request, $listId),
            default  => Response::methodNotAllowed(),
        };
    }

    /** @return int[]|Response */
    private function readContactIds(Request $request): array|Response
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
        if (count($contactIds) > self::MAX_BULK_CONTACTS) {
            return Response::json(['error' => 'Too many contacts at once (max ' . self::MAX_BULK_CONTACTS . ') — split into smaller batches'], 422);
        }
        return $contactIds;
    }

    /**
     * Bulk status change — the "Unsubscribe" / "Mark as bounced" / "Resubscribe"
     * bulk-select actions in the ListMaster member table. Segment lists are
     * blocked the same way single-member updateMember() isn't (there's no
     * such guard there today, kept consistent): a manual status write on a
     * segment member would just get reasserted on the next refresh, so it's
     * silently pointless rather than actively harmful — still worth telling
     * the user rather than letting them think it stuck.
     */
    private function bulkUpdateMembers(Request $request, int $listId): Response
    {
        $list = $this->db->one('SELECT id, name, list_type FROM mailing_lists WHERE id = ?', [$listId]);
        if (!$list) {
            return $this->notFound('Mailing list not found');
        }
        $status = (string) $request->body('status', '');
        if (!in_array($status, ['subscribed', 'unsubscribed', 'bounced'], true)) {
            return Response::json(['error' => "status must be 'subscribed', 'unsubscribed', or 'bounced'"], 422);
        }
        $contactIds = $this->readContactIds($request);
        if ($contactIds instanceof Response) {
            return $contactIds;
        }

        $placeholders = implode(',', array_fill(0, count($contactIds), '?'));
        $matching = $this->db->all(
            "SELECT contact_id FROM list_membership WHERE list_id = ? AND contact_id IN ({$placeholders})",
            [$listId, ...$contactIds]
        );
        $matchedIds = array_map(static fn ($r) => (int) $r['contact_id'], $matching);
        if ($matchedIds !== []) {
            $inPlaceholders = implode(',', array_fill(0, count($matchedIds), '?'));
            $this->db->run(
                "UPDATE list_membership SET status = ? WHERE list_id = ? AND contact_id IN ({$inPlaceholders})",
                [$status, $listId, ...$matchedIds]
            );
            foreach ($matchedIds as $contactId) {
                log_contact_activity($this->db, $contactId, $this->userId(), 'status_changed', 'Marked ' . $status . ' on "' . $list['name'] . '"', ['list_id' => $listId, 'status' => $status]);
            }
        }

        return $this->ok(['updated' => count($matchedIds), 'skipped' => count($contactIds) - count($matchedIds)]);
    }

    /** Bulk "Remove from List" — fully deletes the membership rows (not a status change). */
    private function bulkRemoveMembers(Request $request, int $listId): Response
    {
        $list = $this->db->one('SELECT id, name FROM mailing_lists WHERE id = ?', [$listId]);
        if (!$list) {
            return $this->notFound('Mailing list not found');
        }
        $contactIds = $this->readContactIds($request);
        if ($contactIds instanceof Response) {
            return $contactIds;
        }

        $placeholders = implode(',', array_fill(0, count($contactIds), '?'));
        $matching = $this->db->all(
            "SELECT contact_id FROM list_membership WHERE list_id = ? AND contact_id IN ({$placeholders})",
            [$listId, ...$contactIds]
        );
        $matchedIds = array_map(static fn ($r) => (int) $r['contact_id'], $matching);
        if ($matchedIds !== []) {
            $inPlaceholders = implode(',', array_fill(0, count($matchedIds), '?'));
            $this->db->run("DELETE FROM list_membership WHERE list_id = ? AND contact_id IN ({$inPlaceholders})", [$listId, ...$matchedIds]);
            foreach ($matchedIds as $contactId) {
                log_contact_activity($this->db, $contactId, $this->userId(), 'list_left', 'Removed from list "' . $list['name'] . '"', ['list_id' => $listId]);
            }
        }

        return $this->ok(['removed' => count($matchedIds), 'skipped' => count($contactIds) - count($matchedIds)]);
    }

    private function handleMember(Request $request, int $listId, int $contactId): Response
    {
        return match ($request->method()) {
            'PATCH'  => $this->updateMember($request, $listId, $contactId),
            'DELETE' => $this->removeMember($listId, $contactId),
            default  => Response::methodNotAllowed(),
        };
    }

    // ---- Lists -------------------------------------------------------

    private function index(Request $request): Response
    {
        $where = [];
        $params = [];

        $q = trim((string) $request->query('q'));
        if ($q !== '') {
            $where[] = '(ml.name LIKE ? OR ml.description LIKE ?)';
            $like = '%' . $q . '%';
            array_push($params, $like, $like);
        }
        $whereSql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';

        $lists = $this->db->all(
            "SELECT ml.*, {$this->memberCountColumnsSql()}
             FROM mailing_lists ml{$whereSql}
             ORDER BY ml.name",
            $params
        );

        return $this->ok(['lists' => array_map($this->decorateList(...), $lists)]);
    }

    private function show(int $id): Response
    {
        $row = $this->db->one(
            "SELECT ml.*, {$this->memberCountColumnsSql()}
             FROM mailing_lists ml
             WHERE ml.id = ?",
            [$id]
        );
        if (!$row) {
            return $this->notFound('Mailing list not found');
        }
        return $this->ok(['list' => $this->decorateList($row)]);
    }

    private function create(Request $request): Response
    {
        $name = trim((string) $request->body('name', ''));
        if ($name === '') {
            return Response::json(['error' => 'A list name is required'], 422);
        }
        $description = trim((string) $request->body('description', '')) ?: null;

        $listType = (string) $request->body('list_type', 'static');
        if (!in_array($listType, ['static', 'segment'], true)) {
            return Response::json(['error' => "list_type must be 'static' or 'segment'"], 422);
        }

        // For a segment list, resolve+cap-check its matches BEFORE writing
        // anything, so creation never partially succeeds.
        $rules = null;
        $matchIds = [];
        if ($listType === 'segment') {
            $rulesInput = $request->body('segment_rules');
            $rules = is_array($rulesInput) ? $rulesInput : [];
            if (!ContactFilters::hasAnyRule($rules)) {
                return Response::json(['error' => 'Choose at least one rule for a smart list'], 422);
            }
            $matchIds = $this->matchingContactIds($rules);
            if (count($matchIds) > self::MAX_BULK_CONTACTS) {
                return Response::json([
                    'error' => count($matchIds) . ' contacts match — narrow the rules to ' . self::MAX_BULK_CONTACTS . ' or fewer',
                ], 422);
            }
        }

        try {
            $id = $this->db->insert(
                'INSERT INTO mailing_lists (name, description, list_type, segment_rules, created_by_user_id) VALUES (?, ?, ?, ?, ?)',
                [$name, $description, $listType, $rules !== null ? json_encode($rules) : null, $this->userId()]
            );
        } catch (\PDOException $e) {
            if ($this->isDuplicateKey($e)) {
                return Response::json(['error' => 'A mailing list with that name already exists'], 422);
            }
            throw $e;
        }

        if ($listType === 'segment') {
            $this->applySegmentMembership($id, $matchIds);
        }

        $row = $this->fetchListWithCount($id);
        return $this->ok(['list' => $this->decorateList($row)]);
    }

    private function update(Request $request, int $id): Response
    {
        if (!$id) return $this->notFound();
        $existing = $this->db->one('SELECT * FROM mailing_lists WHERE id = ?', [$id]);
        if (!$existing) return $this->notFound('Mailing list not found');

        if ($request->body('list_type') !== null && (string) $request->body('list_type') !== $existing['list_type']) {
            return Response::json(['error' => "A list's type can't be changed after creation — create a new list instead"], 422);
        }

        $fields = [];
        $params = [];

        if ($request->body('name') !== null) {
            $name = trim((string) $request->body('name', ''));
            if ($name === '') {
                return Response::json(['error' => 'A list name is required'], 422);
            }
            $fields[] = 'name = ?';
            $params[] = $name;
        }
        if ($request->body('description') !== null) {
            $fields[] = 'description = ?';
            $params[] = trim((string) $request->body('description', '')) ?: null;
        }

        // As with create(), resolve+cap-check new rules before writing them.
        $newRuleMatchIds = null;
        if ($request->body('segment_rules') !== null) {
            if ($existing['list_type'] !== 'segment') {
                return Response::json(['error' => 'Only smart (segment) lists have rules'], 422);
            }
            $rulesInput = $request->body('segment_rules');
            $newRules = is_array($rulesInput) ? $rulesInput : [];
            if (!ContactFilters::hasAnyRule($newRules)) {
                return Response::json(['error' => 'Choose at least one rule for a smart list'], 422);
            }
            $newRuleMatchIds = $this->matchingContactIds($newRules);
            if (count($newRuleMatchIds) > self::MAX_BULK_CONTACTS) {
                return Response::json([
                    'error' => count($newRuleMatchIds) . ' contacts match — narrow the rules to ' . self::MAX_BULK_CONTACTS . ' or fewer',
                ], 422);
            }
            $fields[] = 'segment_rules = ?';
            $params[] = json_encode($newRules);
        }

        if ($fields === []) {
            return $this->ok(['list' => $this->decorateList($this->fetchListWithCount($id))]);
        }

        $params[] = $id;
        try {
            $this->db->run('UPDATE mailing_lists SET ' . implode(', ', $fields) . ' WHERE id = ?', $params);
        } catch (\PDOException $e) {
            if ($this->isDuplicateKey($e)) {
                return Response::json(['error' => 'A mailing list with that name already exists'], 422);
            }
            throw $e;
        }

        // Editing rules and not re-syncing would leave the list visibly
        // wrong (still showing the old membership) until someone remembers
        // to hit Refresh, so auto-sync right after a successful rule edit.
        if ($newRuleMatchIds !== null) {
            $this->applySegmentMembership($id, $newRuleMatchIds);
        }

        $row = $this->fetchListWithCount($id);
        return $this->ok(['list' => $this->decorateList($row)]);
    }

    private function delete(int $id): Response
    {
        if (!$id) return $this->notFound();
        $existing = $this->db->one('SELECT id FROM mailing_lists WHERE id = ?', [$id]);
        if (!$existing) return $this->notFound('Mailing list not found');
        $this->db->run('DELETE FROM mailing_lists WHERE id = ?', [$id]);
        return Response::noContent();
    }

    // ---- Members -------------------------------------------------------

    private function listMembers(Request $request, int $listId): Response
    {
        $list = $this->db->one('SELECT id FROM mailing_lists WHERE id = ?', [$listId]);
        if (!$list) {
            return $this->notFound('Mailing list not found');
        }

        $where = ['lm.list_id = ?'];
        $params = [$listId];

        $q = trim((string) $request->query('q'));
        if ($q !== '') {
            $where[] = '(c.first_name LIKE ? OR c.last_name LIKE ? OR CONCAT(c.first_name, " ", c.last_name) LIKE ? OR c.email LIKE ?)';
            $like = '%' . $q . '%';
            array_push($params, $like, $like, $like, $like);
        }

        $status = (string) $request->query('status');
        if (in_array($status, ['subscribed', 'unsubscribed', 'bounced'], true)) {
            $where[] = 'lm.status = ?';
            $params[] = $status;
        }

        $tagId = $request->query('tag');
        if (is_numeric($tagId)) {
            $where[] = 'lm.contact_id IN (SELECT contact_id FROM contact_tag_assignments WHERE tag_id = ?)';
            $params[] = (int) $tagId;
        }

        $whereSql = ' WHERE ' . implode(' AND ', $where);

        $limit = (int) ($request->query('limit') ?: 50);
        $limit = max(1, min(200, $limit));
        $page = max(1, (int) ($request->query('page') ?: 1));
        $offset = ($page - 1) * $limit;

        $total = (int) ($this->db->one(
            "SELECT COUNT(*) n FROM list_membership lm JOIN contacts c ON c.id = lm.contact_id{$whereSql}",
            $params
        )['n'] ?? 0);

        $members = $this->db->all(
            "SELECT c.id AS contact_id, c.first_name, c.last_name, c.email, c.phone, c.marketing_opted_in, c.created_at AS contact_created_at,
                    lm.status, lm.added_at, lm.updated_at,
                    (SELECT COUNT(*) FROM list_membership lm2 WHERE lm2.contact_id = c.id) AS lists_count
             FROM list_membership lm
             JOIN contacts c ON c.id = lm.contact_id
             {$whereSql}
             ORDER BY c.last_name ASC, c.first_name ASC
             LIMIT {$limit} OFFSET {$offset}",
            $params
        );
        $members = $this->attachTags($members, 'contact_id');

        return $this->ok([
            'members' => $members,
            'total'   => $total,
            'page'    => $page,
            'limit'   => $limit,
            'pages'   => (int) ceil($total / $limit),
        ]);
    }

    /**
     * Batch-attaches a `tags` array (id/name/color) to each row, keyed off
     * $idField (a contact id column already present in each row) — one extra
     * query for the whole page rather than one per row. Shared by
     * listMembers() and exportMembers() so the member table and its CSV
     * export show the exact same tag data.
     */
    private function attachTags(array $rows, string $idField): array
    {
        if ($rows === []) {
            return $rows;
        }
        $ids = array_values(array_unique(array_map(static fn ($r) => (int) $r[$idField], $rows)));
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $tagRows = $this->db->all(
            "SELECT cta.contact_id, ct.id, ct.name, ct.color
             FROM contact_tag_assignments cta
             JOIN contact_tags ct ON ct.id = cta.tag_id
             WHERE cta.contact_id IN ({$placeholders})
             ORDER BY ct.name",
            $ids
        );
        $byContact = [];
        foreach ($tagRows as $t) {
            $byContact[(int) $t['contact_id']][] = ['id' => (int) $t['id'], 'name' => $t['name'], 'color' => $t['color']];
        }
        foreach ($rows as &$row) {
            $row['tags'] = $byContact[(int) $row[$idField]] ?? [];
        }
        unset($row);
        return $rows;
    }

    private function addMembers(Request $request, int $listId): Response
    {
        $list = $this->db->one('SELECT id FROM mailing_lists WHERE id = ?', [$listId]);
        if (!$list) {
            return $this->notFound('Mailing list not found');
        }

        $contactIds = $this->readContactIds($request);
        if ($contactIds instanceof Response) {
            return $contactIds;
        }

        $placeholders = implode(',', array_fill(0, count($contactIds), '?'));
        $existingRows = $this->db->all("SELECT id FROM contacts WHERE id IN ({$placeholders})", $contactIds);
        $validIds = array_map(static fn ($r) => (int) $r['id'], $existingRows);

        $added = $this->upsertMembers($listId, $validIds, 'manual');
        $skipped = count($contactIds) - $added;

        return $this->ok([
            'added'   => $added,
            'skipped' => $skipped,
        ]);
    }

    /**
     * "Add all N matching" — resolves the same criteria the Contacts page
     * search box supports (q, opted, min_spend, min_events, min_tickets) to a
     * set of contact ids via the shared ContactFilters builder, then upserts
     * all of them in one batch. This is the alternative to hand-checking
     * boxes one search-page at a time.
     */
    private function addMembersByFilter(Request $request, int $listId): Response
    {
        $list = $this->db->one('SELECT id FROM mailing_lists WHERE id = ?', [$listId]);
        if (!$list) {
            return $this->notFound('Mailing list not found');
        }

        $criteria = is_array($request->body()) ? $request->body() : [];
        if (!ContactFilters::hasAnyRule($criteria)) {
            return Response::json(['error' => 'At least one filter (search text or opted-in) is required'], 422);
        }

        $matchIds = $this->matchingContactIds($criteria);

        if (count($matchIds) > self::MAX_BULK_CONTACTS) {
            return Response::json([
                'error' => count($matchIds) . ' contacts match — narrow your filter to ' . self::MAX_BULK_CONTACTS . ' or fewer at a time',
            ], 422);
        }

        $added = $this->upsertMembers($listId, $matchIds, 'bulk');

        return $this->ok(['added' => $added, 'matched' => count($matchIds)]);
    }

    /**
     * CSV import — matches/creates contacts by email and adds them to the
     * list, all in one request (no job queue exists in this app, so this has
     * to run synchronously; MAX_CSV_ROWS keeps that bounded). Unlike
     * scripts/import-fanview.php (its CLI, all-or-nothing counterpart), this
     * is deliberately per-row/partial-success: a bad row is recorded as an
     * error and skipped rather than failing the whole upload, since a web
     * user needs to see and fix problems interactively rather than rerun a
     * whole batch job.
     */
    private function importCsv(Request $request, int $listId): Response
    {
        $list = $this->db->one('SELECT id FROM mailing_lists WHERE id = ?', [$listId]);
        if (!$list) {
            return $this->notFound('Mailing list not found');
        }

        $file = $request->files()['csv'] ?? null;
        $uploadError = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        if (!$file || $uploadError !== UPLOAD_ERR_OK) {
            $message = match ($uploadError) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File is too large — check your server upload_max_filesize setting (currently ' . ini_get('upload_max_filesize') . ')',
                UPLOAD_ERR_NO_FILE => 'No file was received',
                default => 'Upload failed (PHP error ' . $uploadError . ')',
            };
            return Response::json(['error' => $message], 422);
        }
        if (($file['size'] ?? 0) > self::MAX_CSV_BYTES) {
            return Response::json(['error' => 'CSV files must be ' . (self::MAX_CSV_BYTES / 1024 / 1024) . 'MB or smaller'], 422);
        }
        // CSV is just text, so MIME-sniffing alone is unreliable (a .csv and
        // a .txt are indistinguishable by content) — require the extension too.
        $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        $mime = mime_content_type($file['tmp_name']) ?: '';
        $allowedMimes = ['text/plain', 'text/csv', 'application/csv', 'application/vnd.ms-excel'];
        if ($ext !== 'csv' || !in_array($mime, $allowedMimes, true)) {
            return Response::json(['error' => 'Please upload a .csv file (detected type: ' . $mime . ')'], 422);
        }

        $handle = fopen($file['tmp_name'], 'rb');
        if (!$handle) {
            return Response::json(['error' => 'Could not read the uploaded file'], 500);
        }
        $rows = [];
        while (($row = fgetcsv($handle)) !== false) {
            if ($row === [null]) continue; // blank line
            $rows[] = $row;
        }
        fclose($handle);

        if ($rows === []) {
            return Response::json(['error' => 'The CSV file is empty'], 422);
        }

        // Strip a leading UTF-8 BOM (common Excel-export artifact) from the
        // very first header cell before matching column names.
        $rows[0][0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) ($rows[0][0] ?? ''));
        $header = array_map(static fn ($h) => strtolower(trim((string) $h)), array_shift($rows));

        $findCol = static function (array $names) use ($header): ?int {
            foreach ($names as $name) {
                $i = array_search($name, $header, true);
                if ($i !== false) return $i;
            }
            return null;
        };

        $emailCol = $findCol(['email', 'e-mail']);
        if ($emailCol === null) {
            return Response::json(['error' => 'The CSV needs an "email" column'], 422);
        }
        $firstCol = $findCol(['first_name', 'first name', 'firstname']);
        $lastCol  = $findCol(['last_name', 'last name', 'lastname']);
        $phoneCol = $findCol(['phone', 'phone number']);
        $optedCol = $findCol(['marketing_opted_in', 'opted_in', 'subscribed', 'opt_in']);

        if (count($rows) > self::MAX_CSV_ROWS) {
            return Response::json([
                'error' => count($rows) . ' rows — split into batches of ' . self::MAX_CSV_ROWS . ' or fewer',
            ], 422);
        }

        $created = 0;
        $updated = 0;
        $errors = [];
        $resolvedIds = [];

        foreach ($rows as $i => $row) {
            $rowNum = $i + 1; // 1-based, data rows only (header not counted)
            $email = strtolower(trim((string) ($row[$emailCol] ?? '')));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                if (count($errors) < self::MAX_CSV_ERRORS) {
                    $errors[] = ['row' => $rowNum, 'message' => $email === '' ? 'Missing email' : 'Invalid email'];
                }
                continue;
            }

            $first = $firstCol !== null ? trim((string) ($row[$firstCol] ?? '')) : '';
            $last  = $lastCol  !== null ? trim((string) ($row[$lastCol] ?? '')) : '';
            $phone = $phoneCol !== null ? trim((string) ($row[$phoneCol] ?? '')) : '';
            // Only touch marketing_opted_in when the CSV actually supplied that
            // column — uploading a list of names/emails is not itself consent,
            // so a missing column must never flip anyone's opt-in status.
            $opted = $optedCol !== null ? boolish($row[$optedCol] ?? '') : null;

            $existing = $this->db->one('SELECT id, marketing_opted_in, opt_in_date FROM contacts WHERE email = ?', [$email]);
            if ($existing) {
                $fields = [];
                $params = [];
                if ($first !== '') { $fields[] = 'first_name = ?'; $params[] = $first; }
                if ($last !== '') { $fields[] = 'last_name = ?'; $params[] = $last; }
                if ($phone !== '') { $fields[] = 'phone = ?'; $params[] = $phone; }
                if ($opted !== null) {
                    $fields[] = 'marketing_opted_in = ?';
                    $params[] = $opted;
                    if ($opted && !(int) $existing['marketing_opted_in'] && !$existing['opt_in_date']) {
                        $fields[] = 'opt_in_date = ?';
                        $params[] = date('Y-m-d');
                    }
                }
                if ($fields !== []) {
                    $params[] = $existing['id'];
                    $this->db->run('UPDATE contacts SET ' . implode(', ', $fields) . ' WHERE id = ?', $params);
                }
                $resolvedIds[] = (int) $existing['id'];
                $updated++;
            } else {
                $id = $this->db->insert(
                    'INSERT INTO contacts (source, first_name, last_name, email, phone, marketing_opted_in, opt_in_date) VALUES (?, ?, ?, ?, ?, ?, ?)',
                    ['csv_import', $first ?: null, $last ?: null, $email, $phone ?: null, $opted ?? 0, $opted ? date('Y-m-d') : null]
                );
                $resolvedIds[] = $id;
                $created++;
            }
        }

        $addedToList = $this->upsertMembers($listId, array_values(array_unique($resolvedIds)), 'csv_import');

        $this->db->run(
            'INSERT INTO list_import_history (list_id, filename, created_count, updated_count, added_to_list, skipped_count, errors_json, imported_by_user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$listId, (string) ($file['name'] ?? ''), $created, $updated, $addedToList, count($errors), $errors ? json_encode($errors) : null, $this->userId()]
        );

        return $this->ok([
            'created'       => $created,
            'updated'       => $updated,
            'added_to_list' => $addedToList,
            'skipped'       => count($errors),
            'errors'        => $errors,
        ]);
    }

    /**
     * Batched upsert of `(listId, contactId)` memberships as 'subscribed',
     * stamping how each row was added. Used directly by addMembers() (manual
     * picks) and by the bulk-by-filter, CSV import, and segment-refresh paths
     * added below — all funnel through here so there's exactly one place that
     * writes list_membership rows in bulk.
     *
     * Returns count($contactIds) (the number processed), not a "newly
     * inserted vs already-present" distinction — MySQL's ON DUPLICATE KEY
     * UPDATE affected-rows count isn't a reliable signal for that without
     * extra bookkeeping, and callers only ever needed "how many did I ask to
     * add" for their response/toast copy.
     */
    private function upsertMembers(int $listId, array $contactIds, string $addedVia): int
    {
        if ($contactIds === []) {
            return 0;
        }

        $rowSql = [];
        $params = [];
        foreach ($contactIds as $contactId) {
            $rowSql[] = "(?, ?, 'subscribed', ?)";
            array_push($params, $listId, $contactId, $addedVia);
        }

        $this->db->run(
            'INSERT INTO list_membership (list_id, contact_id, status, added_via) VALUES ' . implode(', ', $rowSql) . "
             ON DUPLICATE KEY UPDATE status = 'subscribed', added_via = VALUES(added_via), updated_at = CURRENT_TIMESTAMP",
            $params
        );

        $listName = (string) ($this->db->one('SELECT name FROM mailing_lists WHERE id = ?', [$listId])['name'] ?? '');
        $message = match ($addedVia) {
            'csv_import' => 'Imported via CSV into list "' . $listName . '"',
            'segment'    => 'Auto-added to smart list "' . $listName . '"',
            'bulk'       => 'Added to list "' . $listName . '" (bulk)',
            default      => 'Added to list "' . $listName . '"',
        };
        foreach ($contactIds as $contactId) {
            log_contact_activity($this->db, $contactId, $this->userId(), 'list_joined', $message, ['list_id' => $listId, 'added_via' => $addedVia]);
        }

        return count($contactIds);
    }

    /** Resolve a filter/rules criteria array (see ContactFilters) to matching contact ids. */
    private function matchingContactIds(array $criteria): array
    {
        ['where' => $whereSql, 'params' => $params] = ContactFilters::buildWhere($criteria);
        return array_map(
            static fn ($r) => (int) $r['id'],
            $this->db->all("SELECT id FROM contacts{$whereSql}", $params)
        );
    }

    /**
     * GET /mailing-lists/{id}/export-members — CSV download of every member
     * matching the same ?q=&status=&tag= filters as listMembers(), but
     * unpaginated (the "Export Lists" toolbar button in ListMaster, run with
     * whatever filter is currently applied to the table). Logs a row to
     * list_export_history so it shows up in the Export History tool.
     */
    private function exportMembers(Request $request, int $listId): Response
    {
        $list = $this->db->one('SELECT id, name FROM mailing_lists WHERE id = ?', [$listId]);
        if (!$list) {
            return $this->notFound('Mailing list not found');
        }

        $where = ['lm.list_id = ?'];
        $params = [$listId];

        $q = trim((string) $request->query('q'));
        if ($q !== '') {
            $where[] = '(c.first_name LIKE ? OR c.last_name LIKE ? OR CONCAT(c.first_name, " ", c.last_name) LIKE ? OR c.email LIKE ?)';
            $like = '%' . $q . '%';
            array_push($params, $like, $like, $like, $like);
        }
        $status = (string) $request->query('status');
        if (in_array($status, ['subscribed', 'unsubscribed', 'bounced'], true)) {
            $where[] = 'lm.status = ?';
            $params[] = $status;
        }
        $tagId = $request->query('tag');
        if (is_numeric($tagId)) {
            $where[] = 'lm.contact_id IN (SELECT contact_id FROM contact_tag_assignments WHERE tag_id = ?)';
            $params[] = (int) $tagId;
        }
        $whereSql = ' WHERE ' . implode(' AND ', $where);

        $members = $this->db->all(
            "SELECT c.id AS contact_id, c.first_name, c.last_name, c.email, c.phone, c.marketing_opted_in,
                    lm.status, lm.added_at
             FROM list_membership lm
             JOIN contacts c ON c.id = lm.contact_id
             {$whereSql}
             ORDER BY c.last_name ASC, c.first_name ASC",
            $params
        );
        $members = $this->attachTags($members, 'contact_id');

        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, ['First Name', 'Last Name', 'Email', 'Phone', 'Marketing Opted In', 'Status', 'Tags', 'Joined']);
        foreach ($members as $m) {
            fputcsv($stream, [
                $m['first_name'], $m['last_name'], $m['email'], $m['phone'],
                $m['marketing_opted_in'] ? 'yes' : 'no', $m['status'],
                implode('; ', array_map(static fn ($t) => $t['name'], $m['tags'])),
                $m['added_at'],
            ]);
        }
        rewind($stream);
        $csv = (string) stream_get_contents($stream);
        fclose($stream);

        $this->db->run(
            'INSERT INTO list_export_history (list_id, list_name_snapshot, format, row_count, filters_json, exported_by_user_id) VALUES (?, ?, ?, ?, ?, ?)',
            [$listId, $list['name'], 'csv', count($members), json_encode(['q' => $q ?: null, 'status' => $status ?: null, 'tag' => is_numeric($tagId) ? (int) $tagId : null]), $this->userId()]
        );

        $stamp = date('Y-m-d_His');
        $safeName = preg_replace('/[^a-z0-9]+/i', '-', $list['name']) ?: 'list';
        return Response::csv($csv, "{$safeName}-members-{$stamp}.csv");
    }

    // ---- Segment (smart) lists -----------------------------------------

    /**
     * POST /mailing-lists/{id}/refresh — re-runs a segment list's saved
     * rules and syncs membership. The only way segment membership ever
     * changes outside of list creation/rule edits (both of which also call
     * applySegmentMembership() below) — there's no cron/background refresh
     * in this app, so this is a manual "Refresh now" action.
     */
    private function refreshSegment(int $listId): Response
    {
        $list = $this->db->one('SELECT * FROM mailing_lists WHERE id = ?', [$listId]);
        if (!$list) {
            return $this->notFound('Mailing list not found');
        }
        if ($list['list_type'] !== 'segment') {
            return Response::json(['error' => 'Only smart (segment) lists can be refreshed'], 422);
        }

        $rules = $list['segment_rules'] !== null ? (json_decode((string) $list['segment_rules'], true) ?: []) : [];
        $matchIds = $this->matchingContactIds($rules);
        if (count($matchIds) > self::MAX_BULK_CONTACTS) {
            return Response::json([
                'error' => count($matchIds) . ' contacts match — narrow the rules to ' . self::MAX_BULK_CONTACTS . ' or fewer',
            ], 422);
        }

        return $this->ok($this->applySegmentMembership($listId, $matchIds));
    }

    /**
     * Diffs $matchIds against the list's current `added_via='segment'`
     * members and syncs the difference: upserts new matches, deletes
     * members that no longer match. Scoped to added_via='segment' so a
     * manually-added, bulk-added, or CSV-imported member is never evicted by
     * a segment refresh — list_type is otherwise immutable specifically so
     * this invariant (a list is either fully computed or fully manual, never
     * a mix with ambiguous ownership) always holds.
     *
     * @return array{added:int, removed:int, total_matching:int}
     */
    private function applySegmentMembership(int $listId, array $matchIds): array
    {
        $currentIds = array_map(
            static fn ($r) => (int) $r['contact_id'],
            $this->db->all("SELECT contact_id FROM list_membership WHERE list_id = ? AND added_via = 'segment'", [$listId])
        );

        $toAdd = array_values(array_diff($matchIds, $currentIds));
        $toRemove = array_values(array_diff($currentIds, $matchIds));

        $added = $this->upsertMembers($listId, $toAdd, 'segment');
        if ($toRemove !== []) {
            $placeholders = implode(',', array_fill(0, count($toRemove), '?'));
            $this->db->run(
                "DELETE FROM list_membership WHERE list_id = ? AND added_via = 'segment' AND contact_id IN ({$placeholders})",
                [$listId, ...$toRemove]
            );
        }
        $this->db->run('UPDATE mailing_lists SET segment_refreshed_at = NOW() WHERE id = ?', [$listId]);

        return ['added' => $added, 'removed' => count($toRemove), 'total_matching' => count($matchIds)];
    }

    /**
     * Fetch a single list row with the same member_count subquery index()/
     * show() use. create()/update() need this too (not just the plain row)
     * so a freshly created/edited list's response already carries an
     * accurate count — otherwise the frontend briefly shows 0 members even
     * right after a segment list auto-populates on creation.
     */
    private function fetchListWithCount(int $id): ?array
    {
        return $this->db->one(
            "SELECT ml.*, {$this->memberCountColumnsSql()}
             FROM mailing_lists ml
             WHERE ml.id = ?",
            [$id]
        );
    }

    /**
     * Correlated-subquery columns shared by index()/show()/fetchListWithCount()
     * — every place a mailing_lists row is returned to the client. member_count
     * (subscribed) is the long-standing field campaigns.js/mailing-lists.js
     * already depend on for audience size; the rest back the ListMaster stats
     * cards (Total/Active/Unsubscribed/Bounced/Last updated).
     */
    private function memberCountColumnsSql(): string
    {
        return "(SELECT COUNT(*) FROM list_membership lm WHERE lm.list_id = ml.id AND lm.status = 'subscribed') AS member_count,
                (SELECT COUNT(*) FROM list_membership lm WHERE lm.list_id = ml.id) AS total_count,
                (SELECT COUNT(*) FROM list_membership lm WHERE lm.list_id = ml.id AND lm.status = 'unsubscribed') AS unsubscribed_count,
                (SELECT COUNT(*) FROM list_membership lm WHERE lm.list_id = ml.id AND lm.status = 'bounced') AS bounced_count,
                (SELECT MAX(lm.updated_at) FROM list_membership lm WHERE lm.list_id = ml.id) AS last_member_update";
    }

    /** Decode segment_rules from its stored JSON-text form for API responses. */
    private function decorateList(array $row): array
    {
        $row['segment_rules'] = $row['segment_rules'] !== null
            ? (json_decode((string) $row['segment_rules'], true) ?: null)
            : null;
        return $row;
    }

    private function updateMember(Request $request, int $listId, int $contactId): Response
    {
        $status = (string) $request->body('status', '');
        if (!in_array($status, ['subscribed', 'unsubscribed', 'bounced'], true)) {
            return Response::json(['error' => "status must be 'subscribed', 'unsubscribed', or 'bounced'"], 422);
        }

        $list = $this->db->one('SELECT id, name FROM mailing_lists WHERE id = ?', [$listId]);
        if (!$list) {
            return $this->notFound('Mailing list not found');
        }
        $existing = $this->db->one(
            'SELECT id FROM list_membership WHERE list_id = ? AND contact_id = ?',
            [$listId, $contactId]
        );
        if (!$existing) {
            return $this->notFound('List membership not found');
        }

        $this->db->run(
            'UPDATE list_membership SET status = ? WHERE list_id = ? AND contact_id = ?',
            [$status, $listId, $contactId]
        );
        log_contact_activity($this->db, $contactId, $this->userId(), 'status_changed', 'Marked ' . $status . ' on "' . $list['name'] . '"', ['list_id' => $listId, 'status' => $status]);

        $row = $this->db->one(
            'SELECT * FROM list_membership WHERE list_id = ? AND contact_id = ?',
            [$listId, $contactId]
        );
        return $this->ok(['membership' => $row]);
    }

    private function removeMember(int $listId, int $contactId): Response
    {
        $list = $this->db->one('SELECT id, name FROM mailing_lists WHERE id = ?', [$listId]);
        if (!$list) {
            return $this->notFound('Mailing list not found');
        }
        $existing = $this->db->one(
            'SELECT id FROM list_membership WHERE list_id = ? AND contact_id = ?',
            [$listId, $contactId]
        );
        if (!$existing) {
            return $this->notFound('List membership not found');
        }

        $this->db->run('DELETE FROM list_membership WHERE list_id = ? AND contact_id = ?', [$listId, $contactId]);
        log_contact_activity($this->db, $contactId, $this->userId(), 'list_left', 'Removed from list "' . $list['name'] . '"', ['list_id' => $listId]);
        return Response::noContent();
    }

    // ---- Import / export history ----------------------------------------

    /** GET /mailing-lists/import-history — the Tools > Import History page (?list_id=&page=&limit=). */
    private function importHistory(Request $request): Response
    {
        $where = [];
        $params = [];
        $listId = $request->query('list_id');
        if (is_numeric($listId)) {
            $where[] = 'h.list_id = ?';
            $params[] = (int) $listId;
        }
        $whereSql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';

        $limit = max(1, min(200, (int) ($request->query('limit') ?: 25)));
        $page = max(1, (int) ($request->query('page') ?: 1));
        $offset = ($page - 1) * $limit;

        $total = (int) ($this->db->one("SELECT COUNT(*) n FROM list_import_history h{$whereSql}", $params)['n'] ?? 0);
        $rows = $this->db->all(
            "SELECT h.*, ml.name AS list_name, u.name AS imported_by_name
             FROM list_import_history h
             LEFT JOIN mailing_lists ml ON ml.id = h.list_id
             LEFT JOIN users u ON u.id = h.imported_by_user_id
             {$whereSql}
             ORDER BY h.created_at DESC, h.id DESC
             LIMIT {$limit} OFFSET {$offset}",
            $params
        );
        $rows = array_map(static function (array $r): array {
            $r['errors'] = $r['errors_json'] !== null ? (json_decode((string) $r['errors_json'], true) ?: []) : [];
            unset($r['errors_json']);
            return $r;
        }, $rows);

        return $this->ok(['history' => $rows, 'total' => $total, 'page' => $page, 'limit' => $limit, 'pages' => (int) ceil($total / $limit)]);
    }

    /** GET /mailing-lists/export-history — the Tools > Export History page (?list_id=&page=&limit=). */
    private function exportHistory(Request $request): Response
    {
        $where = [];
        $params = [];
        $listId = $request->query('list_id');
        if (is_numeric($listId)) {
            $where[] = 'h.list_id = ?';
            $params[] = (int) $listId;
        }
        $whereSql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';

        $limit = max(1, min(200, (int) ($request->query('limit') ?: 25)));
        $page = max(1, (int) ($request->query('page') ?: 1));
        $offset = ($page - 1) * $limit;

        $total = (int) ($this->db->one("SELECT COUNT(*) n FROM list_export_history h{$whereSql}", $params)['n'] ?? 0);
        $rows = $this->db->all(
            "SELECT h.*, COALESCE(ml.name, h.list_name_snapshot) AS list_name, u.name AS exported_by_name
             FROM list_export_history h
             LEFT JOIN mailing_lists ml ON ml.id = h.list_id
             LEFT JOIN users u ON u.id = h.exported_by_user_id
             {$whereSql}
             ORDER BY h.created_at DESC, h.id DESC
             LIMIT {$limit} OFFSET {$offset}",
            $params
        );
        $rows = array_map(static function (array $r): array {
            $r['filters'] = $r['filters_json'] !== null ? (json_decode((string) $r['filters_json'], true) ?: []) : [];
            unset($r['filters_json']);
            return $r;
        }, $rows);

        return $this->ok(['history' => $rows, 'total' => $total, 'page' => $page, 'limit' => $limit, 'pages' => (int) ceil($total / $limit)]);
    }

    // ---- Helpers -------------------------------------------------------

    private function isDuplicateKey(\PDOException $e): bool
    {
        return ($e->errorInfo[1] ?? null) === 1062 || $e->getCode() === '23000';
    }
}
