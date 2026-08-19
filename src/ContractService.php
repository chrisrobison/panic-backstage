<?php
declare(strict_types=1);

namespace Panic;

/**
 * Shared contract operations used by both the top-level Contracts endpoint and
 * the per-event Events\Contracts endpoint, so creation + smart module selection
 * live in one place.
 */
final class ContractService
{
    /** Create a contract row and, if a template is given, build its sections. */
    public static function create(Database $db, array $d, ?int $userId): int
    {
        $type = in_array($d['contract_type'] ?? '', ContractRenderer::CONTRACT_TYPES, true) ? $d['contract_type'] : 'other';
        $templateId = !empty($d['template_id']) ? (int) $d['template_id'] : null;
        if ($templateId) {
            $tpl = $db->one('SELECT contract_type FROM contract_templates WHERE id = ?', [$templateId]);
            if ($tpl) {
                $type = $tpl['contract_type'];
            }
        }
        $id = $db->insert(
            'INSERT INTO contracts (event_id, series_id, venue_id, template_id, contract_type, title, counterparty_name, counterparty_org, counterparty_email, created_by_user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $d['event_id'] ?? null,
                $d['series_id'] ?? null,
                $d['venue_id'] ?? null,
                $templateId,
                $type,
                self::resolveTitle($d),
                $d['counterparty_name'] ?? null,
                $d['counterparty_org'] ?? null,
                $d['counterparty_email'] ?? null,
                $userId,
            ]
        );
        if ($templateId) {
            self::buildSectionsFromTemplate($db, $id, $templateId);
        }
        return $id;
    }

    /**
     * Resolve the title to store for a new contract: use the caller's own
     * title if it gave one (e.g. an admin-typed venue-level contract name,
     * or an automation's "Quote — Event Name"), otherwise derive one from
     * the deal itself via composeTitle(). $d may carry `event_type` and
     * `fallback_title` (the linked event's own title) as extra hints
     * alongside `title`/`counterparty_name`/`counterparty_org` — callers
     * that don't have an event (venue-level contracts) may omit them.
     */
    private static function resolveTitle(array $d): string
    {
        return self::cleanTitlePart($d['title'] ?? null)
            ?? self::composeTitle(
                $d['counterparty_name'] ?? null,
                $d['counterparty_org'] ?? null,
                $d['event_type'] ?? null,
                $d['fallback_title'] ?? null
            );
    }

    /**
     * Build a contract's display title from the deal itself: counterparty
     * name, counterparty organization, and the event's show type (falling
     * back to the linked event's own title, then a generic default, so the
     * NOT NULL `title` column always gets something sane). This is a pure
     * function of those inputs so Contracts::update() can also call it to
     * check whether a *stored* title still matches what would have been
     * derived from the pre-edit values — i.e. whether it's safe to refresh
     * automatically, versus one a person deliberately typed.
     */
    public static function composeTitle(?string $name, ?string $org, ?string $eventType, ?string $fallbackEventTitle = null): string
    {
        $parts = array_values(array_filter([
            self::cleanTitlePart($name),
            self::cleanTitlePart($org),
            $eventType ? ContractRenderer::titleize($eventType) : null,
        ], static fn ($p) => $p !== null));
        if ($parts) {
            return implode(' — ', $parts);
        }
        return self::cleanTitlePart($fallbackEventTitle) ?? 'Untitled Contract';
    }

    /**
     * Trim a candidate title fragment, treating blank and the classic
     * JS string-interpolation bug of a literal "undefined"/"null" as
     * absent rather than storing them verbatim.
     */
    private static function cleanTitlePart(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);
        if ($value === '' || strcasecmp($value, 'undefined') === 0 || strcasecmp($value, 'null') === 0) {
            return null;
        }
        return $value;
    }

    /**
     * Record that a contract was signed outside the system and the signed
     * document was uploaded as an event asset, instead of being generated
     * and signed through the in-app deal builder.
     *
     * Deliberately creates a normal `contracts` row (status='signed',
     * provider='manual_upload') rather than a separate flag: the "booked"
     * status gate in Events::validateStatusTransition() already accepts any
     * contracts row with status signed/fully_executed, so this satisfies
     * that check with no changes to the gate itself. No template/sections
     * are built — there is nothing to render, the asset *is* the document.
     */
    public static function attachUploaded(Database $db, array $d, ?int $userId): int
    {
        return $db->insert(
            'INSERT INTO contracts (event_id, series_id, venue_id, asset_id, contract_type, title, status, provider, signed_at, created_by_user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)',
            [
                $d['event_id'] ?? null,
                $d['series_id'] ?? null,
                $d['venue_id'] ?? null,
                $d['asset_id'],
                'other',
                trim((string) ($d['title'] ?? '')) ?: 'Uploaded Contract',
                'signed',
                'manual_upload',
                $userId,
            ]
        );
    }

    /** Resolve the linked event (with venue_name) and venue rows for a contract. */
    public static function eventVenueFor(Database $db, array $contract): array
    {
        $event = null;
        $venue = null;
        if (!empty($contract['event_id'])) {
            $event = $db->one('SELECT e.*, v.name AS venue_name FROM events e LEFT JOIN venues v ON v.id = e.venue_id WHERE e.id = ?', [(int) $contract['event_id']]);
        }
        if (!empty($contract['venue_id'])) {
            $venue = $db->one('SELECT * FROM venues WHERE id = ?', [(int) $contract['venue_id']]);
        } elseif ($event && !empty($event['venue_id'])) {
            $venue = $db->one('SELECT * FROM venues WHERE id = ?', [(int) $event['venue_id']]);
        }
        // If the event is booked into a specific room and that room has its
        // own address and/or contract name, stash them on $venue so
        // ContractRenderer::context() can prefer them over the venue's own
        // name/address — the only place these are read. Two rooms in the
        // same venue can be genuinely different buildings/addresses (e.g.
        // Mabuhay Gardens' upstairs room does business as "Broadway
        // Studios" at its own address) — see migrations 088 and 106.
        if ($venue !== null && $event && !empty($event['resource_id'])) {
            $room = $db->one('SELECT address, contract_name FROM resources WHERE id = ?', [(int) $event['resource_id']]);
            $venue['room_address'] = $room['address'] ?? null;
            $venue['room_name'] = $room['contract_name'] ?? null;
        }
        return [$event, $venue];
    }

    /**
     * (Re)build a contract's sections from a template, evaluating each module's
     * include_when condition against the current deal context. Required modules
     * are always included; condition modules are auto-selected when they match.
     */
    public static function buildSectionsFromTemplate(Database $db, int $contractId, int $templateId): void
    {
        $contract = $db->one('SELECT * FROM contracts WHERE id = ?', [$contractId]);
        if (!$contract) {
            return;
        }
        [$event, $venue] = self::eventVenueFor($db, $contract);
        $ctx = ContractRenderer::context($contract, $event, $venue);

        $rows = $db->all(
            'SELECT tm.is_required, tm.condition_json, tm.sort_order, m.*
             FROM contract_template_modules tm
             JOIN contract_modules m ON m.id = tm.module_id
             WHERE tm.template_id = ? AND m.is_active = 1
             ORDER BY tm.sort_order, m.sort_order',
            [$templateId]
        );

        $db->run('DELETE FROM contract_sections WHERE contract_id = ?', [$contractId]);

        $order = 0;
        foreach ($rows as $r) {
            $condition = !empty($r['condition_json']) ? json_decode((string) $r['condition_json'], true) : null;
            $required = (int) $r['is_required'] === 1;
            $included = $required ? true : ContractRenderer::evaluate($condition, $ctx['cond']);
            $auto = !$required && $condition !== null;
            $db->insert(
                'INSERT INTO contract_sections (contract_id, module_id, module_key, title, body_template, sort_order, included, is_locked, auto_selected, risk_level, required_fields_json)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $contractId, $r['id'], $r['module_key'], $r['name'], $r['body_template'],
                    $order++, $included ? 1 : 0, (int) $r['is_locked'], $auto ? 1 : 0,
                    $r['risk_level'], $r['required_fields_json'],
                ]
            );
        }
        $db->run('UPDATE contracts SET template_id = ? WHERE id = ?', [$templateId, $contractId]);
    }

    /**
     * Re-run smart selection on auto-selected sections only, leaving manually
     * toggled sections alone. Uses each section's originating template-module
     * condition. Returns the number of sections whose inclusion changed.
     */
    public static function reevaluate(Database $db, array $contract): int
    {
        if (empty($contract['template_id'])) {
            return 0;
        }
        [$event, $venue] = self::eventVenueFor($db, $contract);
        $ctx = ContractRenderer::context($contract, $event, $venue);

        // module_id => condition_json from the template wiring
        $conditions = [];
        foreach ($db->all('SELECT module_id, is_required, condition_json FROM contract_template_modules WHERE template_id = ?', [(int) $contract['template_id']]) as $w) {
            $conditions[(int) $w['module_id']] = [
                'required' => (int) $w['is_required'] === 1,
                'condition' => !empty($w['condition_json']) ? json_decode((string) $w['condition_json'], true) : null,
            ];
        }

        $changed = 0;
        $sections = $db->all('SELECT id, module_id, included, auto_selected FROM contract_sections WHERE contract_id = ?', [(int) $contract['id']]);
        foreach ($sections as $s) {
            if ((int) $s['auto_selected'] !== 1 || empty($s['module_id'])) {
                continue;
            }
            $wiring = $conditions[(int) $s['module_id']] ?? null;
            if (!$wiring || $wiring['required']) {
                continue;
            }
            $shouldInclude = ContractRenderer::evaluate($wiring['condition'], $ctx['cond']) ? 1 : 0;
            if ($shouldInclude !== (int) $s['included']) {
                $db->run('UPDATE contract_sections SET included = ? WHERE id = ?', [$shouldInclude, (int) $s['id']]);
                $changed++;
            }
        }
        return $changed;
    }
}
