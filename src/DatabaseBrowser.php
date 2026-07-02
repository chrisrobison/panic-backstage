<?php
declare(strict_types=1);

namespace Panic;

/**
 * DatabaseBrowser — read-only inspector for the current tenant database.
 *
 *   GET /api/db-browser            → list tables (name + approximate row count)
 *   GET /api/db-browser/{table}    → paginated rows + column metadata (?page= &limit=)
 *
 * Connection follows the standard app pattern: the tenant PDO is resolved from
 * the hostname in the super DB (TenantContext) and injected into $this->db, so
 * this endpoint always reads the caller's own tenant database — never another
 * tenant's.
 *
 * Gated by manage_users (venue_admin / tenant instance admin). Table identifiers
 * cannot be bound as SQL parameters, so every table name is validated against
 * the live schema before it is interpolated, and then back-tick quoted.
 */
final class DatabaseBrowser extends BaseEndpoint
{
    private const DEFAULT_LIMIT = 50;
    private const MAX_LIMIT     = 200;

    public function handle(Request $request): Response
    {
        if ($denied = $this->requireGlobalCapability('manage_users')) {
            return $denied;
        }
        if ($request->method() !== 'GET') {
            return Response::methodNotAllowed();
        }

        $table = $this->params['table'] ?? null;
        return $table === null || $table === ''
            ? $this->tables()
            : $this->rows($request, (string) $table);
    }

    // ─── Table list ──────────────────────────────────────────────────────────────

    private function tables(): Response
    {
        $rows = $this->db->all(
            "SELECT table_name AS name, table_rows AS approx_rows
               FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'
              ORDER BY table_name"
        );

        $tables = array_map(static fn (array $r): array => [
            'name'        => (string) ($r['name'] ?? $r['NAME'] ?? ''),
            'approx_rows' => (int) ($r['approx_rows'] ?? $r['APPROX_ROWS'] ?? 0),
        ], $rows);

        return $this->ok(['tables' => $tables]);
    }

    // ─── Rows for one table ──────────────────────────────────────────────────────

    private function rows(Request $request, string $table): Response
    {
        if (!in_array($table, $this->tableNames(), true)) {
            return $this->notFound('Unknown table');
        }

        $limit  = max(1, min(self::MAX_LIMIT, (int) ($request->query('limit') ?: self::DEFAULT_LIMIT)));
        $page   = max(1, (int) ($request->query('page') ?: 1));
        $offset = ($page - 1) * $limit;

        // Safe: $table was validated against the live schema above.
        $quoted = '`' . str_replace('`', '``', $table) . '`';

        $total = (int) ($this->db->one("SELECT COUNT(*) AS n FROM {$quoted}")['n'] ?? 0);

        $columns = array_map(static fn (array $c): array => [
            'name' => (string) ($c['name'] ?? $c['NAME'] ?? ''),
            'type' => (string) ($c['type'] ?? $c['TYPE'] ?? ''),
            'key'  => (string) ($c['key'] ?? $c['KEY'] ?? ''),
        ], $this->db->all(
            "SELECT column_name AS name, data_type AS type, column_key AS `key`
               FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = ?
              ORDER BY ordinal_position",
            [$table]
        ));

        $rows = $this->db->all("SELECT * FROM {$quoted} LIMIT ? OFFSET ?", [$limit, $offset]);

        return $this->ok([
            'table'   => $table,
            'columns' => $columns,
            'rows'    => $rows,
            'total'   => $total,
            'page'    => $page,
            'limit'   => $limit,
        ]);
    }

    /** @return string[] every base table name in the current tenant database. */
    private function tableNames(): array
    {
        $rows = $this->db->all(
            "SELECT table_name AS name
               FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'"
        );
        return array_map(static fn (array $r): string => (string) ($r['name'] ?? $r['NAME'] ?? ''), $rows);
    }
}
