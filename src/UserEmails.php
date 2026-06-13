<?php
declare(strict_types=1);

namespace Panic;

/**
 * Alias (secondary email) self-management.
 *
 *   POST   /api/users/{id}/emails           {email}  add as UNVERIFIED alias + email confirm link. 201
 *   POST   /api/users/{id}/emails/resend    {email}  re-mint + re-send confirm link. 200
 *   DELETE /api/users/{id}/emails           {email}  remove an alias (cannot remove primary). 200
 *   POST   /api/users/{id}/emails/primary   {email}  promote a VERIFIED alias to primary. 200
 *
 * Authorization: the 'manage_users' global capability (venue_admin) OR the
 * authenticated user acting on their OWN id.
 *
 * alt_emails JSON entry shape:
 *   { "email": "<lowercased>", "verified_at": "<ISO8601|null>", "added_at": "<ISO8601>" }
 */
final class UserEmails extends BaseEndpoint
{
    /** Confirmation links are good for 7 days. */
    private const TOKEN_TTL = '7 DAY';

    public function handle(Request $request): Response
    {
        $targetId = $this->params['userId'] ?? null;
        if (!$targetId) {
            return $this->notFound('User not found');
        }
        $targetId = (int) $targetId;

        if (!$this->canManage($targetId)) {
            return $this->forbidden();
        }

        $sub = $this->params['sub'] ?? null;

        return match (true) {
            $request->method() === 'POST'   && $sub === null       => $this->add($request, $targetId),
            $request->method() === 'POST'   && $sub === 'resend'    => $this->resend($request, $targetId),
            $request->method() === 'POST'   && $sub === 'primary'   => $this->makePrimary($request, $targetId),
            $request->method() === 'DELETE' && $sub === null        => $this->remove($request, $targetId),
            default                                                 => Response::methodNotAllowed(),
        };
    }

    /** manage_users (venue_admin) OR the signed-in user operating on their own id. */
    private function canManage(int $targetId): bool
    {
        return $this->hasGlobalCapability('manage_users') || $this->userId() === $targetId;
    }

    // ─── Add (unverified) ─────────────────────────────────────────────────────

    private function add(Request $request, int $targetId): Response
    {
        $email = strtolower(trim((string) $request->body('email', '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return Response::json(['error' => 'A valid email is required'], 422);
        }

        $user = $this->loadUser($targetId);
        if (!$user) {
            return $this->notFound('User not found');
        }
        if (strtolower(trim((string) $user['email'])) === $email) {
            return Response::json(['error' => 'That is already this account\'s primary email'], 409);
        }
        if (Identity::emailIsTaken($this->db, $email, $targetId)) {
            return Response::json(['error' => 'That email is already in use'], 409);
        }

        $entries = Identity::altEmails($user);
        $exists  = false;
        foreach ($entries as $entry) {
            if (($entry['email'] ?? null) === $email) {
                $exists = true;
                break;
            }
        }
        if (!$exists) {
            $entries[] = [
                'email'       => $email,
                'verified_at' => null,
                'added_at'    => date('c'),
            ];
            $this->db->run(
                'UPDATE users SET alt_emails = ? WHERE id = ?',
                [json_encode(array_values($entries)), $targetId]
            );
        }

        $this->mintAndSend($targetId, $email);

        return Response::json(['ok' => true, 'email' => $email], 201);
    }

    // ─── Resend confirmation ──────────────────────────────────────────────────

    private function resend(Request $request, int $targetId): Response
    {
        $email = strtolower(trim((string) $request->body('email', '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return Response::json(['error' => 'A valid email is required'], 422);
        }

        $user = $this->loadUser($targetId);
        if (!$user) {
            return $this->notFound('User not found');
        }

        $entry = $this->findAlias($user, $email);
        if (!$entry) {
            return $this->notFound('That email is not on this account');
        }
        if (!empty($entry['verified_at'])) {
            return Response::json(['error' => 'That email is already confirmed'], 409);
        }

        $this->mintAndSend($targetId, $email);

        return $this->ok(['ok' => true, 'email' => $email]);
    }

    // ─── Remove ───────────────────────────────────────────────────────────────

    private function remove(Request $request, int $targetId): Response
    {
        $email = strtolower(trim((string) $request->body('email', '')));
        if ($email === '') {
            return Response::json(['error' => 'email is required'], 422);
        }

        $user = $this->loadUser($targetId);
        if (!$user) {
            return $this->notFound('User not found');
        }
        if (strtolower(trim((string) $user['email'])) === $email) {
            return Response::json(['error' => 'Cannot remove the primary email'], 409);
        }

        $entries = Identity::altEmails($user);
        $kept    = array_values(array_filter(
            $entries,
            static fn (array $e): bool => ($e['email'] ?? null) !== $email
        ));

        $this->db->run(
            'UPDATE users SET alt_emails = ? WHERE id = ?',
            [($kept ? json_encode($kept) : null), $targetId]
        );

        // Invalidate any outstanding confirmation tokens for this address.
        $this->db->run(
            'DELETE FROM email_verification_tokens WHERE user_id = ? AND email = ?',
            [$targetId, $email]
        );

        return $this->ok(['ok' => true]);
    }

    // ─── Promote verified alias to primary ─────────────────────────────────────

    private function makePrimary(Request $request, int $targetId): Response
    {
        $email = strtolower(trim((string) $request->body('email', '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return Response::json(['error' => 'A valid email is required'], 422);
        }

        $user = $this->loadUser($targetId);
        if (!$user) {
            return $this->notFound('User not found');
        }

        $oldPrimary = strtolower(trim((string) $user['email']));
        if ($oldPrimary === $email) {
            return Response::json(['error' => 'That email is already the primary'], 409);
        }

        $entry = $this->findAlias($user, $email);
        if (!$entry) {
            return $this->notFound('That email is not on this account');
        }
        if (empty($entry['verified_at'])) {
            return Response::json(['error' => 'Only a confirmed email can be made primary'], 409);
        }

        // Guard against the new primary colliding with any OTHER account.
        if (Identity::emailIsTaken($this->db, $email, $targetId)) {
            return Response::json(['error' => 'That email is already in use'], 409);
        }

        // Swap: promote the alias, demote the old primary into alt_emails (verified).
        $entries = Identity::altEmails($user);
        $rebuilt = [];
        foreach ($entries as $e) {
            if (($e['email'] ?? null) === $email) {
                continue; // becomes the new primary
            }
            $rebuilt[] = $e;
        }
        $rebuilt[] = [
            'email'       => $oldPrimary,
            'verified_at' => date('c'),
            'added_at'    => date('c'),
        ];

        $this->db->run(
            'UPDATE users SET email = ?, alt_emails = ? WHERE id = ?',
            [$email, json_encode(array_values($rebuilt)), $targetId]
        );

        return $this->ok(['ok' => true, 'email' => $email]);
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

    private function loadUser(int $id): ?array
    {
        return $this->db->one('SELECT * FROM users WHERE id = ? LIMIT 1', [$id]);
    }

    /** Return the matching alt_emails entry (assoc) or null. */
    private function findAlias(array $user, string $email): ?array
    {
        foreach (Identity::altEmails($user) as $entry) {
            if (($entry['email'] ?? null) === $email) {
                return $entry;
            }
        }
        return null;
    }

    /**
     * Mint a fresh single-use confirmation token (7-day expiry, hashed at rest)
     * and email the confirm link to the alias address itself.
     */
    private function mintAndSend(int $userId, string $email): void
    {
        $token = $this->auth->generateToken(24);
        $hash  = $this->auth->hashToken($token);

        $this->db->run(
            'INSERT INTO email_verification_tokens (user_id, email, token_hash, expires_at)
             VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ' . self::TOKEN_TTL . '))',
            [$userId, $email, $hash]
        );

        $appUrl = rtrim((string) (getenv('APP_URL') ?: ''), '/');
        $link   = "{$appUrl}/login.html?verify_email={$token}";

        (new Mailer($this->root))->sendTemplate(
            $email,
            'Confirm your Backstage email',
            'confirm-email',
            ['confirm_url' => htmlspecialchars($link, ENT_QUOTES, 'UTF-8')]
        );
    }
}
