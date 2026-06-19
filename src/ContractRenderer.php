<?php
declare(strict_types=1);

namespace Panic;

/**
 * Turns structured deal terms + clause sections into a rendered document, and
 * powers two "smart" features:
 *
 *   - evaluate():     condition engine for auto-including modules
 *                     (e.g. all-ages downstairs → All-Ages Alcohol Control).
 *   - missingFields(): which required terms are still blank, per included section.
 *
 * Token substitution is {{key}} against a merged context of: the contract's
 * deal-term columns, its variables_json long tail, and the linked event/venue.
 */
final class ContractRenderer
{
    /** Friendly labels for tokens / missing-field reporting. */
    private const LABELS = [
        'venue_name' => 'Venue name', 'venue_address' => 'Venue address',
        'venue_city' => 'Venue city', 'venue_state' => 'Venue state',
        'counterparty_display' => 'Counterparty', 'title' => 'Contract title',
        'event_date' => 'Event date', 'event_room' => 'Room', 'event_title' => 'Event',
        'rental_fee' => 'Rental fee', 'deposit_amount' => 'Deposit', 'balance_due_date' => 'Balance due date',
        'bar_minimum' => 'Bar minimum', 'guarantee_amount' => 'Guarantee',
        'door_split_artist' => 'Artist/promoter split', 'door_split_venue' => 'Venue split',
        'door_split_promoter' => 'Promoter split',
        'advance_ticket_price' => 'Advance ticket price', 'door_ticket_price' => 'Door ticket price',
        'security_count' => 'Number of guards', 'security_rate' => 'Security rate',
        'security_paid_by' => 'Security paid by',
        'sound_tech_included' => 'Sound tech included', 'lighting_tech_included' => 'Lighting tech included',
        'merch_venue_percent' => 'Venue merch %',
        'recurrence_rule' => 'Recurrence', 'term_start' => 'Term start', 'term_end' => 'Term end',
        'trial_period_weeks' => 'Trial period (weeks)', 'termination_notice_days' => 'Termination notice (days)',
        'review_cadence' => 'Review cadence',
        'revenue_split_house' => 'House split', 'revenue_split_producer' => 'Producer split',
        'ticket_platform' => 'Ticket platform', 'marketing_deadline' => 'Marketing asset deadline',
        'cancellation_notice_days' => 'Cancellation notice (days)', 'insurance_amount' => 'Insurance amount',
    ];

    /** Deal-term columns carried on the contracts row. */
    public const DEAL_COLUMNS = [
        'rental_fee', 'deposit_amount', 'balance_due_date', 'bar_minimum', 'guarantee_amount',
        'door_split_artist', 'door_split_venue', 'door_split_promoter',
        'advance_ticket_price', 'door_ticket_price',
        'security_count', 'security_rate', 'security_paid_by',
        'sound_tech_included', 'lighting_tech_included', 'merch_venue_percent',
        'recurrence_rule', 'term_start', 'term_end', 'trial_period_weeks',
        'termination_notice_days', 'review_cadence', 'revenue_split_house', 'revenue_split_producer',
    ];

    public const CONTRACT_TYPES = [
        'private_event', 'promoter_show', 'artist_performance',
        'recurring_night', 'fundraiser', 'house_show', 'other',
    ];

    /** All valid contract statuses (original + digital-signature workflow states). */
    public const STATUSES = [
        // Original workflow
        'draft', 'needs_review', 'approved', 'sent', 'signed', 'canceled', 'superseded',
        // Digital-signature workflow
        'ready_to_send', 'viewed', 'partially_signed', 'signed_by_client',
        'countersigned', 'fully_executed', 'voided', 'declined', 'expired', 'error',
    ];

    /** Human-readable label for each status (used in admin UI). */
    public const STATUS_LABELS = [
        'draft'            => 'Draft',
        'needs_review'     => 'Needs Review',
        'approved'         => 'Approved',
        'ready_to_send'    => 'Ready to Send',
        'sent'             => 'Sent',
        'viewed'           => 'Viewed by Signer',
        'partially_signed' => 'Partially Signed',
        'signed_by_client' => 'Signed by Client',
        'countersigned'    => 'Countersigned',
        'fully_executed'   => 'Fully Executed',
        'signed'           => 'Signed',
        'canceled'         => 'Canceled',
        'voided'           => 'Voided',
        'declined'         => 'Declined',
        'expired'          => 'Expired',
        'superseded'       => 'Superseded',
        'error'            => 'Error',
    ];

    public static function label(string $key): string
    {
        return self::LABELS[$key] ?? ucwords(str_replace('_', ' ', $key));
    }

    /**
     * Build the substitution + condition context.
     * @return array{tokens: array<string,string>, cond: array<string,mixed>}
     */
    public static function context(array $contract, ?array $event, ?array $venue): array
    {
        $vars = self::decodeVars($contract['variables_json'] ?? null);

        // Raw values usable by the condition engine (numbers stay numbers).
        $cond = [];
        foreach (self::DEAL_COLUMNS as $col) {
            $cond[$col] = $contract[$col] ?? null;
        }
        foreach ($vars as $k => $v) {
            $cond[$k] = $v;
        }

        $venueName    = $venue['name'] ?? ($event['venue_name'] ?? '');
        $venueAddress = trim((string) ($venue['address'] ?? ''));
        $venueCity    = $venue['city'] ?? '';
        $venueState   = $venue['state'] ?? '';

        $ageRestriction = $event['age_restriction'] ?? ($vars['age_restriction'] ?? '');
        $agePolicy = $vars['age_policy'] ?? (stripos((string) $ageRestriction, 'all') !== false ? 'all_ages' : '21_plus');
        $attendance = $vars['expected_attendance'] ?? ($event['capacity'] ?? null);

        $cond['age_policy'] = $agePolicy;
        $cond['expected_attendance'] = $attendance !== null && $attendance !== '' ? (float) $attendance : null;
        $cond['room'] = $event['room'] ?? ($vars['room'] ?? null);

        // Display tokens (formatted strings).
        $counterparty = trim((string) ($contract['counterparty_name'] ?? ''));
        if (!empty($contract['counterparty_org'])) {
            $counterparty = $counterparty !== ''
                ? $counterparty . ' (' . $contract['counterparty_org'] . ')'
                : (string) $contract['counterparty_org'];
        }

        $tokens = [
            'venue_name' => $venueName,
            'venue_address' => $venueAddress !== '' ? $venueAddress : ($venueCity ? "$venueCity, $venueState" : ''),
            'venue_city' => $venueCity,
            'venue_state' => $venueState,
            'counterparty_display' => $counterparty,
            'title' => $contract['title'] ?? '',
            'event_title' => $event['title'] ?? '',
            'event_date' => self::formatDate($event['date'] ?? ($contract['term_start'] ?? null)),
            'event_room' => self::titleize($event['room'] ?? ($vars['room'] ?? 'venue')),
            'age_restriction' => (string) $ageRestriction,
            'capacity' => $event['capacity'] ?? '',
            'doors_time' => $event['doors_time'] ?? '',
            'show_time' => $event['show_time'] ?? '',
            'end_time' => $event['end_time'] ?? '',
        ];

        // Deal-term column tokens (formatted by key heuristics).
        foreach (self::DEAL_COLUMNS as $col) {
            $tokens[$col] = self::formatValue($col, $contract[$col] ?? null);
        }
        // Long-tail variable tokens.
        foreach ($vars as $k => $v) {
            if (!isset($tokens[$k]) || $tokens[$k] === '') {
                $tokens[$k] = self::formatValue($k, $v);
            }
        }

        return ['tokens' => $tokens, 'cond' => $cond];
    }

    /** Evaluate an include_when condition against the condition context. */
    public static function evaluate(?array $condition, array $cond): bool
    {
        if ($condition === null || $condition === []) {
            return true; // no condition → include by default
        }
        if (isset($condition['all'])) {
            foreach ($condition['all'] as $rule) {
                if (!self::evaluate($rule, $cond)) {
                    return false;
                }
            }
            return true;
        }
        if (isset($condition['any'])) {
            foreach ($condition['any'] as $rule) {
                if (self::evaluate($rule, $cond)) {
                    return true;
                }
            }
            return false;
        }
        if (isset($condition['not'])) {
            return !self::evaluate($condition['not'], $cond);
        }
        // Leaf: {field, op, value}
        $field = $condition['field'] ?? null;
        if ($field === null) {
            return true;
        }
        $actual = $cond[$field] ?? null;
        $expected = $condition['value'] ?? null;
        return match ($condition['op'] ?? 'truthy') {
            'eq'  => (string) $actual === (string) $expected,
            'ne'  => (string) $actual !== (string) $expected,
            'in'  => is_array($expected) && in_array((string) $actual, array_map('strval', $expected), true),
            'nin' => is_array($expected) && !in_array((string) $actual, array_map('strval', $expected), true),
            'gt'  => is_numeric($actual) && (float) $actual >  (float) $expected,
            'gte' => is_numeric($actual) && (float) $actual >= (float) $expected,
            'lt'  => is_numeric($actual) && (float) $actual <  (float) $expected,
            'lte' => is_numeric($actual) && (float) $actual <= (float) $expected,
            'set' => $actual !== null && $actual !== '',
            'falsy' => !self::truthy($actual),
            default => self::truthy($actual), // 'truthy'
        };
    }

    /**
     * Required fields (from included sections) whose token value is blank.
     * @return list<array{key:string,label:string,section:string}>
     */
    public static function missingFields(array $sections, array $tokens): array
    {
        $missing = [];
        $seen = [];
        foreach ($sections as $section) {
            if (!($section['included'] ?? false)) {
                continue;
            }
            $required = self::decodeList($section['required_fields_json'] ?? null);
            foreach ($required as $key) {
                if (isset($seen[$key])) {
                    continue;
                }
                $value = $tokens[$key] ?? '';
                if ($value === '' || $value === null) {
                    $seen[$key] = true;
                    $missing[] = ['key' => $key, 'label' => self::label($key), 'section' => $section['title'] ?? ''];
                }
            }
        }
        return $missing;
    }

    /**
     * Render the included sections to HTML + plain text.
     * @return array{html:string, text:string, summary:array}
     */
    public static function render(array $contract, array $sections, array $context, ?array $event = null, ?array $venue = null): array
    {
        $tokens = $context['tokens'];
        $summary = self::dealSummary($contract, $tokens);

        $htmlParts = [];
        $textParts = [];
        $n = 0;
        foreach ($sections as $section) {
            if (!($section['included'] ?? false)) {
                continue;
            }
            $n++;
            $title = (string) ($section['title'] ?? 'Section');
            $body = (string) ($section['body_template'] ?? '');
            $sectionId = (int) ($section['id'] ?? 0);
            $htmlParts[] = '<section class="contract-section" data-section-id="' . $sectionId . '"><h2>' . $n . '. ' . self::e($title) . '</h2>'
                . '<div class="contract-section-body">' . self::renderBodyHtml($body, $tokens) . '</div></section>';
            $textParts[] = "$n. " . strtoupper($title) . "\n\n" . self::renderBodyText($body, $tokens);
        }

        $summaryRows = '';
        foreach ($summary as $row) {
            $summaryRows .= '<tr><th>' . self::e($row['label']) . '</th><td>' . self::e($row['value']) . '</td></tr>';
        }
        $summaryHtml = $summaryRows !== ''
            ? '<table class="contract-summary"><caption>Deal Summary</caption><tbody>' . $summaryRows . '</tbody></table>'
            : '';

        $head = '<header class="contract-doc-head">'
            . '<h1>' . self::e($contract['title'] ?? 'Contract') . '</h1>'
            . '<p class="contract-doc-sub">' . self::e(self::titleize((string) ($contract['contract_type'] ?? 'other'))) . '</p>'
            . $summaryHtml . '</header>';

        $html = '<article class="contract-doc">' . $head . implode('', $htmlParts) . '</article>';

        $textHead = strtoupper((string) ($contract['title'] ?? 'Contract')) . "\n"
            . self::titleize((string) ($contract['contract_type'] ?? 'other')) . "\n";
        foreach ($summary as $row) {
            $textHead .= '  ' . $row['label'] . ': ' . $row['value'] . "\n";
        }
        $text = $textHead . "\n" . implode("\n\n", $textParts) . "\n";

        return ['html' => $html, 'text' => $text, 'summary' => $summary];
    }

    /** Compact list of the money/key terms that are actually set. */
    public static function dealSummary(array $contract, array $tokens): array
    {
        $keys = [
            'recurrence_rule', 'term_start', 'term_end', 'trial_period_weeks', 'termination_notice_days',
            'rental_fee', 'deposit_amount', 'balance_due_date', 'bar_minimum',
            'guarantee_amount', 'revenue_split_house', 'revenue_split_producer',
            'door_split_artist', 'door_split_venue', 'door_split_promoter',
            'security_count', 'merch_venue_percent',
        ];
        $rows = [];
        foreach ($keys as $key) {
            $raw = $contract[$key] ?? null;
            if ($raw === null || $raw === '' || (is_numeric($raw) && (float) $raw == 0.0 && !in_array($key, ['door_split_venue'], true))) {
                continue;
            }
            $rows[] = ['label' => self::label($key), 'value' => $tokens[$key] ?? self::formatValue($key, $raw)];
        }
        return $rows;
    }

    // ── formatting helpers ────────────────────────────────────────────────

    public static function formatValue(string $key, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if (self::isBoolKey($key)) {
            return self::truthy($value) ? 'Yes' : 'No';
        }
        if (self::isMoneyKey($key) && is_numeric($value)) {
            return '$' . number_format((float) $value, 2);
        }
        if (self::isPercentKey($key) && is_numeric($value)) {
            $n = (float) $value;
            return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.') . '%';
        }
        if (self::isDateKey($key)) {
            return self::formatDate($value);
        }
        return (string) $value;
    }

    private static function isMoneyKey(string $key): bool
    {
        return (bool) preg_match('/(_fee|_amount|_minimum|_rate|_price)$/', $key) || $key === 'guarantee_amount';
    }

    private static function isPercentKey(string $key): bool
    {
        return str_contains($key, 'split') || str_contains($key, 'percent');
    }

    private static function isBoolKey(string $key): bool
    {
        return str_ends_with($key, '_included') || str_ends_with($key, '_required') || str_ends_with($key, '_sold');
    }

    private static function isDateKey(string $key): bool
    {
        return str_ends_with($key, '_date') || in_array($key, ['term_start', 'term_end'], true);
    }

    private static function formatDate(mixed $value): string
    {
        if (!$value) {
            return '';
        }
        $ts = strtotime((string) $value);
        return $ts ? date('F j, Y', $ts) : (string) $value;
    }

    private static function titleize(string $value): string
    {
        return ucwords(str_replace(['_', '-'], ' ', $value));
    }

    private static function truthy(mixed $value): bool
    {
        return in_array($value, [1, '1', true, 'true', 'on', 'yes', 'Yes'], true)
            || (is_numeric($value) && (float) $value > 0);
    }

    private static function renderBodyHtml(string $template, array $tokens): string
    {
        $paragraphs = preg_split('/\n{2,}/', trim($template)) ?: [];
        $out = [];
        foreach ($paragraphs as $para) {
            $escaped = self::e($para);
            $filled = self::fill($escaped, $tokens, true);
            $out[] = '<p>' . nl2br($filled) . '</p>';
        }
        return implode('', $out);
    }

    private static function renderBodyText(string $template, array $tokens): string
    {
        return self::fill($template, $tokens, false);
    }

    /** Replace {{key}} tokens; mark blanks so reviewers see what's missing. */
    private static function fill(string $text, array $tokens, bool $html): string
    {
        return preg_replace_callback('/\{\{\s*([a-z0-9_]+)\s*\}\}/i', function ($m) use ($tokens, $html) {
            $key = $m[1];
            $value = $tokens[$key] ?? '';
            if ($value === '' || $value === null) {
                $label = self::label($key);
                // data-token lets the JS click handler map the span back to its form field.
                return $html ? '<span class="contract-token-missing" data-token="' . self::e($key) . '">[ ' . self::e($label) . ' ]</span>' : '[[ ' . $label . ' ]]';
            }
            return $html ? $value : $value; // value already escaped for html path (text passes raw)
        }, $text) ?? $text;
    }

    private static function decodeVars(mixed $json): array
    {
        if (is_array($json)) {
            return $json;
        }
        $decoded = is_string($json) && $json !== '' ? json_decode($json, true) : null;
        return is_array($decoded) ? $decoded : [];
    }

    private static function decodeList(mixed $json): array
    {
        if (is_array($json)) {
            return array_values($json);
        }
        $decoded = is_string($json) && $json !== '' ? json_decode($json, true) : null;
        return is_array($decoded) ? array_values($decoded) : [];
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
