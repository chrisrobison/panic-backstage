<?php
declare(strict_types=1);

namespace Panic;

/**
 * GDPR data-subject endpoints for the signed-in user's own account.
 *
 *   GET  /api/account/export          Right of access & portability (Art. 15/20):
 *                                      download all personal data on the account
 *                                      as a JSON file.
 *   POST /api/account/delete          Right to erasure (Art. 17): anonymise the
 *                                      account and remove credentials. Requires
 *                                      {"confirm": true, "email": "<your email>"}.
 *   POST /api/account/accept-privacy  Record agreement to the current privacy
 *                                      policy (accountability, Art. 5(2)).
 *
 * Customer/audience records (contacts, leads, ticket buyers) belong to the venue
 * as controller and are governed separately; this endpoint covers only the
 * authenticated user's own personal data.
 */
final class AccountData extends BaseEndpoint
{
    private const POLICY_VERSION = '2026-06-25';

    public function handle(Request $request): Response
    {
        if ($denied = $this->requireAuth()) {
            return $denied;
        }

        $action = (string) ($this->params['action'] ?? '');
        $method = $request->method();

        if ($action === 'export' && $method === 'GET') {
            return $this->export();
        }
        if ($action === 'delete' && $method === 'POST') {
            return $this->erase($request);
        }
        if ($action === 'accept-privacy' && $method === 'POST') {
            return $this->acceptPrivacy();
        }

        return Response::json(['error' => 'Not found'], 404);
    }

    private function export(): Response
    {
        $userId = (int) $this->userId();

        $account = $this->db->one('SELECT * FROM users WHERE id = ? LIMIT 1', [$userId]) ?: [];
        unset($account['password_hash']);
        if (isset($account['alt_emails'])) {
            $account['alt_emails'] = json_decode((string) $account['alt_emails'], true);
        }

        $export = [
            'exported_at'      => gmdate('c'),
            'account'          => $account,
            'passkeys'         => $this->safeAll(
                'SELECT id, label, created_at, last_used_at FROM passkeys WHERE user_id = ?',
                [$userId]
            ),
            'event_collaborations' => $this->safeAll(
                'SELECT event_id, role, created_at FROM event_collaborators WHERE user_id = ?',
                [$userId]
            ),
            'messages' => $this->safeAll(
                'SELECT id, subject, body, created_at FROM messages WHERE user_id = ? ORDER BY id',
                [$userId]
            ),
            'activity' => $this->safeAll(
                'SELECT event_id, action, created_at FROM event_activity_log WHERE user_id = ? ORDER BY id',
                [$userId]
            ),
        ];

        $filename = 'panicbackstage-data-export-' . $userId . '-' . gmdate('Ymd') . '.json';
        return new Response($export, 200, [
            'Content-Type'        => 'application/json; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    private function erase(Request $request): Response
    {
        $userId = (int) $this->userId();
        $confirm = $request->body('confirm');
        $email   = strtolower(trim((string) $request->body('email', '')));
        $current = strtolower(trim((string) ($this->auth->user()['email'] ?? '')));

        if ($confirm !== true && $confirm !== 'true' && $confirm !== 1 && $confirm !== '1') {
            return Response::json(['error' => 'Confirmation required. Send {"confirm": true, "email": "your email"}.'], 422);
        }
        if ($email === '' || $email !== $current) {
            return Response::json(['error' => 'The email you entered does not match this account.'], 422);
        }

        // Anonymise the account in place: personal fields and credentials are
        // wiped, but the row is retained so that event ownership and historical
        // references stay consistent. The email is replaced with a unique,
        // non-routable placeholder to satisfy the UNIQUE/ NOT NULL constraint.
        $placeholder = 'erased+' . $userId . '@deleted.invalid';
        $this->db->run(
            "UPDATE users
                SET name = '[erased]',
                    email = ?,
                    phone = NULL,
                    password_hash = NULL,
                    alt_emails = NULL,
                    request_notes = NULL,
                    hide_credential_setup_prompt = 1
              WHERE id = ?",
            [$placeholder, $userId]
        );

        // Remove authentication credentials and tokens tied to the account.
        foreach ([
            ['DELETE FROM passkeys WHERE user_id = ?', [$userId]],
            ['DELETE FROM refresh_tokens WHERE user_id = ?', [$userId]],
            ['DELETE FROM email_verification_tokens WHERE user_id = ?', [$userId]],
            ['DELETE FROM magic_link_tokens WHERE email = ?', [$current]],
        ] as [$sql, $args]) {
            try { $this->db->run($sql, $args); } catch (\Throwable $e) { /* table may not exist on this deployment */ }
        }

        error_log('account_erased user_id=' . $userId);
        return $this->ok(['success' => true, 'message' => 'Your personal data has been erased.']);
    }

    private function acceptPrivacy(): Response
    {
        $userId = (int) $this->userId();
        try {
            $this->db->run(
                'UPDATE users SET privacy_policy_accepted_at = NOW(), privacy_policy_version = ? WHERE id = ?',
                [self::POLICY_VERSION, $userId]
            );
        } catch (\Throwable $e) {
            return Response::json(['error' => 'Could not record acceptance.'], 500);
        }
        return $this->ok(['success' => true, 'privacy_policy_version' => self::POLICY_VERSION]);
    }

    /** Run a query that may reference tables/columns absent on some deployments. */
    private function safeAll(string $sql, array $params): array
    {
        try {
            return $this->db->all($sql, $params);
        } catch (\Throwable $e) {
            return [];
        }
    }
}
