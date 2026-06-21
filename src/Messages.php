<?php
declare(strict_types=1);

namespace Panic;

/**
 * Messages — in-app messaging for staff (Inbox / Archive / Outbox).
 *
 * A single `messages` row serves two views: it shows in the recipient's Inbox
 * (recipient_user_id) and in the sender's Outbox (sender_user_id). System
 * notifications are fanned out from outgoing email by Mailer (sender NULL);
 * staff compose/reply creates a row here and also emails the recipient.
 *
 *   GET  /api/messages?box=inbox|archive|sent      list (?q= &page= &limit=)
 *   GET  /api/messages/{id}                        single message (marks read)
 *   POST /api/messages                             compose / reply
 *   POST /api/messages/{id}/archive                archive (recipient only)
 *   POST /api/messages/{id}/unarchive              restore to inbox
 *   POST /api/messages/{id}/read                   mark read
 *   POST /api/messages/{id}/unread                 mark unread
 *   GET  /api/messages/recipients                  addressable users
 *   GET  /api/messages/unread-count                inbox unread count
 *
 * Authenticated users only (gated at the kernel). Each user sees only their
 * own inbox/sent; no global capability is required.
 */
final class Messages extends BaseEndpoint
{
    public function handle(Request $request): Response
    {
        if (!$this->userId()) {
            return $this->forbidden();
        }

        $sub    = $this->params['sub'] ?? null;       // 'recipients' | 'unread-count' | numeric id
        $id     = $this->params['messageId'] ?? null; // numeric id (when $sub is numeric)
        $action = $this->params['action'] ?? null;    // 'archive' | 'unarchive' | 'read' | 'unread'
        $method = $request->method();

        // Collection-level named sub-resources
        if ($sub === 'recipients') {
            return $method === 'GET' ? $this->recipients() : Response::methodNotAllowed();
        }
        if ($sub === 'unread-count') {
            return $method === 'GET' ? $this->unreadCount() : Response::methodNotAllowed();
        }

        // Single message + actions
        if ($id !== null) {
            $messageId = (int) $id;
            if ($method === 'GET') {
                return $this->show($messageId);
            }
            if ($method === 'POST') {
                return match ($action) {
                    'archive'   => $this->setArchived($messageId, true),
                    'unarchive' => $this->setArchived($messageId, false),
                    'read'      => $this->setRead($messageId, true),
                    'unread'    => $this->setRead($messageId, false),
                    default     => $this->notFound('Unknown action'),
                };
            }
            return Response::methodNotAllowed();
        }

        // Collection
        return match ($method) {
            'GET'   => $this->index($request),
            'POST'  => $this->compose($request),
            default => Response::methodNotAllowed(),
        };
    }

    // ─── List ────────────────────────────────────────────────────────────────────

    private function index(Request $request): Response
    {
        $me   = $this->userId();
        $box  = (string) $request->query('box') ?: 'inbox';
        $box  = in_array($box, ['inbox', 'archive', 'sent'], true) ? $box : 'inbox';

        $limit  = max(1, min(200, (int) ($request->query('limit') ?: 50)));
        $page   = max(1, (int) ($request->query('page') ?: 1));
        $offset = ($page - 1) * $limit;

        $q = trim((string) $request->query('q'));

        if ($box === 'sent') {
            $where  = ['m.sender_user_id = ?'];
            $params = [$me];
            if ($q !== '') {
                $where[]  = '(m.subject LIKE ? OR ru.name LIKE ? OR ru.email LIKE ?)';
                $like     = '%' . $q . '%';
                array_push($params, $like, $like, $like);
            }
            $whereSql = ' WHERE ' . implode(' AND ', $where);

            $total = (int) ($this->db->one(
                "SELECT COUNT(*) n FROM messages m LEFT JOIN users ru ON ru.id = m.recipient_user_id{$whereSql}",
                $params
            )['n'] ?? 0);

            $rows = $this->db->all(
                "SELECT m.id, m.created_at, m.subject, m.template, m.read_at,
                        m.recipient_user_id, ru.name AS recipient_name, m.recipient_email
                   FROM messages m
                   LEFT JOIN users ru ON ru.id = m.recipient_user_id
                  {$whereSql}
                  ORDER BY m.created_at DESC
                  LIMIT ? OFFSET ?",
                [...$params, $limit, $offset]
            );
        } else {
            $where  = ['m.recipient_user_id = ?', $box === 'archive' ? 'm.archived_at IS NOT NULL' : 'm.archived_at IS NULL'];
            $params = [$me];
            if ($q !== '') {
                $where[]  = '(m.subject LIKE ? OR su.name LIKE ? OR su.email LIKE ?)';
                $like     = '%' . $q . '%';
                array_push($params, $like, $like, $like);
            }
            $whereSql = ' WHERE ' . implode(' AND ', $where);

            $total = (int) ($this->db->one(
                "SELECT COUNT(*) n FROM messages m LEFT JOIN users su ON su.id = m.sender_user_id{$whereSql}",
                $params
            )['n'] ?? 0);

            $rows = $this->db->all(
                "SELECT m.id, m.created_at, m.subject, m.template, m.read_at, m.archived_at,
                        m.sender_user_id, su.name AS sender_name, su.email AS sender_email
                   FROM messages m
                   LEFT JOIN users su ON su.id = m.sender_user_id
                  {$whereSql}
                  ORDER BY m.created_at DESC
                  LIMIT ? OFFSET ?",
                [...$params, $limit, $offset]
            );
        }

        return $this->ok([
            'messages' => $rows,
            'total'    => $total,
            'page'     => $page,
            'limit'    => $limit,
            'box'      => $box,
        ]);
    }

    // ─── Single message ──────────────────────────────────────────────────────────

    private function show(int $id): Response
    {
        $me  = $this->userId();
        $row = $this->db->one(
            'SELECT m.*, su.name AS sender_name, su.email AS sender_email,
                    ru.name AS recipient_name
               FROM messages m
               LEFT JOIN users su ON su.id = m.sender_user_id
               LEFT JOIN users ru ON ru.id = m.recipient_user_id
              WHERE m.id = ?',
            [$id]
        );

        if (!$row) {
            return $this->notFound('Message not found');
        }
        if ((int) $row['recipient_user_id'] !== $me && (int) ($row['sender_user_id'] ?? 0) !== $me) {
            return $this->forbidden();
        }

        // Mark read the first time the recipient opens it.
        if ((int) $row['recipient_user_id'] === $me && $row['read_at'] === null) {
            $this->db->run('UPDATE messages SET read_at = NOW() WHERE id = ? AND read_at IS NULL', [$id]);
            $row['read_at'] = date('Y-m-d H:i:s');
        }

        return $this->ok(['message' => $row]);
    }

    // ─── Compose / reply ─────────────────────────────────────────────────────────

    private function compose(Request $request): Response
    {
        $me   = $this->userId();
        $body = (array) ($request->body() ?? []);

        $recipients = $body['recipient_user_ids'] ?? null;
        if (!is_array($recipients)) {
            $recipients = isset($body['recipient_user_id']) ? [$body['recipient_user_id']] : [];
        }
        $recipientIds = array_values(array_unique(array_filter(array_map('intval', $recipients))));

        $subject = trim((string) ($body['subject'] ?? ''));
        $text    = trim((string) ($body['body'] ?? ''));
        $replyTo = isset($body['in_reply_to_id']) ? (int) $body['in_reply_to_id'] : null;

        if (!$recipientIds) {
            return Response::json(['error' => 'At least one recipient is required'], 422);
        }
        if ($text === '') {
            return Response::json(['error' => 'Message body is required'], 422);
        }
        if ($subject === '') {
            $subject = '(no subject)';
        }

        // Restrict to users this sender is allowed to message.
        $allowed = [];
        foreach ($this->recipientRows() as $u) {
            $allowed[(int) $u['id']] = $u;
        }
        $targets = [];
        foreach ($recipientIds as $rid) {
            if (isset($allowed[$rid])) {
                $targets[] = $allowed[$rid];
            }
        }
        if (!$targets) {
            return $this->forbidden('You cannot message the selected recipient(s)');
        }

        $senderName = (string) ($this->auth->user()['name'] ?? $this->auth->user()['email'] ?? 'A colleague');
        $bodyHtml   = '<div style="font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;font-size:14px;line-height:1.55;color:#1a1a1a">'
                    . nl2br(htmlspecialchars($text, ENT_QUOTES))
                    . '</div>';

        // Email copy never re-fans-out into the inbox — we insert the canonical row below.
        $mailer = (new Mailer($this->root, $this->db))->skipInboxCopy();

        $created = [];
        foreach ($targets as $u) {
            $rid   = (int) $u['id'];
            $email = (string) $u['email'];

            $newId = $this->db->insert(
                'INSERT INTO messages
                    (sender_user_id, recipient_user_id, recipient_email, subject, body_text, body_html, template, in_reply_to_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$me, $rid, $email, $subject, $text, $bodyHtml, 'staff-message', $replyTo]
            );
            $created[] = $newId;

            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $emailText = "{$senderName} sent you a message via Backstage:\n\n{$text}";
                $emailHtml = '<p style="font:13px/1.5 system-ui,sans-serif;color:#666;margin:0 0 12px">'
                           . htmlspecialchars($senderName, ENT_QUOTES) . ' sent you a message via Backstage:</p>'
                           . $bodyHtml;
                try {
                    $mailer->send($email, $subject, $emailText, $emailHtml, 'staff-message');
                } catch (\Throwable) {
                    // Delivery problems must not fail the in-app message.
                }
            }
        }

        return $this->ok(['created' => count($created), 'ids' => $created]);
    }

    // ─── State changes ───────────────────────────────────────────────────────────

    private function setArchived(int $id, bool $archived): Response
    {
        $me  = $this->userId();
        $row = $this->db->one('SELECT recipient_user_id FROM messages WHERE id = ?', [$id]);
        if (!$row) {
            return $this->notFound('Message not found');
        }
        if ((int) $row['recipient_user_id'] !== $me) {
            return $this->forbidden();
        }
        $this->db->run(
            'UPDATE messages SET archived_at = ' . ($archived ? 'NOW()' : 'NULL') . ' WHERE id = ?',
            [$id]
        );
        return $this->ok(['id' => $id, 'archived' => $archived]);
    }

    private function setRead(int $id, bool $read): Response
    {
        $me  = $this->userId();
        $row = $this->db->one('SELECT recipient_user_id FROM messages WHERE id = ?', [$id]);
        if (!$row) {
            return $this->notFound('Message not found');
        }
        if ((int) $row['recipient_user_id'] !== $me) {
            return $this->forbidden();
        }
        $this->db->run(
            'UPDATE messages SET read_at = ' . ($read ? 'NOW()' : 'NULL') . ' WHERE id = ?',
            [$id]
        );
        return $this->ok(['id' => $id, 'read' => $read]);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────────

    private function unreadCount(): Response
    {
        $n = (int) ($this->db->one(
            'SELECT COUNT(*) n FROM messages WHERE recipient_user_id = ? AND read_at IS NULL AND archived_at IS NULL',
            [$this->userId()]
        )['n'] ?? 0);
        return $this->ok(['unread' => $n]);
    }

    private function recipients(): Response
    {
        return $this->ok(['recipients' => $this->recipientRows()]);
    }

    /**
     * Users the current user may address. Admins can message anyone active;
     * everyone else can reach venue admins plus people they share events with.
     *
     * @return list<array{id:int,name:string,email:string,role:string}>
     */
    private function recipientRows(): array
    {
        $me = $this->userId();

        if ($this->isVenueAdmin()) {
            $rows = $this->db->all(
                "SELECT id, name, email, role FROM users WHERE id <> ? AND access_status = 'active' ORDER BY name",
                [$me]
            );
            return array_map([$this, 'normalizeRecipient'], $rows);
        }

        $byId  = [];
        $admins = $this->db->all("SELECT id, name, email, role FROM users WHERE role = 'venue_admin' AND access_status = 'active'");
        foreach (array_merge($this->accessibleUsers(), $admins) as $u) {
            $uid = (int) $u['id'];
            if ($uid === $me) {
                continue;
            }
            $byId[$uid] = $this->normalizeRecipient($u);
        }
        usort($byId, static fn ($a, $b) => strcasecmp((string) $a['name'], (string) $b['name']));
        return array_values($byId);
    }

    /** @return array{id:int,name:string,email:string,role:string} */
    private function normalizeRecipient(array $u): array
    {
        return [
            'id'    => (int) $u['id'],
            'name'  => (string) ($u['name'] ?? ''),
            'email' => (string) ($u['email'] ?? ''),
            'role'  => (string) ($u['role'] ?? ''),
        ];
    }
}
