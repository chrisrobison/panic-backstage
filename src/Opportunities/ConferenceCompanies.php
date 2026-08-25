<?php
declare(strict_types=1);

namespace Panic\Opportunities;

use Panic\BaseEndpoint;
use Panic\Request;
use Panic\Response;

use function Panic\date_or_null;

/**
 * Conference <-> company participation links (a company attending/sponsoring/
 * exhibiting at a conference) — see docs/OPPORTUNITIES-IMPLEMENTATION.md §3.1.
 *
 * One class serves both directions, distinguished by which id Kernel hands
 * it (src/Kernel.php's `opportunity-conferences`/`opportunity-companies`
 * route blocks):
 *
 *   GET    /api/opportunity-conferences/{id}/companies             list
 *   POST   /api/opportunity-conferences/{id}/companies             attach
 *   PATCH  /api/opportunity-conferences/{id}/companies/{linkId}    update
 *   DELETE /api/opportunity-conferences/{id}/companies/{linkId}    detach
 *   GET    /api/opportunity-companies/{id}/conferences              list (reverse, read-only)
 *
 * Capabilities: view_opportunities (read), manage_opportunities (write).
 */
final class ConferenceCompanies extends BaseEndpoint
{
    public const ROLES = [
        'organizer', 'headline_sponsor', 'sponsor', 'exhibitor', 'speaker',
        'partner', 'vendor', 'delegation', 'attendee', 'unknown',
    ];

    public const CONFIDENCE_LEVELS = ['low', 'medium', 'high'];

    private const WRITABLE_FIELDS = ['role', 'sponsor_tier', 'booth', 'participation_notes', 'confidence', 'source_url', 'observed_at'];

    public function handle(Request $request): Response
    {
        if (array_key_exists('companyId', $this->params)) {
            if ($request->method() !== 'GET') {
                return Response::methodNotAllowed();
            }
            return $this->listForCompany((int) $this->params['companyId']);
        }

        $conferenceId = (int) ($this->params['conferenceId'] ?? 0);
        $linkId       = $this->params['linkId'] ?? null;

        return match ($request->method()) {
            'GET'    => $this->listForConference($conferenceId),
            'POST'   => $this->attach($request, $conferenceId),
            'PATCH'  => $linkId ? $this->updateLink($request, $conferenceId, (int) $linkId) : Response::methodNotAllowed(),
            'DELETE' => $linkId ? $this->detach($conferenceId, (int) $linkId) : Response::methodNotAllowed(),
            default  => Response::methodNotAllowed(),
        };
    }

    private function listForConference(int $conferenceId): Response
    {
        if ($denied = $this->requireGlobalCapability('view_opportunities')) {
            return $denied;
        }
        if (!$this->db->one('SELECT id FROM opportunity_conferences WHERE id = ?', [$conferenceId])) {
            return $this->notFound('Conference not found');
        }

        $links = $this->db->all(
            'SELECT cc.*, co.name AS company_name, co.domain AS company_domain
             FROM opportunity_conference_companies cc
             JOIN opportunity_companies co ON co.id = cc.company_id
             WHERE cc.conference_id = ?
             ORDER BY FIELD(cc.role, "organizer","headline_sponsor","sponsor","exhibitor","speaker","partner","vendor","delegation","attendee","unknown"), co.name',
            [$conferenceId]
        );

        return $this->ok(['companies' => $links, 'roles' => self::ROLES]);
    }

    private function listForCompany(int $companyId): Response
    {
        if ($denied = $this->requireGlobalCapability('view_opportunities')) {
            return $denied;
        }
        if (!$this->db->one('SELECT id FROM opportunity_companies WHERE id = ?', [$companyId])) {
            return $this->notFound('Company not found');
        }

        $links = $this->db->all(
            'SELECT cc.*, conf.name AS conference_name, conf.slug AS conference_slug, conf.starts_at AS conference_starts_at
             FROM opportunity_conference_companies cc
             JOIN opportunity_conferences conf ON conf.id = cc.conference_id
             WHERE cc.company_id = ?
             ORDER BY conf.starts_at IS NULL, conf.starts_at DESC',
            [$companyId]
        );

        return $this->ok(['conferences' => $links]);
    }

    private function attach(Request $request, int $conferenceId): Response
    {
        if ($denied = $this->requireGlobalCapability('manage_opportunities')) {
            return $denied;
        }
        if (!$this->db->one('SELECT id FROM opportunity_conferences WHERE id = ?', [$conferenceId])) {
            return $this->notFound('Conference not found');
        }

        $b         = $request->body();
        $companyId = isset($b['company_id']) ? (int) $b['company_id'] : 0;
        if ($companyId <= 0 || !$this->db->one('SELECT id FROM opportunity_companies WHERE id = ?', [$companyId])) {
            return Response::json(['error' => 'A valid company_id is required'], 422);
        }

        if ($this->db->one('SELECT id FROM opportunity_conference_companies WHERE conference_id = ? AND company_id = ?', [$conferenceId, $companyId])) {
            return Response::json(['error' => 'This company is already linked to this conference'], 422);
        }

        [$role, $confidence, $error] = $this->validateEnums($b);
        if ($error) {
            return $error;
        }

        $id = $this->db->insert(
            'INSERT INTO opportunity_conference_companies (
                conference_id, company_id, role, sponsor_tier, booth, participation_notes,
                confidence, source_url, observed_at, created_by
             ) VALUES (?,?,?,?,?,?,?,?,?,?)',
            [
                $conferenceId,
                $companyId,
                $role,
                $b['sponsor_tier']         ?? null,
                $b['booth']                ?? null,
                $b['participation_notes']  ?? null,
                $confidence,
                $b['source_url']           ?? null,
                date_or_null($b['observed_at'] ?? null),
                $this->userId(),
            ]
        );

        return $this->ok(['link' => $this->db->one('SELECT * FROM opportunity_conference_companies WHERE id = ?', [$id])]);
    }

    private function updateLink(Request $request, int $conferenceId, int $linkId): Response
    {
        if ($denied = $this->requireGlobalCapability('manage_opportunities')) {
            return $denied;
        }

        $existing = $this->db->one('SELECT * FROM opportunity_conference_companies WHERE id = ? AND conference_id = ?', [$linkId, $conferenceId]);
        if (!$existing) {
            return $this->notFound('Link not found');
        }

        $b = $request->body();
        [, , $error] = $this->validateEnums($b, requireFields: false);
        if ($error) {
            return $error;
        }

        $sets   = [];
        $params = [];
        foreach (self::WRITABLE_FIELDS as $field) {
            if (!array_key_exists($field, $b)) {
                continue;
            }
            $val = $field === 'observed_at' ? date_or_null($b[$field]) : $b[$field];
            $sets[]   = "`$field` = ?";
            $params[] = $val;
        }

        if (empty($sets)) {
            return $this->ok(['link' => $existing]);
        }

        $params[] = $linkId;
        $this->db->run('UPDATE opportunity_conference_companies SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);

        return $this->ok(['link' => $this->db->one('SELECT * FROM opportunity_conference_companies WHERE id = ?', [$linkId])]);
    }

    private function detach(int $conferenceId, int $linkId): Response
    {
        if ($denied = $this->requireGlobalCapability('manage_opportunities')) {
            return $denied;
        }
        if (!$this->db->one('SELECT id FROM opportunity_conference_companies WHERE id = ? AND conference_id = ?', [$linkId, $conferenceId])) {
            return $this->notFound('Link not found');
        }

        $this->db->run('DELETE FROM opportunity_conference_companies WHERE id = ?', [$linkId]);

        return Response::noContent();
    }

    /** @return array{0: ?string, 1: ?string, 2: ?Response} */
    private function validateEnums(array $b, bool $requireFields = true): array
    {
        $role = (string) ($b['role'] ?? 'unknown');
        if (array_key_exists('role', $b) && !in_array($b['role'], self::ROLES, true)) {
            return [null, null, Response::json(['error' => 'Invalid role'], 422)];
        }
        $confidence = (string) ($b['confidence'] ?? 'medium');
        if (array_key_exists('confidence', $b) && !in_array($b['confidence'], self::CONFIDENCE_LEVELS, true)) {
            return [null, null, Response::json(['error' => 'Invalid confidence'], 422)];
        }
        return [$role, $confidence, null];
    }
}
