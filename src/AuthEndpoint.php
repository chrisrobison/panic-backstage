<?php
declare(strict_types=1);

namespace Panic;

/**
 * Authentication endpoints.
 *
 * All routes are POST and publicly accessible at the Kernel level;
 * endpoints that require a logged-in user enforce that internally.
 *
 * ── Magic-link (email) ──────────────────────────────────────────
 * POST /api/auth/magic-link              Request a magic-link email
 * POST /api/auth/verify-status           Non-consuming token validity check
 * POST /api/auth/verify                  Exchange token → JWT pair (single-use)
 *
 * ── Email-first login lookup ────────────────────────────────────
 * POST /api/auth/lookup                  What sign-in methods does this email have?
 *
 * ── Access requests ─────────────────────────────────────────────
 * POST /api/auth/request-access          Prospective user asks for an account
 *
 * ── Password ────────────────────────────────────────────────────
 * POST /api/auth/login                   Email + password → JWT pair
 * POST /api/auth/set-password            Set / change password  [auth required]
 *
 * ── Passkeys (WebAuthn) ─────────────────────────────────────────
 * POST /api/auth/passkey-register-begin  Start passkey registration [auth required]
 * POST /api/auth/passkey-register-complete Finish registration    [auth required]
 * POST /api/auth/passkey-login-begin     Start passkey login
 * POST /api/auth/passkey-login-complete  Finish login → JWT pair
 * POST /api/auth/passkeys                List user's passkeys       [auth required]
 * POST /api/auth/remove-passkey          Delete a passkey           [auth required]
 *
 * ── Profile + preferences ────────────────────────────────────────
 * POST /api/auth/profile                 Update own name/email/phone [auth required]
 * POST /api/auth/preferences             Update per-user UI prefs   [auth required]
 *
 * ── Session ─────────────────────────────────────────────────────
 * POST /api/auth/refresh                 Rotate refresh token → new JWT pair
 * POST /api/auth/logout                  Revoke a refresh token
 */
final class AuthEndpoint extends BaseEndpoint
{
    public function handle(Request $request): Response
    {
        if ($request->method() !== 'POST') {
            return Response::methodNotAllowed();
        }
        return match ($this->params['action'] ?? '') {
            // Magic-link
            'magic-link'               => $this->requestMagicLink($request),
            'verify-status'            => $this->verifyStatus($request),
            'verify'                   => $this->verify($request),
            // Email-first lookup
            'lookup'                   => $this->lookup($request),
            // Access requests
            'request-access'           => $this->requestAccess($request),
            // Password
            'login'                    => $this->login($request),
            'set-password'             => $this->setPassword($request),
            // Passkeys
            'passkey-register-begin'   => $this->passkeyRegisterBegin($request),
            'passkey-register-complete'=> $this->passkeyRegisterComplete($request),
            'passkey-login-begin'      => $this->passkeyLoginBegin($request),
            'passkey-login-complete'   => $this->passkeyLoginComplete($request),
            'passkeys'                 => $this->listPasskeys($request),
            'remove-passkey'           => $this->removePasskey($request),
            // Profile + preferences
            'profile'                  => $this->updateProfile($request),
            'preferences'              => $this->updatePreferences($request),
            // Session
            'refresh'                  => $this->refresh($request),
            'logout'                   => $this->logout($request),
            default                    => $this->notFound(),
        };
    }

    // ─── Magic-link ───────────────────────────────────────────────────────────

    private function requestMagicLink(Request $request): Response
    {
        $email = trim(strtolower((string) $request->body('email', '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return Response::json(['error' => 'Valid email is required'], 422);
        }

        // Only mint + send if the address belongs to an existing user OR has
        // an unused, unexpired event_invites row. For unknown emails we
        // return `ok: true` anyway so the client UI doesn't reveal which
        // addresses are registered.
        if (!$this->isEligibleForMagicLink($email)) {
            return $this->ok(['ok' => true]);
        }

        $token = $this->auth->generateToken(24);
        $hash  = $this->auth->hashToken($token);

        $this->db->run(
            'INSERT INTO magic_link_tokens (email, token_hash, expires_at)
             VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR))',
            [$email, $hash]
        );

        $appUrl = rtrim((string) (getenv('APP_URL') ?: ''), '/');
        $link   = "{$appUrl}/login.html?token={$token}";

        (new Mailer($this->root))->send(
            $email,
            'Your Backstage login link',
            "Here is your login link — it expires in 24 hours and can be used once.\n\n"
            . "  {$link}\n\n"
            . "If you did not request this you can safely ignore this email.\n"
        );

        return $this->ok(['ok' => true]);
    }

    /**
     * Non-consuming preflight: returns whether a magic-link token is still
     * valid, plus a few hints the client uses to render the "Continue"
     * interstitial without burning the token. Safe for link previewers
     * (iMessage / SMS / corporate scanners) to hit — does NOT mark used_at.
     */
    private function verifyStatus(Request $request): Response
    {
        $token = trim((string) $request->body('token', ''));
        if ($token === '') {
            return $this->ok(['valid' => false]);
        }

        $hash = $this->auth->hashToken($token);
        $row  = $this->db->one(
            'SELECT * FROM magic_link_tokens
             WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW()
             LIMIT 1',
            [$hash]
        );
        if (!$row) {
            return $this->ok(['valid' => false]);
        }

        $user = $this->db->one(
            'SELECT id, name, email, password_hash FROM users WHERE email = ? LIMIT 1',
            [$row['email']]
        );
        $hasPasskey = $user
            ? (bool) $this->db->one('SELECT id FROM passkeys WHERE user_id = ? LIMIT 1', [$user['id']])
            : false;

        return $this->ok([
            'valid'        => true,
            'email'        => (string) $row['email'],
            'name'         => $user['name'] ?? null,
            'has_password' => $user ? !empty($user['password_hash']) : false,
            'has_passkey'  => $hasPasskey,
        ]);
    }

    private function verify(Request $request): Response
    {
        $token = trim((string) $request->body('token', ''));
        if ($token === '') {
            return Response::json(['error' => 'Token is required'], 422);
        }

        $hash = $this->auth->hashToken($token);
        $row  = $this->db->one(
            'SELECT * FROM magic_link_tokens
             WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW()
             LIMIT 1',
            [$hash]
        );

        if (!$row) {
            return Response::json(['error' => 'Invalid or expired token'], 401);
        }

        $this->db->run(
            'UPDATE magic_link_tokens SET used_at = NOW() WHERE id = ?',
            [$row['id']]
        );

        $user = $this->db->one('SELECT * FROM users WHERE email = ? LIMIT 1', [$row['email']]);
        if (!$user) {
            $id   = $this->db->insert(
                'INSERT INTO users (name, email, role) VALUES (?, ?, ?)',
                [$row['email'], $row['email'], 'viewer']
            );
            $user = $this->db->one('SELECT * FROM users WHERE id = ?', [$id]);
        }

        return $this->ok($this->issueTokenPair($user));
    }

    /**
     * Email-first login: tell the client what sign-in methods this email
     * supports so it can render only the relevant inputs.
     *
     * For unknown emails returns the same shape as a known-but-credentialless
     * account ({has_password: false, has_passkey: false}) so account
     * enumeration is limited to what /auth/login error messages already leak.
     */
    private function lookup(Request $request): Response
    {
        $email = trim(strtolower((string) $request->body('email', '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return Response::json(['error' => 'Valid email is required'], 422);
        }

        $user = $this->db->one(
            'SELECT id, name, password_hash FROM users WHERE email = ? LIMIT 1',
            [$email]
        );
        if (!$user) {
            return $this->ok([
                'has_password'        => false,
                'has_passkey'         => false,
                'magic_link_eligible' => $this->hasOpenInvite($email),
            ]);
        }

        $hasPasskey = (bool) $this->db->one(
            'SELECT id FROM passkeys WHERE user_id = ? LIMIT 1',
            [$user['id']]
        );

        return $this->ok([
            'has_password'        => !empty($user['password_hash']),
            'has_passkey'         => $hasPasskey,
            'name'                => (string) ($user['name'] ?? ''),
            'magic_link_eligible' => true,
        ]);
    }

    /** True iff a magic-link request for this email should actually send mail. */
    private function isEligibleForMagicLink(string $email): bool
    {
        // Active accounts only — a 'requested' account is awaiting admin
        // approval and must not be able to sign in via a self-served link.
        if ($this->db->one("SELECT id FROM users WHERE email = ? AND access_status = 'active' LIMIT 1", [$email])) {
            return true;
        }
        return $this->hasOpenInvite($email);
    }

    private function hasOpenInvite(string $email): bool
    {
        return (bool) $this->db->one(
            'SELECT id FROM event_invites
             WHERE email = ? AND used_at IS NULL AND expires_at > NOW()
             LIMIT 1',
            [$email]
        );
    }

    // ─── Access requests ────────────────────────────────────────────────────────

    /**
     * Public "request access" form (login page). Records the request as a
     * 'requested' user row (no credentials) for a venue_admin to review and
     * approve. Admins are emailed a heads-up. The response is intentionally
     * uniform so the form can't be used to enumerate existing accounts.
     */
    private function requestAccess(Request $request): Response
    {
        $name  = trim((string) $request->body('name', ''));
        $email = trim(strtolower((string) $request->body('email', '')));
        $phone = trim((string) $request->body('phone', ''));
        $notes = trim((string) $request->body('notes', ''));

        if ($name === '') {
            return Response::json(['error' => 'Your name is required'], 422);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return Response::json(['error' => 'A valid email is required'], 422);
        }

        $ok = ['ok' => true, 'message' => 'Thanks — your request has been sent. An administrator will review it and email you a login link once approved.'];

        $existing = $this->db->one(
            'SELECT id, access_status FROM users WHERE email = ? LIMIT 1',
            [$email]
        );

        if ($existing) {
            // Already a pending request → refresh the details they submitted.
            if ($existing['access_status'] === 'requested') {
                $this->db->run(
                    'UPDATE users SET name = ?, phone = ?, request_notes = ? WHERE id = ?',
                    [$name, ($phone !== '' ? $phone : null), ($notes !== '' ? $notes : null), $existing['id']]
                );
                $this->notifyAdminsOfAccessRequest($name, $email, $phone, $notes);
            }
            // For an already-active account we silently no-op (don't reveal it
            // exists); they can just sign in. Either way the response is uniform.
            return $this->ok($ok);
        }

        $this->db->insert(
            "INSERT INTO users (name, email, phone, role, access_status, request_notes)
             VALUES (?, ?, ?, 'viewer', 'requested', ?)",
            [$name, $email, ($phone !== '' ? $phone : null), ($notes !== '' ? $notes : null)]
        );

        $this->notifyAdminsOfAccessRequest($name, $email, $phone, $notes);

        return $this->ok($ok);
    }

    /** Email every venue_admin that a new access request is awaiting review. */
    private function notifyAdminsOfAccessRequest(string $name, string $email, string $phone, string $notes): void
    {
        $admins = $this->db->all(
            "SELECT email FROM users WHERE role = 'venue_admin' AND access_status = 'active'"
        );
        if (!$admins) {
            return;
        }

        $appUrl = rtrim((string) (getenv('APP_URL') ?: ''), '/');
        $link   = "{$appUrl}/index.html#admin-users";

        $body = "A new account request is waiting for review on Backstage.\n\n"
              . "  Name:  {$name}\n"
              . "  Email: {$email}\n"
              . ($phone !== '' ? "  Phone: {$phone}\n" : '')
              . ($notes !== '' ? "\n  Situation:\n  {$notes}\n" : '')
              . "\nReview and approve it here:\n  {$link}\n";

        $mailer = new Mailer($this->root);
        foreach ($admins as $admin) {
            $mailer->send((string) $admin['email'], 'Backstage — new access request', $body);
        }
    }

    // ─── Password auth ────────────────────────────────────────────────────────

    private function login(Request $request): Response
    {
        $email    = trim(strtolower((string) $request->body('email', '')));
        $password = (string) $request->body('password', '');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
            return Response::json(['error' => 'Email and password are required'], 422);
        }

        $user = $this->db->one('SELECT * FROM users WHERE email = ? LIMIT 1', [$email]);
        if (!$user || !$user['password_hash'] || !password_verify($password, (string) $user['password_hash'])) {
            return Response::json(['error' => 'Invalid email or password'], 401);
        }

        return $this->ok($this->issueTokenPair($user));
    }

    private function setPassword(Request $request): Response
    {
        $currentUser = $this->auth->user();
        if (!$currentUser) {
            return Response::json(['error' => 'Authentication required'], 401);
        }

        $newPassword = (string) $request->body('password', '');
        $curPassword = (string) $request->body('current_password', '');

        if (strlen($newPassword) < 8) {
            return Response::json(['error' => 'Password must be at least 8 characters'], 422);
        }

        $user = $this->db->one('SELECT * FROM users WHERE id = ? LIMIT 1', [$currentUser['id']]);

        // If user already has a password, require the current one before changing
        if ($user && $user['password_hash'] && !password_verify($curPassword, (string) $user['password_hash'])) {
            return Response::json(['error' => 'Current password is incorrect'], 401);
        }

        $hash = password_hash($newPassword, PASSWORD_BCRYPT);
        $this->db->run('UPDATE users SET password_hash = ? WHERE id = ?', [$hash, $currentUser['id']]);

        return $this->ok(['ok' => true]);
    }

    // ─── Passkey registration ─────────────────────────────────────────────────

    private function passkeyRegisterBegin(Request $request): Response
    {
        $currentUser = $this->auth->user();
        if (!$currentUser) {
            return Response::json(['error' => 'Authentication required'], 401);
        }

        $wc        = new Webauthn();
        $challenge = $wc->generateChallenge();

        $this->db->run(
            'INSERT INTO webauthn_challenges (challenge, user_id, intent, expires_at)
             VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 5 MINUTE))',
            [$challenge, $currentUser['id'], 'register']
        );

        $user = $this->db->one('SELECT * FROM users WHERE id = ? LIMIT 1', [$currentUser['id']]);

        // Exclude already-registered credentials so the authenticator doesn't create duplicates
        $existing           = $this->db->all(
            'SELECT credential_id, transports FROM passkeys WHERE user_id = ?',
            [$currentUser['id']]
        );
        $excludeCredentials = array_map(fn ($pk) => [
            'type'       => 'public-key',
            'id'         => $pk['credential_id'],
            'transports' => $pk['transports'] ? json_decode((string) $pk['transports'], true) : [],
        ], $existing);

        return $this->ok([
            'challenge'               => $challenge,
            'rp'                      => ['name' => $wc->getRpName(), 'id' => $wc->getRpId()],
            'user'                    => [
                'id'          => $wc->b64u(pack('N', (int) $currentUser['id'])),
                'name'        => (string) ($user['email'] ?? ''),
                'displayName' => (string) ($user['name'] ?? ''),
            ],
            'pubKeyCredParams'        => [
                ['type' => 'public-key', 'alg' => -7],    // ES256 (P-256 ECDSA)
                ['type' => 'public-key', 'alg' => -257],  // RS256
            ],
            'excludeCredentials'      => $excludeCredentials,
            'authenticatorSelection'  => [
                'authenticatorAttachment' => 'platform',
                'residentKey'             => 'preferred',
                'requireResidentKey'      => false,
                'userVerification'        => 'preferred',
            ],
            'timeout'                 => 60000,
            'attestation'             => 'none',
        ]);
    }

    private function passkeyRegisterComplete(Request $request): Response
    {
        $currentUser = $this->auth->user();
        if (!$currentUser) {
            return Response::json(['error' => 'Authentication required'], 401);
        }

        $response = $request->body('response', null);
        if (!is_array($response)) {
            return Response::json(['error' => 'Credential response is required'], 422);
        }

        // Extract the challenge the browser echoed back inside clientDataJSON
        $challenge = $this->extractChallenge((string) ($response['clientDataJSON'] ?? ''));
        if ($challenge === '') {
            return Response::json(['error' => 'Could not extract challenge'], 422);
        }

        $row = $this->db->one(
            'SELECT * FROM webauthn_challenges
             WHERE challenge = ? AND user_id = ? AND intent = ? AND expires_at > NOW()
             LIMIT 1',
            [$challenge, $currentUser['id'], 'register']
        );
        if (!$row) {
            return Response::json(['error' => 'Invalid or expired registration challenge'], 401);
        }
        $this->db->run('DELETE FROM webauthn_challenges WHERE id = ?', [$row['id']]);

        try {
            $wc         = new Webauthn();
            $credential = $wc->verifyRegistration($challenge, $response);

            $name = trim((string) $request->body('name', 'Passkey'));
            if ($name === '') $name = 'Passkey';

            $this->db->insert(
                'INSERT INTO passkeys (user_id, credential_id, public_key_pem, sign_count, transports, name)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [
                    (int) $currentUser['id'],
                    $credential['credential_id'],
                    $credential['public_key_pem'],
                    $credential['sign_count'],
                    $credential['transports'] ? json_encode($credential['transports']) : null,
                    $name,
                ]
            );

            return $this->ok(['ok' => true]);
        } catch (\RuntimeException $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        }
    }

    // ─── Passkey login ────────────────────────────────────────────────────────

    private function passkeyLoginBegin(Request $request): Response
    {
        $wc        = new Webauthn();
        $challenge = $wc->generateChallenge();

        // If an email is provided, limit allowCredentials to credentials for that account
        $allowCredentials = [];
        $email            = trim(strtolower((string) $request->body('email', '')));
        if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $user = $this->db->one('SELECT id FROM users WHERE email = ? LIMIT 1', [$email]);
            if ($user) {
                $rows = $this->db->all(
                    'SELECT credential_id, transports FROM passkeys WHERE user_id = ?',
                    [$user['id']]
                );
                $allowCredentials = array_map(fn ($pk) => [
                    'type'       => 'public-key',
                    'id'         => $pk['credential_id'],
                    'transports' => $pk['transports'] ? json_decode((string) $pk['transports'], true) : [],
                ], $rows);
            }
        }

        $this->db->run(
            'INSERT INTO webauthn_challenges (challenge, intent, expires_at)
             VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 5 MINUTE))',
            [$challenge, 'login']
        );

        return $this->ok([
            'challenge'        => $challenge,
            'timeout'          => 60000,
            'rpId'             => $wc->getRpId(),
            'allowCredentials' => $allowCredentials,
            'userVerification' => 'preferred',
        ]);
    }

    private function passkeyLoginComplete(Request $request): Response
    {
        $response = $request->body('response', null);
        $credId   = trim((string) $request->body('id', ''));

        if (!is_array($response) || $credId === '') {
            return Response::json(['error' => 'Credential data is required'], 422);
        }

        // Extract and look up the challenge
        $challenge = $this->extractChallenge((string) ($response['clientDataJSON'] ?? ''));
        if ($challenge === '') {
            return Response::json(['error' => 'Could not extract challenge'], 422);
        }

        $row = $this->db->one(
            'SELECT * FROM webauthn_challenges
             WHERE challenge = ? AND user_id IS NULL AND intent = ? AND expires_at > NOW()
             LIMIT 1',
            [$challenge, 'login']
        );
        if (!$row) {
            return Response::json(['error' => 'Invalid or expired login challenge'], 401);
        }
        $this->db->run('DELETE FROM webauthn_challenges WHERE id = ?', [$row['id']]);

        // Look up the credential
        $passkey = $this->db->one(
            'SELECT * FROM passkeys WHERE credential_id = ? LIMIT 1',
            [$credId]
        );
        if (!$passkey) {
            return Response::json(['error' => 'Passkey not recognised'], 401);
        }

        try {
            $wc          = new Webauthn();
            $newSignCount = $wc->verifyAssertion($challenge, $response, $passkey);

            $this->db->run(
                'UPDATE passkeys SET sign_count = ?, last_used_at = NOW() WHERE id = ?',
                [$newSignCount, $passkey['id']]
            );

            $user = $this->db->one('SELECT * FROM users WHERE id = ? LIMIT 1', [$passkey['user_id']]);
            if (!$user) {
                return Response::json(['error' => 'User account not found'], 401);
            }

            return $this->ok($this->issueTokenPair($user));
        } catch (\RuntimeException $e) {
            return Response::json(['error' => $e->getMessage()], 401);
        }
    }

    // ─── Passkey management ───────────────────────────────────────────────────

    private function listPasskeys(Request $request): Response
    {
        $currentUser = $this->auth->user();
        if (!$currentUser) {
            return Response::json(['error' => 'Authentication required'], 401);
        }

        $passkeys = $this->db->all(
            'SELECT id, name, created_at, last_used_at
             FROM passkeys
             WHERE user_id = ?
             ORDER BY created_at DESC',
            [$currentUser['id']]
        );

        $user        = $this->db->one('SELECT password_hash FROM users WHERE id = ? LIMIT 1', [$currentUser['id']]);
        $hasPassword = !empty($user['password_hash']);

        return $this->ok(['passkeys' => $passkeys, 'has_password' => $hasPassword]);
    }

    private function removePasskey(Request $request): Response
    {
        $currentUser = $this->auth->user();
        if (!$currentUser) {
            return Response::json(['error' => 'Authentication required'], 401);
        }

        $id = (int) $request->body('id', 0);
        if (!$id) {
            return Response::json(['error' => 'Passkey id is required'], 422);
        }

        $this->db->run(
            'DELETE FROM passkeys WHERE id = ? AND user_id = ?',
            [$id, $currentUser['id']]
        );

        return $this->ok(['ok' => true]);
    }

    // ─── Self-service profile ─────────────────────────────────────────────────

    /**
     * Let a signed-in user edit their own name, email, and phone. Unlike the
     * admin Users endpoint this is self-scoped and never touches role.
     */
    private function updateProfile(Request $request): Response
    {
        $currentUser = $this->auth->user();
        if (!$currentUser) {
            return Response::json(['error' => 'Authentication required'], 401);
        }
        $id = (int) $currentUser['id'];

        $name  = trim((string) $request->body('name', ''));
        $email = strtolower(trim((string) $request->body('email', '')));
        $phone = trim((string) $request->body('phone', ''));

        if ($name === '' || $email === '') {
            return Response::json(['error' => 'name and email are required'], 422);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return Response::json(['error' => 'Invalid email'], 422);
        }
        $clash = $this->db->one('SELECT id FROM users WHERE email = ? AND id != ?', [$email, $id]);
        if ($clash) {
            return Response::json(['error' => 'Another user already uses that email'], 409);
        }

        $this->db->run(
            'UPDATE users SET name = ?, email = ?, phone = ? WHERE id = ?',
            [$name, $email, ($phone !== '' ? $phone : null), $id]
        );

        return $this->ok([
            'ok'   => true,
            'user' => ['id' => $id, 'name' => $name, 'email' => $email, 'phone' => ($phone !== '' ? $phone : null)],
        ]);
    }

    // ─── Per-user preferences ─────────────────────────────────────────────────

    /** Route keys allowed as a default landing page. */
    private const LANDING_ROUTES = ['dashboard', 'calendar', 'pipeline', 'events', 'templates'];

    private function updatePreferences(Request $request): Response
    {
        $currentUser = $this->auth->user();
        if (!$currentUser) {
            return Response::json(['error' => 'Authentication required'], 401);
        }

        $body    = $request->body();
        $updates = [];
        $params  = [];

        if (array_key_exists('hide_credential_setup_prompt', $body)) {
            $updates[] = 'hide_credential_setup_prompt = ?';
            $params[]  = $body['hide_credential_setup_prompt'] ? 1 : 0;
        }

        if (array_key_exists('default_landing', $body)) {
            $landing = (string) $body['default_landing'];
            if ($landing !== '' && !in_array($landing, self::LANDING_ROUTES, true)) {
                return Response::json(['error' => 'Invalid default_landing'], 422);
            }
            $updates[] = 'default_landing = ?';
            $params[]  = ($landing !== '' ? $landing : null);
        }

        if (array_key_exists('nav_collapsed', $body)) {
            $updates[] = 'nav_collapsed = ?';
            $params[]  = $body['nav_collapsed'] ? 1 : 0;
        }

        if (array_key_exists('events_sort', $body)) {
            $sort = (string) $body['events_sort'];
            if ($sort !== '' && !in_array($sort, ['asc', 'desc'], true)) {
                return Response::json(['error' => 'Invalid events_sort'], 422);
            }
            $updates[] = 'events_sort = ?';
            $params[]  = ($sort !== '' ? $sort : null);
        }

        if (!$updates) {
            return Response::json(['error' => 'No supported preference fields provided'], 422);
        }

        $params[] = (int) $currentUser['id'];
        $this->db->run('UPDATE users SET ' . implode(', ', $updates) . ' WHERE id = ?', $params);

        return $this->ok(['ok' => true]);
    }

    // ─── Session management ───────────────────────────────────────────────────

    private function refresh(Request $request): Response
    {
        $token = trim((string) $request->body('refresh_token', ''));
        if ($token === '') {
            return Response::json(['error' => 'refresh_token is required'], 422);
        }

        $hash = $this->auth->hashToken($token);
        $row  = $this->db->one(
            'SELECT rt.id, rt.user_id,
                    u.name, u.email, u.role
             FROM   refresh_tokens rt
             JOIN   users u ON u.id = rt.user_id
             WHERE  rt.token_hash = ?
               AND  rt.revoked_at IS NULL
               AND  rt.expires_at > NOW()
             LIMIT  1',
            [$hash]
        );

        if (!$row) {
            return Response::json(['error' => 'Invalid or expired refresh token'], 401);
        }

        $this->db->run(
            'UPDATE refresh_tokens SET revoked_at = NOW() WHERE id = ?',
            [$row['id']]
        );

        $user = [
            'id'    => (int) $row['user_id'],
            'name'  => $row['name'],
            'email' => $row['email'],
            'role'  => $row['role'],
        ];

        return $this->ok($this->issueTokenPair($user));
    }

    private function logout(Request $request): Response
    {
        $token = trim((string) $request->body('refresh_token', ''));
        if ($token !== '') {
            $this->db->run(
                'UPDATE refresh_tokens SET revoked_at = NOW() WHERE token_hash = ?',
                [$this->auth->hashToken($token)]
            );
        }
        return $this->ok(['ok' => true]);
    }

    // ─── Shared helpers ───────────────────────────────────────────────────────

    /** Mint a fresh access + refresh token pair for a user. */
    private function issueTokenPair(array $user): array
    {
        $refreshToken = $this->auth->generateToken(32);

        $this->db->insert(
            'INSERT INTO refresh_tokens (user_id, token_hash, expires_at)
             VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 180 DAY))',
            [(int) $user['id'], $this->auth->hashToken($refreshToken)]
        );

        $this->auth->setUser($user);

        // Latest credential state — needed by the client to decide whether
        // to show the "set up a passkey or password" modal after login.
        // Reread from DB so we get the right values regardless of how this
        // method was reached (verify / login / refresh / passkey-login).
        $fresh = $this->db->one(
            'SELECT password_hash, hide_credential_setup_prompt FROM users WHERE id = ? LIMIT 1',
            [(int) $user['id']]
        ) ?: [];
        $hasPasskey = (bool) $this->db->one(
            'SELECT id FROM passkeys WHERE user_id = ? LIMIT 1',
            [(int) $user['id']]
        );

        return [
            'access_token'  => $this->auth->issueAccessToken($user),
            'refresh_token' => $refreshToken,
            'expires_in'    => 90 * 24 * 3600,  // 90 days in seconds
            'user'          => [
                'id'    => (int) $user['id'],
                'name'  => (string) $user['name'],
                'email' => (string) $user['email'],
                'role'  => (string) $user['role'],
                'has_password'                 => !empty($fresh['password_hash']),
                'has_passkey'                  => $hasPasskey,
                'hide_credential_setup_prompt' => (bool) ($fresh['hide_credential_setup_prompt'] ?? false),
            ],
            'capabilities'  => $this->globalCapabilities(),
        ];
    }

    /**
     * Extract and normalise the challenge embedded in a base64url-encoded clientDataJSON.
     * Returns empty string on failure.
     */
    private function extractChallenge(string $clientDataJsonB64u): string
    {
        if ($clientDataJsonB64u === '') return '';
        $pad     = strlen($clientDataJsonB64u) % 4;
        $b64     = strtr($clientDataJsonB64u, '-_', '+/') . ($pad ? str_repeat('=', 4 - $pad) : '');
        $decoded = json_decode((string) base64_decode($b64), true);
        $raw     = (string) ($decoded['challenge'] ?? '');
        // Normalise to unpadded base64url
        return rtrim(strtr($raw, '+/', '-_'), '=');
    }
}
