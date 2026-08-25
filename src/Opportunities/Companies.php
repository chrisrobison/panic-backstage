<?php
declare(strict_types=1);

namespace Panic\Opportunities;

use Panic\BaseEndpoint;
use Panic\Request;
use Panic\Response;

use function Panic\boolish;

/**
 * Prospect companies — organizations that could purchase a private event.
 * Deliberately NOT the existing `contacts` table (a B2C ticket-buyer
 * marketing audience) — see docs/OPPORTUNITIES-IMPLEMENTATION.md §1.15 for
 * why that reuse was unsafe.
 *
 *   GET    /api/opportunity-companies         list (filterable by q, status)
 *   POST   /api/opportunity-companies          create
 *   GET    /api/opportunity-companies/{id}     detail (+ participating conferences)
 *   PATCH  /api/opportunity-companies/{id}     update
 *
 * `/{id}/conferences` (reverse of the conference participation link),
 * `/{id}/notes`, and `/{id}/signals` are dispatched by Kernel straight to
 * ConferenceCompanies/Notes/Signals — see src/Kernel.php's
 * `opportunity-companies` route block.
 *
 * Capabilities: view_opportunities (read), manage_opportunities (write).
 */
final class Companies extends BaseEndpoint
{
    public const RELATIONSHIP_STATUSES = ['prospect', 'active', 'past_client', 'do_not_contact', 'unknown'];

    private const WRITABLE_FIELDS = [
        'name', 'domain', 'website_url', 'logo_url', 'industry', 'employee_range',
        'hq_city', 'hq_state', 'local_office', 'linkedin_url', 'relationship_status',
        'description', 'last_researched_at',
    ];

    public function handle(Request $request): Response
    {
        $id = $this->params['companyId'] ?? null;

        return match ($request->method()) {
            'GET'   => $id ? $this->show((int) $id) : $this->index($request),
            'POST'  => $this->create($request),
            'PATCH' => $this->update($request, (int) $id),
            default => Response::methodNotAllowed(),
        };
    }

    private function index(Request $request): Response
    {
        if ($denied = $this->requireGlobalCapability('view_opportunities')) {
            return $denied;
        }

        $where  = ['1=1'];
        $params = [];

        $q = trim((string) $request->query('q', ''));
        if ($q !== '') {
            $where[]  = '(name LIKE ? OR domain LIKE ? OR hq_city LIKE ?)';
            $like     = '%' . $q . '%';
            $params   = array_merge($params, [$like, $like, $like]);
        }

        $status = $request->query('relationship_status');
        if ($status && in_array($status, self::RELATIONSHIP_STATUSES, true)) {
            $where[]  = 'relationship_status = ?';
            $params[] = $status;
        }

        $companies = $this->db->all(
            'SELECT * FROM opportunity_companies WHERE ' . implode(' AND ', $where) . '
             ORDER BY name ASC
             LIMIT 200',
            $params
        );

        return $this->ok([
            'companies'             => $companies,
            'relationship_statuses' => self::RELATIONSHIP_STATUSES,
        ]);
    }

    private function show(int $id): Response
    {
        if ($denied = $this->requireGlobalCapability('view_opportunities')) {
            return $denied;
        }

        $company = $this->db->one('SELECT * FROM opportunity_companies WHERE id = ?', [$id]);
        if (!$company) {
            return $this->notFound('Company not found');
        }

        $conferences = $this->db->all(
            'SELECT cc.*, conf.name AS conference_name, conf.slug AS conference_slug,
                    conf.starts_at AS conference_starts_at
             FROM opportunity_conference_companies cc
             JOIN opportunity_conferences conf ON conf.id = cc.conference_id
             WHERE cc.company_id = ?
             ORDER BY conf.starts_at IS NULL, conf.starts_at DESC',
            [$id]
        );

        $opportunities = $this->db->all(
            'SELECT id, name, stage, estimated_value, target_date
             FROM opportunities WHERE company_id = ? ORDER BY created_at DESC',
            [$id]
        );

        return $this->ok(['company' => $company, 'conferences' => $conferences, 'opportunities' => $opportunities]);
    }

    private function create(Request $request): Response
    {
        if ($denied = $this->requireGlobalCapability('manage_opportunities')) {
            return $denied;
        }

        $b    = $request->body();
        $name = trim((string) ($b['name'] ?? ''));
        if ($name === '') {
            return Response::json(['error' => 'name is required'], 422);
        }

        $domain = $this->normalizeDomain($b['domain'] ?? null);
        if ($domain !== null && $this->db->one('SELECT id FROM opportunity_companies WHERE domain = ?', [$domain])) {
            return Response::json(['error' => 'A company with this domain already exists'], 422);
        }

        $status = (string) ($b['relationship_status'] ?? 'prospect');
        if (!in_array($status, self::RELATIONSHIP_STATUSES, true)) {
            $status = 'prospect';
        }

        $id = $this->db->insert(
            'INSERT INTO opportunity_companies (
                name, domain, website_url, logo_url, industry, employee_range,
                hq_city, hq_state, local_office, linkedin_url, relationship_status,
                description, created_by
             ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [
                $name,
                $domain,
                $b['website_url']    ?? null,
                $b['logo_url']       ?? null,
                $b['industry']       ?? null,
                $b['employee_range'] ?? null,
                $b['hq_city']        ?? null,
                $b['hq_state']       ?? null,
                boolish($b['local_office'] ?? false),
                $b['linkedin_url']   ?? null,
                $status,
                $b['description']    ?? null,
                $this->userId(),
            ]
        );

        return $this->ok(['company' => $this->db->one('SELECT * FROM opportunity_companies WHERE id = ?', [$id])]);
    }

    private function update(Request $request, int $id): Response
    {
        if ($denied = $this->requireGlobalCapability('manage_opportunities')) {
            return $denied;
        }

        if (!$this->db->one('SELECT id FROM opportunity_companies WHERE id = ?', [$id])) {
            return $this->notFound('Company not found');
        }

        $b = $request->body();

        if (array_key_exists('relationship_status', $b) && !in_array($b['relationship_status'], self::RELATIONSHIP_STATUSES, true)) {
            return Response::json(['error' => 'Invalid relationship_status'], 422);
        }

        if (array_key_exists('domain', $b)) {
            $domain = $this->normalizeDomain($b['domain']);
            $clash  = $domain !== null
                ? $this->db->one('SELECT id FROM opportunity_companies WHERE domain = ? AND id != ?', [$domain, $id])
                : null;
            if ($clash) {
                return Response::json(['error' => 'A company with this domain already exists'], 422);
            }
            $b['domain'] = $domain;
        }

        $sets   = [];
        $params = [];

        foreach (self::WRITABLE_FIELDS as $field) {
            if (!array_key_exists($field, $b)) {
                continue;
            }
            $val = $b[$field];
            if ($field === 'local_office') {
                $val = boolish($val);
            } elseif ($field === 'last_researched_at') {
                $val = $val !== null && $val !== '' ? (string) $val : null;
            }
            $sets[]   = "`$field` = ?";
            $params[] = $val;
        }

        if (empty($sets)) {
            return $this->ok(['company' => $this->db->one('SELECT * FROM opportunity_companies WHERE id = ?', [$id])]);
        }

        $params[] = $id;
        $this->db->run('UPDATE opportunity_companies SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);

        return $this->ok(['company' => $this->db->one('SELECT * FROM opportunity_companies WHERE id = ?', [$id])]);
    }

    /** Lowercase, scheme/www/path stripped — e.g. "https://www.NVIDIA.com/en-us/" -> "nvidia.com". */
    private function normalizeDomain(mixed $raw): ?string
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }
        $raw = preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', $raw) ?? $raw;
        $raw = preg_replace('#^www\.#i', '', $raw) ?? $raw;
        $raw = strtolower(explode('/', $raw)[0]);
        $raw = trim($raw);
        return $raw !== '' ? $raw : null;
    }
}
