<?php
declare(strict_types=1);

namespace Panic;

use function Panic\date_or_null;
use function Panic\boolish;

/**
 * Systems Inventory — lightweight catalog of connected external platforms.
 *
 *   GET    /api/systems-inventory           list all items
 *   POST   /api/systems-inventory           create
 *   GET    /api/systems-inventory/{id}      detail
 *   PATCH  /api/systems-inventory/{id}      update
 *   DELETE /api/systems-inventory/{id}      delete (admin only)
 *
 * IMPORTANT: This catalog stores operational metadata ONLY.
 * It must NEVER store passwords, recovery codes, MFA secrets, API keys,
 * access tokens, or any sensitive credentials.
 *
 * Capability: manage_systems_inventory (venue_admin)
 */
final class SystemsInventory extends BaseEndpoint
{
    private const CATEGORIES = [
        'social','ticketing','payment','email','analytics','security',
        'hosting','dns','storage','communication','pos','other',
    ];

    public function handle(Request $request): Response
    {
        if ($denied = $this->requireGlobalCapability('manage_systems_inventory')) {
            return $denied;
        }

        $itemId = $this->params['itemId'] ?? null;

        return match ($request->method()) {
            'GET'    => $itemId ? $this->show((int) $itemId) : $this->index($request),
            'POST'   => $this->create($request),
            'PATCH'  => $this->update($request, (int) $itemId),
            'DELETE' => $this->deleteItem((int) $itemId),
            default  => Response::methodNotAllowed(),
        };
    }

    private function index(Request $request): Response
    {
        $where  = ['is_active = 1'];
        $params = [];

        if ($request->query('expiring_days')) {
            $days     = max(1, (int) $request->query('expiring_days'));
            $where[]  = 'renewal_date IS NOT NULL AND renewal_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY)';
            $params[] = $days;
        }

        if ($request->query('category')) {
            $where[]  = 'category = ?';
            $params[] = $request->query('category');
        }

        $items = $this->db->all(
            "SELECT s.*, u.name owner_name FROM systems_inventory s
             LEFT JOIN users u ON u.id = s.owner_user_id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY s.category, s.name",
            $params
        );

        // Flag items expiring within their alert window
        $today = date('Y-m-d');
        foreach ($items as &$item) {
            $item['is_expiring_soon'] = false;
            if ($item['renewal_date']) {
                $daysUntil = (int) ((strtotime($item['renewal_date']) - strtotime($today)) / 86400);
                $item['days_until_renewal'] = $daysUntil;
                $item['is_expiring_soon']   = $daysUntil <= (int) $item['expiry_alert_days'];
            } else {
                $item['days_until_renewal'] = null;
            }
        }
        unset($item);

        return $this->ok([
            'items'      => $items,
            'categories' => self::CATEGORIES,
        ]);
    }

    private function show(int $id): Response
    {
        $item = $this->db->one(
            'SELECT s.*, u.name owner_name FROM systems_inventory s
             LEFT JOIN users u ON u.id = s.owner_user_id
             WHERE s.id = ?',
            [$id]
        );
        return $item ? $this->ok(['item' => $item]) : $this->notFound();
    }

    private function create(Request $request): Response
    {
        $b = $request->body();

        // Refuse to store anything that looks like a credential
        $this->rejectCredentialFields($b);

        $category = (string) ($b['category'] ?? 'other');
        if (!in_array($category, self::CATEGORIES, true)) {
            $category = 'other';
        }

        $id = $this->db->insert(
            'INSERT INTO systems_inventory
             (name, category, url, owner_user_id, owner_name, owner_email, purpose,
              recovery_path, vault_reference, renewal_date, expiry_alert_days,
              notes, is_active, created_by_id)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [
                trim((string) ($b['name'] ?? 'Unnamed System')),
                $category,
                $b['url']            ?? null,
                isset($b['owner_user_id']) ? (int) $b['owner_user_id'] : null,
                $b['owner_name']     ?? null,
                $b['owner_email']    ?? null,
                $b['purpose']        ?? null,
                $b['recovery_path']  ?? null,
                $b['vault_reference'] ?? null,
                date_or_null($b['renewal_date'] ?? null),
                (int) ($b['expiry_alert_days'] ?? 30),
                $b['notes']          ?? null,
                1,
                $this->userId(),
            ]
        );

        return $this->ok(['id' => $id]);
    }

    private function update(Request $request, int $id): Response
    {
        if (!$this->db->one('SELECT id FROM systems_inventory WHERE id = ?', [$id])) {
            return $this->notFound();
        }

        $b = $request->body();
        $this->rejectCredentialFields($b);

        $sets   = [];
        $params = [];

        $fields = [
            'name','category','url','owner_user_id','owner_name','owner_email',
            'purpose','recovery_path','vault_reference','renewal_date',
            'expiry_alert_days','notes','is_active',
        ];

        foreach ($fields as $f) {
            if (!array_key_exists($f, $b)) continue;
            $val = $b[$f];
            if ($f === 'renewal_date') {
                $val = date_or_null($val);
            } elseif ($f === 'is_active') {
                $val = boolish($val);
            }
            $sets[]   = "$f = ?";
            $params[] = $val;
        }

        if (empty($sets)) {
            return $this->ok(['ok' => true]);
        }

        $params[] = $id;
        $this->db->run('UPDATE systems_inventory SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);

        return $this->ok(['ok' => true]);
    }

    private function deleteItem(int $id): Response
    {
        if (!$this->isVenueAdmin()) {
            return $this->forbidden('Only venue admins can delete inventory items');
        }
        $this->db->run('DELETE FROM systems_inventory WHERE id = ?', [$id]);
        return Response::noContent();
    }

    /**
     * Explicitly refuse fields that suggest credentials are being submitted.
     * This is a defense-in-depth check — the table itself has no credential columns.
     */
    private function rejectCredentialFields(array $b): void
    {
        $suspicious = ['password','secret','token','api_key','access_key','private_key',
                       'recovery_code','mfa_secret','totp','passphrase','credential'];
        foreach (array_keys($b) as $key) {
            foreach ($suspicious as $word) {
                if (str_contains(strtolower((string) $key), $word)) {
                    throw new \InvalidArgumentException(
                        "Systems inventory must not store credentials. " .
                        "Field '$key' appears to contain sensitive data. " .
                        "Use your password manager or the Promote credentials screen instead."
                    );
                }
            }
        }
    }
}
