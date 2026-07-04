<?php
declare(strict_types=1);

namespace Panic;

use function Panic\log_activity;

/**
 * Email campaigns — the in-app marketing email tool. A campaign is either
 * built from scratch ("blank") or generated from a set of picked events
 * ("events", using EventEmailComposer's shared lineup-rendering logic), then
 * edited in draft, then sent to a mix of named mailing lists and/or ad-hoc
 * contacts. Per-recipient delivery is tracked in email_campaign_recipients
 * (see database/migrations/046_campaigns_and_lists.sql).
 *
 *   GET    /api/campaigns                              list (?q= &status= &page= &limit=)
 *   GET    /api/campaigns/eligible-events               events available for the "generate from events" picker (?q=)
 *   POST   /api/campaigns/generate-from-events           create a campaign from picked events {event_ids[], name?}
 *   GET    /api/campaigns/{id}                           show one (incl. linked events + live recipient_count)
 *   POST   /api/campaigns                                create a blank draft campaign {name}
 *   PATCH  /api/campaigns/{id}                           update draft fields {name?,subject?,html_body?,text_body?}
 *   DELETE /api/campaigns/{id}                           delete a draft
 *   GET    /api/campaigns/{id}/recipients/preview         preview recipient resolution (?list_ids=&contact_ids=)
 *   POST   /api/campaigns/{id}/send                       send to resolved recipients {list_ids[],contact_ids[]}
 *   POST   /api/campaigns/{id}/send-test                  send a one-off test copy {email}
 *
 * Gated by the manage_campaigns global capability (venue_admin).
 */
final class Campaigns extends BaseEndpoint
{
    public function handle(Request $request): Response
    {
        if ($denied = $this->requireGlobalCapability('manage_campaigns')) {
            return $denied;
        }

        $method = $request->method();
        $action = $this->params['action'] ?? null;

        if ($action === 'eligible-events') {
            return $method === 'GET' ? $this->eligibleEvents($request) : Response::methodNotAllowed();
        }
        if ($action === 'generate-from-events') {
            return $method === 'POST' ? $this->generateFromEvents($request) : Response::methodNotAllowed();
        }

        $id = $this->params['campaignId'] ?? null;

        if ($id) {
            $subAction = $this->params['subAction'] ?? null;

            if ($action === 'recipients' && $subAction === 'preview') {
                return $method === 'GET' ? $this->recipientsPreview((int) $id, $request) : Response::methodNotAllowed();
            }
            if ($action === 'send') {
                return $method === 'POST' ? $this->send((int) $id, $request) : Response::methodNotAllowed();
            }
            if ($action === 'send-test') {
                return $method === 'POST' ? $this->sendTest((int) $id, $request) : Response::methodNotAllowed();
            }

            return match ($method) {
                'GET'    => $this->show((int) $id),
                'PATCH'  => $this->update($request, (int) $id),
                'DELETE' => $this->delete((int) $id),
                default  => Response::methodNotAllowed(),
            };
        }

        return match ($method) {
            'GET'  => $this->index($request),
            'POST' => $this->create($request),
            default => Response::methodNotAllowed(),
        };
    }

    // ── List ──────────────────────────────────────────────────────────────────

    private function index(Request $request): Response
    {
        $where = [];
        $params = [];

        $q = trim((string) $request->query('q'));
        if ($q !== '') {
            $where[] = '(name LIKE ? OR subject LIKE ?)';
            $like = '%' . $q . '%';
            array_push($params, $like, $like);
        }
        $status = trim((string) $request->query('status'));
        if ($status !== '') {
            $where[] = 'status = ?';
            $params[] = $status;
        }
        $whereSql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';

        $limit = (int) ($request->query('limit') ?: 50);
        $limit = max(1, min(200, $limit));
        $page = max(1, (int) ($request->query('page') ?: 1));
        $offset = ($page - 1) * $limit;

        $total = (int) ($this->db->one("SELECT COUNT(*) n FROM email_campaigns{$whereSql}", $params)['n'] ?? 0);
        $campaigns = $this->db->all(
            "SELECT id, name, subject, source, status, sent_count, failed_count, sent_at,
                    created_by_user_id, created_at, updated_at
             FROM email_campaigns{$whereSql}
             ORDER BY created_at DESC LIMIT {$limit} OFFSET {$offset}",
            $params
        );

        // "recipient_count" here is the attempted total (sent + failed) rather than
        // a live COUNT(*) against email_campaign_recipients — draft campaigns have
        // no rows yet anyway, and avoiding a per-row join keeps the list cheap.
        foreach ($campaigns as &$c) {
            $c['recipient_count'] = (int) $c['sent_count'] + (int) $c['failed_count'];
        }
        unset($c);

        return $this->ok([
            'campaigns' => $campaigns,
            'total'     => $total,
            'page'      => $page,
            'limit'     => $limit,
            'pages'     => (int) ceil($total / $limit),
        ]);
    }

    // ── Show ──────────────────────────────────────────────────────────────────

    private function show(int $id): Response
    {
        $row = $this->db->one('SELECT * FROM email_campaigns WHERE id = ?', [$id]);
        if (!$row) {
            return $this->notFound('Campaign not found');
        }

        $row['events'] = $this->db->all(
            'SELECT e.id, e.title, e.date
             FROM email_campaign_events ce
             JOIN events e ON e.id = ce.event_id
             WHERE ce.campaign_id = ?
             ORDER BY e.date ASC',
            [$id]
        );
        $row['recipient_count'] = (int) (
            $this->db->one('SELECT COUNT(*) n FROM email_campaign_recipients WHERE campaign_id = ?', [$id])['n'] ?? 0
        );

        return $this->ok(['campaign' => $row]);
    }

    // ── Create blank ──────────────────────────────────────────────────────────

    private function create(Request $request): Response
    {
        $name = trim((string) $request->body('name', ''));
        if ($name === '') {
            return Response::json(['error' => 'name is required'], 422);
        }

        $vars = $this->venueVars([]);
        $vars['html']['body_html'] = '<p>Start writing your email here…</p>';
        $vars['text']['body_text'] = 'Start writing your email here…';

        $rendered = $this->renderTemplate('campaign-blank', $vars['html'], $vars['text']);

        $id = $this->db->insert(
            'INSERT INTO email_campaigns (name, subject, source, status, html_body, text_body, created_by_user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$name, $name, 'blank', 'draft', $rendered['html'], $rendered['text'], $this->userId()]
        );

        $row = $this->db->one('SELECT * FROM email_campaigns WHERE id = ?', [$id]);
        return $this->ok(['campaign' => $row]);
    }

    // ── Generate from events ─────────────────────────────────────────────────

    private function generateFromEvents(Request $request): Response
    {
        $body = $request->body();
        $rawIds = $body['event_ids'] ?? null;
        if (!is_array($rawIds) || $rawIds === []) {
            return Response::json(['error' => 'event_ids is required'], 422);
        }
        $eventIds = [];
        foreach ($rawIds as $v) {
            if (!is_numeric($v)) {
                return Response::json(['error' => 'event_ids must be an array of integers'], 422);
            }
            $eventIds[] = (int) $v;
        }
        $eventIds = array_values(array_unique($eventIds));

        $events = EventEmailComposer::eligibleEventsByIds($this->db, $eventIds);
        if ($events === []) {
            return Response::json([
                'error' => 'None of the selected events are eligible to be emailed (must be public and published/advanced)',
            ], 422);
        }
        $droppedCount = count($eventIds) - count($events);

        $fragment  = EventEmailComposer::buildEventsFragment($this->db, $events);
        $dateRange = EventEmailComposer::dateRangeLabelForEvents($events);
        $heading   = 'Upcoming Shows — ' . $dateRange;

        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            $name = 'Events: ' . $dateRange;
        }

        $vars = $this->venueVars($events);
        $vars['html']['heading']     = $this->esc($heading);
        $vars['html']['week_range']  = $this->esc($dateRange);
        $vars['html']['events_html'] = $fragment['html'];
        $vars['text']['heading']     = $heading;
        $vars['text']['week_range']  = $dateRange;
        $vars['text']['events_text'] = $fragment['text'];

        $rendered = $this->renderTemplate('campaign-lineup', $vars['html'], $vars['text']);

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $campaignId = $this->db->insert(
                'INSERT INTO email_campaigns (name, subject, source, status, html_body, text_body, created_by_user_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$name, $name, 'events', 'draft', $rendered['html'], $rendered['text'], $this->userId()]
            );
            foreach ($events as $event) {
                $this->db->run(
                    'INSERT INTO email_campaign_events (campaign_id, event_id) VALUES (?, ?)',
                    [$campaignId, (int) $event['id']]
                );
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $row = $this->db->one('SELECT * FROM email_campaigns WHERE id = ?', [$campaignId]);
        $row['events'] = $this->db->all(
            'SELECT e.id, e.title, e.date
             FROM email_campaign_events ce JOIN events e ON e.id = ce.event_id
             WHERE ce.campaign_id = ? ORDER BY e.date ASC',
            [$campaignId]
        );

        return $this->ok(['campaign' => $row, 'dropped_count' => $droppedCount]);
    }

    // ── Update (draft only) ──────────────────────────────────────────────────

    private function update(Request $request, int $id): Response
    {
        $existing = $this->db->one('SELECT id, status FROM email_campaigns WHERE id = ?', [$id]);
        if (!$existing) {
            return $this->notFound('Campaign not found');
        }
        if ($existing['status'] !== 'draft') {
            return Response::json(['error' => 'Only draft campaigns can be edited'], 422);
        }

        $b = $request->body();
        $sets = [];
        $params = [];

        foreach (['name', 'subject', 'html_body', 'text_body'] as $f) {
            if (!array_key_exists($f, $b)) {
                continue;
            }
            $val = $b[$f];
            if ($f === 'name') {
                $val = trim((string) $val);
                if ($val === '') {
                    return Response::json(['error' => 'name cannot be empty'], 422);
                }
            }
            $sets[] = "$f = ?";
            $params[] = $val;
        }

        if ($sets !== []) {
            $params[] = $id;
            $this->db->run('UPDATE email_campaigns SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);
        }

        $row = $this->db->one('SELECT * FROM email_campaigns WHERE id = ?', [$id]);
        return $this->ok(['campaign' => $row]);
    }

    // ── Delete (draft only) ──────────────────────────────────────────────────

    private function delete(int $id): Response
    {
        $existing = $this->db->one('SELECT id, status FROM email_campaigns WHERE id = ?', [$id]);
        if (!$existing) {
            return $this->notFound('Campaign not found');
        }
        if ($existing['status'] !== 'draft') {
            return Response::json(['error' => 'Only draft campaigns can be deleted'], 422);
        }
        $this->db->run('DELETE FROM email_campaigns WHERE id = ?', [$id]);
        return Response::noContent();
    }

    // ── Eligible events picker ────────────────────────────────────────────────

    private function eligibleEvents(Request $request): Response
    {
        $q = trim((string) $request->query('q'));

        $where = [
            'e.public_visibility = 1',
            "e.status IN ('published', 'advanced')",
            'e.date >= CURDATE()',
            'e.date <= DATE_ADD(CURDATE(), INTERVAL 90 DAY)',
        ];
        $params = [];
        if ($q !== '') {
            $where[] = 'e.title LIKE ?';
            $params[] = '%' . $q . '%';
        }

        $events = $this->db->all(
            'SELECT e.id, e.title, e.date, v.name AS venue_name
             FROM events e JOIN venues v ON v.id = e.venue_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY e.date ASC',
            $params
        );

        return $this->ok(['events' => $events]);
    }

    // ── Recipient preview ─────────────────────────────────────────────────────

    private function recipientsPreview(int $id, Request $request): Response
    {
        if (!$this->db->one('SELECT id FROM email_campaigns WHERE id = ?', [$id])) {
            return $this->notFound('Campaign not found');
        }

        $listIds    = $this->parseIdCsv((string) $request->query('list_ids', ''));
        $contactIds = $this->parseIdCsv((string) $request->query('contact_ids', ''));

        $optedIn = array_values(array_filter(
            $this->resolveContacts($listIds, $contactIds),
            static fn (array $r) => $r['opted_in']
        ));

        $sample = array_slice(array_map(static fn (array $r) => [
            'id'    => $r['id'],
            'name'  => trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')) ?: $r['email'],
            'email' => $r['email'],
        ], $optedIn), 0, 10);

        return $this->ok([
            'count'  => count($optedIn),
            'sample' => $sample,
        ]);
    }

    // ── Send ──────────────────────────────────────────────────────────────────

    private function send(int $id, Request $request): Response
    {
        $campaign = $this->db->one('SELECT * FROM email_campaigns WHERE id = ?', [$id]);
        if (!$campaign) {
            return $this->notFound('Campaign not found');
        }
        if ($campaign['status'] !== 'draft') {
            return Response::json(['error' => 'Only draft campaigns can be sent'], 422);
        }

        $body       = $request->body();
        $listIds    = $this->intArray($body['list_ids'] ?? []);
        $contactIds = $this->intArray($body['contact_ids'] ?? []);
        if ($listIds === [] && $contactIds === []) {
            return Response::json(['error' => 'Select at least one list or contact'], 422);
        }

        $all      = $this->resolveContacts($listIds, $contactIds);
        $sendable = array_values(array_filter($all, static fn (array $r) => $r['opted_in']));
        $skipped  = array_values(array_filter($all, static fn (array $r) => !$r['opted_in']));

        if ($sendable === []) {
            return Response::json([
                'error' => 'None of the selected recipients are opted in to marketing email — pick a list or contact with at least one opted-in contact',
            ], 422);
        }

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        $sentCount = 0;
        $failedCount = 0;
        try {
            $this->db->run("UPDATE email_campaigns SET status = 'sending' WHERE id = ?", [$id]);

            foreach ($skipped as $r) {
                $this->db->run(
                    'INSERT INTO email_campaign_recipients (campaign_id, contact_id, list_id, email_snapshot, status)
                     VALUES (?, ?, ?, ?, ?)',
                    [$id, $r['id'], $r['list_id'], $r['email'], 'skipped_opted_out']
                );
            }

            $mailer = new Mailer($this->root, $this->db);
            foreach ($sendable as $r) {
                try {
                    $outboxId = $mailer->send(
                        $r['email'],
                        (string) $campaign['subject'],
                        (string) $campaign['text_body'],
                        $campaign['html_body'],
                        'campaign'
                    );
                    $this->db->run(
                        'INSERT INTO email_campaign_recipients (campaign_id, contact_id, list_id, email_snapshot, status, outbox_id)
                         VALUES (?, ?, ?, ?, ?, ?)',
                        [$id, $r['id'], $r['list_id'], $r['email'], 'sent', $outboxId]
                    );
                    $sentCount++;
                } catch (\Throwable $e) {
                    $this->db->run(
                        'INSERT INTO email_campaign_recipients (campaign_id, contact_id, list_id, email_snapshot, status, error_message)
                         VALUES (?, ?, ?, ?, ?, ?)',
                        [$id, $r['id'], $r['list_id'], $r['email'], 'failed', $e->getMessage()]
                    );
                    $failedCount++;
                }
            }

            $finalStatus = match (true) {
                $sentCount === 0 => 'failed',
                $failedCount > 0 => 'partial_failure',
                default           => 'sent',
            };

            $this->db->run(
                'UPDATE email_campaigns SET status = ?, sent_at = NOW(), sent_count = ?, failed_count = ? WHERE id = ?',
                [$finalStatus, $sentCount, $failedCount, $id]
            );

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $eventIds = array_column(
            $this->db->all('SELECT event_id FROM email_campaign_events WHERE campaign_id = ?', [$id]),
            'event_id'
        );
        foreach ($eventIds as $eventId) {
            try {
                log_activity($this->db, (int) $eventId, $this->userId(), 'campaign sent', [
                    'campaign_id'  => $id,
                    'sent_count'   => $sentCount,
                    'failed_count' => $failedCount,
                ]);
            } catch (\Throwable) {
                // Best-effort — a logging failure must never fail the send.
            }
        }

        $row = $this->db->one('SELECT * FROM email_campaigns WHERE id = ?', [$id]);
        return $this->ok(['campaign' => $row]);
    }

    // ── Send test ─────────────────────────────────────────────────────────────

    private function sendTest(int $id, Request $request): Response
    {
        $campaign = $this->db->one('SELECT * FROM email_campaigns WHERE id = ?', [$id]);
        if (!$campaign) {
            return $this->notFound('Campaign not found');
        }

        $email = trim((string) $request->body('email', ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return Response::json(['error' => 'A valid email is required'], 422);
        }

        $mailer = new Mailer($this->root, $this->db);
        $mailer->send(
            $email,
            '[TEST] ' . (string) $campaign['subject'],
            (string) $campaign['text_body'],
            $campaign['html_body'],
            'campaign'
        );

        return $this->ok(['sent' => true, 'email' => $email]);
    }

    // ── Recipient resolution ──────────────────────────────────────────────────

    /**
     * Resolve the union of contacts referenced by $contactIds (ad hoc picks)
     * and by $listIds (via an active 'subscribed' list_membership row), with
     * NO opt-in filtering — every matched contact is returned, tagged with
     * `opted_in` (bool) and, for list matches, the first `list_id` it matched
     * through (null when only matched via an ad hoc contact id). Callers
     * filter/partition on `opted_in` themselves: recipients/preview only
     * wants the opted-in subset, while send() also needs the opted-out subset
     * to record skipped-opted-out rows.
     *
     * @param list<int> $listIds
     * @param list<int> $contactIds
     * @return list<array{id:int, first_name:?string, last_name:?string, email:string, opted_in:bool, list_id:?int}>
     */
    private function resolveContacts(array $listIds, array $contactIds): array
    {
        $resolved = [];

        if ($contactIds !== []) {
            $placeholders = implode(',', array_fill(0, count($contactIds), '?'));
            $rows = $this->db->all(
                "SELECT id, first_name, last_name, email, marketing_opted_in
                 FROM contacts WHERE id IN ($placeholders)",
                $contactIds
            );
            foreach ($rows as $row) {
                $resolved[(int) $row['id']] = [
                    'id'         => (int) $row['id'],
                    'first_name' => $row['first_name'],
                    'last_name'  => $row['last_name'],
                    'email'      => $row['email'],
                    'opted_in'   => (bool) $row['marketing_opted_in'],
                    'list_id'    => null,
                ];
            }
        }

        if ($listIds !== []) {
            $placeholders = implode(',', array_fill(0, count($listIds), '?'));
            $rows = $this->db->all(
                "SELECT c.id, c.first_name, c.last_name, c.email, c.marketing_opted_in, lm.list_id
                 FROM contacts c
                 JOIN list_membership lm ON lm.contact_id = c.id
                 WHERE lm.list_id IN ($placeholders) AND lm.status = 'subscribed'",
                $listIds
            );
            foreach ($rows as $row) {
                $id = (int) $row['id'];
                if (isset($resolved[$id])) {
                    continue;
                }
                $resolved[$id] = [
                    'id'         => $id,
                    'first_name' => $row['first_name'],
                    'last_name'  => $row['last_name'],
                    'email'      => $row['email'],
                    'opted_in'   => (bool) $row['marketing_opted_in'],
                    'list_id'    => (int) $row['list_id'],
                ];
            }
        }

        return array_values($resolved);
    }

    /** Parse a comma-separated list of ids from a query string, ignoring junk. */
    private function parseIdCsv(string $csv): array
    {
        if (trim($csv) === '') {
            return [];
        }
        $ids = array_filter(array_map('trim', explode(',', $csv)), static fn ($v) => $v !== '' && ctype_digit($v));
        return array_values(array_unique(array_map('intval', $ids)));
    }

    /** @return list<int> */
    private function intArray(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $v) {
            if (is_numeric($v)) {
                $out[] = (int) $v;
            }
        }
        return array_values(array_unique($out));
    }

    // ── Template rendering ────────────────────────────────────────────────────

    /**
     * Build the {{venue_*}}/{{footer_note}}/{{preheader}} substitution values
     * shared by both campaign templates. Mirrors the venue-var logic in
     * scripts/generate-weekly-lineup-email.php (env vars first, falling back
     * to the first linked event's venue columns, then a generic default) so
     * an events-generated campaign matches the look of the standalone weekly
     * digest script. Two maps are returned — 'html' values are entity-escaped,
     * 'text' values are left raw — same rationale as the script: sharing one
     * escaped map across both templates would leak entities into plain text.
     *
     * @param list<array<string,mixed>> $events
     * @return array{html: array<string,string>, text: array<string,string>}
     */
    private function venueVars(array $events): array
    {
        $venueName    = (string) (getenv('VENUE_NAME') ?: ($events[0]['venue_name'] ?? 'Backstage'));
        $venueCity    = (string) (getenv('VENUE_CITY') ?: ($events[0]['venue_city'] ?? ''));
        $venueState   = (string) (getenv('VENUE_STATE') ?: ($events[0]['venue_state'] ?? ''));
        $venueAddress = (string) ($events[0]['venue_address'] ?? '');

        $addressLine = implode(', ', array_filter([
            $venueAddress,
            trim(implode(', ', array_filter([$venueCity, $venueState]))),
        ]));
        $addressLineRaw = $addressLine !== '' ? $addressLine : $venueName;

        $eventCount = count($events);
        $footerNote = $eventCount > 0
            ? "You're receiving this because you asked to hear about shows at {$venueName}."
            : 'Sent from Backstage.';
        $preheaderRaw = $eventCount > 0
            ? $eventCount . ' show' . ($eventCount === 1 ? '' : 's') . " on stage this week at {$venueName}."
            : "See what's coming up at {$venueName}.";

        return [
            'html' => [
                'venue_name'         => $this->esc($venueName),
                'venue_address_line' => $this->esc($addressLineRaw),
                'footer_note'        => $this->esc($footerNote),
                'preheader'          => $this->esc($preheaderRaw),
            ],
            'text' => [
                'venue_name'         => $venueName,
                'venue_address_line' => $addressLineRaw,
                'footer_note'        => $footerNote,
            ],
        ];
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Load a template pair from storage/email-templates/ and substitute
     * {{key}} tokens. Deliberately independent of Mailer's own template
     * loader (which sends immediately) — campaigns need the rendered
     * html_body/text_body persisted to the DB, edited, previewed, and sent
     * later, not sent on the spot.
     *
     * @param array<string,string> $htmlVars
     * @param array<string,string> $textVars
     * @return array{html: string, text: string}
     */
    private function renderTemplate(string $name, array $htmlVars, array $textVars): array
    {
        $htmlPath = $this->root . '/storage/email-templates/' . $name . '.html';
        $textPath = $this->root . '/storage/email-templates/' . $name . '.txt';

        $html = is_file($htmlPath) ? (string) file_get_contents($htmlPath) : '';
        $text = is_file($textPath) ? (string) file_get_contents($textPath) : '';

        foreach ($htmlVars as $key => $value) {
            $html = str_replace('{{' . $key . '}}', $value, $html);
        }
        foreach ($textVars as $key => $value) {
            $text = str_replace('{{' . $key . '}}', $value, $text);
        }

        return ['html' => $html, 'text' => $text];
    }
}
