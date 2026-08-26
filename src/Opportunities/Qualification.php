<?php
declare(strict_types=1);

namespace Panic\Opportunities;

use Panic\BaseEndpoint;
use Panic\Request;
use Panic\Response;

use function Panic\boolish;

/**
 * Opportunity qualification checklist — one row per opportunity, a fixed
 * boolean per item (docs/OPPORTUNITIES-IMPLEMENTATION.md §3.1/§5: "start
 * with fixed boolean columns per spec's fixed 9-item list, simplest thing
 * that works"). The row is lazily created on first GET/PATCH so callers
 * never have to special-case "no qualification row yet" — a brand new
 * opportunity just reads back all-false.
 *
 *   GET   /api/opportunities/{id}/qualification
 *   PATCH /api/opportunities/{id}/qualification
 *
 * Capabilities: view_opportunities (read), manage_opportunities (write).
 */
final class Qualification extends BaseEndpoint
{
    public const ITEMS = [
        'decision_makers_identified',
        'event_objective_understood',
        'guest_range_confirmed',
        'budget_range_identified',
        'venue_fit_explored',
        'target_date_confirmed',
        'must_have_amenities_identified',
        'competitor_venues_assessed',
        'success_metrics_established',
    ];

    public function handle(Request $request): Response
    {
        $opportunityId = (int) ($this->params['opportunityId'] ?? 0);
        if ($opportunityId <= 0 || !$this->db->one('SELECT id FROM opportunities WHERE id = ?', [$opportunityId])) {
            return $this->notFound('Opportunity not found');
        }

        return match ($request->method()) {
            'GET'   => $this->show($opportunityId),
            'PATCH' => $this->update($request, $opportunityId),
            default => Response::methodNotAllowed(),
        };
    }

    private function show(int $opportunityId): Response
    {
        if ($denied = $this->requireGlobalCapability('view_opportunities')) {
            return $denied;
        }
        return $this->ok(['qualification' => $this->findOrDefault($opportunityId)]);
    }

    private function update(Request $request, int $opportunityId): Response
    {
        if ($denied = $this->requireGlobalCapability('manage_opportunities')) {
            return $denied;
        }

        $b = $request->body();
        $sets   = [];
        $params = [];
        foreach (self::ITEMS as $item) {
            if (!array_key_exists($item, $b)) {
                continue;
            }
            $sets[]   = "`$item` = ?";
            $params[] = boolish($b[$item]);
        }

        // Ensure the row exists first (lazy provisioning — see class docblock).
        $this->ensureRow($opportunityId);

        if ($sets) {
            $sets[]   = '`updated_by` = ?';
            $params[] = $this->userId();
            $params[] = $opportunityId;
            $this->db->run(
                'UPDATE opportunity_qualification SET ' . implode(', ', $sets) . ' WHERE opportunity_id = ?',
                $params
            );
        }

        return $this->ok(['qualification' => $this->findOrDefault($opportunityId)]);
    }

    private function ensureRow(int $opportunityId): void
    {
        // INSERT ... ON DUPLICATE KEY (opportunity_id is unique) rather than
        // a select-then-insert race — cheap and idempotent under concurrent
        // first-writes from two open tabs.
        $this->db->run(
            'INSERT INTO opportunity_qualification (opportunity_id) VALUES (?)
             ON DUPLICATE KEY UPDATE opportunity_id = opportunity_id',
            [$opportunityId]
        );
    }

    private function findOrDefault(int $opportunityId): array
    {
        $row = $this->db->one('SELECT * FROM opportunity_qualification WHERE opportunity_id = ?', [$opportunityId]);
        if ($row) {
            $completed = 0;
            foreach (self::ITEMS as $item) {
                $completed += (int) $row[$item];
            }
            $row['completed_count'] = $completed;
            $row['total_count']     = count(self::ITEMS);
            return $row;
        }

        // No row yet (never PATCHed) — synthesize the all-false default so
        // GET always returns a complete, renderable shape.
        $default = ['opportunity_id' => $opportunityId];
        foreach (self::ITEMS as $item) {
            $default[$item] = 0;
        }
        $default['updated_by']      = null;
        $default['updated_at']      = null;
        $default['completed_count'] = 0;
        $default['total_count']     = count(self::ITEMS);
        return $default;
    }
}
