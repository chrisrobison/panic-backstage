<?php
declare(strict_types=1);

namespace Panic;

final class Me extends BaseEndpoint
{
    public function handle(Request $request): Response
    {
        $user = $this->auth->user();
        if ($user) {
            // Drop the one-time welcome message into this user's inbox on first
            // load. Idempotent + best-effort, so it covers every account-creation
            // path without breaking app load if messaging isn't migrated yet.
            WelcomeMessage::ensureFor(
                $this->db,
                (int) $user['id'],
                $user['name']  ?? null,
                $user['email'] ?? null
            );

            // Enrich with credential state + UI preferences so the client can
            // decide whether to show the "set up a passkey or password" modal
            // on every load — not just immediately after sign-in.
            $row = $this->db->one(
                'SELECT phone, password_hash, hide_credential_setup_prompt,
                        default_landing, nav_collapsed, events_sort, dashboard_metrics,
                        notify_event_updates, notify_contracts, notify_access_requests
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
            // Per-user dashboard metric selection. Null (or unparseable) means
            // "use the default set" — the client decides which keys those are.
            $metrics = isset($row['dashboard_metrics'])
                ? json_decode((string) $row['dashboard_metrics'], true)
                : null;
            $user['dashboard_metrics']            = is_array($metrics) ? $metrics : null;
            // Email notification preferences default to ON (opted-in) when the
            // column is missing, matching the migration default.
            $user['notify_event_updates']         = (bool) ($row['notify_event_updates']   ?? true);
            $user['notify_contracts']             = (bool) ($row['notify_contracts']       ?? true);
            $user['notify_access_requests']       = (bool) ($row['notify_access_requests'] ?? true);
        }

        return $this->ok([
            'user'         => $user,
            'capabilities' => $this->globalCapabilities(),
        ]);
    }
}
