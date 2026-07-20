<?php
declare(strict_types=1);

namespace Panic;

/**
 * DbHistory — browse the audit trail written by the AFTER INSERT/UPDATE/DELETE
 * triggers (see scripts/generate-audit-triggers.php) into db_history, and let an
 * admin undo (or redo) any individual change.
 *
 *   GET  /api/db-history                 → paginated list (?table=&pk=&actor=&action=&from=&to=&undone=any|yes|no&page=&limit=)
 *   GET  /api/db-history/{id}            → one entry, full old/new JSON + undo SQL
 *   POST /api/db-history/{id}/undo       → execute undo_sql, mark this entry undone,
 *                                          and link the resulting new entry back to it
 *
 * Gated by manage_db_history (venue_admin only) — this is more dangerous than the
 * read-only DatabaseBrowser, so it gets its own capability rather than reusing
 * manage_users.
 *
 * There is no separate "redo": undoing a change is itself a real write, so it
 * fires the same triggers and produces its own db_history entry. Redoing the
 * original change is just running undo again on *that* entry — undo_of_id links
 * the two so the UI can show the chain.
 */
final class DbHistory extends BaseEndpoint
{
    private const DEFAULT_LIMIT = 50;
    private const MAX_LIMIT     = 200;

    public function handle(Request $request): Response
    {
        if ($denied = $this->requireGlobalCapability('manage_db_history')) {
            return $denied;
        }

        $id     = $this->params['id'] ?? null;
        $action = $this->params['action'] ?? null;

        if ($id === null) {
            if ($request->method() !== 'GET') {
                return Response::methodNotAllowed();
            }
            return $this->list($request);
        }

        if ($action === 'undo') {
            if ($request->method() !== 'POST') {
                return Response::methodNotAllowed();
            }
            return $this->undo((int) $id);
        }

        if ($request->method() !== 'GET') {
            return Response::methodNotAllowed();
        }
        return $this->detail((int) $id);
    }

    // ─── List ────────────────────────────────────────────────────────────────────

    private function list(Request $request): Response
    {
        $limit  = max(1, min(self::MAX_LIMIT, (int) ($request->query('limit') ?: self::DEFAULT_LIMIT)));
        $page   = max(1, (int) ($request->query('page') ?: 1));
        $offset = ($page - 1) * $limit;

        [$where, $params] = $this->buildFilter($request);

        $total = (int) ($this->db->one("SELECT COUNT(*) AS n FROM db_history {$where}", $params)['n'] ?? 0);

        $rows = $this->db->all(
            "SELECT id, table_name, pk_column, pk_value, action, actor,
                    undone_at, undone_by_actor, undo_of_id, created_at,
                    old_row, new_row
               FROM db_history
               {$where}
              ORDER BY id DESC
              LIMIT ? OFFSET ?",
            [...$params, $limit, $offset]
        );

        $entries = array_map(fn (array $r) => $this->summarize($r), $rows);

        $tables = $this->db->all(
            "SELECT DISTINCT table_name FROM db_history ORDER BY table_name"
        );

        return $this->ok([
            'entries' => $entries,
            'total'   => $total,
            'page'    => $page,
            'limit'   => $limit,
            'tables'  => array_map(static fn (array $t) => $t['table_name'], $tables),
        ]);
    }

    /** @return array{0:string,1:array<int,mixed>} */
    private function buildFilter(Request $request): array
    {
        $clauses = [];
        $params  = [];

        if ($table = trim((string) ($request->query('table') ?: ''))) {
            $clauses[] = 'table_name = ?';
            $params[]  = $table;
        }
        if ($pk = trim((string) ($request->query('pk') ?: ''))) {
            $clauses[] = 'pk_value = ?';
            $params[]  = $pk;
        }
        if ($actor = trim((string) ($request->query('actor') ?: ''))) {
            $clauses[] = 'actor LIKE ?';
            $params[]  = "%{$actor}%";
        }
        $action = strtoupper(trim((string) ($request->query('action') ?: '')));
        if (in_array($action, ['INSERT', 'UPDATE', 'DELETE'], true)) {
            $clauses[] = 'action = ?';
            $params[]  = $action;
        }
        if ($from = trim((string) ($request->query('from') ?: ''))) {
            $clauses[] = 'created_at >= ?';
            $params[]  = $from;
        }
        if ($to = trim((string) ($request->query('to') ?: ''))) {
            $clauses[] = 'created_at <= ?';
            $params[]  = $to;
        }
        $undone = strtolower(trim((string) ($request->query('undone') ?: '')));
        if ($undone === 'yes') {
            $clauses[] = 'undone_at IS NOT NULL';
        } elseif ($undone === 'no') {
            $clauses[] = 'undone_at IS NULL';
        }

        return $clauses === [] ? ['', []] : ['WHERE ' . implode(' AND ', $clauses), $params];
    }

    /** List-view row: id/meta + a short changed-fields summary, no full JSON blobs. */
    private function summarize(array $row): array
    {
        return DbHistorySupport::summarize($row);
    }

    /** @return array<int,array{field:string,from:mixed,to:mixed}> */
    private function diffSummary(string $action, ?array $old, ?array $new): array
    {
        return DbHistorySupport::diffSummary($action, $old, $new);
    }

    // ─── Detail ──────────────────────────────────────────────────────────────────

    private function detail(int $id): Response
    {
        $row = $this->db->one('SELECT * FROM db_history WHERE id = ?', [$id]);
        if (!$row) {
            return $this->notFound('History entry not found');
        }

        $old = $row['old_row'] !== null ? json_decode((string) $row['old_row'], true) : null;
        $new = $row['new_row'] !== null ? json_decode((string) $row['new_row'], true) : null;

        // The entry (if any) that resulted from undoing *this* one.
        $undoResult = $row['undone_at'] !== null
            ? $this->db->one('SELECT id, created_at, actor FROM db_history WHERE undo_of_id = ? ORDER BY id DESC LIMIT 1', [$id])
            : null;

        return $this->ok([
            'id'              => (int) $row['id'],
            'table_name'      => $row['table_name'],
            'pk_column'       => $row['pk_column'],
            'pk_value'        => $row['pk_value'],
            'action'          => $row['action'],
            'actor'           => $row['actor'],
            'created_at'      => $row['created_at'],
            'undone_at'       => $row['undone_at'],
            'undone_by_actor' => $row['undone_by_actor'],
            'undo_of_id'      => $row['undo_of_id'] !== null ? (int) $row['undo_of_id'] : null,
            'old_row'         => $old,
            'new_row'         => $new,
            'changed_fields'  => $this->diffSummary($row['action'], $old, $new),
            'undo_sql'        => $row['undo_sql'],
            'undo_result'     => $undoResult,
        ]);
    }

    // ─── Undo (= redo, when applied to an undo's own entry) ─────────────────────

    private function undo(int $id): Response
    {
        $actor = 'user:' . $this->userId();

        try {
            $result = DbHistorySupport::undo($this->db, $id, $actor);
        } catch (DbHistoryUndoPrecondition $e) {
            return $e->getCode() === 404
                ? $this->notFound($e->getMessage())
                : Response::json(['error' => $e->getMessage()], 409);
        } catch (\Throwable $e) {
            return Response::json(['error' => 'Undo failed: ' . $e->getMessage()], 500);
        }

        return $this->ok([
            'ok'              => true,
            'undone_id'       => $id,
            'result_entry_id' => $result['result_entry_id'],
        ]);
    }
}
