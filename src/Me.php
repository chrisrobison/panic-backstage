<?php
declare(strict_types=1);

namespace Panic;

final class Me extends BaseEndpoint
{
    public function handle(Request $request): Response
    {
        $user = $this->auth->user();
        if ($user) {
            // Enrich with credential state + UI preferences so the client can
            // decide whether to show the "set up a passkey or password" modal
            // on every load — not just immediately after sign-in.
            $row = $this->db->one(
                'SELECT password_hash, hide_credential_setup_prompt
                 FROM users WHERE id = ? LIMIT 1',
                [(int) $user['id']]
            ) ?: [];
            $hasPasskey = (bool) $this->db->one(
                'SELECT id FROM passkeys WHERE user_id = ? LIMIT 1',
                [(int) $user['id']]
            );
            $user['has_password']                 = !empty($row['password_hash']);
            $user['has_passkey']                  = $hasPasskey;
            $user['hide_credential_setup_prompt'] = (bool) ($row['hide_credential_setup_prompt'] ?? false);
        }

        return $this->ok([
            'user'         => $user,
            'capabilities' => $this->globalCapabilities(),
        ]);
    }
}
