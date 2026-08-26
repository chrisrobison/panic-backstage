<?php
declare(strict_types=1);

namespace Panic\Opportunities;

use Panic\BaseEndpoint;
use Panic\Request;
use Panic\Response;

/**
 * Contact <-> opportunity role link (champion/influencer/decision_maker/
 * finance/blocker/other) — docs/OPPORTUNITIES-IMPLEMENTATION.md §3.1,
 * deferred from Phase 4 pending the Opportunity detail UI that manages it.
 *
 *   GET    /api/opportunities/{id}/decision-makers
 *   POST   /api/opportunities/{id}/decision-makers        {contact_id, role}
 *   DELETE /api/opportunities/{id}/decision-makers/{linkId}
 *
 * A contact must belong to the opportunity's own company — same rule as
 * `opportunities.primary_contact_id` (Opportunities::validateOptionalContact()).
 *
 * Capabilities: view_opportunities (read), manage_opportunities (write).
 */
final class DecisionMakers extends BaseEndpoint
{
    public const ROLES = ['champion', 'influencer', 'decision_maker', 'finance', 'blocker', 'other'];

    public function handle(Request $request): Response
    {
        $opportunityId = (int) ($this->params['opportunityId'] ?? 0);
        $linkId        = $this->params['linkId'] ?? null;

        $opportunity = $this->db->one('SELECT id, company_id FROM opportunities WHERE id = ?', [$opportunityId]);
        if (!$opportunity) {
            return $this->notFound('Opportunity not found');
        }

        return match ($request->method()) {
            'GET'    => $this->index($opportunityId),
            'POST'   => $this->create($request, $opportunity),
            'DELETE' => $linkId ? $this->deleteLink($opportunityId, (int) $linkId) : Response::methodNotAllowed(),
            default  => Response::methodNotAllowed(),
        };
    }

    private function index(int $opportunityId): Response
    {
        if ($denied = $this->requireGlobalCapability('view_opportunities')) {
            return $denied;
        }

        $rows = $this->db->all(
            'SELECT dm.*, c.name, c.title, c.email, c.department
             FROM opportunity_decision_makers dm
             JOIN opportunity_contacts c ON c.id = dm.contact_id
             WHERE dm.opportunity_id = ?
             ORDER BY FIELD(dm.role, "decision_maker","champion","influencer","finance","blocker","other"), c.name',
            [$opportunityId]
        );

        return $this->ok(['decision_makers' => $rows, 'roles' => self::ROLES]);
    }

    private function create(Request $request, array $opportunity): Response
    {
        if ($denied = $this->requireGlobalCapability('manage_opportunities')) {
            return $denied;
        }

        $b = $request->body();
        $contactId = isset($b['contact_id']) ? (int) $b['contact_id'] : 0;
        if ($contactId <= 0
            || !$this->db->one('SELECT id FROM opportunity_contacts WHERE id = ? AND company_id = ?', [$contactId, (int) $opportunity['company_id']])
        ) {
            return Response::json(['error' => 'contact_id must reference a contact belonging to this opportunity\'s company'], 422);
        }

        $role = (string) ($b['role'] ?? 'other');
        if (!in_array($role, self::ROLES, true)) {
            return Response::json(['error' => 'Invalid role'], 422);
        }

        if ($this->db->one('SELECT id FROM opportunity_decision_makers WHERE opportunity_id = ? AND contact_id = ?', [$opportunity['id'], $contactId])) {
            return Response::json(['error' => 'This contact is already linked to this opportunity'], 409);
        }

        $id = $this->db->insert(
            'INSERT INTO opportunity_decision_makers (opportunity_id, contact_id, role, created_by) VALUES (?,?,?,?)',
            [$opportunity['id'], $contactId, $role, $this->userId()]
        );

        $row = $this->db->one(
            'SELECT dm.*, c.name, c.title, c.email, c.department
             FROM opportunity_decision_makers dm JOIN opportunity_contacts c ON c.id = dm.contact_id
             WHERE dm.id = ?',
            [$id]
        );

        return $this->ok(['decision_maker' => $row]);
    }

    private function deleteLink(int $opportunityId, int $linkId): Response
    {
        if ($denied = $this->requireGlobalCapability('manage_opportunities')) {
            return $denied;
        }
        if (!$this->db->one('SELECT id FROM opportunity_decision_makers WHERE id = ? AND opportunity_id = ?', [$linkId, $opportunityId])) {
            return $this->notFound('Decision maker link not found');
        }
        $this->db->run('DELETE FROM opportunity_decision_makers WHERE id = ?', [$linkId]);
        return Response::noContent();
    }
}
