<?php
declare(strict_types=1);

namespace Panic;

/**
 * Outbox — read-only view of every transactional email the system has sent.
 * Populated automatically by Mailer whenever a Database is injected at
 * construction time.
 *
 *   GET  /api/outbox                list (?q= &sort= &dir= &page= &limit=)
 *   GET  /api/outbox/{id}           single message (includes full bodies)
 *
 * Gated by manage_users (venue_admin only) — the outbox contains all
 * system-generated email including magic-link tokens and user addresses.
 */
final class Outbox extends BaseEndpoint
{
    private const SORTS = [
        'sent_at'    => 'sent_at',
        'to_address' => 'to_address',
        'subject'    => 'subject',
        'template'   => 'template',
    ];

    public function handle(Request $request): Response
    {
        if ($denied = $this->requireGlobalCapability('manage_users')) {
            return $denied;
        }

        $id = $this->params['outboxId'] ?? null;

        return match ($request->method()) {
            'GET'   => $id ? $this->show((int) $id) : $this->index($request),
            default => Response::methodNotAllowed(),
        };
    }

    // ─── List ──────────────────────────────────────────────────────────────────

    private function index(Request $request): Response
    {
        $where  = [];
        $params = [];

        $q = trim((string) $request->query('q'));
        if ($q !== '') {
            $where[] = '(to_address LIKE ? OR subject LIKE ? OR template LIKE ?)';
            $like    = '%' . $q . '%';
            array_push($params, $like, $like, $like);
        }

        $whereSql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';

        $sortKey = (string) $request->query('sort');
        $sortCol = self::SORTS[$sortKey] ?? 'sent_at';
        // Default to newest-first; explicit ?dir=asc reverses it.
        $dir = strtolower((string) $request->query('dir')) === 'asc' ? 'ASC' : 'DESC';

        $limit  = max(1, min(200, (int) ($request->query('limit') ?: 50)));
        $page   = max(1, (int) ($request->query('page') ?: 1));
        $offset = ($page - 1) * $limit;

        $total = (int) ($this->db->one(
            "SELECT COUNT(*) n FROM outbox{$whereSql}",
            $params
        )['n'] ?? 0);

        // List view: omit bulky body columns; client fetches them on demand.
        $rows = $this->db->all(
            "SELECT id, sent_at, to_address, subject, template
               FROM outbox{$whereSql}
              ORDER BY {$sortCol} {$dir}
              LIMIT ? OFFSET ?",
            [...$params, $limit, $offset]
        );

        return $this->ok([
            'messages' => $rows,
            'total'    => $total,
            'page'     => $page,
            'limit'    => $limit,
        ]);
    }

    // ─── Single message ────────────────────────────────────────────────────────

    private function show(int $id): Response
    {
        $row = $this->db->one('SELECT * FROM outbox WHERE id = ?', [$id]);
        if (!$row) {
            return $this->notFound('Message not found');
        }
        return $this->ok(['message' => $row]);
    }
}
