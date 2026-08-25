<?php
declare(strict_types=1);

namespace Panic\Opportunities;

use Panic\BaseEndpoint;
use Panic\Request;
use Panic\Response;

use function Panic\date_or_null;
use function Panic\slugify;

/**
 * Conference/convention/trade-show source-of-demand records — see
 * docs/OPPORTUNITIES-IMPLEMENTATION.md §3.1. A conference is not itself a
 * sales opportunity; it's the thing that creates demand for one.
 *
 *   GET    /api/opportunity-conferences         list (filterable by q, upcoming)
 *   POST   /api/opportunity-conferences          create
 *   GET    /api/opportunity-conferences/{id}     detail (+ participating companies)
 *   PATCH  /api/opportunity-conferences/{id}     update
 *
 * `/{id}/companies` (participation links), `/{id}/notes`, and `/{id}/signals`
 * are dispatched by Kernel straight to ConferenceCompanies/Notes/Signals —
 * see src/Kernel.php's `opportunity-conferences` route block.
 *
 * Capabilities: view_opportunities (read), manage_opportunities (write).
 */
final class Conferences extends BaseEndpoint
{
    private const WRITABLE_FIELDS = [
        'name', 'description', 'website_url', 'venue_name', 'venue_address',
        'city', 'state', 'country', 'starts_at', 'ends_at',
        'estimated_attendance', 'estimated_exhibitors', 'estimated_sponsors',
        'latitude', 'longitude', 'distance_from_venue_miles', 'opportunity_score',
        'source_url', 'last_researched_at',
    ];

    private const DATE_FIELDS = ['starts_at', 'ends_at'];
    private const INT_FIELDS  = ['estimated_attendance', 'estimated_exhibitors', 'estimated_sponsors'];

    public function handle(Request $request): Response
    {
        $id = $this->params['conferenceId'] ?? null;

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
            $where[]  = '(name LIKE ? OR city LIKE ? OR state LIKE ?)';
            $like     = '%' . $q . '%';
            $params   = array_merge($params, [$like, $like, $like]);
        }

        if ($request->query('upcoming') === '1') {
            $where[] = '(starts_at IS NULL OR starts_at >= CURDATE())';
        }

        $conferences = $this->db->all(
            'SELECT * FROM opportunity_conferences WHERE ' . implode(' AND ', $where) . '
             ORDER BY starts_at IS NULL, starts_at ASC, name ASC
             LIMIT 200',
            $params
        );

        return $this->ok(['conferences' => $conferences]);
    }

    private function show(int $id): Response
    {
        if ($denied = $this->requireGlobalCapability('view_opportunities')) {
            return $denied;
        }

        $conference = $this->db->one('SELECT * FROM opportunity_conferences WHERE id = ?', [$id]);
        if (!$conference) {
            return $this->notFound('Conference not found');
        }

        $companies = $this->db->all(
            'SELECT cc.*, co.name AS company_name, co.domain AS company_domain,
                    co.relationship_status AS company_relationship_status
             FROM opportunity_conference_companies cc
             JOIN opportunity_companies co ON co.id = cc.company_id
             WHERE cc.conference_id = ?
             ORDER BY FIELD(cc.role, "organizer","headline_sponsor","sponsor","exhibitor","speaker","partner","vendor","delegation","attendee","unknown"), co.name',
            [$id]
        );

        return $this->ok(['conference' => $conference, 'companies' => $companies]);
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

        $baseSlug = slugify($name);
        $slug     = $baseSlug;
        $suffix   = 2;
        while ($this->db->one('SELECT id FROM opportunity_conferences WHERE slug = ?', [$slug])) {
            $slug = $baseSlug . '-' . $suffix;
            $suffix++;
        }

        $id = $this->db->insert(
            'INSERT INTO opportunity_conferences (
                name, slug, description, website_url, venue_name, venue_address,
                city, state, country, starts_at, ends_at,
                estimated_attendance, estimated_exhibitors, estimated_sponsors,
                latitude, longitude, source_url, created_by
             ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [
                $name,
                $slug,
                $b['description']    ?? null,
                $b['website_url']    ?? null,
                $b['venue_name']     ?? null,
                $b['venue_address']  ?? null,
                $b['city']           ?? null,
                $b['state']          ?? null,
                $b['country']        ?? null,
                date_or_null($b['starts_at'] ?? null),
                date_or_null($b['ends_at'] ?? null),
                $this->intOrNull($b['estimated_attendance'] ?? null),
                $this->intOrNull($b['estimated_exhibitors'] ?? null),
                $this->intOrNull($b['estimated_sponsors'] ?? null),
                $this->decimalOrNull($b['latitude'] ?? null),
                $this->decimalOrNull($b['longitude'] ?? null),
                $b['source_url']     ?? null,
                $this->userId(),
            ]
        );

        return $this->ok(['conference' => $this->db->one('SELECT * FROM opportunity_conferences WHERE id = ?', [$id])]);
    }

    private function update(Request $request, int $id): Response
    {
        if ($denied = $this->requireGlobalCapability('manage_opportunities')) {
            return $denied;
        }

        if (!$this->db->one('SELECT id FROM opportunity_conferences WHERE id = ?', [$id])) {
            return $this->notFound('Conference not found');
        }

        $b      = $request->body();
        $sets   = [];
        $params = [];

        foreach (self::WRITABLE_FIELDS as $field) {
            if (!array_key_exists($field, $b)) {
                continue;
            }
            $val = $b[$field];
            if (in_array($field, self::DATE_FIELDS, true)) {
                $val = date_or_null($val);
            } elseif (in_array($field, self::INT_FIELDS, true)) {
                $val = $this->intOrNull($val);
            } elseif (in_array($field, ['latitude', 'longitude', 'distance_from_venue_miles'], true)) {
                $val = $this->decimalOrNull($val);
            } elseif ($field === 'opportunity_score') {
                $val = $val !== null && $val !== '' ? max(0, min(100, (int) $val)) : null;
            } elseif ($field === 'last_researched_at') {
                $val = $val !== null && $val !== '' ? (string) $val : null;
            }
            $sets[]   = "`$field` = ?";
            $params[] = $val;
        }

        if (empty($sets)) {
            return $this->ok(['conference' => $this->db->one('SELECT * FROM opportunity_conferences WHERE id = ?', [$id])]);
        }

        $params[] = $id;
        $this->db->run('UPDATE opportunity_conferences SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);

        return $this->ok(['conference' => $this->db->one('SELECT * FROM opportunity_conferences WHERE id = ?', [$id])]);
    }

    private function intOrNull(mixed $value): ?int
    {
        return $value !== null && $value !== '' ? (int) $value : null;
    }

    private function decimalOrNull(mixed $value): ?float
    {
        return $value !== null && $value !== '' ? (float) $value : null;
    }
}
