<?php
declare(strict_types=1);

namespace Panic;

/**
 * DatabaseBrowser — read-only inspector for the current tenant database.
 *
 *   GET /api/db-browser                 → list tables (name + approximate row count)
 *   GET /api/db-browser/{table}         → paginated rows + column metadata
 *                                          (?page=&limit=&sort=col&dir=asc|desc&filter[col]=text)
 *   GET /api/db-browser/{table}/export  → download rows matching the same sort/filter
 *                                          (?format=csv|xls|sql)
 *
 * Connection follows the standard app pattern: the tenant PDO is resolved from
 * the hostname in the super DB (TenantContext) and injected into $this->db, so
 * this endpoint always reads the caller's own tenant database — never another
 * tenant's.
 *
 * Gated by manage_users (venue_admin / tenant instance admin). Table and column
 * identifiers cannot be bound as SQL parameters, so every one is validated
 * against the live schema before it is interpolated, and then back-tick quoted.
 */
final class DatabaseBrowser extends BaseEndpoint
{
    private const DEFAULT_LIMIT = 50;
    private const MAX_LIMIT     = 200;
    private const MAX_EXPORT_ROWS = 20000;

    public function handle(Request $request): Response
    {
        if ($denied = $this->requireGlobalCapability('manage_users')) {
            return $denied;
        }
        if ($request->method() !== 'GET') {
            return Response::methodNotAllowed();
        }

        $table  = $this->params['table'] ?? null;
        $action = $this->params['action'] ?? null;

        if ($table === null || $table === '') {
            return $this->tables();
        }
        return $action === 'export'
            ? $this->export($request, (string) $table)
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

        $columns     = $this->columnsFor($table);
        $columnNames = array_map(static fn (array $c): string => $c['name'], $columns);

        [$where, $filterParams] = $this->buildFilterClause($request, $columnNames);
        $order = $this->buildOrderClause($request, $columnNames);

        $total = (int) ($this->db->one("SELECT COUNT(*) AS n FROM {$quoted} {$where}", $filterParams)['n'] ?? 0);

        $rows = $this->db->all(
            "SELECT * FROM {$quoted} {$where} {$order} LIMIT ? OFFSET ?",
            [...$filterParams, $limit, $offset]
        );

        return $this->ok([
            'table'   => $table,
            'columns' => $columns,
            'rows'    => $rows,
            'total'   => $total,
            'page'    => $page,
            'limit'   => $limit,
        ]);
    }

    // ─── Export (CSV / XLS / SQL) ────────────────────────────────────────────────

    private function export(Request $request, string $table): Response
    {
        if (!in_array($table, $this->tableNames(), true)) {
            return $this->notFound('Unknown table');
        }

        $format = strtolower((string) ($request->query('format') ?: 'csv'));
        if (!in_array($format, ['csv', 'xls', 'sql'], true)) {
            return Response::json(['error' => 'format must be csv, xls, or sql'], 400);
        }

        $columns     = $this->columnsFor($table);
        $columnNames = array_map(static fn (array $c): string => $c['name'], $columns);

        $quoted = '`' . str_replace('`', '``', $table) . '`';
        [$where, $filterParams] = $this->buildFilterClause($request, $columnNames);
        $order = $this->buildOrderClause($request, $columnNames);

        $rows = $this->db->all(
            "SELECT * FROM {$quoted} {$where} {$order} LIMIT " . self::MAX_EXPORT_ROWS,
            $filterParams
        );

        $stamp = date('Y-m-d');
        return match ($format) {
            'csv' => Response::csv($this->toCsv($columnNames, $rows), "{$table}-{$stamp}.csv"),
            'xls' => Response::download($this->toXls($columnNames, $rows), "{$table}-{$stamp}.xls", 'application/vnd.ms-excel; charset=utf-8'),
            default => Response::download($this->toSql($table, $columnNames, $rows), "{$table}-{$stamp}.sql", 'application/sql; charset=utf-8'),
        };
    }

    private function toCsv(array $columnNames, array $rows): string
    {
        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, $columnNames);
        foreach ($rows as $row) {
            fputcsv($stream, array_map(
                static fn ($v) => $v === null ? '' : (string) $v,
                array_map(static fn (string $c) => $row[$c] ?? null, $columnNames)
            ));
        }
        rewind($stream);
        $csv = (string) stream_get_contents($stream);
        fclose($stream);
        return $csv;
    }

    /** A plain HTML table served as .xls — every spreadsheet app (Excel, Sheets, LibreOffice) opens this directly. */
    private function toXls(array $columnNames, array $rows): string
    {
        $head = '<tr>' . implode('', array_map(
            static fn (string $c) => '<th>' . htmlspecialchars($c, ENT_QUOTES) . '</th>',
            $columnNames
        )) . '</tr>';

        $body = implode('', array_map(function (array $row) use ($columnNames): string {
            $cells = implode('', array_map(static function (string $c) use ($row): string {
                $value = $row[$c] ?? null;
                return '<td>' . ($value === null ? '' : htmlspecialchars((string) $value, ENT_QUOTES)) . '</td>';
            }, $columnNames));
            return "<tr>{$cells}</tr>";
        }, $rows));

        return '<html><head><meta charset="UTF-8"></head><body><table border="1">' . $head . $body . '</table></body></html>';
    }

    private function toSql(string $table, array $columnNames, array $rows): string
    {
        $pdo = $this->db->pdo();
        $quotedTable = '`' . str_replace('`', '``', $table) . '`';
        $quotedCols  = implode(', ', array_map(
            static fn (string $c) => '`' . str_replace('`', '``', $c) . '`',
            $columnNames
        ));

        $lines = ["-- Export of {$table} — " . date('c')];
        if (count($rows) >= self::MAX_EXPORT_ROWS) {
            $lines[] = "-- Row limit (" . self::MAX_EXPORT_ROWS . ") reached; export truncated.";
        }
        $lines[] = '';

        foreach ($rows as $row) {
            $values = implode(', ', array_map(static function (string $c) use ($row, $pdo): string {
                $value = $row[$c] ?? null;
                return $value === null ? 'NULL' : $pdo->quote((string) $value);
            }, $columnNames));
            $lines[] = "INSERT INTO {$quotedTable} ({$quotedCols}) VALUES ({$values});";
        }

        return implode("\n", $lines) . "\n";
    }

    // ─── Shared helpers: schema lookup, filtering, sorting ──────────────────────

    /** @return array<int,array{name:string,type:string,key:string}> column metadata for $table. */
    private function columnsFor(string $table): array
    {
        return array_map(static fn (array $c): array => [
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
    }

    /**
     * Builds a `WHERE col LIKE ? AND ...` clause from the `filter[col]=text` query
     * params, ignoring any key that isn't a real column of this table (columns
     * can't be bound as parameters, so they're whitelisted against $validColumns
     * before being interpolated).
     *
     * @param string[] $validColumns
     * @return array{0:string,1:array<int,string>} [whereSql, params]
     */
    private function buildFilterClause(Request $request, array $validColumns): array
    {
        $filters = $request->query('filter');
        if (!is_array($filters) || $filters === []) {
            return ['', []];
        }

        $clauses = [];
        $params  = [];
        foreach ($filters as $column => $value) {
            if (!is_string($column) || !in_array($column, $validColumns, true)) {
                continue;
            }
            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }
            $quotedCol = '`' . str_replace('`', '``', $column) . '`';
            $clauses[] = "{$quotedCol} LIKE ?";
            $params[]  = $this->likePattern($value);
        }

        return $clauses === [] ? ['', []] : ['WHERE ' . implode(' AND ', $clauses), $params];
    }

    /** Wraps $value in '%…%' for a substring search, unless it already contains a '%' wildcard. */
    private function likePattern(string $value): string
    {
        return str_contains($value, '%') ? $value : "%{$value}%";
    }

    /**
     * @param string[] $validColumns
     */
    private function buildOrderClause(Request $request, array $validColumns): string
    {
        $sort = (string) ($request->query('sort') ?: '');
        if ($sort === '' || !in_array($sort, $validColumns, true)) {
            return '';
        }
        $dir = strtolower((string) ($request->query('dir') ?: 'asc')) === 'desc' ? 'DESC' : 'ASC';
        $quotedCol = '`' . str_replace('`', '``', $sort) . '`';
        return "ORDER BY {$quotedCol} {$dir}";
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
