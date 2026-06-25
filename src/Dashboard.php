<?php
declare(strict_types=1);

namespace Panic;

final class Dashboard extends BaseEndpoint
{
    public function handle(Request $request): Response
    {
        [$scopeSql, $scopeParams] = $this->eventScopeSql('e');
        $events = $this->db->all(
            "SELECT e.*, u.name owner_name,
              (SELECT title FROM event_blockers b WHERE b.event_id = e.id AND b.status IN ('open','waiting') ORDER BY due_date, id LIMIT 1) primary_blocker,
              (SELECT COUNT(*) FROM event_tasks t WHERE t.event_id = e.id AND t.status NOT IN ('done','canceled')) incomplete_tasks,
              (SELECT COUNT(*) FROM event_blockers b WHERE b.event_id = e.id AND b.status IN ('open','waiting')) open_items,
              (SELECT COUNT(*) FROM event_assets a WHERE a.event_id = e.id AND a.asset_type = 'flyer' AND a.approval_status = 'approved') approved_flyers
             FROM events e
             LEFT JOIN users u ON u.id = e.owner_user_id
             WHERE e.date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 14 DAY)
               AND $scopeSql
             ORDER BY e.date, e.show_time",
            $scopeParams
        );
        [$settlementSql, $settlementParams] = $this->settlementScopeSql();
        $nextEmpty = $this->db->one("SELECT e.date FROM events e WHERE e.date >= CURDATE() AND e.status IN ('empty','hold') AND $scopeSql ORDER BY e.date LIMIT 1", $scopeParams);
        $oldestUnsettled = $this->db->one("SELECT e.id, e.title, e.date FROM events e LEFT JOIN event_settlements s ON s.event_id = e.id WHERE e.status = 'completed' AND s.id IS NULL AND $scopeSql AND $settlementSql ORDER BY e.date LIMIT 1", array_merge($scopeParams, $settlementParams));
        $events = array_map(fn ($event) => $event + ['capabilities' => $this->eventCapabilities((int) $event['id'])], $events);
        $cards = [
            'empty' => $this->count("SELECT COUNT(*) c FROM events e WHERE e.date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 14 DAY) AND e.status IN ('empty','hold') AND $scopeSql", $scopeParams),
            'needsAssets' => $this->count("SELECT COUNT(*) c FROM events e WHERE e.status IN ('confirmed','needs_assets') AND e.id NOT IN (SELECT event_id FROM event_assets WHERE asset_type='flyer' AND approval_status='approved') AND $scopeSql", $scopeParams),
            'ready' => $this->count("SELECT COUNT(*) c FROM events e WHERE e.status = 'ready_to_announce' AND $scopeSql", $scopeParams),
            'blockers' => $this->count("SELECT COUNT(*) c FROM event_blockers b JOIN events e ON e.id = b.event_id WHERE b.status IN ('open','waiting') AND $scopeSql", $scopeParams),
            'urgentItems' => $this->count("SELECT COUNT(*) c FROM event_blockers b JOIN events e ON e.id = b.event_id WHERE b.status IN ('open','waiting') AND b.due_date <= DATE_ADD(CURDATE(), INTERVAL 2 DAY) AND $scopeSql", $scopeParams),
            'published' => $this->count("SELECT COUNT(*) c FROM events e WHERE e.status = 'published' AND e.date >= CURDATE() AND $scopeSql", $scopeParams),
            'unsettled' => $this->count("SELECT COUNT(*) c FROM events e LEFT JOIN event_settlements s ON s.event_id = e.id WHERE e.status = 'completed' AND s.id IS NULL AND $scopeSql AND $settlementSql", array_merge($scopeParams, $settlementParams)),
            // New operational counts
            'leadsNeedingReview' => $this->isVenueAdmin()
                ? $this->count("SELECT COUNT(*) c FROM leads WHERE status IN ('new','triage','evaluating','needs_review')")
                : 0,
            'contractsAwaitingSignature' => $this->count("SELECT COUNT(*) c FROM contracts c2 JOIN events e ON e.id = c2.event_id WHERE c2.status IN ('sent','partially_signed') AND $scopeSql", $scopeParams),
            'depositsOverdue' => $this->count("SELECT COUNT(*) c FROM events e JOIN event_payments ep ON ep.event_id = e.id WHERE ep.payment_type='deposit' AND ep.status='pending' AND ep.due_date < CURDATE() AND $scopeSql", $scopeParams),
            'eventsAwaitingCloseout' => $this->count("SELECT COUNT(*) c FROM events e LEFT JOIN event_closeout_state ecs ON ecs.event_id = e.id WHERE e.status='completed' AND (ecs.id IS NULL OR ecs.status NOT IN ('finalized')) AND $scopeSql", $scopeParams),
            'overdueFollowups' => $this->isVenueAdmin()
                ? $this->count("SELECT COUNT(*) c FROM client_notes WHERE type IN ('task','followup') AND is_done=0 AND due_date < CURDATE()")
                : 0,
            // Utilization: distinct days with at least one booked show (not empty/hold) in the next 14 days
            'utilizedDays' => $this->count(
                "SELECT COUNT(DISTINCT CASE WHEN e.status NOT IN ('empty','hold') THEN e.date END) c
                 FROM events e
                 WHERE e.date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 13 DAY)
                   AND $scopeSql",
                $scopeParams
            ),
            'utilizationPct' => $this->count(
                "SELECT ROUND(COUNT(DISTINCT CASE WHEN e.status NOT IN ('empty','hold') THEN e.date END) * 100.0 / 14) c
                 FROM events e
                 WHERE e.date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 13 DAY)
                   AND $scopeSql",
                $scopeParams
            ),
        ];
        return $this->ok([
            'cards'      => $cards,
            'events'     => $events,
            'highlights' => [
                'next_empty_date' => $nextEmpty['date'] ?? null,
                'oldest_unsettled' => $oldestUnsettled,
            ],
            'onboarding' => $this->onboardingChecklist(),
        ]);
    }

    /**
     * Build the getting-started checklist for venue_admins.
     *
     * Returns null when the checklist should not be shown (non-admin role,
     * or the current user has dismissed it). Each step has:
     *   key     — stable identifier used by the frontend
     *   label   — what the user needs to do
     *   done    — whether the step is complete (detected from DB state)
     *   href    — deep-link hash the "Go →" button navigates to
     *
     * @return array<string,mixed>|null
     */
    private function onboardingChecklist(): ?array
    {
        // Only venue_admins see the checklist.
        if (!$this->isVenueAdmin()) {
            return null;
        }

        // Respect the user's dismiss choice.
        $user = $this->db->one(
            'SELECT onboarding_dismissed FROM users WHERE id = ? LIMIT 1',
            [$this->userId()]
        );
        if ($user && (bool) $user['onboarding_dismissed']) {
            return null;
        }

        $steps = [
            [
                'key'   => 'venue_details',
                'label' => 'Add your venue details',
                'note'  => 'Name, address and timezone — used in contracts and emails.',
                'done'  => (bool) $this->db->one(
                    "SELECT 1 FROM venues WHERE address IS NOT NULL AND address != '' LIMIT 1"
                ),
                'href'  => '#admin-venue',
            ],
            [
                'key'   => 'contract_template',
                'label' => 'Create a contract template',
                'note'  => 'Build the standard agreement you send every artist.',
                'done'  => (bool) $this->db->one('SELECT 1 FROM contract_templates LIMIT 1'),
                'href'  => '#admin-contracts',
            ],
            [
                'key'   => 'payment_processing',
                'label' => 'Connect payment processing',
                'note'  => 'Square or Stripe — needed to sell tickets through the app.',
                'done'  => $this->isPaymentConfigured(),
                'href'  => '#admin-payments',
            ],
            [
                'key'   => 'staff_member',
                'label' => 'Invite a staff member',
                'note'  => 'Add a booker, manager or door person to your team.',
                'done'  => $this->count('SELECT COUNT(*) c FROM users WHERE access_status = \'active\'') > 1,
                'href'  => '#admin-staff',
            ],
            [
                'key'   => 'first_event',
                'label' => 'Create your first event',
                'note'  => 'Book a show and walk through the full workflow.',
                'done'  => (bool) $this->db->one(
                    "SELECT 1 FROM events WHERE status NOT IN ('empty') LIMIT 1"
                ),
                'href'  => '#calendar',
            ],
        ];

        $completed = count(array_filter($steps, fn ($s) => $s['done']));

        return [
            'steps'     => $steps,
            'completed' => $completed,
            'total'     => count($steps),
        ];
    }

    /**
     * Detect whether at least one payment provider has been configured.
     * Checks payment_settings.active_provider against available env credentials.
     */
    private function isPaymentConfigured(): bool
    {
        $row = $this->db->one('SELECT active_provider, settings_json FROM payment_settings LIMIT 1');
        if (!$row) {
            return false;
        }
        $provider = (string) ($row['active_provider'] ?? '');
        $settings = json_decode((string) ($row['settings_json'] ?? '{}'), true) ?: [];

        return match ($provider) {
            'square'  => !empty($settings['access_token']) || (getenv('SQUARE_ACCESS_TOKEN') !== false && getenv('SQUARE_ACCESS_TOKEN') !== ''),
            'stripe'  => !empty($settings['secret_key'])   || (getenv('STRIPE_SECRET_KEY')   !== false && getenv('STRIPE_SECRET_KEY')   !== ''),
            default   => false,
        };
    }

    private function count(string $sql, array $params = []): int
    {
        return (int) ($this->db->one($sql, $params)['c'] ?? 0);
    }

    private function settlementScopeSql(): array
    {
        if ($this->isVenueAdmin() || $this->isGlobalViewer()) {
            return ['1=1', []];
        }
        return [
            "(e.owner_user_id = ? OR EXISTS (SELECT 1 FROM event_collaborators ec_settle WHERE ec_settle.event_id = e.id AND ec_settle.user_id = ? AND ec_settle.role IN ('venue_admin','event_owner')))",
            [$this->userId(), $this->userId()],
        ];
    }
}
