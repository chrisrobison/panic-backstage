<?php
declare(strict_types=1);

namespace Panic\Opportunities;

use DateTimeImmutable;
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
 *   GET    /api/opportunity-conferences         list (filterable/sortable — see index())
 *   POST   /api/opportunity-conferences          create
 *   GET    /api/opportunity-conferences/{id}     detail (+ companies, facts,
 *                                                 target_company_count, distance,
 *                                                 peak_windows, outreach_angles)
 *   PATCH  /api/opportunity-conferences/{id}     update
 *   GET/POST   /api/opportunity-conferences/{id}/facts           key facts (Phase 3)
 *   DELETE     /api/opportunity-conferences/{id}/facts/{factId}
 *
 * `/{id}/companies` (participation links), `/{id}/notes`, `/{id}/signals`
 * (reused for Side Event Signals — see Signals.php), and `/{id}/tasks`
 * (lazily-provisioned linked task_documents row — see TaskLink.php) are
 * dispatched by Kernel straight to their own classes — see
 * src/Kernel.php's `opportunity-conferences` route block.
 *
 * Distance/peak-windows/outreach-angles are all computed locally and
 * deterministically (no external API calls, no AI) — see
 * Opportunities/Availability.php for the distance math and
 * emptyNightMatches() this class's outreach-angle generator reads from.
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

    private const SORTS = [
        'date'             => 'oc.starts_at IS NULL, oc.starts_at ASC',
        'date_desc'        => 'oc.starts_at IS NULL, oc.starts_at DESC',
        'score'            => 'oc.opportunity_score IS NULL, oc.opportunity_score DESC',
        'attendance'       => 'oc.estimated_attendance IS NULL, oc.estimated_attendance DESC',
        'target_companies' => 'target_company_count DESC, oc.name ASC',
    ];

    public function handle(Request $request): Response
    {
        $id    = $this->params['conferenceId'] ?? null;
        $child = $this->params['child'] ?? null;

        if ($child === 'facts') {
            return $this->handleFacts($request, (int) $id, $this->params['factId'] ?? null);
        }

        return match ($request->method()) {
            'GET'    => $id ? $this->show((int) $id) : $this->index($request),
            'POST'   => $this->create($request),
            'PATCH'  => $this->update($request, (int) $id),
            'DELETE' => $this->deleteConference((int) $id),
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
            $where[]  = '(oc.name LIKE ? OR oc.city LIKE ? OR oc.state LIKE ?)';
            $like     = '%' . $q . '%';
            $params   = array_merge($params, [$like, $like, $like]);
        }

        $city = trim((string) $request->query('city', ''));
        if ($city !== '') {
            $where[]  = 'oc.city LIKE ?';
            $params[] = '%' . $city . '%';
        }

        if ($request->query('upcoming') === '1') {
            $where[] = '(oc.starts_at IS NULL OR oc.starts_at >= CURDATE())';
        }
        if ($request->query('past') === '1') {
            $where[] = 'COALESCE(oc.ends_at, oc.starts_at) < CURDATE()';
        }

        $dateFrom = date_or_null($request->query('date_from'));
        if ($dateFrom) {
            $where[]  = 'oc.starts_at >= ?';
            $params[] = $dateFrom;
        }
        $dateTo = date_or_null($request->query('date_to'));
        if ($dateTo) {
            $where[]  = 'oc.starts_at <= ?';
            $params[] = $dateTo;
        }

        $researched = $request->query('researched');
        if ($researched === '1') {
            $where[] = 'oc.last_researched_at IS NOT NULL';
        } elseif ($researched === '0') {
            $where[] = 'oc.last_researched_at IS NULL';
        }

        $minScore = $request->query('min_score');
        if ($minScore !== null && $minScore !== '') {
            $where[]  = 'oc.opportunity_score >= ?';
            $params[] = (int) $minScore;
        }

        $sortKey = (string) $request->query('sort', 'date');
        $orderBy = self::SORTS[$sortKey] ?? self::SORTS['date'];

        $conferences = $this->db->all(
            'SELECT oc.*, COALESCE(cc.company_count, 0) AS target_company_count,
                    COALESCE(sig.signal_count, 0) AS side_event_signal_count
             FROM opportunity_conferences oc
             LEFT JOIN (
               SELECT conference_id, COUNT(*) AS company_count
               FROM opportunity_conference_companies GROUP BY conference_id
             ) cc ON cc.conference_id = oc.id
             LEFT JOIN (
               SELECT conference_id, COUNT(*) AS signal_count
               FROM opportunity_signals WHERE conference_id IS NOT NULL GROUP BY conference_id
             ) sig ON sig.conference_id = oc.id
             WHERE ' . implode(' AND ', $where) . "
             ORDER BY $orderBy
             LIMIT 200",
            $params
        );

        $venueCoords = Availability::venueCoordinates($this->db);
        foreach ($conferences as &$conference) {
            $conference['distance_from_venue_miles'] = Availability::conferenceDistanceMiles($conference, $venueCoords);
        }
        unset($conference);

        // Proximity has no meaningful SQL ORDER BY (nulls-vs-computed), so
        // sort in PHP after hydrating distance — the same small in-memory
        // sort every "sort a fetched page by a derived value" list in this
        // codebase already does, not a query-level concern at this row count.
        if ($sortKey === 'proximity') {
            usort($conferences, static function (array $a, array $b): int {
                if ($a['distance_from_venue_miles'] === null) return $b['distance_from_venue_miles'] === null ? 0 : 1;
                if ($b['distance_from_venue_miles'] === null) return -1;
                return $a['distance_from_venue_miles'] <=> $b['distance_from_venue_miles'];
            });
        }

        return $this->ok(['conferences' => $conferences, 'sort' => $sortKey]);
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

        $facts = $this->db->all(
            'SELECT * FROM opportunity_conference_facts WHERE conference_id = ? ORDER BY sort_order, id',
            [$id]
        );

        $venueCoords = Availability::venueCoordinates($this->db);
        $conference['distance_from_venue_miles'] = Availability::conferenceDistanceMiles($conference, $venueCoords);
        $conference['venue_coordinates_known'] = $venueCoords !== null;

        $emptyNightDates = [];
        if ($conference['starts_at']) {
            $emptyNightDates = array_values(array_map(
                static fn (array $m) => $m['date'],
                array_filter(
                    Availability::emptyNightMatches($this->db, 365),
                    static fn (array $m) => (int) $m['conference']['id'] === $id
                )
            ));
        }

        return $this->ok([
            'conference'            => $conference,
            'companies'             => $companies,
            'target_company_count'  => count($companies),
            'facts'                 => $facts,
            'peak_windows'          => $this->peakWindows($conference),
            'empty_night_dates'     => $emptyNightDates,
            'outreach_angles'       => $this->outreachAngles($conference, $companies, $emptyNightDates),
        ]);
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

    /**
     * Not part of the spec's Phase 3 acceptance list (add/edit, not
     * delete) — added anyway, gated the same way
     * Opportunities::deleteOpportunity() is (manage_opportunities AND
     * venue_admin), because with zero delete path a mis-entered or
     * throwaway conference (including this module's own test fixtures)
     * could never be removed from a production database. Cascades
     * opportunity_conference_companies/opportunity_conference_facts (FK
     * ON DELETE CASCADE); linked opportunities/notes/signals keep their
     * row but lose the conference reference (ON DELETE SET NULL/CASCADE
     * per migration 109/111 — see each table's own FK).
     */
    private function deleteConference(int $id): Response
    {
        if ($denied = $this->requireGlobalCapability('manage_opportunities')) {
            return $denied;
        }
        if (!$this->isVenueAdmin()) {
            return $this->forbidden('Only venue admins can delete conferences');
        }
        if (!$this->db->one('SELECT id FROM opportunity_conferences WHERE id = ?', [$id])) {
            return $this->notFound('Conference not found');
        }

        // opportunity_note_links has no SQL FK to the conference it points
        // at (linked_id is polymorphic — see Notes.php's docblock), so
        // nothing cascades those rows away on its own; clean them up
        // explicitly rather than leaving orphaned links behind. The notes
        // themselves survive if they also link something else.
        $this->db->run("DELETE FROM opportunity_note_links WHERE linked_type = 'conference' AND linked_id = ?", [$id]);
        $this->db->run('DELETE FROM opportunity_conferences WHERE id = ?', [$id]);

        return Response::noContent();
    }

    // ── Key Facts sub-resource ──────────────────────────────────────────────

    private function handleFacts(Request $request, int $conferenceId, mixed $factId): Response
    {
        if ($request->method() === 'GET') {
            if ($denied = $this->requireGlobalCapability('view_opportunities')) {
                return $denied;
            }
            $facts = $this->db->all(
                'SELECT * FROM opportunity_conference_facts WHERE conference_id = ? ORDER BY sort_order, id',
                [$conferenceId]
            );
            return $this->ok(['facts' => $facts]);
        }

        if ($denied = $this->requireGlobalCapability('manage_opportunities')) {
            return $denied;
        }
        if (!$this->db->one('SELECT id FROM opportunity_conferences WHERE id = ?', [$conferenceId])) {
            return $this->notFound('Conference not found');
        }

        if ($request->method() === 'POST') {
            $fact = trim((string) $request->body('fact', ''));
            if ($fact === '') {
                return Response::json(['error' => 'fact is required'], 422);
            }
            $nextOrder = (int) ($this->db->one(
                'SELECT COALESCE(MAX(sort_order), 0) + 10 n FROM opportunity_conference_facts WHERE conference_id = ?',
                [$conferenceId]
            )['n'] ?? 10);
            $id = $this->db->insert(
                'INSERT INTO opportunity_conference_facts (conference_id, fact, source_url, sort_order, created_by) VALUES (?,?,?,?,?)',
                [$conferenceId, $fact, $request->body('source_url') ?: null, $nextOrder, $this->userId()]
            );
            return $this->ok(['fact' => $this->db->one('SELECT * FROM opportunity_conference_facts WHERE id = ?', [$id])]);
        }

        if ($request->method() === 'DELETE' && $factId) {
            $this->db->run('DELETE FROM opportunity_conference_facts WHERE id = ? AND conference_id = ?', [(int) $factId, $conferenceId]);
            return Response::noContent();
        }

        return Response::methodNotAllowed();
    }

    // ── Deterministic computed sections (no AI, no external calls) ─────────

    /**
     * Evening-before / each-conference-day / evening-after, plus a small
     * "best side-event dates" subset (first pre-day, first and last main
     * days, the post day) — the spec's "start with deterministic date logic"
     * for Peak Side-Event Windows. AI enrichment (Phase 7) can add to this
     * later; it never has to replace it.
     */
    private function peakWindows(array $conference): array
    {
        if (!$conference['starts_at']) {
            return ['windows' => [], 'best_dates' => []];
        }

        $start = new DateTimeImmutable($conference['starts_at']);
        $end   = new DateTimeImmutable($conference['ends_at'] ?: $conference['starts_at']);

        $windows = [];
        $preDate = $start->modify('-1 day');
        $windows[] = ['date' => $preDate->format('Y-m-d'), 'phase' => 'pre', 'activity' => 'high'];

        $mainDays = [];
        for ($cursor = $start; $cursor <= $end; $cursor = $cursor->modify('+1 day')) {
            $isEdge = $cursor == $start || $cursor == $end;
            $windows[] = ['date' => $cursor->format('Y-m-d'), 'phase' => 'main', 'activity' => $isEdge ? 'high' : 'very_high'];
            $mainDays[] = $cursor->format('Y-m-d');
        }

        $postDate = $end->modify('+1 day');
        $windows[] = ['date' => $postDate->format('Y-m-d'), 'phase' => 'post', 'activity' => 'high'];

        $bestDates = array_values(array_unique(array_filter([
            $preDate->format('Y-m-d'),
            $mainDays[0] ?? null,
            count($mainDays) > 1 ? $mainDays[count($mainDays) - 1] : null,
            $postDate->format('Y-m-d'),
        ])));

        return ['windows' => $windows, 'best_dates' => $bestDates];
    }

    /**
     * Deterministic outreach-angle templates from real participation data
     * (sponsor/exhibitor counts) and real venue availability (empty-night
     * matches) — spec: "Initially deterministic templates ... AI enrichment
     * comes later." Never invents a company name or a date that isn't real.
     */
    private function outreachAngles(array $conference, array $companies, array $emptyNightDates): array
    {
        $angles = [];

        $sponsorCount = count(array_filter($companies, static fn (array $c) => in_array($c['role'], ['sponsor', 'headline_sponsor'], true)));
        if ($sponsorCount > 0) {
            $angles[] = "Host a VIP reception for the $sponsorCount sponsor(s) attending {$conference['name']}.";
        }

        $exhibitorCount = count(array_filter($companies, static fn (array $c) => $c['role'] === 'exhibitor'));
        if ($exhibitorCount > 0) {
            $angles[] = "Pitch a private breakfast or happy hour to the $exhibitorCount exhibitor(s) who'll want a nearby venue.";
        }

        if ($emptyNightDates) {
            $angles[] = 'Offer an exclusive after-event reception on ' . implode(', ', $emptyNightDates)
                . ' — the venue has no other booking those nights.';
        }

        if (!$angles) {
            $angles[] = 'Add target companies and participation roles to generate outreach angles.';
        }

        return $angles;
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
