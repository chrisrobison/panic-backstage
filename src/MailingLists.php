<?php
declare(strict_types=1);

namespace Panic;

/**
 * Reusable, named mailing lists layered on top of `contacts` — the audience
 * targeting mechanism for email campaigns (see `src/Campaigns.php`).
 *
 *   GET    /api/mailing-lists                        list (?q=)
 *   GET    /api/mailing-lists/{id}                    show one (+ member count)
 *   POST   /api/mailing-lists                         create {name, description}
 *   PATCH  /api/mailing-lists/{id}                     update {name?, description?}
 *   DELETE /api/mailing-lists/{id}                     delete (membership cascades)
 *   GET    /api/mailing-lists/{id}/members             list members (?q=&status=&page=&limit=)
 *   POST   /api/mailing-lists/{id}/members             {contact_ids: int[]} bulk upsert-subscribe
 *   PATCH  /api/mailing-lists/{id}/members/{contactId} {status: subscribed|unsubscribed}
 *   DELETE /api/mailing-lists/{id}/members/{contactId} remove the membership row entirely
 *
 * Gated by the manage_campaigns global capability (venue_admin) — Lists is
 * part of the same nav group/feature as Campaigns, so it reuses that grant
 * rather than minting a separate capability.
 */
final class MailingLists extends BaseEndpoint
{
    public function handle(Request $request): Response
    {
        if ($denied = $this->requireGlobalCapability('manage_campaigns')) {
            return $denied;
        }

        $id      = $this->params['listId'] ?? null;
        $child   = $this->params['child'] ?? null;
        $childId = $this->params['childId'] ?? null;

        if ($id && $child === 'members') {
            return $childId
                ? $this->handleMember($request, (int) $id, (int) $childId)
                : $this->handleMembers($request, (int) $id);
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
            'GET'   => $this->listMembers($request, $listId),
            'POST'  => $this->addMembers($request, $listId),
            default => Response::methodNotAllowed(),
        };
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
            "SELECT ml.*,
                    (SELECT COUNT(*) FROM list_membership lm WHERE lm.list_id = ml.id AND lm.status = 'subscribed') AS member_count
             FROM mailing_lists ml{$whereSql}
             ORDER BY ml.name",
            $params
        );

        return $this->ok(['lists' => $lists]);
    }

    private function show(int $id): Response
    {
        $row = $this->db->one(
            "SELECT ml.*,
                    (SELECT COUNT(*) FROM list_membership lm WHERE lm.list_id = ml.id AND lm.status = 'subscribed') AS member_count
             FROM mailing_lists ml
             WHERE ml.id = ?",
            [$id]
        );
        if (!$row) {
            return $this->notFound('Mailing list not found');
        }
        return $this->ok(['list' => $row]);
    }

    private function create(Request $request): Response
    {
        $name = trim((string) $request->body('name', ''));
        if ($name === '') {
            return Response::json(['error' => 'A list name is required'], 422);
        }
        $description = trim((string) $request->body('description', '')) ?: null;

        try {
            $id = $this->db->insert(
                'INSERT INTO mailing_lists (name, description, created_by_user_id) VALUES (?, ?, ?)',
                [$name, $description, $this->userId()]
            );
        } catch (\PDOException $e) {
            if ($this->isDuplicateKey($e)) {
                return Response::json(['error' => 'A mailing list with that name already exists'], 422);
            }
            throw $e;
        }

        $row = $this->db->one('SELECT * FROM mailing_lists WHERE id = ?', [$id]);
        return $this->ok(['list' => $row]);
    }

    private function update(Request $request, int $id): Response
    {
        if (!$id) return $this->notFound();
        $existing = $this->db->one('SELECT * FROM mailing_lists WHERE id = ?', [$id]);
        if (!$existing) return $this->notFound('Mailing list not found');

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

        if ($fields === []) {
            return $this->ok(['list' => $existing]);
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

        $row = $this->db->one('SELECT * FROM mailing_lists WHERE id = ?', [$id]);
        return $this->ok(['list' => $row]);
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
        if (in_array($status, ['subscribed', 'unsubscribed'], true)) {
            $where[] = 'lm.status = ?';
            $params[] = $status;
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
            "SELECT c.id AS contact_id, c.first_name, c.last_name, c.email, c.marketing_opted_in,
                    lm.status, lm.added_at, lm.updated_at
             FROM list_membership lm
             JOIN contacts c ON c.id = lm.contact_id
             {$whereSql}
             ORDER BY c.last_name ASC, c.first_name ASC
             LIMIT {$limit} OFFSET {$offset}",
            $params
        );

        return $this->ok([
            'members' => $members,
            'total'   => $total,
            'page'    => $page,
            'limit'   => $limit,
            'pages'   => (int) ceil($total / $limit),
        ]);
    }

    private function addMembers(Request $request, int $listId): Response
    {
        $list = $this->db->one('SELECT id FROM mailing_lists WHERE id = ?', [$listId]);
        if (!$list) {
            return $this->notFound('Mailing list not found');
        }

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

        $placeholders = implode(',', array_fill(0, count($contactIds), '?'));
        $existingRows = $this->db->all("SELECT id FROM contacts WHERE id IN ({$placeholders})", $contactIds);
        $validIds = array_map(static fn ($r) => (int) $r['id'], $existingRows);

        $added = 0;
        foreach ($validIds as $contactId) {
            $this->db->run(
                "INSERT INTO list_membership (list_id, contact_id, status) VALUES (?, ?, 'subscribed')
                 ON DUPLICATE KEY UPDATE status = 'subscribed', updated_at = CURRENT_TIMESTAMP",
                [$listId, $contactId]
            );
            $added++;
        }

        $skipped = count($contactIds) - $added;

        return $this->ok([
            'added'   => $added,
            'skipped' => $skipped,
        ]);
    }

    private function updateMember(Request $request, int $listId, int $contactId): Response
    {
        $status = (string) $request->body('status', '');
        if (!in_array($status, ['subscribed', 'unsubscribed'], true)) {
            return Response::json(['error' => "status must be 'subscribed' or 'unsubscribed'"], 422);
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

        $row = $this->db->one(
            'SELECT * FROM list_membership WHERE list_id = ? AND contact_id = ?',
            [$listId, $contactId]
        );
        return $this->ok(['membership' => $row]);
    }

    private function removeMember(int $listId, int $contactId): Response
    {
        $existing = $this->db->one(
            'SELECT id FROM list_membership WHERE list_id = ? AND contact_id = ?',
            [$listId, $contactId]
        );
        if (!$existing) {
            return $this->notFound('List membership not found');
        }

        $this->db->run('DELETE FROM list_membership WHERE list_id = ? AND contact_id = ?', [$listId, $contactId]);
        return Response::noContent();
    }

    // ---- Helpers -------------------------------------------------------

    private function isDuplicateKey(\PDOException $e): bool
    {
        return ($e->errorInfo[1] ?? null) === 1062 || $e->getCode() === '23000';
    }
}
