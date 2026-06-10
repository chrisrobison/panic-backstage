<?php
declare(strict_types=1);

namespace Panic;

use function Panic\boolish;

/**
 * Marketing / CRM contacts — the audience that buys tickets and receives event
 * emails. Seeded from the ticketing provider's "Fan View" export
 * (scripts/import-fanview.php) and manageable in-app.
 *
 *   GET    /api/contacts            list (?q= &opted=0|1 &sort= &dir= &page= &limit=)
 *   GET    /api/contacts/{id}       show one
 *   POST   /api/contacts            create (manual)
 *   PATCH  /api/contacts/{id}       update editable fields
 *   DELETE /api/contacts/{id}       delete
 *
 * Gated by the manage_contacts global capability (venue_admin).
 */
final class Contacts extends BaseEndpoint
{
    private const SORTS = [
        'last_name'        => 'last_name',
        'first_name'       => 'first_name',
        'email'            => 'email',
        'usd_spend'        => 'usd_spend',
        'tickets_count'    => 'tickets_count',
        'events_count'     => 'events_count',
        'last_interaction' => 'last_interaction',
        'created_at'       => 'created_at',
    ];

    public function handle(Request $request): Response
    {
        if ($denied = $this->requireGlobalCapability('manage_contacts')) {
            return $denied;
        }
        $id = $this->params['contactId'] ?? null;
        return match ($request->method()) {
            'GET'    => $id ? $this->show((int) $id) : $this->index($request),
            'POST'   => $this->create($request),
            'PATCH'  => $this->update($request, (int) $id),
            'DELETE' => $this->delete((int) $id),
            default  => Response::methodNotAllowed(),
        };
    }

    private function index(Request $request): Response
    {
        $where = [];
        $params = [];

        $q = trim((string) $request->query('q'));
        if ($q !== '') {
            $where[] = '(first_name LIKE ? OR last_name LIKE ? OR CONCAT(first_name, " ", last_name) LIKE ? OR email LIKE ? OR phone LIKE ?)';
            $like = '%' . $q . '%';
            array_push($params, $like, $like, $like, $like, $like);
        }
        $opted = $request->query('opted');
        if ($opted === '0' || $opted === '1') {
            $where[] = 'marketing_opted_in = ?';
            $params[] = (int) $opted;
        }
        $whereSql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';

        $sortKey = (string) $request->query('sort');
        $sortCol = self::SORTS[$sortKey] ?? 'last_name';
        $dir = strtolower((string) $request->query('dir')) === 'desc' ? 'DESC' : 'ASC';
        // Stable, sensible secondary ordering.
        $orderSql = $sortCol === 'last_name'
            ? "last_name {$dir}, first_name {$dir}"
            : "{$sortCol} {$dir}, last_name ASC";

        $limit = (int) ($request->query('limit') ?: 50);
        $limit = max(1, min(200, $limit));
        $page = max(1, (int) ($request->query('page') ?: 1));
        $offset = ($page - 1) * $limit;

        $total = (int) ($this->db->one("SELECT COUNT(*) n FROM contacts{$whereSql}", $params)['n'] ?? 0);
        $contacts = $this->db->all(
            "SELECT * FROM contacts{$whereSql} ORDER BY {$orderSql} LIMIT {$limit} OFFSET {$offset}",
            $params
        );

        // Overall KPIs across the whole table (independent of the active filter).
        $stats = $this->db->one('SELECT COUNT(*) total, SUM(marketing_opted_in) opted_in, COALESCE(SUM(usd_spend),0) total_spend, COALESCE(SUM(tickets_count),0) total_tickets FROM contacts');

        return $this->ok([
            'contacts' => $contacts,
            'total'    => $total,
            'page'     => $page,
            'limit'    => $limit,
            'pages'    => (int) ceil($total / $limit),
            'sort'     => ['key' => array_search($sortCol, self::SORTS, true) ?: 'last_name', 'dir' => strtolower($dir)],
            'stats'    => [
                'total'         => (int) ($stats['total'] ?? 0),
                'opted_in'      => (int) ($stats['opted_in'] ?? 0),
                'total_spend'   => (float) ($stats['total_spend'] ?? 0),
                'total_tickets' => (int) ($stats['total_tickets'] ?? 0),
            ],
        ]);
    }

    private function show(int $id): Response
    {
        $row = $this->db->one('SELECT * FROM contacts WHERE id = ?', [$id]);
        if (!$row) {
            return $this->notFound('Contact not found');
        }
        return $this->ok(['contact' => $row]);
    }

    private function create(Request $request): Response
    {
        [$payload, $error] = $this->payload($request);
        if ($error) return $error;
        $id = $this->db->insert(
            'INSERT INTO contacts (source, first_name, last_name, email, phone, gender, birthday, marketing_opted_in, opt_in_date, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                'manual',
                $payload['first_name'], $payload['last_name'], $payload['email'], $payload['phone'],
                $payload['gender'], $payload['birthday'], $payload['marketing_opted_in'],
                $payload['marketing_opted_in'] ? date('Y-m-d') : null, $payload['notes'],
            ]
        );
        return $this->ok(['id' => $id]);
    }

    private function update(Request $request, int $id): Response
    {
        if (!$id) return $this->notFound();
        $existing = $this->db->one('SELECT id, marketing_opted_in, opt_in_date FROM contacts WHERE id = ?', [$id]);
        if (!$existing) return $this->notFound('Contact not found');
        [$payload, $error] = $this->payload($request);
        if ($error) return $error;
        // Stamp an opt-in date the first time someone is opted in.
        $optDate = $existing['opt_in_date'];
        if ($payload['marketing_opted_in'] && !(int) $existing['marketing_opted_in'] && !$optDate) {
            $optDate = date('Y-m-d');
        }
        $this->db->run(
            'UPDATE contacts SET first_name=?, last_name=?, email=?, phone=?, gender=?, birthday=?, marketing_opted_in=?, opt_in_date=?, notes=? WHERE id=?',
            [
                $payload['first_name'], $payload['last_name'], $payload['email'], $payload['phone'],
                $payload['gender'], $payload['birthday'], $payload['marketing_opted_in'], $optDate,
                $payload['notes'], $id,
            ]
        );
        return $this->ok(['ok' => true]);
    }

    private function delete(int $id): Response
    {
        if (!$id) return $this->notFound();
        $this->db->run('DELETE FROM contacts WHERE id = ?', [$id]);
        return Response::noContent();
    }

    /** @return array{0: array, 1: ?Response} */
    private function payload(Request $request): array
    {
        $first = trim((string) $request->body('first_name', ''));
        $last  = trim((string) $request->body('last_name', ''));
        if ($first === '' && $last === '') {
            return [[], Response::json(['error' => 'A first or last name is required'], 422)];
        }
        $email = trim((string) $request->body('email', ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return [[], Response::json(['error' => 'Invalid email'], 422)];
        }
        $birthday = trim((string) $request->body('birthday', ''));
        $birthday = ($birthday !== '' && ($ts = strtotime($birthday)) !== false) ? date('Y-m-d', $ts) : null;

        return [[
            'first_name'         => $first ?: null,
            'last_name'          => $last ?: null,
            'email'              => $email !== '' ? strtolower($email) : null,
            'phone'              => trim((string) $request->body('phone', '')) ?: null,
            'gender'             => trim((string) $request->body('gender', '')) ?: null,
            'birthday'           => $birthday,
            'marketing_opted_in' => boolish($request->body('marketing_opted_in', 0)) ? 1 : 0,
            'notes'              => trim((string) $request->body('notes', '')) ?: null,
        ], null];
    }
}
