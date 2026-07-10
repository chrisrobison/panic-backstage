<?php
declare(strict_types=1);

namespace Panic;

/**
 * Backs the "List storage: N of LIMIT contacts" meter in the ListMaster UI
 * (public/assets/listmaster.js). This app has no billing/subscription-plan
 * system, so `contact_limit` is a plain admin-editable cap (singleton row,
 * contact_storage_settings.id = 1) rather than a real plan tier — it exists
 * so the meter shows a genuine, persisted number instead of a hardcoded one.
 *
 *   GET   /api/contact-storage   {used, limit, percent}
 *   PATCH /api/contact-storage   {limit} — update the cap
 *
 * Gated by the manage_contacts global capability (same gate as Contacts).
 */
final class ContactStorage extends BaseEndpoint
{
    public function handle(Request $request): Response
    {
        if ($denied = $this->requireGlobalCapability('manage_contacts')) {
            return $denied;
        }
        return match ($request->method()) {
            'GET'   => $this->show(),
            'PATCH' => $this->update($request),
            default => Response::methodNotAllowed(),
        };
    }

    private function show(): Response
    {
        return $this->ok($this->summary());
    }

    private function update(Request $request): Response
    {
        $limit = $request->body('limit');
        if (!is_numeric($limit) || (int) $limit < 0) {
            return Response::json(['error' => 'limit must be a non-negative number'], 422);
        }
        $this->ensureRow();
        $this->db->run('UPDATE contact_storage_settings SET contact_limit = ? WHERE id = 1', [(int) $limit]);
        return $this->ok($this->summary());
    }

    private function summary(): array
    {
        $this->ensureRow();
        $limit = (int) ($this->db->one('SELECT contact_limit FROM contact_storage_settings WHERE id = 1')['contact_limit'] ?? 250000);
        $used = (int) ($this->db->one('SELECT COUNT(*) n FROM contacts')['n'] ?? 0);
        return [
            'used'    => $used,
            'limit'   => $limit,
            'percent' => $limit > 0 ? round(min(100, $used / $limit * 100), 1) : 0.0,
        ];
    }

    /** The singleton row is seeded by the migration, but guard against a hand-cleared table. */
    private function ensureRow(): void
    {
        $this->db->run('INSERT IGNORE INTO contact_storage_settings (id, contact_limit) VALUES (1, 250000)');
    }
}
