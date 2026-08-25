<?php
declare(strict_types=1);

namespace Panic;

use Panic\Opportunities\Availability;

use function Panic\boolish;
use function Panic\date_or_null;
use function Panic\log_opportunity_activity;

/**
 * Opportunities — the sales-pipeline record at the center of the
 * Opportunities module (prospecting CRM prepended to the existing
 * inquiry->event spine; see docs/OPPORTUNITIES-IMPLEMENTATION.md).
 *
 *   GET    /api/opportunities/dashboard      Discover-page aggregates: KPI
 *                                             cards, best opportunities,
 *                                             upcoming conferences, venue
 *                                             availability matches (see
 *                                             Opportunities/Availability.php),
 *                                             recent notes, and deterministic
 *                                             data-derived suggestions.
 *                                             `?window_days=N` (default 30).
 *   GET    /api/opportunities                list (filterable by stage,
 *                                             company_id, conference_id,
 *                                             owner_id, mine)
 *   POST   /api/opportunities                create
 *   GET    /api/opportunities/{id}           detail (+ company/conference join)
 *   PATCH  /api/opportunities/{id}           update fields / move stage
 *   DELETE /api/opportunities/{id}           delete (venue_admin only)
 *   GET    /api/opportunities/{id}/activities read-only audit feed
 *
 * Notes and signals are polymorphic (a note/signal can attach to a
 * conference, company, contact, and/or opportunity) and are served by the
 * shared src/Opportunities/Notes.php and src/Opportunities/Signals.php
 * classes instead of living here — see src/Kernel.php's `opportunities`
 * route block for how `/{id}/notes` and `/{id}/signals` are dispatched to
 * those classes directly.
 *
 * Capabilities: view_opportunities (read), manage_opportunities (write).
 */
final class Opportunities extends BaseEndpoint
{
    public const STAGES = [
        'new_signal', 'researching', 'contacted', 'qualified',
        'proposal_sent', 'verbal_yes', 'won', 'lost', 'nurture',
    ];

    private const WRITABLE_FIELDS = [
        'name', 'company_id', 'conference_id', 'probability', 'estimated_value',
        'target_date', 'target_date_end', 'guest_count_min', 'guest_count_max',
        'event_type', 'event_concept', 'owner_user_id', 'next_action',
        'next_action_at', 'lost_reason',
    ];

    public function handle(Request $request): Response
    {
        if (($this->params['action'] ?? null) === 'dashboard') {
            return $this->dashboard($request);
        }

        $id    = $this->params['opportunityId'] ?? null;
        $child = $this->params['child'] ?? null;

        if ($child === 'activities' && $id) {
            return $this->activities((int) $id, $request);
        }

        return match ($request->method()) {
            'GET'    => $id ? $this->show((int) $id) : $this->index($request),
            'POST'   => $this->create($request),
            'PATCH'  => $this->update($request, (int) $id),
            'DELETE' => $this->deleteOpportunity((int) $id),
            default  => Response::methodNotAllowed(),
        };
    }

    // ── Dashboard (Discover page) ───────────────────────────────────────────
    //
    // One aggregate-heavy endpoint rather than 6 small ones — the Discover
    // page mockup needs 5 KPI cards + 4 panels' worth of data, and the Phase
    // 2 spec explicitly asks for "dashboard-ready aggregates rather than
    // forcing the browser to fetch dozens of records". Every number here is
    // a real query result; nothing is fabricated for display purposes (the
    // "AI Suggestions" panel is a deterministic, data-derived rule set —
    // real AI research arrives in Phase 7).

    private function dashboard(Request $request): Response
    {
        if ($denied = $this->requireGlobalCapability('view_opportunities')) {
            return $denied;
        }

        $windowDays = (int) ($request->query('window_days') ?: 30);
        $windowDays = max(7, min(365, $windowDays));

        $openCount = (int) ($this->db->one(
            "SELECT COUNT(*) c FROM opportunities WHERE stage NOT IN ('won','lost')"
        )['c'] ?? 0);
        $openCreatedRecently = (int) ($this->db->one(
            "SELECT COUNT(*) c FROM opportunities WHERE stage NOT IN ('won','lost') AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
        )['c'] ?? 0);

        $revenue = (float) ($this->db->one(
            "SELECT COALESCE(SUM(estimated_value), 0) v FROM opportunities WHERE stage NOT IN ('won','lost')"
        )['v'] ?? 0);
        $revenueCreatedRecently = (float) ($this->db->one(
            "SELECT COALESCE(SUM(estimated_value), 0) v FROM opportunities WHERE stage NOT IN ('won','lost') AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
        )['v'] ?? 0);

        $conferenceRange = $this->db->one(
            'SELECT COUNT(*) c, MIN(starts_at) start_date, MAX(COALESCE(ends_at, starts_at)) end_date
             FROM opportunity_conferences
             WHERE starts_at IS NOT NULL
               AND starts_at <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
               AND COALESCE(ends_at, starts_at) >= CURDATE()',
            [$windowDays]
        ) ?? ['c' => 0, 'start_date' => null, 'end_date' => null];

        $followups = $this->db->one(
            "SELECT
                SUM(CASE WHEN next_action_at IS NOT NULL AND next_action_at <= DATE_ADD(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) due,
                SUM(CASE WHEN next_action_at IS NOT NULL AND next_action_at < NOW() THEN 1 ELSE 0 END) overdue
             FROM opportunities WHERE stage NOT IN ('won','lost')"
        ) ?? ['due' => 0, 'overdue' => 0];

        $matches = Availability::emptyNightMatches($this->db, $windowDays);
        $matchedConferenceIds = array_values(array_unique(array_map(
            static fn (array $m) => (int) $m['conference']['id'],
            $matches
        )));
        $emptyNightPotential = 0.0;
        if ($matchedConferenceIds) {
            $placeholders = implode(',', array_fill(0, count($matchedConferenceIds), '?'));
            $emptyNightPotential = (float) ($this->db->one(
                "SELECT COALESCE(SUM(estimated_value), 0) v FROM opportunities
                 WHERE stage NOT IN ('won','lost') AND conference_id IN ($placeholders)",
                $matchedConferenceIds
            )['v'] ?? 0);
        }

        $bestOpportunities = $this->db->all(
            "SELECT o.id, o.name, o.estimated_value, o.probability, o.next_action, o.next_action_at,
                    c.name AS company_name, conf.name AS conference_name
             FROM opportunities o
             JOIN opportunity_companies c ON c.id = o.company_id
             LEFT JOIN opportunity_conferences conf ON conf.id = o.conference_id
             WHERE o.stage NOT IN ('won', 'lost')
             ORDER BY o.probability IS NULL, o.probability DESC, o.estimated_value IS NULL, o.estimated_value DESC
             LIMIT 10"
        );

        $upcomingConferences = $this->db->all(
            'SELECT id, name, slug, city, state, starts_at, ends_at,
                    estimated_attendance, estimated_exhibitors, estimated_sponsors, opportunity_score
             FROM opportunity_conferences
             WHERE starts_at IS NOT NULL
               AND starts_at <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
               AND COALESCE(ends_at, starts_at) >= CURDATE()
             ORDER BY starts_at ASC
             LIMIT 20',
            [$windowDays]
        );

        return $this->ok([
            'window_days' => $windowDays,
            'kpis' => [
                'open_opportunities' => ['value' => $openCount, 'new_last_30_days' => $openCreatedRecently],
                'projected_revenue'  => ['value' => $revenue, 'new_last_30_days' => $revenueCreatedRecently],
                'upcoming_conferences' => [
                    'value' => (int) $conferenceRange['c'],
                    'range_start' => $conferenceRange['start_date'],
                    'range_end'   => $conferenceRange['end_date'],
                ],
                'empty_nights' => ['value' => count($matches), 'potential_value' => $emptyNightPotential],
                'followups_due' => ['value' => (int) $followups['due'], 'overdue' => (int) $followups['overdue']],
            ],
            'best_opportunities'   => $bestOpportunities,
            'upcoming_conferences' => $upcomingConferences,
            'availability_matches' => array_map(static fn (array $m) => [
                'date'       => $m['date'],
                'conference' => $m['conference'],
            ], $matches),
            'recent_notes' => $this->recentNotes(8),
            'suggestions'  => $this->dashboardSuggestions($followups, $matches),
            // Kept for continuity with any Phase 1 caller — same pipeline
            // breakdown, now alongside the richer Phase 2 payload above.
            'stage_counts' => $this->db->all(
                'SELECT stage, COUNT(*) AS count, COALESCE(SUM(estimated_value), 0) AS total_value
                 FROM opportunities GROUP BY stage'
            ),
            'stages'       => self::STAGES,
            'capabilities' => $this->globalCapabilities(),
        ]);
    }

    /**
     * Latest notes across every linked record type, each with a short
     * "context" label (e.g. "NVIDIA — GTC DC") resolved from its links.
     * Two follow-up queries total (links, then one name lookup per linked
     * type actually present) — never one query per note.
     */
    private function recentNotes(int $limit): array
    {
        $notes = $this->db->all(
            'SELECT n.id, n.body, n.note_type, n.is_pinned, n.is_ai_generated, n.created_at,
                    u.name AS created_by_name
             FROM opportunity_notes n
             LEFT JOIN users u ON u.id = n.created_by
             ORDER BY n.created_at DESC
             LIMIT ?',
            [$limit]
        );
        if (!$notes) {
            return [];
        }

        $noteIds = array_column($notes, 'id');
        $placeholders = implode(',', array_fill(0, count($noteIds), '?'));
        $links = $this->db->all(
            "SELECT * FROM opportunity_note_links WHERE note_id IN ($placeholders)",
            $noteIds
        );

        $linksByNote = [];
        $idsByType   = [];
        foreach ($links as $link) {
            $linksByNote[$link['note_id']][] = $link;
            $idsByType[$link['linked_type']][] = (int) $link['linked_id'];
        }

        $names = ['company' => [], 'conference' => [], 'opportunity' => []];
        if (!empty($idsByType['company'])) {
            $names['company'] = $this->nameMap('opportunity_companies', array_unique($idsByType['company']));
        }
        if (!empty($idsByType['conference'])) {
            $names['conference'] = $this->nameMap('opportunity_conferences', array_unique($idsByType['conference']));
        }
        if (!empty($idsByType['opportunity'])) {
            $names['opportunity'] = $this->nameMap('opportunities', array_unique($idsByType['opportunity']));
        }

        foreach ($notes as &$note) {
            $noteLinks = $linksByNote[$note['id']] ?? [];
            $companyName = null;
            $secondaryName = null;
            foreach ($noteLinks as $link) {
                $label = $names[$link['linked_type']][$link['linked_id']] ?? null;
                if (!$label) {
                    continue;
                }
                if ($link['linked_type'] === 'company' && !$companyName) {
                    $companyName = $label;
                } elseif (in_array($link['linked_type'], ['conference', 'opportunity'], true) && !$secondaryName) {
                    $secondaryName = $label;
                }
            }
            $note['context'] = $companyName && $secondaryName
                ? "$companyName — $secondaryName"
                : ($companyName ?? $secondaryName);
        }

        return $notes;
    }

    private function nameMap(string $table, array $ids): array
    {
        if (!$ids) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = $this->db->all("SELECT id, name FROM `$table` WHERE id IN ($placeholders)", $ids);
        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['id']] = $row['name'];
        }
        return $map;
    }

    /**
     * Deterministic, data-derived suggestions (Phase 2 spec: "these may be
     * deterministic/non-AI suggestions generated from data" — real AI
     * research is Phase 7). Every entry is backed by a real count computed
     * above or here; nothing is invented copy.
     */
    private function dashboardSuggestions(array $followups, array $matches): array
    {
        $suggestions = [];

        if ((int) $followups['overdue'] > 0) {
            $suggestions[] = [
                'tone' => 'high',
                'text' => (int) $followups['overdue'] . ' follow-up(s) are overdue.',
            ];
        }

        $noNextAction = (int) ($this->db->one(
            "SELECT COUNT(*) c FROM opportunities WHERE stage NOT IN ('won','lost') AND (next_action IS NULL OR next_action = '')"
        )['c'] ?? 0);
        if ($noNextAction > 0) {
            $suggestions[] = [
                'tone' => 'medium',
                'text' => "$noNextAction open opportunity(ies) have no next action set.",
            ];
        }

        $matchedConferenceCount = count(array_unique(array_map(
            static fn (array $m) => (int) $m['conference']['id'],
            $matches
        )));
        if ($matchedConferenceCount > 0) {
            $suggestions[] = [
                'tone' => 'high',
                'text' => "$matchedConferenceCount upcoming conference(s) have at least one open night at the venue.",
            ];
        }

        $unresearchedConferences = (int) ($this->db->one(
            "SELECT COUNT(*) c FROM opportunity_conferences conf
             WHERE conf.starts_at >= CURDATE()
               AND NOT EXISTS (SELECT 1 FROM opportunity_conference_companies cc WHERE cc.conference_id = conf.id)"
        )['c'] ?? 0);
        if ($unresearchedConferences > 0) {
            $suggestions[] = [
                'tone' => 'medium',
                'text' => "$unresearchedConferences upcoming conference(s) have no target companies linked yet.",
            ];
        }

        return array_slice($suggestions, 0, 5);
    }

    // ── List ─────────────────────────────────────────────────────────────────

    private function index(Request $request): Response
    {
        if ($denied = $this->requireGlobalCapability('view_opportunities')) {
            return $denied;
        }

        $where  = ['1=1'];
        $params = [];

        $stage = $request->query('stage');
        if ($stage && in_array($stage, self::STAGES, true)) {
            $where[]  = 'o.stage = ?';
            $params[] = $stage;
        }

        if ($request->query('company_id')) {
            $where[]  = 'o.company_id = ?';
            $params[] = (int) $request->query('company_id');
        }

        if ($request->query('conference_id')) {
            $where[]  = 'o.conference_id = ?';
            $params[] = (int) $request->query('conference_id');
        }

        if ($request->query('owner_id')) {
            $where[]  = 'o.owner_user_id = ?';
            $params[] = (int) $request->query('owner_id');
        }

        if ($request->query('mine') === '1') {
            $where[]  = 'o.owner_user_id = ?';
            $params[] = $this->userId();
        }

        $sql = 'SELECT o.*, c.name AS company_name, conf.name AS conference_name,
                       u.name AS owner_name
                FROM opportunities o
                JOIN opportunity_companies c ON c.id = o.company_id
                LEFT JOIN opportunity_conferences conf ON conf.id = o.conference_id
                LEFT JOIN users u ON u.id = o.owner_user_id
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY FIELD(o.stage, ' . implode(',', array_fill(0, count(self::STAGES), '?')) . '),
                         o.target_date IS NULL, o.target_date, o.created_at DESC
                LIMIT 200';

        $opportunities = $this->db->all($sql, array_merge($params, self::STAGES));

        return $this->ok([
            'opportunities' => $opportunities,
            'stages'        => self::STAGES,
            'capabilities'  => $this->globalCapabilities(),
        ]);
    }

    // ── Show ─────────────────────────────────────────────────────────────────

    private function show(int $id): Response
    {
        if ($denied = $this->requireGlobalCapability('view_opportunities')) {
            return $denied;
        }

        $opportunity = $this->find($id);
        if (!$opportunity) {
            return $this->notFound('Opportunity not found');
        }

        return $this->ok([
            'opportunity'  => $opportunity,
            'capabilities' => $this->globalCapabilities(),
        ]);
    }

    private function find(int $id): ?array
    {
        return $this->db->one(
            'SELECT o.*, c.name AS company_name, c.domain AS company_domain,
                    conf.name AS conference_name, conf.slug AS conference_slug,
                    u.name AS owner_name, e.title AS won_event_title
             FROM opportunities o
             JOIN opportunity_companies c ON c.id = o.company_id
             LEFT JOIN opportunity_conferences conf ON conf.id = o.conference_id
             LEFT JOIN users u ON u.id = o.owner_user_id
             LEFT JOIN events e ON e.id = o.won_event_id
             WHERE o.id = ?',
            [$id]
        );
    }

    // ── Create ───────────────────────────────────────────────────────────────

    private function create(Request $request): Response
    {
        if ($denied = $this->requireGlobalCapability('manage_opportunities')) {
            return $denied;
        }

        $b = $request->body();

        $name = trim((string) ($b['name'] ?? ''));
        if ($name === '') {
            return Response::json(['error' => 'name is required'], 422);
        }

        $companyId = isset($b['company_id']) ? (int) $b['company_id'] : 0;
        if ($companyId <= 0 || !$this->db->one('SELECT id FROM opportunity_companies WHERE id = ?', [$companyId])) {
            return Response::json(['error' => 'A valid company_id is required'], 422);
        }

        if ($error = $this->validateOptionalConference($b['conference_id'] ?? null)) {
            return $error;
        }
        if ($error = $this->validateOptionalUser($b['owner_user_id'] ?? null, 'owner_user_id')) {
            return $error;
        }

        $stage = (string) ($b['stage'] ?? 'new_signal');
        if (!in_array($stage, self::STAGES, true)) {
            $stage = 'new_signal';
        }

        $id = $this->db->insert(
            'INSERT INTO opportunities (
                name, company_id, conference_id, stage, probability, estimated_value,
                target_date, target_date_end, guest_count_min, guest_count_max,
                event_type, event_concept, owner_user_id, next_action, next_action_at,
                created_by
             ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [
                $name,
                $companyId,
                isset($b['conference_id']) && $b['conference_id'] !== '' ? (int) $b['conference_id'] : null,
                $stage,
                $this->clampProbability($b['probability'] ?? null),
                $this->toDecimalOrNull($b['estimated_value'] ?? null),
                date_or_null($b['target_date'] ?? null),
                date_or_null($b['target_date_end'] ?? null),
                isset($b['guest_count_min']) && $b['guest_count_min'] !== '' ? (int) $b['guest_count_min'] : null,
                isset($b['guest_count_max']) && $b['guest_count_max'] !== '' ? (int) $b['guest_count_max'] : null,
                $b['event_type']    ?? null,
                $b['event_concept'] ?? null,
                isset($b['owner_user_id']) && $b['owner_user_id'] !== '' ? (int) $b['owner_user_id'] : $this->userId(),
                $b['next_action']   ?? null,
                isset($b['next_action_at']) && $b['next_action_at'] !== '' ? (string) $b['next_action_at'] : null,
                $this->userId(),
            ]
        );

        log_opportunity_activity($this->db, $id, $this->userId(), 'created', ['stage' => $stage]);

        return $this->ok(['opportunity' => $this->find($id)]);
    }

    // ── Update ───────────────────────────────────────────────────────────────

    private function update(Request $request, int $id): Response
    {
        if ($denied = $this->requireGlobalCapability('manage_opportunities')) {
            return $denied;
        }

        $existing = $this->db->one('SELECT * FROM opportunities WHERE id = ?', [$id]);
        if (!$existing) {
            return $this->notFound('Opportunity not found');
        }

        $b = $request->body();

        if (array_key_exists('company_id', $b)) {
            $companyId = (int) $b['company_id'];
            if ($companyId <= 0 || !$this->db->one('SELECT id FROM opportunity_companies WHERE id = ?', [$companyId])) {
                return Response::json(['error' => 'A valid company_id is required'], 422);
            }
        }
        if (array_key_exists('conference_id', $b) && ($error = $this->validateOptionalConference($b['conference_id']))) {
            return $error;
        }
        if (array_key_exists('owner_user_id', $b) && ($error = $this->validateOptionalUser($b['owner_user_id'], 'owner_user_id'))) {
            return $error;
        }

        $newStage = $existing['stage'];
        if (array_key_exists('stage', $b)) {
            if (!in_array($b['stage'], self::STAGES, true)) {
                return Response::json(['error' => 'Invalid stage'], 422);
            }
            $newStage = $b['stage'];
        }

        $sets   = [];
        $params = [];

        foreach (self::WRITABLE_FIELDS as $field) {
            if (!array_key_exists($field, $b)) {
                continue;
            }
            $val = $b[$field];
            if (in_array($field, ['target_date', 'target_date_end'], true)) {
                $val = date_or_null($val);
            } elseif (in_array($field, ['company_id', 'conference_id', 'owner_user_id', 'guest_count_min', 'guest_count_max'], true)) {
                $val = $val !== null && $val !== '' ? (int) $val : null;
            } elseif ($field === 'probability') {
                $val = $this->clampProbability($val);
            } elseif ($field === 'estimated_value') {
                $val = $this->toDecimalOrNull($val);
            } elseif ($field === 'next_action_at') {
                $val = $val !== null && $val !== '' ? (string) $val : null;
            }
            $sets[]   = "`$field` = ?";
            $params[] = $val;
        }

        if ($newStage !== $existing['stage']) {
            $sets[]   = '`stage` = ?';
            $params[] = $newStage;
        }

        if (empty($sets)) {
            return $this->ok(['opportunity' => $this->find($id)]);
        }

        $params[] = $id;
        $this->db->run('UPDATE opportunities SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);

        if ($newStage !== $existing['stage']) {
            log_opportunity_activity($this->db, $id, $this->userId(), 'stage_changed', [
                'from' => $existing['stage'],
                'to'   => $newStage,
            ]);
        } else {
            log_opportunity_activity($this->db, $id, $this->userId(), 'updated', ['fields' => array_keys($b)]);
        }

        return $this->ok(['opportunity' => $this->find($id)]);
    }

    // ── Delete ───────────────────────────────────────────────────────────────

    private function deleteOpportunity(int $id): Response
    {
        if ($denied = $this->requireGlobalCapability('manage_opportunities')) {
            return $denied;
        }
        if (!$this->isVenueAdmin()) {
            return $this->forbidden('Only venue admins can delete opportunities');
        }

        $existing = $this->db->one('SELECT id FROM opportunities WHERE id = ?', [$id]);
        if (!$existing) {
            return $this->notFound('Opportunity not found');
        }

        $this->db->run('DELETE FROM opportunities WHERE id = ?', [$id]);

        return Response::noContent();
    }

    // ── Activities (read-only) ──────────────────────────────────────────────

    private function activities(int $id, Request $request): Response
    {
        if ($denied = $this->requireGlobalCapability('view_opportunities')) {
            return $denied;
        }
        if ($request->method() !== 'GET') {
            return Response::methodNotAllowed();
        }
        if (!$this->db->one('SELECT id FROM opportunities WHERE id = ?', [$id])) {
            return $this->notFound('Opportunity not found');
        }

        $activities = $this->db->all(
            'SELECT a.*, u.name AS created_by_name
             FROM opportunity_activities a
             LEFT JOIN users u ON u.id = a.created_by
             WHERE a.opportunity_id = ?
             ORDER BY a.created_at DESC
             LIMIT 200',
            [$id]
        );

        return $this->ok(['activities' => $activities]);
    }

    // ── Validation helpers ──────────────────────────────────────────────────

    private function validateOptionalConference(mixed $value): ?Response
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!$this->db->one('SELECT id FROM opportunity_conferences WHERE id = ?', [(int) $value])) {
            return Response::json(['error' => 'conference_id does not reference an existing conference'], 422);
        }
        return null;
    }

    private function validateOptionalUser(mixed $value, string $field): ?Response
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!$this->db->one('SELECT id FROM users WHERE id = ?', [(int) $value])) {
            return Response::json(['error' => "$field does not reference an existing user"], 422);
        }
        return null;
    }

    private function clampProbability(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        return max(0, min(100, (int) $value));
    }

    private function toDecimalOrNull(mixed $value): ?float
    {
        return $value !== null && $value !== '' ? (float) $value : null;
    }
}
