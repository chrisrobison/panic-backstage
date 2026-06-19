<?php
declare(strict_types=1);

namespace Panic;

/**
 * Wizard defaults — admin-configurable default field values for the Event
 * Creation Wizard. Stored as a single JSON blob (id = 1 singleton row).
 *
 *   GET  /api/wizard-defaults        return current defaults object
 *   PUT  /api/wizard-defaults        replace defaults object
 *
 * Gated by manage_users (venue_admin only).
 * The GET /api/templates response also embeds these so the wizard can load
 * its metadata and defaults in one round-trip.
 */
final class WizardDefaults extends BaseEndpoint
{
    /** Fields that may be set as defaults. Unknown keys are silently dropped. */
    private const ALLOWED = [
        'venue_id',
        'event_type',
        'doors_time',
        'show_time',
        'end_time',
        'age_restriction',
        'capacity',
        'deal_type',
        'deposit_amount',
        'bar_minimum',
        'merch_venue_percent',
        'sound_tech_included',
        'lighting_tech_included',
        'security_count',
        'security_rate',
        'security_paid_by',
    ];

    public function handle(Request $request): Response
    {
        if ($denied = $this->requireGlobalCapability('manage_users')) {
            return $denied;
        }

        return match ($request->method()) {
            'GET'   => $this->get(),
            'PUT'   => $this->put($request),
            default => Response::methodNotAllowed(),
        };
    }

    private function get(): Response
    {
        $row      = $this->db->one('SELECT defaults_json FROM wizard_defaults WHERE id = 1');
        $defaults = $row ? (json_decode((string) ($row['defaults_json'] ?? '{}'), true) ?? []) : [];
        return $this->ok(['defaults' => $defaults]);
    }

    private function put(Request $request): Response
    {
        $incoming = $request->body('defaults', []);
        if (!is_array($incoming)) {
            return Response::json(['error' => 'defaults must be an object'], 422);
        }

        // Keep only known field IDs; cast all values to strings (matching
        // how the wizard stores wizardData values).
        $clean = [];
        foreach (self::ALLOWED as $key) {
            if (!array_key_exists($key, $incoming)) {
                continue;
            }
            $val = $incoming[$key];
            // Empty string / null = "no default for this field" → omit from stored JSON.
            if ($val === null || $val === '') {
                continue;
            }
            $clean[$key] = (string) $val;
        }

        $json = json_encode($clean, JSON_UNESCAPED_UNICODE);
        $this->db->run(
            'INSERT INTO wizard_defaults (id, defaults_json)
             VALUES (1, ?)
             ON DUPLICATE KEY UPDATE defaults_json = ?',
            [$json, $json]
        );

        return $this->ok(['defaults' => $clean]);
    }
}
