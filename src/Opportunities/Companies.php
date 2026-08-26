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
 *   GET    /api/opportunity-companies         list (filterable by q, industry,
 *                                              relationship_status, conference_id,
 *                                              researched, has_open_opportunities;
 *                                              sortable — see SORTS below)
 *   POST   /api/opportunity-companies          create
 *   GET    /api/opportunity-companies/{id}     detail (+ participating conferences,
 *                                               opportunities, KPIs, venue-fit tags,
 *                                               pitch ideas — see show())
 *   PATCH  /api/opportunity-companies/{id}     update
 *   DELETE /api/opportunity-companies/{id}     delete (venue_admin only; rejected with 422
 *                                               if the company still has any opportunities —
 *                                               opportunities.company_id has no ON DELETE
 *                                               action, unlike conference_id's SET NULL)
 *   GET    /api/opportunity-companies/{id}/activity   real activity feed (Phase 4,
 *                                              see activity() below)
 *
 * `/{id}/conferences` (reverse of the conference participation link),
 * `/{id}/notes`, `/{id}/signals`, `/{id}/contacts` (Phase 4 buyer contacts),
 * and `/{id}/tasks` are dispatched by Kernel straight to
 * ConferenceCompanies/Notes/Signals/Contacts/TaskLink — see src/Kernel.php's
 * `opportunity-companies` route block.
 *
 * Capabilities: view_opportunities (read), manage_opportunities (write).
 */
final class Companies extends BaseEndpoint
{
    public const RELATIONSHIP_STATUSES = ['prospect', 'active', 'past_client', 'do_not_contact', 'unknown'];

    public const SORTS = ['name', 'pipeline_value', 'open_opportunities', 'last_activity', 'conferences', 'research'];

    private const WRITABLE_FIELDS = [
        'name', 'domain', 'website_url', 'logo_url', 'industry', 'employee_range',
        'hq_city', 'hq_state', 'local_office', 'linkedin_url', 'relationship_status',
        'description', 'last_researched_at',
    ];

    // Deterministic Venue Fit tags (spec's example set) — see venueFitTags()
    // for the heuristic behind each one. Not AI, not stored: recomputed from
    // real company/participation data on every show() call.
    public const VENUE_FIT_TAGS = [
        'large_audience', 'tech_and_innovation', 'executive_visibility',
        'nightlife_fit', 'presentation_fit', 'live_entertainment_fit',
    ];

    public function handle(Request $request): Response
    {
        $id    = $this->params['companyId'] ?? null;
        $child = $this->params['child'] ?? null;

        if ($child === 'activity' && $id) {
            return $this->activity((int) $id, $request);
        }

        return match ($request->method()) {
            'GET'    => $id ? $this->show((int) $id) : $this->index($request),
            'POST'   => $this->create($request),
            'PATCH'  => $this->update($request, (int) $id),
            'DELETE' => $id ? $this->deleteCompany((int) $id) : Response::methodNotAllowed(),
            default  => Response::methodNotAllowed(),
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
            $where[]  = '(co.name LIKE ? OR co.domain LIKE ? OR co.hq_city LIKE ?)';
            $like     = '%' . $q . '%';
            $params   = array_merge($params, [$like, $like, $like]);
        }

        $status = $request->query('relationship_status');
        if ($status && in_array($status, self::RELATIONSHIP_STATUSES, true)) {
            $where[]  = 'co.relationship_status = ?';
            $params[] = $status;
        }

        $industry = trim((string) $request->query('industry', ''));
        if ($industry !== '') {
            $where[]  = 'co.industry LIKE ?';
            $params[] = '%' . $industry . '%';
        }

        $researched = $request->query('researched');
        if ($researched === '1') {
            $where[] = 'co.last_researched_at IS NOT NULL';
        } elseif ($researched === '0') {
            $where[] = 'co.last_researched_at IS NULL';
        }

        $conferenceId = $request->query('conference_id');
        if ($conferenceId) {
            $where[]  = 'EXISTS (SELECT 1 FROM opportunity_conference_companies x WHERE x.company_id = co.id AND x.conference_id = ?)';
            $params[] = (int) $conferenceId;
        }

        if ($request->query('has_open_opportunities') === '1') {
            $where[] = 'COALESCE(agg.open_opportunity_count, 0) > 0';
        }

        $sort = (string) $request->query('sort', 'name');
        if (!in_array($sort, self::SORTS, true)) {
            $sort = 'name';
        }
        $orderBy = match ($sort) {
            'pipeline_value'     => 'COALESCE(agg.pipeline_value, 0) DESC, co.name ASC',
            'open_opportunities' => 'COALESCE(agg.open_opportunity_count, 0) DESC, co.name ASC',
            'last_activity'      => 'agg.last_activity_at IS NULL, agg.last_activity_at DESC',
            'conferences'        => 'COALESCE(cc.conference_count, 0) DESC, co.name ASC',
            'research'           => 'co.last_researched_at IS NULL, co.last_researched_at DESC',
            default              => 'co.name ASC',
        };

        // Aggregates come from derived tables joined once, not from joining
        // opportunities/conference-links directly into this query — doing
        // that would fan-out each company row and inflate SUM(estimated_value)
        // by however many conference links it also has. Still exactly one
        // round trip to MySQL (no N+1 from PHP), same spirit as
        // Conferences::index()'s single-query LEFT JOIN aggregates.
        $companies = $this->db->all(
            'SELECT co.*,
                    COALESCE(agg.open_opportunity_count, 0) AS open_opportunity_count,
                    COALESCE(agg.pipeline_value, 0) AS pipeline_value,
                    agg.last_activity_at,
                    COALESCE(cc.conference_count, 0) AS conference_count,
                    COALESCE(tk.task_count, 0) AS task_count,
                    COALESCE(tk.overdue_task_count, 0) AS overdue_task_count
             FROM opportunity_companies co
             LEFT JOIN (
                 SELECT company_id,
                        SUM(CASE WHEN stage NOT IN (\'won\',\'lost\') THEN 1 ELSE 0 END) AS open_opportunity_count,
                        COALESCE(SUM(CASE WHEN stage NOT IN (\'won\',\'lost\') THEN estimated_value ELSE 0 END), 0) AS pipeline_value,
                        MAX(updated_at) AS last_activity_at
                 FROM opportunities GROUP BY company_id
             ) agg ON agg.company_id = co.id
             LEFT JOIN (
                 SELECT company_id, COUNT(*) AS conference_count
                 FROM opportunity_conference_companies GROUP BY company_id
             ) cc ON cc.company_id = co.id
             LEFT JOIN (
                 SELECT document_id,
                        COUNT(*) AS task_count,
                        SUM(CASE WHEN due_date IS NOT NULL AND due_date < CURDATE() THEN 1 ELSE 0 END) AS overdue_task_count
                 FROM tasks WHERE status != \'done\' GROUP BY document_id
             ) tk ON tk.document_id = co.task_document_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY ' . $orderBy . '
             LIMIT 200',
            $params
        );

        return $this->ok([
            'companies'             => $companies,
            'relationship_statuses' => self::RELATIONSHIP_STATUSES,
            'sorts'                 => self::SORTS,
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

        $conferenceLinks = $this->db->all(
            'SELECT cc.*, conf.name AS conference_name, conf.slug AS conference_slug,
                    conf.starts_at AS conference_starts_at, conf.ends_at AS conference_ends_at
             FROM opportunity_conference_companies cc
             JOIN opportunity_conferences conf ON conf.id = cc.conference_id
             WHERE cc.company_id = ?
             ORDER BY conf.starts_at IS NULL, conf.starts_at DESC',
            [$id]
        );
        foreach ($conferenceLinks as &$link) {
            $link['why_relevant'] = $this->whyRelevant($link);
        }
        unset($link);

        $opportunities = $this->db->all(
            'SELECT o.id, o.name, o.stage, o.estimated_value, o.probability, o.target_date, o.event_type,
                    u.name AS owner_name, conf.name AS conference_name
             FROM opportunities o
             LEFT JOIN users u ON u.id = o.owner_user_id
             LEFT JOIN opportunity_conferences conf ON conf.id = o.conference_id
             WHERE o.company_id = ? ORDER BY o.created_at DESC',
            [$id]
        );

        $openCount = 0;
        $pipelineValue = 0.0;
        $lastActivityAt = $company['updated_at'] ?? null;
        foreach ($opportunities as $o) {
            if (!in_array($o['stage'], ['won', 'lost'], true)) {
                $openCount++;
                $pipelineValue += (float) ($o['estimated_value'] ?? 0);
            }
        }
        $latestOppActivity = $this->db->one(
            'SELECT MAX(updated_at) latest FROM opportunities WHERE company_id = ?',
            [$id]
        );
        if (!empty($latestOppActivity['latest']) && ($lastActivityAt === null || $latestOppActivity['latest'] > $lastActivityAt)) {
            $lastActivityAt = $latestOppActivity['latest'];
        }

        $fitTags = $this->venueFitTags($company, $conferenceLinks);
        $taskCounts = TaskLink::taskCounts($this->db, $company['task_document_id'] ?? null);

        return $this->ok([
            'company'       => $company,
            'conferences'   => $conferenceLinks,
            'opportunities' => $opportunities,
            'kpis' => [
                'open_opportunity_count' => $openCount,
                'pipeline_value'         => $pipelineValue,
                'conference_count'       => count($conferenceLinks),
                'last_activity_at'       => $lastActivityAt,
            ],
            'venue_fit_tags'     => $fitTags,
            'pitch_ideas'        => $this->pitchIdeas($fitTags, $conferenceLinks),
            'task_count'         => $taskCounts['task_count'],
            'overdue_task_count' => $taskCounts['overdue_task_count'],
        ]);
    }

    /**
     * Real activity feed for a company — aggregated across every one of its
     * opportunities' opportunity_activities rows (created/stage_changed/
     * note_added/signal_added/converted/...). Deliberately does NOT invent
     * an "emails/calls/meetings" integration that doesn't exist anywhere in
     * this codebase (spec: "do not fabricate integrations that do not
     * exist") — the company detail page combines this with its own Notes
     * and Tasks panels (fetched separately, same pattern as
     * conference-detail.js) to cover the spec's "notes, tasks, stage
     * changes" list. A free-text manual "Log Activity" entry point is
     * explicitly a Phase 5 opportunity-detail action in the spec, not
     * scoped here.
     */
    private function activity(int $id, Request $request): Response
    {
        if ($denied = $this->requireGlobalCapability('view_opportunities')) {
            return $denied;
        }
        if (!$this->db->one('SELECT id FROM opportunity_companies WHERE id = ?', [$id])) {
            return $this->notFound('Company not found');
        }

        $rows = $this->db->all(
            'SELECT a.id, a.action, a.details_json, a.created_at, u.name AS created_by_name,
                    o.id AS opportunity_id, o.name AS opportunity_name
             FROM opportunity_activities a
             JOIN opportunities o ON o.id = a.opportunity_id
             LEFT JOIN users u ON u.id = a.created_by
             WHERE o.company_id = ?
             ORDER BY a.created_at DESC
             LIMIT 50',
            [$id]
        );
        foreach ($rows as &$row) {
            $row['details'] = $row['details_json'] ? json_decode((string) $row['details_json'], true) : null;
            unset($row['details_json']);
        }
        unset($row);

        // Phase 8: completed AI research jobs scoped to this company (see
        // src/Opportunities/Research/Jobs.php) merged in as synthetic
        // activity rows. Research jobs run scoped to a conference/company,
        // never an opportunity, so they never land in opportunity_activities
        // — without this merge, "research imported" (spec's activity-history
        // list) would have no home at all on the one company-level feed this
        // module has. Real, already-stored data only — nothing fabricated.
        $researchRows = $this->db->all(
            "SELECT id, job_type, completed_at FROM opportunity_research_jobs
             WHERE company_id = ? AND status = 'completed' AND completed_at IS NOT NULL
             ORDER BY completed_at DESC LIMIT 20",
            [$id]
        );
        foreach ($researchRows as $job) {
            $rows[] = [
                'id'               => 'research-' . $job['id'],
                'action'           => 'research_completed',
                'details'          => ['job_type' => $job['job_type']],
                'created_at'       => $job['completed_at'],
                'created_by_name'  => 'AI Research',
                'opportunity_id'   => null,
                'opportunity_name' => null,
            ];
        }
        usort($rows, static fn (array $a, array $b) => strcmp((string) $b['created_at'], (string) $a['created_at']));
        $rows = array_slice($rows, 0, 50);

        return $this->ok(['activity' => $rows]);
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

    private function deleteCompany(int $id): Response
    {
        if ($denied = $this->requireGlobalCapability('manage_opportunities')) {
            return $denied;
        }
        if (!$this->isVenueAdmin()) {
            return $this->forbidden('Only venue admins can delete companies');
        }
        if (!$this->db->one('SELECT id FROM opportunity_companies WHERE id = ?', [$id])) {
            return $this->notFound('Company not found');
        }

        // Unlike opportunity_conferences (whose FK on opportunities.conference_id
        // is ON DELETE SET NULL), opportunity_companies has no such fallback —
        // opportunities.company_id is NOT NULL and required. Reject rather than
        // let the FK constraint throw a raw DB error, and rather than silently
        // orphaning/destroying real pipeline history.
        $openOpps = (int) ($this->db->one('SELECT COUNT(*) c FROM opportunities WHERE company_id = ?', [$id])['c'] ?? 0);
        if ($openOpps > 0) {
            return Response::json(['error' => 'Cannot delete a company with existing opportunities — remove or reassign them first'], 422);
        }

        // opportunity_contacts cascades via its own FK, but opportunity_note_links
        // has no SQL FK to follow either that cascade or a direct company link
        // (linked_id is polymorphic) — clean up both explicitly.
        $contactIds = array_column($this->db->all('SELECT id FROM opportunity_contacts WHERE company_id = ?', [$id]), 'id');
        if ($contactIds) {
            $placeholders = implode(',', array_fill(0, count($contactIds), '?'));
            $this->db->run("DELETE FROM opportunity_note_links WHERE linked_type = 'contact' AND linked_id IN ($placeholders)", $contactIds);
        }
        $this->db->run("DELETE FROM opportunity_note_links WHERE linked_type = 'company' AND linked_id = ?", [$id]);
        $this->db->run('DELETE FROM opportunity_companies WHERE id = ?', [$id]);

        return Response::noContent();
    }

    /**
     * Lowercase, scheme/www/path stripped — e.g. "https://www.NVIDIA.com/en-us/" -> "nvidia.com".
     * Public + static so Research\Importer (Phase 7) can dedupe an
     * AI-discovered company against existing rows using the exact same
     * normalization this class's own create()/update() use, rather than a
     * second drifting implementation.
     */
    public static function normalizeDomain(mixed $raw): ?string
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

    /** A short, non-fabricated reason string for a conference participation row. */
    private function whyRelevant(array $link): string
    {
        $bits = [ucwords(str_replace('_', ' ', (string) $link['role']))];
        if (!empty($link['sponsor_tier'])) {
            $bits[] = $link['sponsor_tier'] . ' tier';
        }
        if (!empty($link['participation_notes'])) {
            $bits[] = (string) $link['participation_notes'];
        }
        return implode(' · ', $bits);
    }

    /**
     * Deterministic Venue Fit tags (spec's example set — see
     * self::VENUE_FIT_TAGS) derived from real stored data, never AI or
     * fabricated. Each heuristic is intentionally simple and documented
     * inline so it stays auditable; Phase 8's scoring service or a future
     * AI phase may refine these, but this is the non-AI floor the spec asks
     * every phase before Phase 7 to have.
     *
     * @param array<int, array<string, mixed>> $conferenceLinks
     * @return string[]
     */
    private function venueFitTags(array $company, array $conferenceLinks): array
    {
        $tags = [];

        // "10,001+" / "1001-5000" / "500+" -> take the first number found;
        // >= 1000 reads as a company big enough to fill a large-audience event.
        $employeeRange = (string) ($company['employee_range'] ?? '');
        if (preg_match('/\d[\d,]*/', $employeeRange, $m) && (int) str_replace(',', '', $m[0]) >= 1000) {
            $tags[] = 'large_audience';
        }

        $industry = strtolower((string) ($company['industry'] ?? ''));
        foreach (['software', 'tech', 'computer', 'hardware', 'ai', 'saas', 'internet', 'semiconductor', 'cloud', 'data'] as $kw) {
            if ($industry !== '' && str_contains($industry, $kw)) {
                $tags[] = 'tech_and_innovation';
                break;
            }
        }

        $roles = array_column($conferenceLinks, 'role');
        if (array_intersect($roles, ['headline_sponsor', 'sponsor', 'speaker', 'organizer'])) {
            $tags[] = 'executive_visibility';
        }
        if (array_intersect($roles, ['speaker', 'organizer'])) {
            $tags[] = 'presentation_fit';
        }
        if (array_intersect($roles, ['headline_sponsor', 'sponsor', 'exhibitor']) && (string) ($company['relationship_status'] ?? '') !== 'do_not_contact') {
            $tags[] = 'nightlife_fit';
        }
        // Attends/sponsors more than one conference we track -> a recurring
        // enough visitor that a splashier live-entertainment format is a
        // reasonable pitch, not a one-off guess.
        if (count($conferenceLinks) >= 2) {
            $tags[] = 'live_entertainment_fit';
        }

        return array_values(array_unique($tags));
    }

    /**
     * Deterministic pitch-idea templates keyed off real venue-fit tags and
     * conference roles — spec: "Use deterministic suggestions until AI
     * phase," matching the same non-AI-labeled precedent set by Discover's
     * "Suggestions" panel (Phase 2). Never claims to be AI-generated.
     *
     * @param string[] $fitTags
     * @param array<int, array<string, mixed>> $conferenceLinks
     * @return string[]
     */
    private function pitchIdeas(array $fitTags, array $conferenceLinks): array
    {
        $ideas = [];
        $conferenceName = $conferenceLinks[0]['conference_name'] ?? null;

        if (in_array('executive_visibility', $fitTags, true)) {
            $ideas[] = $conferenceName
                ? "Propose an executive reception or VIP dinner around {$conferenceName}."
                : 'Propose an executive reception or VIP dinner tied to their next conference appearance.';
        }
        if (in_array('presentation_fit', $fitTags, true)) {
            $ideas[] = 'Offer a fireside chat or speaker meetup space tied to their conference presence.';
        }
        if (in_array('tech_and_innovation', $fitTags, true)) {
            $ideas[] = "Highlight the venue's AV capabilities for a product showcase or demo night.";
        }
        if (in_array('large_audience', $fitTags, true)) {
            $ideas[] = 'A large-guest-count format (all-hands, staff party) fits their company size.';
        }
        if (in_array('live_entertainment_fit', $fitTags, true)) {
            $ideas[] = 'Suggest a live-entertainment after-party format given how often they show up at conferences we track.';
        }
        if (empty($ideas)) {
            $ideas[] = 'Not enough data yet to suggest a pitch — add conference roles or research signals for this company.';
        }

        return array_slice(array_values(array_unique($ideas)), 0, 4);
    }
}
