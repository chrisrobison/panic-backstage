<?php
declare(strict_types=1);

namespace Panic;

/**
 * Thrown only for the two "can't even attempt this" preconditions in
 * DbHistorySupport::undo() (entry not found / already undone) — never for a
 * failure of the undo_sql itself. Kept distinct from the generic
 * \RuntimeException so callers can catch precisely these two cases without
 * also swallowing PDOException, which (being a \RuntimeException subclass)
 * would otherwise be caught by the same block and lose its "Undo failed"
 * framing.
 */
final class DbHistoryUndoPrecondition extends \RuntimeException
{
}

/**
 * Shared plumbing behind every Undo button that reads from `db_history`
 * (see scripts/generate-audit-triggers.php for how that table gets filled).
 * Two call sites use this:
 *
 *   - src/DbHistory.php     — the venue-admin-only Database History screen,
 *                             which can undo any row in any table.
 *   - src/Events/History.php — an event-scoped view that lets anyone who can
 *                             edit an event undo changes to just that event's
 *                             own row + a curated set of directly related
 *                             tables (execution records, payments, etc.).
 *
 * Keeping the actual "run undo_sql, mark this entry undone, link the
 * resulting new row back to it" transaction in one place means a fix to that
 * logic can't accidentally apply to only one of the two undo buttons.
 */
final class DbHistorySupport
{
    /**
     * Executes the stored undo_sql for entry $id inside a transaction, marks
     * it undone, and links whichever new db_history row that write produced
     * (via the AFTER trigger on the target table) back to this entry so the
     * UI can show the "undo of the undo" pair.
     *
     * @return array{result_entry_id:?int}
     * @throws DbHistoryUndoPrecondition with HTTP-status-shaped code 404/409
     *         for the "not found" / "already undone" preconditions.
     * @throws \Throwable if undo_sql itself fails; the transaction is rolled
     *         back before rethrowing.
     */
    public static function undo(Database $db, int $id, string $actor): array
    {
        $row = $db->one('SELECT * FROM db_history WHERE id = ?', [$id]);
        if (!$row) {
            throw new DbHistoryUndoPrecondition('History entry not found', 404);
        }
        if ($row['undone_at'] !== null) {
            throw new DbHistoryUndoPrecondition('This entry has already been undone.', 409);
        }

        $pdo = $db->pdo();
        $pdo->beginTransaction();
        try {
            // The undo_sql was built server-side by the trigger from QUOTE()'d
            // historical values — not user input — so executing it directly is
            // safe; it can't be parameterized since it's a full statement, not
            // a single value.
            $pdo->exec((string) $row['undo_sql']);

            // That exec just fired the same AFTER trigger on the target table,
            // which inserted its own new db_history row for the reverse write.
            // Find it (same table+pk, newest) and link it back to the entry we
            // just undid.
            $resultRow = $db->one(
                'SELECT id FROM db_history
                  WHERE table_name = ? AND pk_value = ? AND id > ?
                  ORDER BY id DESC LIMIT 1',
                [$row['table_name'], $row['pk_value'], $id]
            );

            if ($resultRow) {
                $db->run('UPDATE db_history SET undo_of_id = ? WHERE id = ?', [$id, $resultRow['id']]);
            }

            $db->run(
                'UPDATE db_history SET undone_at = NOW(6), undone_by_actor = ? WHERE id = ?',
                [$actor, $id]
            );

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return ['result_entry_id' => $resultRow['id'] ?? null];
    }

    /** @return array<int,array{field:string,from:mixed,to:mixed}> */
    public static function diffSummary(string $action, ?array $old, ?array $new): array
    {
        if ($action !== 'UPDATE' || $old === null || $new === null) {
            return [];
        }
        $changes = [];
        foreach ($new as $field => $value) {
            $before = $old[$field] ?? null;
            if ($before !== $value) {
                $changes[] = ['field' => $field, 'from' => $before, 'to' => $value];
            }
        }
        return $changes;
    }

    /** List-view row: id/meta + a short changed-fields summary, no full JSON blobs. */
    public static function summarize(array $row): array
    {
        $old = $row['old_row'] !== null ? json_decode((string) $row['old_row'], true) : null;
        $new = $row['new_row'] !== null ? json_decode((string) $row['new_row'], true) : null;

        return [
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
            'changed_fields'  => self::diffSummary($row['action'], $old, $new),
        ];
    }
}
