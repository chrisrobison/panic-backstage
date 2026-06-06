<?php
declare(strict_types=1);

/**
 * Idempotent seeder for the contract clause library + starter templates.
 *
 * Safe to run repeatedly: modules upsert on their unique module_key, and
 * templates are matched by name (their module wiring is rebuilt each run).
 *
 * Used by database/seed.php (demo reset) and runnable directly against an
 * existing install after applying migration 017:
 *
 *   php database/seed_contracts.php
 *
 * Clause text here is starter boilerplate, not legal advice — venues should
 * have counsel review the library before sending real contracts.
 */

namespace Panic;

/**
 * @param \PDO $pdo Connected to the panic_backstage database.
 */
function seed_contract_library(\PDO $pdo): void
{
    // module_key, name, category, risk_level, is_locked, [required fields], body
    $modules = [
        ['base_parties', 'Parties & Engagement', 'base', 'none', 0, [],
            'This Agreement ("Agreement") is entered into between {{venue_name}} ("Venue"), located at {{venue_address}}, and {{counterparty_display}} ("Counterparty"). The parties agree to the terms set forth below for {{title}}.'],

        ['recurring_event', 'Recurring Engagement', 'operational', 'low', 0, ['recurrence_rule', 'trial_period_weeks', 'termination_notice_days'],
            'The Counterparty shall produce a recurring event at the Venue on the following schedule: {{recurrence_rule}}. The engagement begins on {{term_start}} and continues until {{term_end}} unless terminated earlier in accordance with this Agreement. The arrangement opens with a {{trial_period_weeks}}-week trial period, after which the parties will review attendance, bar sales, operational fit, and promotional performance on a {{review_cadence}} basis. Either party may terminate this recurring engagement with {{termination_notice_days}} days'."'".' written notice.'],

        ['revenue_split', 'Revenue Share', 'financial', 'low', 0, ['revenue_split_house', 'revenue_split_producer'],
            'Net event revenue shall be split {{revenue_split_house}} to the Venue ("House") and {{revenue_split_producer}} to the Counterparty ("Producer"), calculated after approved event expenses. Settlement shall occur at the conclusion of each event night unless otherwise agreed in writing.'],

        ['door_split', 'Door Split', 'financial', 'low', 0, ['door_split_artist', 'door_split_venue'],
            'Net ticket revenue shall be calculated as gross ticket sales less approved deductions, including ticketing fees, security, sound technician fees, and other agreed event expenses. Net ticket revenue shall be split {{door_split_artist}} to Artist/Promoter and {{door_split_venue}} to Venue.'],

        ['flat_rental', 'Flat Rental Fee', 'financial', 'medium', 0, ['rental_fee', 'deposit_amount', 'balance_due_date'],
            'The Counterparty agrees to pay the Venue a rental fee of {{rental_fee}} for use of the {{event_room}} room on {{event_date}}. A non-refundable deposit of {{deposit_amount}} is due upon signing this Agreement. The remaining balance is due no later than {{balance_due_date}}.'],

        ['guarantee', 'Artist Guarantee', 'financial', 'medium', 0, ['guarantee_amount'],
            'The Venue shall pay the Artist a guarantee of {{guarantee_amount}}, payable at settlement on the night of the event.'],

        ['bar_minimum', 'Bar Minimum', 'financial', 'low', 0, ['bar_minimum'],
            'The Counterparty agrees to a bar minimum of {{bar_minimum}} before taxes and gratuity. If actual bar sales do not meet this minimum, the Counterparty is responsible for paying the difference at the conclusion of the event.'],

        ['ticketing', 'Ticketing', 'operational', 'low', 0, ['ticket_platform'],
            'Ticketing shall be managed via {{ticket_platform}}. Advance tickets shall be priced at {{advance_ticket_price}} and door tickets at {{door_ticket_price}}. The Venue shall control the box office and provide a final settlement count at the conclusion of the event.'],

        ['security', 'Security Staffing', 'operational', 'medium', 0, ['security_count', 'security_rate', 'security_paid_by'],
            'Security staffing shall consist of {{security_count}} licensed guard(s) at a rate of {{security_rate}} per hour. Security costs shall be borne by the {{security_paid_by}}. Guards shall be on duty from doors through final load-out and clearance of the premises.'],

        ['production', 'Production & Technical', 'operational', 'none', 0, [],
            'Production support for this event: sound technician included — {{sound_tech_included}}; lighting technician included — {{lighting_tech_included}}. The Counterparty shall deliver a stage plot and input list no later than seven (7) days before the event. Any equipment beyond the Venue'."'".'s standard package is the Counterparty'."'".'s responsibility.'],

        ['all_ages', 'All-Ages Alcohol Control', 'risk', 'high', 0, [],
            'As this is an all-ages event, the following controls apply: all patrons of legal drinking age shall be wristbanded after ID verification at entry; alcohol service is restricted to wristbanded patrons in designated areas only; minors shall not be served alcohol or permitted in age-restricted areas; and the Counterparty acknowledges the enhanced security and door-staffing requirements associated with all-ages programming.'],

        ['marketing', 'Marketing Responsibilities', 'operational', 'low', 0, ['marketing_deadline'],
            'The Counterparty shall provide final event artwork, lineup, billing, social-media handles, and promotional copy no later than {{marketing_deadline}}. Both parties agree to make reasonable promotional efforts, including social-media posts, event-page sharing, and coordination with Venue marketing.'],

        ['merch', 'Merchandise', 'financial', 'low', 0, [],
            'Merchandise may be sold at the event. The Venue shall retain {{merch_venue_percent}} of gross merchandise sales; the remainder belongs to the Counterparty. The Venue shall provide a merchandise location and the Counterparty shall staff the table. Prohibited or counterfeit items may not be sold.'],

        ['hospitality', 'Hospitality & Rider', 'operational', 'none', 0, [],
            'The Venue shall provide reasonable green-room access and drink tickets as agreed. Any meals, lodging, parking, or travel are the Counterparty'."'".'s responsibility unless separately agreed in writing.'],

        ['recording_photo', 'Recording, Photo & Video', 'legal', 'low', 0, [],
            'The Venue may photograph and record the event for promotional and archival use, and the Counterparty grants the Venue a non-exclusive license to use such media to promote the Venue and its events. Any commercial recording, broadcast, or livestream by the Counterparty requires the Venue'."'".'s prior written consent.'],

        ['cancellation', 'Cancellation Policy', 'legal', 'medium', 0, ['cancellation_notice_days'],
            'Either party may cancel this Agreement with at least {{cancellation_notice_days}} days'."'".' written notice. Deposits are non-refundable upon cancellation by the Counterparty within the notice window. Cancellation by the Venue for reasons other than breach shall entitle the Counterparty to a refund of any deposit paid.'],

        ['insurance', 'Insurance', 'legal', 'medium', 0, ['insurance_amount'],
            'The Counterparty shall maintain commercial general liability insurance of not less than {{insurance_amount}}, naming the Venue as additional insured, and shall provide a certificate of insurance no later than seven (7) days before the event.'],

        ['indemnification', 'Indemnification', 'legal', 'high', 1, [],
            'Each party shall indemnify, defend, and hold harmless the other from and against any claims, damages, liabilities, and expenses arising out of its own negligence or willful misconduct in connection with the event. This provision survives termination of this Agreement.'],

        ['force_majeure', 'Force Majeure', 'legal', 'medium', 1, [],
            'Neither party shall be liable for failure to perform due to causes beyond its reasonable control, including acts of God, government order, public-health emergency, fire, or labor dispute. The affected party shall notify the other promptly, and the parties shall negotiate an equitable resolution, including rescheduling where practicable.'],

        ['governing_law', 'Governing Law', 'legal', 'low', 1, [],
            'This Agreement shall be governed by the laws of the State of {{venue_state}}, without regard to its conflict-of-laws principles. Any dispute shall be resolved in the courts located in {{venue_city}}, {{venue_state}}.'],

        ['signatures', 'Signatures', 'base', 'none', 0, [],
            "The parties execute this Agreement as of the dates written below.\n\nFor the Venue ({{venue_name}}):\nName: ____________________   Signature: ____________________   Date: __________\n\nFor the Counterparty ({{counterparty_display}}):\nName: ____________________   Signature: ____________________   Date: __________"],
    ];

    $upsert = $pdo->prepare(
        'INSERT INTO contract_modules (module_key, name, category, body_template, required_fields_json, risk_level, is_locked, sort_order)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            name=VALUES(name), category=VALUES(category), body_template=VALUES(body_template),
            required_fields_json=VALUES(required_fields_json), risk_level=VALUES(risk_level),
            is_locked=VALUES(is_locked), sort_order=VALUES(sort_order)'
    );
    foreach ($modules as $i => [$key, $name, $category, $risk, $locked, $required, $body]) {
        $upsert->execute([$key, $name, $category, $body, json_encode(array_values($required)), $risk, $locked, $i]);
    }

    // module_key => id
    $moduleId = [];
    foreach ($pdo->query('SELECT id, module_key FROM contract_modules')->fetchAll(\PDO::FETCH_ASSOC) as $row) {
        $moduleId[$row['module_key']] = (int) $row['id'];
    }

    // Smart-selection condition helpers.
    $allAges     = ['all' => [['field' => 'age_policy', 'op' => 'eq', 'value' => 'all_ages']]];
    $hasBarMin   = ['all' => [['field' => 'bar_minimum', 'op' => 'gt', 'value' => 0]]];
    $bigOrAllAge = ['any' => [['field' => 'expected_attendance', 'op' => 'gte', 'value' => 200], ['field' => 'age_policy', 'op' => 'eq', 'value' => 'all_ages']]];
    $insurance   = ['all' => [['field' => 'insurance_required', 'op' => 'truthy']]];
    $merch       = ['all' => [['field' => 'merch_sold', 'op' => 'truthy']]];
    $hospitality = ['all' => [['field' => 'hospitality_provided', 'op' => 'truthy']]];
    $venueTix    = ['all' => [['field' => 'venue_controls_tickets', 'op' => 'truthy']]];
    $hasGuarantee= ['all' => [['field' => 'guarantee_amount', 'op' => 'gt', 'value' => 0]]];
    $hasDoorSplit= ['all' => [['field' => 'door_split_artist', 'op' => 'gt', 'value' => 0]]];

    // name, description, contract_type, intro_text, [ [module_key, is_required, condition|null], ... ]
    // is_required modules are always included; a null condition means "on by
    // default" (removable); a condition means "auto-include only when it passes".
    $templates = [
        ['Recurring Night Agreement', 'Weekly/residency engagement: trial period, revenue share, termination notice, and review cadence.', 'recurring_night',
            'This Recurring Night Agreement establishes the terms for an ongoing programmed night at the Venue.',
            [
                ['base_parties', 1, null],
                ['recurring_event', 1, null],
                ['revenue_split', 1, null],
                ['marketing', 0, null],
                ['bar_minimum', 0, $hasBarMin],
                ['all_ages', 0, $allAges],
                ['security', 0, $bigOrAllAge],
                ['production', 0, null],
                ['hospitality', 0, $hospitality],
                ['recording_photo', 0, null],
                ['cancellation', 1, null],
                ['insurance', 0, $insurance],
                ['indemnification', 1, null],
                ['force_majeure', 1, null],
                ['governing_law', 1, null],
                ['signatures', 1, null],
            ]],

        ['Private Event Rental', 'Room rental for birthdays, corporate events, fundraisers, and private parties.', 'private_event',
            'This Private Event Rental Agreement governs a one-time rental of Venue space.',
            [
                ['base_parties', 1, null],
                ['flat_rental', 1, null],
                ['bar_minimum', 0, $hasBarMin],
                ['security', 0, $bigOrAllAge],
                ['all_ages', 0, $allAges],
                ['production', 0, null],
                ['cancellation', 1, null],
                ['insurance', 0, $insurance],
                ['indemnification', 1, null],
                ['governing_law', 1, null],
                ['signatures', 1, null],
            ]],

        ['Promoter / Production Show', 'Outside promoter brings a show: door split, ticketing, security, and settlement terms.', 'promoter_show',
            'This Promoter Agreement governs an event produced at the Venue by an outside promoter.',
            [
                ['base_parties', 1, null],
                ['door_split', 1, null],
                ['ticketing', 0, $venueTix],
                ['marketing', 0, null],
                ['security', 0, $bigOrAllAge],
                ['production', 0, null],
                ['merch', 0, $merch],
                ['all_ages', 0, $allAges],
                ['recording_photo', 0, null],
                ['cancellation', 1, null],
                ['indemnification', 1, null],
                ['force_majeure', 1, null],
                ['governing_law', 1, null],
                ['signatures', 1, null],
            ]],

        ['Artist / Band Performance', 'Venue books the act directly: guarantee and/or door deal, hospitality, and merch.', 'artist_performance',
            'This Performance Agreement governs a booking of the Artist by the Venue.',
            [
                ['base_parties', 1, null],
                ['guarantee', 0, $hasGuarantee],
                ['door_split', 0, $hasDoorSplit],
                ['hospitality', 0, null],
                ['production', 0, null],
                ['merch', 0, $merch],
                ['marketing', 0, null],
                ['recording_photo', 0, null],
                ['all_ages', 0, $allAges],
                ['cancellation', 1, null],
                ['indemnification', 1, null],
                ['governing_law', 1, null],
                ['signatures', 1, null],
            ]],
    ];

    $findTemplate = $pdo->prepare('SELECT id FROM contract_templates WHERE name = ? LIMIT 1');
    $insertTemplate = $pdo->prepare('INSERT INTO contract_templates (name, description, contract_type, intro_text) VALUES (?, ?, ?, ?)');
    $updateTemplate = $pdo->prepare('UPDATE contract_templates SET description=?, contract_type=?, intro_text=? WHERE id=?');
    $clearWiring = $pdo->prepare('DELETE FROM contract_template_modules WHERE template_id = ?');
    $insertWiring = $pdo->prepare('INSERT INTO contract_template_modules (template_id, module_id, sort_order, is_required, condition_json) VALUES (?, ?, ?, ?, ?)');

    foreach ($templates as [$name, $desc, $type, $intro, $wiring]) {
        $findTemplate->execute([$name]);
        $existing = $findTemplate->fetchColumn();
        if ($existing) {
            $templateId = (int) $existing;
            $updateTemplate->execute([$desc, $type, $intro, $templateId]);
        } else {
            $insertTemplate->execute([$name, $desc, $type, $intro]);
            $templateId = (int) $pdo->lastInsertId();
        }
        $clearWiring->execute([$templateId]);
        $order = 0;
        foreach ($wiring as [$moduleKey, $required, $condition]) {
            if (!isset($moduleId[$moduleKey])) {
                continue;
            }
            $insertWiring->execute([
                $templateId,
                $moduleId[$moduleKey],
                $order++,
                $required ? 1 : 0,
                $condition === null ? null : json_encode($condition),
            ]);
        }
    }
}

// Allow running standalone: php database/seed_contracts.php
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    $root = dirname(__DIR__);
    require $root . '/src/bootstrap.php';
    Env::load($root . '/.env');
    $host = getenv('DB_HOST') ?: '127.0.0.1';
    $port = getenv('DB_PORT') ?: '3306';
    $user = getenv('DB_USER') ?: 'root';
    $password = getenv('DB_PASSWORD') ?: '';
    $dbName = getenv('DB_NAME') ?: 'panic_backstage';
    $pdo = new \PDO("mysql:host=$host;port=$port;dbname=$dbName;charset=utf8mb4", $user, $password, [
        \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
    ]);
    seed_contract_library($pdo);
    echo "Contract clause library seeded.\n";
}
