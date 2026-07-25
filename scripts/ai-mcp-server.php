#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * scripts/ai-mcp-server.php — standalone stdio MCP server backing the AI
 * Assistant drawer (Phase 1: read-only). Spawned fresh, per request, by
 * `claude` via src/Ai/Assistant.php's --mcp-config — see
 * docs/AI-ASSISTANT-PLAN.md, "MCP tool server".
 *
 * This process bootstraps its own Database connection directly. It does
 * NOT go through Kernel/Auth/JWT — it has no HTTP Request to hang those
 * off of. The acting identity instead comes from two env vars,
 * PB_ACTING_USER_ID / PB_ACTING_ROLE, set by Assistant.php from the
 * request it already authenticated before spawning this process. There is
 * no argument on any tool below that lets the model specify who it's
 * acting as — the model cannot impersonate a role even if a crafted prompt
 * asks it to try.
 *
 * Every tool re-runs the exact same Panic\Capabilities checks a
 * human-facing endpoint would (see src/Capabilities.php) before touching
 * the DB — an admin using the AI can never see more than that same admin
 * could already see by hand through the normal UI.
 *
 * The tool registry is a fixed match()/switch dispatch over a hand-written
 * list, never reflection over arbitrary methods — adding a tool is always
 * a deliberate, reviewable source change, never data-driven. Phase 1 has
 * exactly two tools, both read-only: get_event, list_events. No write
 * tool, no SQL tool, no shell tool exists in this file, ever (see the
 * plan doc's "Guardrails, restated").
 *
 * Transport: newline-delimited JSON-RPC 2.0 over stdio, per the MCP stdio
 * spec — one JSON object per line on stdin, one per line on stdout,
 * flushed immediately after every write. All logging/diagnostics go to
 * stderr; nothing but protocol JSON is ever written to stdout, since one
 * stray warning on stdout would corrupt the stream the CLI is parsing.
 */

require __DIR__ . '/../src/bootstrap.php';

use Panic\Ai\EventContext;
use Panic\Capabilities;
use Panic\Database;
use Panic\Env;

error_reporting(E_ALL);
ini_set('display_errors', '0');
set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline): bool {
    fwrite(STDERR, "[ai-mcp-server] PHP error: $errstr in $errfile:$errline\n");
    return true; // swallow — never let a warning/notice leak onto stdout
});

$root = dirname(__DIR__);
Env::load($root . '/.env');
Env::load($root . '/storage/config/app-settings.env');

// ── Acting identity (never trust the model for this — see docblock) ────────
$actingUserId = (int) (getenv('PB_ACTING_USER_ID') ?: 0);
$actingRole   = (string) (getenv('PB_ACTING_ROLE') ?: '');
// PB_EVENT_ID (the event workspace the drawer was opened from, if any) is
// informational only — every tool below still requires an explicit
// event_id argument and still runs the full capability check on it. Kept
// available here for future tools that might want a default, not read yet.

if ($actingUserId <= 0 || $actingRole === '') {
    fwrite(STDERR, "[ai-mcp-server] missing/invalid PB_ACTING_USER_ID or PB_ACTING_ROLE — refusing to start\n");
    exit(1);
}

$db = new Database();

// ── JSON-RPC helpers ─────────────────────────────────────────────────────────

function mcp_send(array $message): void
{
    fwrite(STDOUT, json_encode($message, JSON_UNESCAPED_SLASHES) . "\n");
    fflush(STDOUT);
}

/** A successful tool call whose *content* is the JSON-encoded result. */
function mcp_tool_result($id, array $payload): void
{
    mcp_send([
        'jsonrpc' => '2.0',
        'id'      => $id,
        'result'  => ['content' => [['type' => 'text', 'text' => json_encode($payload, JSON_UNESCAPED_SLASHES)]]],
    ]);
}

/**
 * A tool-level failure (bad args, access denied, not found). Per MCP
 * convention this is still a successful JSON-RPC response with
 * isError:true in the result — not a JSON-RPC error object — so the model
 * sees the message and can react (e.g. ask a clarifying question) instead
 * of the whole turn blowing up as a transport-level failure.
 */
function mcp_tool_error($id, string $message): void
{
    mcp_send([
        'jsonrpc' => '2.0',
        'id'      => $id,
        'result'  => ['content' => [['type' => 'text', 'text' => $message]], 'isError' => true],
    ]);
}

// ── Tool registry (Phase 1: read-only) ──────────────────────────────────────

const AI_MCP_TOOLS = [
    'get_event' => [
        'description' => 'Get curated details for exactly one event by id: title, type, status, dates/times, promoter/booker contact fields, capacity, ticketing info, and lineup count. Never returns internal notes, raw financials, or any DB column not explicitly listed — ask a human for anything else.',
        'inputSchema' => [
            'type'       => 'object',
            'properties' => [
                'event_id' => ['type' => 'integer', 'description' => 'The event id to look up.'],
            ],
            'required' => ['event_id'],
        ],
    ],
    'list_events' => [
        'description' => 'List events matching optional filters, most recent first. Hard-capped at 25 rows per call — if you need more, call again with a narrower date range or other filter rather than assuming this is the complete result set.',
        'inputSchema' => [
            'type'       => 'object',
            'properties' => [
                'status'        => ['type' => 'string', 'description' => 'Exact event status match, e.g. "confirmed", "published", "completed", "canceled".'],
                'date_from'     => ['type' => 'string', 'description' => 'YYYY-MM-DD, inclusive lower bound on event date.'],
                'date_to'       => ['type' => 'string', 'description' => 'YYYY-MM-DD, inclusive upper bound on event date.'],
                'promoter_name' => ['type' => 'string', 'description' => 'Case-insensitive substring match against the promoter name.'],
                'limit'         => ['type' => 'integer', 'description' => 'Max rows to return. Default and hard cap: 25.'],
            ],
        ],
    ],
];

// ── Tool handlers ────────────────────────────────────────────────────────────

function handle_get_event($id, Database $db, int $userId, string $role, array $args): void
{
    $eventId = (int) ($args['event_id'] ?? 0);
    if ($eventId <= 0) {
        mcp_tool_error($id, 'event_id is required and must be a positive integer.');
        return;
    }

    // Re-runs the identical check Events::show() / event-workspace's load
    // path uses — this admin cannot see anything here they couldn't already
    // see by opening the event in the app.
    if (!Capabilities::hasEvent($db, $eventId, $userId, $role, 'read_event')) {
        mcp_tool_error($id, "No access to event {$eventId} (it may not exist, or you don't have read access to it).");
        return;
    }

    $event = EventContext::curated($db, $eventId);
    if (!$event) {
        // Capability check above already confirmed the row exists, so this
        // would only happen on a race (event deleted between the two
        // queries) — treat it the same as "not found" either way.
        mcp_tool_error($id, "Event {$eventId} not found.");
        return;
    }

    mcp_tool_result($id, $event);
}

function handle_list_events($id, Database $db, int $userId, string $role, array $args): void
{
    $limit = (int) ($args['limit'] ?? 25);
    if ($limit <= 0 || $limit > 25) {
        $limit = 25;
    }

    $where  = [];
    $params = [];

    // Same event-visibility scope as BaseEndpoint::eventScopeSql(): venue_admin
    // and global_viewer see every event; everyone else is scoped to events
    // they own or collaborate on. This is the same rule the human Events list
    // page runs.
    if (!Capabilities::hasGlobal($role, 'view_all_events')) {
        $where[]  = '(e.owner_user_id = ? OR EXISTS (SELECT 1 FROM event_collaborators ec WHERE ec.event_id = e.id AND ec.user_id = ?))';
        $params[] = $userId;
        $params[] = $userId;
    }

    if (!empty($args['status']) && is_string($args['status'])) {
        $where[]  = 'e.status = ?';
        $params[] = $args['status'];
    }
    if (!empty($args['date_from']) && is_string($args['date_from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $args['date_from'])) {
        $where[]  = 'e.date >= ?';
        $params[] = $args['date_from'];
    }
    if (!empty($args['date_to']) && is_string($args['date_to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $args['date_to'])) {
        $where[]  = 'e.date <= ?';
        $params[] = $args['date_to'];
    }
    if (!empty($args['promoter_name']) && is_string($args['promoter_name'])) {
        $where[]  = 'e.promoter_name LIKE ?';
        $params[] = '%' . $args['promoter_name'] . '%';
    }

    $sql = 'SELECT e.id, e.title, e.date, e.status, e.promoter_name, e.capacity FROM events e';
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY e.date DESC LIMIT ' . $limit; // $limit is int-cast above, never interpolated from raw input

    $rows = $db->all($sql, $params);
    mcp_tool_result($id, ['events' => $rows, 'count' => count($rows), 'limit' => $limit]);
}

// ── Main loop ────────────────────────────────────────────────────────────────

while (($line = fgets(STDIN)) !== false) {
    $line = trim($line);
    if ($line === '') {
        continue;
    }

    $message = json_decode($line, true);
    if (!is_array($message)) {
        continue;
    }

    $id     = $message['id'] ?? null;
    $method = (string) ($message['method'] ?? '');

    switch ($method) {
        case 'initialize':
            mcp_send([
                'jsonrpc' => '2.0',
                'id'      => $id,
                'result'  => [
                    'protocolVersion' => '2024-11-05',
                    'capabilities'    => ['tools' => new stdClass()],
                    'serverInfo'      => ['name' => 'panic-backstage-ai', 'version' => '1.0.0'],
                ],
            ]);
            break;

        case 'notifications/initialized':
            // Notification — no response.
            break;

        case 'tools/list':
            $tools = [];
            foreach (AI_MCP_TOOLS as $name => $def) {
                $tools[] = ['name' => $name, 'description' => $def['description'], 'inputSchema' => $def['inputSchema']];
            }
            mcp_send(['jsonrpc' => '2.0', 'id' => $id, 'result' => ['tools' => $tools]]);
            break;

        case 'tools/call':
            $name = (string) ($message['params']['name'] ?? '');
            $args = $message['params']['arguments'] ?? [];
            if (!is_array($args)) {
                $args = [];
            }
            try {
                match ($name) {
                    'get_event'   => handle_get_event($id, $db, $actingUserId, $actingRole, $args),
                    'list_events' => handle_list_events($id, $db, $actingUserId, $actingRole, $args),
                    default       => mcp_tool_error($id, "Unknown tool: {$name}"),
                };
            } catch (\Throwable $e) {
                fwrite(STDERR, "[ai-mcp-server] tool {$name} threw: {$e->getMessage()}\n");
                mcp_tool_error($id, 'Internal error handling this tool call.');
            }
            break;

        default:
            if ($id !== null) {
                mcp_send(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32601, 'message' => 'Method not found']]);
            }
            // Unhandled notifications (no id) are silently ignored.
    }
}
