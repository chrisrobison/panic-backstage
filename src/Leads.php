<?php
declare(strict_types=1);

namespace Panic;

use function Panic\slugify;
use function Panic\boolish;
use function Panic\date_or_null;

/**
 * Lead pipeline — inbox, evaluation, and conversion to event.
 *
 *   GET    /api/leads                      list leads (filterable by status, source, owner)
 *   POST   /api/leads                      create a lead
 *   GET    /api/leads/{id}                 lead detail with notes + evaluations
 *   PATCH  /api/leads/{id}                 update lead fields / status
 *   DELETE /api/leads/{id}                 delete (admin only)
 *   POST   /api/leads/{id}/notes           add a note or task
 *   PATCH  /api/leads/{id}/notes/{nid}     update note (mark done, edit body)
 *   DELETE /api/leads/{id}/notes/{nid}     delete note
 *   GET    /api/leads/{id}/evaluation      get latest deal evaluation
 *   POST   /api/leads/{id}/evaluation      create/update deal evaluation (server-calculated)
 *   POST   /api/leads/{id}/convert         convert lead to event (atomic)
 *
 * Capabilities:
 *   manage_leads   — create, edit, delete, convert
 *   view_leads     — read-only access
 */
final class Leads extends BaseEndpoint
{
    private const STATUSES = ['new','triage','evaluating','needs_review','approved','declined','converted','canceled'];
    private const SOURCES  = ['internal','website','promoter','referral','peerspace','eventective','giggster','phone','email','manual','other'];

    private const DEAL_TYPES = ['rental_buyout','guarantee','door_split','guarantee_plus_pct',
                                 'bar_minimum','hybrid','private_hosted_bar','other'];

    public function handle(Request $request): Response
    {
        $leadId   = $this->params['leadId']   ?? null;
        $child    = $this->params['child']    ?? null;
        $childId  = $this->params['childId']  ?? null;

        // Notes sub-resource
        if ($child === 'notes') {
            return $this->handleNotes($request, (int) $leadId, $childId ? (int) $childId : null);
        }

        // Deal evaluation sub-resource
        if ($child === 'evaluation') {
            return $this->handleEvaluation($request, (int) $leadId);
        }

        // Convert to event
        if ($child === 'convert' && $request->method() === 'POST') {
            return $this->convert($request, (int) $leadId);
        }

        return match ($request->method()) {
            'GET'    => $leadId ? $this->show((int) $leadId) : $this->index($request),
            'POST'   => $this->create($request),
            'PATCH'  => $this->update($request, (int) $leadId),
            'DELETE' => $this->deleteLead((int) $leadId),
            default  => Response::methodNotAllowed(),
        };
    }

    // ── List ──────────────────────────────────────────────────────────────────

    private function index(Request $request): Response
    {
        if ($denied = $this->requireGlobalCapability('view_leads')) {
            return $denied;
        }

        $where  = ['1=1'];
        $params = [];

        $status = $request->query('status');
        if ($status && in_array($status, self::STATUSES, true)) {
            $where[] = 'l.status = ?';
            $params[] = $status;
        }

        $source = $request->query('source');
        if ($source && in_array($source, self::SOURCES, true)) {
            $where[] = 'l.source = ?';
            $params[] = $source;
        }

        if ($request->query('owner_id')) {
            $where[]  = 'l.point_person_id = ?';
            $params[] = (int) $request->query('owner_id');
        }

        if ($request->query('mine') === '1') {
            $where[]  = 'l.point_person_id = ?';
            $params[] = $this->userId();
        }

        $sql = "SELECT l.*,
                  u.name point_person_name,
                  d.name decision_by_name,
                  e.title converted_event_title
                FROM leads l
                LEFT JOIN users u ON u.id = l.point_person_id
                LEFT JOIN users d ON d.id = l.decision_by_id
                LEFT JOIN events e ON e.id = l.converted_event_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY FIELD(l.status,'new','triage','evaluating','needs_review','approved','declined','converted','canceled'),
                         l.desired_date, l.created_at DESC
                LIMIT 200";

        $leads = $this->db->all($sql, $params);

        return $this->ok([
            'leads'        => $leads,
            'statuses'     => self::STATUSES,
            'sources'      => self::SOURCES,
            'users'        => $this->db->all('SELECT id, name FROM users ORDER BY name'),
            'capabilities' => $this->globalCapabilities(),
        ]);
    }

    // ── Show ──────────────────────────────────────────────────────────────────

    private function show(int $id): Response
    {
        if ($denied = $this->requireGlobalCapability('view_leads')) {
            return $denied;
        }

        $lead = $this->db->one(
            "SELECT l.*, u.name point_person_name, d.name decision_by_name,
               e.title converted_event_title, e.status converted_event_status
             FROM leads l
             LEFT JOIN users u ON u.id = l.point_person_id
             LEFT JOIN users d ON d.id = l.decision_by_id
             LEFT JOIN events e ON e.id = l.converted_event_id
             WHERE l.id = ?",
            [$id]
        );
        if (!$lead) {
            return $this->notFound('Lead not found');
        }

        $notes = $this->db->all(
            "SELECT n.*, u.name user_name FROM lead_notes n LEFT JOIN users u ON u.id = n.user_id
             WHERE n.lead_id = ? ORDER BY n.created_at DESC",
            [$id]
        );

        $evaluation = $this->db->one(
            "SELECT e.*, u.name approved_by_name FROM lead_deal_evaluations e
             LEFT JOIN users u ON u.id = e.approved_by_id
             WHERE e.lead_id = ? ORDER BY e.created_at DESC LIMIT 1",
            [$id]
        );

        return $this->ok([
            'lead'       => $lead,
            'notes'      => $notes,
            'evaluation' => $evaluation,
        ]);
    }

    // ── Create ────────────────────────────────────────────────────────────────

    private function create(Request $request): Response
    {
        if ($denied = $this->requireGlobalCapability('manage_leads')) {
            return $denied;
        }

        $b = $request->body();

        $status = (string) ($b['status'] ?? 'new');
        if (!in_array($status, self::STATUSES, true)) {
            $status = 'new';
        }

        $source = (string) ($b['source'] ?? 'manual');
        if (!in_array($source, self::SOURCES, true)) {
            $source = 'manual';
        }

        $id = $this->db->insert(
            'INSERT INTO leads (status, source, contact_name, contact_email, contact_org, contact_phone,
             event_name, event_type, band_name, desired_date, desired_date_alt, rooms_requested,
             projected_attendance, is_private, alcohol_plan, notes, point_person_id,
             risk_level, created_by_id)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [
                $status,
                $source,
                $b['contact_name']    ?? null,
                $b['contact_email']   ?? null,
                $b['contact_org']     ?? null,
                $b['contact_phone']   ?? null,
                $b['event_name']      ?? null,
                $b['event_type']      ?? null,
                $b['band_name']       ?? null,
                date_or_null($b['desired_date'] ?? null),
                date_or_null($b['desired_date_alt'] ?? null),
                $b['rooms_requested'] ?? null,
                isset($b['projected_attendance']) ? (int) $b['projected_attendance'] : null,
                boolish($b['is_private'] ?? false),
                $b['alcohol_plan']    ?? null,
                $b['notes']           ?? null,
                isset($b['point_person_id']) ? (int) $b['point_person_id'] : $this->userId(),
                $b['risk_level']      ?? 'unknown',
                $this->userId(),
            ]
        );

        $this->addAuditNote($id, "Lead created (source: $source)");

        $lead = $this->db->one(
            "SELECT l.*, u.name point_person_name FROM leads l
             LEFT JOIN users u ON u.id = l.point_person_id
             WHERE l.id = ?",
            [$id]
        );

        return $this->ok(['lead' => $lead]);
    }

    // ── Update ────────────────────────────────────────────────────────────────

    private function update(Request $request, int $id): Response
    {
        if ($denied = $this->requireGlobalCapability('manage_leads')) {
            return $denied;
        }

        $lead = $this->db->one('SELECT * FROM leads WHERE id = ?', [$id]);
        if (!$lead) {
            return $this->notFound('Lead not found');
        }

        $b = $request->body();

        // Status transitions — record audit note
        $newStatus = $b['status'] ?? $lead['status'];
        if (!in_array($newStatus, self::STATUSES, true)) {
            $newStatus = $lead['status'];
        }

        $sets   = [];
        $params = [];

        $fields = [
            'status', 'source', 'contact_name', 'contact_email', 'contact_org', 'contact_phone',
            'event_name', 'event_type', 'band_name', 'desired_date', 'desired_date_alt', 'rooms_requested',
            'projected_attendance', 'is_private', 'alcohol_plan', 'notes',
            'point_person_id', 'risk_level', 'decline_reason', 'decision_notes',
        ];

        foreach ($fields as $field) {
            if (!array_key_exists($field, $b)) {
                continue;
            }
            $val = $b[$field];
            if (in_array($field, ['desired_date', 'desired_date_alt'], true)) {
                $val = date_or_null($val);
            } elseif ($field === 'is_private') {
                $val = boolish($val);
            } elseif (in_array($field, ['projected_attendance', 'point_person_id'], true)) {
                $val = $val !== null && $val !== '' ? (int) $val : null;
            }
            $sets[]   = "$field = ?";
            $params[] = $val;
        }

        // If declining or approving, record decision metadata
        if (in_array($newStatus, ['approved','declined','needs_review'], true)
            && $newStatus !== $lead['status']
        ) {
            $sets[]   = 'decision_by_id = ?';
            $params[] = $this->userId();
            $sets[]   = 'decided_at = NOW()';
        }

        if (empty($sets)) {
            return $this->ok(['ok' => true]);
        }

        $params[] = $id;
        $this->db->run('UPDATE leads SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);

        if ($newStatus !== $lead['status']) {
            $this->addAuditNote($id, "Status changed: {$lead['status']} → $newStatus");
            // Optional user-supplied note explaining the status change
            $statusNote = trim((string) ($b['status_note'] ?? ''));
            if ($statusNote !== '') {
                $this->db->run(
                    "INSERT INTO lead_notes (lead_id, user_id, type, body) VALUES (?,?,?,?)",
                    [$id, $this->userId(), 'status_change', $statusNote]
                );
            }
        }

        $updated = $this->db->one(
            "SELECT l.*, u.name point_person_name, d.name decision_by_name
             FROM leads l
             LEFT JOIN users u ON u.id = l.point_person_id
             LEFT JOIN users d ON d.id = l.decision_by_id
             WHERE l.id = ?",
            [$id]
        );
        return $this->ok(['lead' => $updated]);
    }

    // ── Delete ────────────────────────────────────────────────────────────────

    private function deleteLead(int $id): Response
    {
        if ($denied = $this->requireGlobalCapability('manage_leads')) {
            return $denied;
        }
        if (!$this->isVenueAdmin()) {
            return $this->forbidden('Only venue admins can delete leads');
        }
        $this->db->run('DELETE FROM leads WHERE id = ?', [$id]);
        return Response::noContent();
    }

    // ── Notes sub-resource ───────────────────────────────────────────────────

    private function handleNotes(Request $request, int $leadId, ?int $noteId): Response
    {
        if ($denied = $this->requireGlobalCapability('view_leads')) {
            return $denied;
        }
        $lead = $this->db->one('SELECT id FROM leads WHERE id = ?', [$leadId]);
        if (!$lead) {
            return $this->notFound('Lead not found');
        }

        if ($request->method() === 'POST' && $noteId === null) {
            if ($denied = $this->requireGlobalCapability('manage_leads')) {
                return $denied;
            }
            $b    = $request->body();
            $type = in_array($b['type'] ?? '', ['note','task','audit'], true) ? $b['type'] : 'note';
            $nid  = $this->db->insert(
                'INSERT INTO lead_notes (lead_id, user_id, type, body, due_date) VALUES (?,?,?,?,?)',
                [
                    $leadId,
                    $this->userId(),
                    $type,
                    (string) ($b['body'] ?? ''),
                    date_or_null($b['due_date'] ?? null),
                ]
            );
            return $this->ok(['id' => $nid]);
        }

        if ($request->method() === 'PATCH' && $noteId) {
            if ($denied = $this->requireGlobalCapability('manage_leads')) {
                return $denied;
            }
            $b = $request->body();
            $this->db->run(
                'UPDATE lead_notes SET body = COALESCE(?, body), is_done = COALESCE(?, is_done),
                 due_date = COALESCE(?, due_date) WHERE id = ? AND lead_id = ?',
                [
                    $b['body']    ?? null,
                    isset($b['is_done']) ? boolish($b['is_done']) : null,
                    isset($b['due_date']) ? date_or_null($b['due_date']) : null,
                    $noteId, $leadId,
                ]
            );
            return $this->ok(['ok' => true]);
        }

        if ($request->method() === 'DELETE' && $noteId) {
            if ($denied = $this->requireGlobalCapability('manage_leads')) {
                return $denied;
            }
            $this->db->run('DELETE FROM lead_notes WHERE id = ? AND lead_id = ?', [$noteId, $leadId]);
            return Response::noContent();
        }

        return Response::methodNotAllowed();
    }

    // ── Deal evaluation sub-resource ─────────────────────────────────────────

    private function handleEvaluation(Request $request, int $leadId): Response
    {
        if ($denied = $this->requireGlobalCapability('view_leads')) {
            return $denied;
        }
        $lead = $this->db->one('SELECT id FROM leads WHERE id = ?', [$leadId]);
        if (!$lead) {
            return $this->notFound('Lead not found');
        }

        if ($request->method() === 'GET') {
            $eval = $this->db->one(
                'SELECT e.*, u.name approved_by_name FROM lead_deal_evaluations e
                 LEFT JOIN users u ON u.id = e.approved_by_id
                 WHERE e.lead_id = ? ORDER BY e.created_at DESC LIMIT 1',
                [$leadId]
            );
            return $this->ok(['evaluation' => $eval]);
        }

        if ($request->method() === 'POST') {
            if ($denied = $this->requireGlobalCapability('manage_leads')) {
                return $denied;
            }
            return $this->saveEvaluation($request->body(), $leadId);
        }

        return Response::methodNotAllowed();
    }

    /**
     * Server-side deal math.  All totals are calculated here — never trusted
     * from the client.
     */
    private function saveEvaluation(array $b, int $leadId): Response
    {
        $dealType = (string) ($b['deal_type'] ?? 'other');
        if (!in_array($dealType, self::DEAL_TYPES, true)) {
            $dealType = 'other';
        }

        // Inputs
        $capacity    = max(0, (int)   ($b['room_capacity']        ?? 0));
        $attendance  = max(0, (int)   ($b['expected_attendance']  ?? 0));
        $ticketPrice = max(0, (float) ($b['ticket_price']         ?? 0));
        $ticketFee   = max(0, (float) ($b['ticket_fee_per']       ?? 0));
        $rentalFee   = max(0, (float) ($b['rental_fee']           ?? 0));
        $guarantee   = max(0, (float) ($b['artist_guarantee']     ?? 0));
        $barSpend    = max(0, (float) ($b['projected_bar_spend']  ?? 0));
        $barMinimum  = max(0, (float) ($b['bar_minimum']          ?? 0));
        $labor       = max(0, (float) ($b['labor_forecast']       ?? 0));
        $production  = max(0, (float) ($b['production_costs']     ?? 0));
        $facility    = max(0, (float) ($b['facility_costs']       ?? 0));
        $other       = max(0, (float) ($b['other_costs']          ?? 0));

        // ── Server-calculated outputs ──────────────────────────────────────

        // Gross revenue
        $ticketRevenue = $attendance * $ticketPrice;
        $feeRevenue    = $attendance * $ticketFee;
        $barRevenue    = max($barSpend, $barMinimum);
        $grossRevenue  = $ticketRevenue + $feeRevenue + $rentalFee + $barRevenue;

        // Estimated cost
        $estimatedCost = $guarantee + $labor + $production + $facility + $other;

        // Venue net
        $venueNet = $grossRevenue - $estimatedCost;

        // Margin %
        $marginPct = $grossRevenue > 0 ? round(($venueNet / $grossRevenue) * 100, 2) : 0;

        // Break-even attendance (ticket-driven events)
        $breakEven = 0;
        if ($ticketPrice > 0 && $estimatedCost > 0) {
            $breakEven = (int) ceil(($estimatedCost - $rentalFee - $barRevenue) / $ticketPrice);
            $breakEven = max(0, $breakEven);
        }

        // Minimum tickets for guarantee (artist-guarantee events)
        $minTickets = 0;
        if ($guarantee > 0 && $ticketPrice > 0) {
            $minTickets = (int) ceil($guarantee / $ticketPrice);
        }

        // Risk flags
        $flags = [];
        if ($attendance > $capacity && $capacity > 0) {
            $flags[] = 'projected_attendance_exceeds_capacity';
        }
        if ($marginPct < 0) {
            $flags[] = 'negative_margin';
        }
        if ($marginPct < 15 && $marginPct >= 0) {
            $flags[] = 'low_margin_under_15_pct';
        }
        if ($breakEven > 0 && $attendance < $breakEven) {
            $flags[] = 'attendance_below_break_even';
        }
        if ($barSpend > 0 && $barSpend < $barMinimum) {
            $flags[] = 'bar_spend_below_minimum';
        }
        if ($guarantee > 0 && $venueNet < 0) {
            $flags[] = 'venue_net_negative_with_guarantee';
        }

        $approvalStatus = 'pending';

        $id = $this->db->insert(
            'INSERT INTO lead_deal_evaluations
             (lead_id, deal_type, room_capacity, expected_attendance, ticket_price, ticket_fee_per,
              rental_fee, artist_guarantee, projected_bar_spend, bar_minimum, labor_forecast,
              production_costs, facility_costs, other_costs,
              calc_gross_revenue, calc_estimated_cost, calc_venue_net, calc_margin_pct,
              calc_break_even_attendance, calc_min_tickets_guarantee, risk_flags_json,
              approval_status, notes, created_by_id)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [
                $leadId, $dealType,
                $capacity ?: null, $attendance ?: null,
                $ticketPrice ?: null, $ticketFee ?: null,
                $rentalFee ?: null, $guarantee ?: null,
                $barSpend ?: null, $barMinimum ?: null,
                $labor ?: null, $production ?: null,
                $facility ?: null, $other ?: null,
                $grossRevenue, $estimatedCost, $venueNet,
                $marginPct, $breakEven ?: null, $minTickets ?: null,
                json_encode($flags),
                $approvalStatus,
                $b['notes'] ?? null,
                $this->userId(),
            ]
        );

        return $this->ok([
            'id'               => $id,
            'gross_revenue'    => $grossRevenue,
            'estimated_cost'   => $estimatedCost,
            'venue_net'        => $venueNet,
            'margin_pct'       => $marginPct,
            'break_even'       => $breakEven,
            'min_tickets'      => $minTickets,
            'risk_flags'       => $flags,
            'approval_status'  => $approvalStatus,
        ]);
    }

    // ── Convert to event ──────────────────────────────────────────────────────

    /**
     * Atomically convert a lead into a booked event.
     * Preserves source data and creates an audit trail on both sides.
     */
    private function convert(Request $request, int $leadId): Response
    {
        if ($denied = $this->requireGlobalCapability('manage_leads')) {
            return $denied;
        }

        $lead = $this->db->one('SELECT * FROM leads WHERE id = ?', [$leadId]);
        if (!$lead) {
            return $this->notFound('Lead not found');
        }

        if ($lead['status'] === 'converted') {
            return Response::json(['error' => 'Lead has already been converted'], 409);
        }

        if (!in_array($lead['status'], ['approved', 'evaluating', 'needs_review'], true)) {
            return Response::json([
                'error' => 'Lead must be in approved, evaluating, or needs_review status to convert',
            ], 422);
        }

        $b = $request->body();

        // Determine the venue ID for the new event
        $venues  = $this->db->all('SELECT id FROM venues ORDER BY id LIMIT 1');
        $venueId = isset($b['venue_id']) ? (int) $b['venue_id'] : (int) ($venues[0]['id'] ?? 1);

        // Map lead → event fields
        $title    = (string) ($b['title'] ?? $lead['event_name'] ?? 'Untitled Event');
        $slug     = slugify($title) . '-' . date('Ymd') . '-' . $leadId;
        $date     = (string) ($b['date'] ?? $lead['desired_date'] ?? date('Y-m-d', strtotime('+30 days')));
        $type     = (string) ($b['event_type'] ?? $lead['event_type'] ?? 'private_event');
        $isPrivate = boolish($lead['is_private']);

        $validTypes = ['live_music','karaoke','open_mic','promoter_night','dj_night',
                       'comedy','private_event','special_event'];
        if (!in_array($type, $validTypes, true)) {
            $type = 'special_event';
        }

        // Run inside a transaction — all-or-nothing
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();

        try {
            // Create the event
            $eventId = $this->db->insert(
                'INSERT INTO events
                 (venue_id, title, slug, event_type, status, date, lead_id, is_private,
                  promoter_name, promoter_email, promoter_phone,
                  client_org, booker_name, booker_email, booker_phone,
                  estimated_guests, description_internal, owner_user_id, created_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())',
                [
                    $venueId,
                    $title,
                    $slug,
                    $type,
                    'proposed',
                    $date,
                    $leadId,
                    $isPrivate,
                    $lead['contact_name'],
                    $lead['contact_email'],
                    $lead['contact_phone'],
                    $lead['contact_org'],
                    $lead['contact_name'],
                    $lead['contact_email'],
                    $lead['contact_phone'],
                    $lead['projected_attendance'],
                    $lead['notes'],
                    $this->userId(),
                ]
            );

            // Mark lead as converted
            $this->db->run(
                'UPDATE leads SET status=?, converted_event_id=?, converted_at=NOW() WHERE id=?',
                ['converted', $eventId, $leadId]
            );

            // Audit note on the lead
            $this->db->run(
                "INSERT INTO lead_notes (lead_id, user_id, type, body) VALUES (?,?,?,?)",
                [$leadId, $this->userId(), 'audit', "Converted to event #$eventId: \"$title\""]
            );

            // Activity log on the event
            $this->db->run(
                'INSERT INTO event_activity_log (event_id, user_id, action, details_json) VALUES (?,?,?,?)',
                [$eventId, $this->userId(), 'event created from lead',
                 json_encode(['lead_id' => $leadId, 'source' => $lead['source']])]
            );

            $pdo->commit();

        } catch (\Throwable $e) {
            $pdo->rollBack();
            error_log("Lead convert failed: " . $e->getMessage());
            return Response::json(['error' => 'Conversion failed: ' . $e->getMessage()], 500);
        }

        return $this->ok([
            'event_id'  => $eventId,
            'event_url' => "#events/$eventId",
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function addAuditNote(int $leadId, string $body): void
    {
        $this->db->run(
            "INSERT INTO lead_notes (lead_id, user_id, type, body) VALUES (?,?,?,?)",
            [$leadId, $this->userId(), 'audit', $body]
        );
    }
}
