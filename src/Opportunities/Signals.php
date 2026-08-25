<?php
declare(strict_types=1);

namespace Panic\Opportunities;

use Panic\BaseEndpoint;
use Panic\Request;
use Panic\Response;

use function Panic\boolish;
use function Panic\log_opportunity_activity;

/**
 * Research/buying signals — evidence that makes a prospect more or less
 * attractive (sponsor tier, hiring activity, proximity, prior hospitality
 * history, ...). Scoped to exactly one of a conference, a company, or an
 * opportunity per request; the scope comes from which nested route Kernel
 * dispatched through (src/Kernel.php):
 *
 *   GET/POST /api/opportunities/{id}/signals
 *   GET/POST /api/opportunity-conferences/{id}/signals
 *   GET/POST /api/opportunity-companies/{id}/signals
 *
 * Mostly read + create for now (Phase 1); scoring (Phase 8) reads `weight`/
 * `confidence` off these rows rather than recomputing anything itself.
 *
 * Capabilities: view_opportunities (read), manage_opportunities (write).
 */
final class Signals extends BaseEndpoint
{
    public const SIGNAL_TYPES = [
        'proximity', 'availability', 'sponsorship', 'exhibitor',
        'hospitality_history', 'side_event_history', 'hiring',
        'company_size', 'speaking', 'budget', 'other',
    ];
    public const CONFIDENCE_LEVELS = ['low', 'medium', 'high'];

    private const SCOPE_COLUMNS = [
        'opportunity' => ['opportunity_id', 'opportunities'],
        'conference'  => ['conference_id', 'opportunity_conferences'],
        'company'     => ['company_id', 'opportunity_companies'],
    ];

    public function handle(Request $request): Response
    {
        $scopeType = (string) ($this->params['scopeType'] ?? '');
        $scopeId   = isset($this->params['scopeId']) ? (int) $this->params['scopeId'] : 0;

        if (!isset(self::SCOPE_COLUMNS[$scopeType]) || $scopeId <= 0) {
            return Response::json(['error' => 'Unknown signal scope'], 404);
        }

        return match ($request->method()) {
            'GET'   => $this->index($scopeType, $scopeId),
            'POST'  => $this->create($request, $scopeType, $scopeId),
            default => Response::methodNotAllowed(),
        };
    }

    private function index(string $scopeType, int $scopeId): Response
    {
        if ($denied = $this->requireGlobalCapability('view_opportunities')) {
            return $denied;
        }

        [$column, $table] = self::SCOPE_COLUMNS[$scopeType];
        if (!$this->db->one("SELECT id FROM `$table` WHERE id = ?", [$scopeId])) {
            return $this->notFound(ucfirst($scopeType) . ' not found');
        }

        $signals = $this->db->all(
            "SELECT s.*, u.name AS created_by_name FROM opportunity_signals s
             LEFT JOIN users u ON u.id = s.created_by
             WHERE s.`$column` = ?
             ORDER BY s.observed_at IS NULL, s.observed_at DESC, s.created_at DESC",
            [$scopeId]
        );

        return $this->ok(['signals' => $signals, 'signal_types' => self::SIGNAL_TYPES]);
    }

    private function create(Request $request, string $scopeType, int $scopeId): Response
    {
        if ($denied = $this->requireGlobalCapability('manage_opportunities')) {
            return $denied;
        }

        [$column, $table] = self::SCOPE_COLUMNS[$scopeType];
        if (!$this->db->one("SELECT id FROM `$table` WHERE id = ?", [$scopeId])) {
            return $this->notFound(ucfirst($scopeType) . ' not found');
        }

        $b = $request->body();
        $description = trim((string) ($b['description'] ?? ''));
        if ($description === '') {
            return Response::json(['error' => 'description is required'], 422);
        }

        $signalType = (string) ($b['signal_type'] ?? 'other');
        if (!in_array($signalType, self::SIGNAL_TYPES, true)) {
            return Response::json(['error' => 'Invalid signal_type'], 422);
        }
        $confidence = (string) ($b['confidence'] ?? 'medium');
        if (!in_array($confidence, self::CONFIDENCE_LEVELS, true)) {
            return Response::json(['error' => 'Invalid confidence'], 422);
        }

        $columns = ['signal_type', 'description', 'weight', 'confidence', 'source_url', 'source_title', 'observed_at', 'is_ai_generated', 'created_by', $column];
        $id = $this->db->insert(
            'INSERT INTO opportunity_signals (`' . implode('`,`', $columns) . '`) VALUES (?,?,?,?,?,?,?,?,?,?)',
            [
                $signalType,
                $description,
                isset($b['weight']) && $b['weight'] !== '' ? (float) $b['weight'] : null,
                $confidence,
                $b['source_url']   ?? null,
                $b['source_title'] ?? null,
                isset($b['observed_at']) && $b['observed_at'] !== '' ? (string) $b['observed_at'] : null,
                boolish($b['is_ai_generated'] ?? false),
                $this->userId(),
                $scopeId,
            ]
        );

        if ($scopeType === 'opportunity') {
            log_opportunity_activity($this->db, $scopeId, $this->userId(), 'signal_added', ['signal_type' => $signalType]);
        }

        return $this->ok(['signal' => $this->db->one('SELECT * FROM opportunity_signals WHERE id = ?', [$id])]);
    }
}
