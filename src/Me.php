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
                'SELECT phone, password_hash, hide_credential_setup_prompt,
                        default_landing, nav_collapsed, events_sort
                 FROM users WHERE id = ? LIMIT 1',
                [(int) $user['id']]
            ) ?: [];
            $hasPasskey = (bool) $this->db->one(
                'SELECT id FROM passkeys WHERE user_id = ? LIMIT 1',
                [(int) $user['id']]
            );
            $user['phone']                        = $row['phone'] ?? null;
            $user['has_password']                 = !empty($row['password_hash']);
            $user['has_passkey']                  = $hasPasskey;
            $user['hide_credential_setup_prompt'] = (bool) ($row['hide_credential_setup_prompt'] ?? false);
            $user['default_landing']              = $row['default_landing'] ?? null;
            $user['nav_collapsed']                = (bool) ($row['nav_collapsed'] ?? false);
            $user['events_sort']                  = $row['events_sort'] ?? null;
        }

        return $this->ok([
            'user'         => $user,
            'capabilities' => $this->globalCapabilities(),
        ]);
    }
}
