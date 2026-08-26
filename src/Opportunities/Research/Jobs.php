<?php
declare(strict_types=1);

namespace Panic\Opportunities\Research;

use Panic\BaseEndpoint;
use Panic\Jobs\JobQueue;
use Panic\RateLimiter;
use Panic\Request;
use Panic\Response;

use function Panic\log_opportunity_activity;

/**
 * Durable AI/web research jobs (Phase 7 — see
 * docs/OPPORTUNITIES-IMPLEMENTATION.md and the spec's "Do not perform long
 * research synchronously" section). A request here only ever creates a row
 * and enqueues a background job (src/Jobs/JobWorker.php ->
 * Opportunities\Research\Runner) — the actual `claude` subprocess call
 * happens on the worker, never on this request thread, so a slow web-backed
 * research run can never hold a PHP-FPM worker open.
 *
 *   POST /api/opportunity-research/jobs             enqueue a research job
 *   GET  /api/opportunity-research/jobs             list (filterable)
 *   GET  /api/opportunity-research/jobs/{id}         status + result
 *   POST /api/opportunity-research/jobs/{id}/import  human-reviewed import
 *                                                     of selected result
 *                                                     items into real CRM rows
 *
 * Capabilities: research_opportunities (enqueue — gates who can spend AI/web
 * research usage at all), view_opportunities (read job status/results),
 * manage_opportunities (import — turning reviewed AI output into trusted
 * CRM data is an ordinary write, same gate every other Opportunities write
 * uses).
 *
 * "Research must not silently populate trusted CRM data" (spec) — a
 * completed job's result_json is just a proposal for a human to review;
 * only import() ever writes it into opportunity_conferences/companies/
 * signals/facts/contacts/notes, and only for the specific items a human
 * explicitly selected. See Importer::import() for exactly what each mode
 * imports as.
 */
final class Jobs extends BaseEndpoint
{
    private const RATE_LIMIT_MAX_PER_HOUR = 20;
    private const RATE_LIMIT_WINDOW_SECONDS = 3600;

    public function handle(Request $request): Response
    {
        $jobId  = $this->params['jobId'] ?? null;
        $action = $this->params['action'] ?? null;

        if ($jobId !== null && $action === 'import') {
            return $request->method() === 'POST' ? $this->import($request, (int) $jobId) : Response::methodNotAllowed();
        }
        if ($jobId !== null) {
            return $request->method() === 'GET' ? $this->show((int) $jobId) : Response::methodNotAllowed();
        }
        return match ($request->method()) {
            'GET'   => $this->index($request),
            'POST'  => $this->create($request),
            default => Response::methodNotAllowed(),
        };
    }

    private function create(Request $request): Response
    {
        if ($denied = $this->requireGlobalCapability('research_opportunities')) {
            return $denied;
        }
        if (getenv('OPPORTUNITY_RESEARCH_ENABLED') === '0') {
            return Response::json(['error' => 'AI research is disabled on this deployment.'], 503);
        }

        $userId = $this->userId();
        if (RateLimiter::tooMany($this->db, 'opp_research:user:' . $userId, self::RATE_LIMIT_MAX_PER_HOUR, self::RATE_LIMIT_WINDOW_SECONDS)) {
            return Response::json(['error' => 'Too many research requests — please wait a bit and try again.'], 429);
        }

        $b = $request->body();
        $jobType = (string) ($b['job_type'] ?? '');
        if (!Modes::isValidMode($jobType)) {
            return Response::json(['error' => 'Unknown job_type. Must be one of: ' . implode(', ', Modes::MODES)], 422);
        }

        $conferenceId = $this->intOrNull($b['conference_id'] ?? null);
        $companyId    = $this->intOrNull($b['company_id'] ?? null);
        $opportunityId = $this->intOrNull($b['opportunity_id'] ?? null);

        $scopeError = $this->validateScope($jobType, $conferenceId, $companyId);
        if ($scopeError !== null) {
            return Response::json(['error' => $scopeError], 422);
        }

        try {
            $input = Modes::validateInput($jobType, is_array($b['input'] ?? null) ? $b['input'] : []);
        } catch (\InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }

        // Dedup guard: don't let a double-click (or an impatient second
        // click while a slow web search is still running) enqueue two
        // identical jobs — same shape as DecisionMakers' duplicate-link
        // 409, just scoped to (job_type, conference_id, company_id).
        $existing = $this->db->one(
            "SELECT id FROM opportunity_research_jobs
             WHERE job_type = ? AND status IN ('pending','processing')
               AND conference_id <=> ? AND company_id <=> ? AND opportunity_id <=> ?
             LIMIT 1",
            [$jobType, $conferenceId, $companyId, $opportunityId]
        );
        if ($existing) {
            return Response::json(['error' => 'A research job of this type is already running for this record.', 'job_id' => (int) $existing['id']], 409);
        }

        $this->db->pdo()->beginTransaction();
        try {
            $jobId = $this->db->insert(
                'INSERT INTO opportunity_research_jobs
                    (job_type, status, conference_id, company_id, opportunity_id, input_json, requested_by)
                 VALUES (?, \'pending\', ?, ?, ?, ?, ?)',
                [$jobType, $conferenceId, $companyId, $opportunityId, json_encode($input, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $userId]
            );

            $backgroundJobId = (new JobQueue($this->db))->enqueue(
                'opportunity_research',
                ['research_job_id' => $jobId],
                'opp_research:' . $jobId,
                'default',
                2 // small max_attempts — a retried web-search burns real usage each time, unlike a cheap DB job
            );
            $this->db->run('UPDATE opportunity_research_jobs SET background_job_id = ? WHERE id = ?', [$backgroundJobId, $jobId]);

            $this->db->pdo()->commit();
        } catch (\Throwable $e) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            throw $e;
        }

        if ($opportunityId !== null) {
            log_opportunity_activity($this->db, $opportunityId, $userId, 'research_requested', ['job_type' => $jobType]);
        }

        return $this->ok(['job' => $this->hydrate($this->loadJob($jobId))]);
    }

    private function show(int $jobId): Response
    {
        if ($denied = $this->requireGlobalCapability('view_opportunities')) {
            return $denied;
        }
        $job = $this->loadJob($jobId);
        if ($job === null) {
            return $this->notFound('Research job not found');
        }
        return $this->ok(['job' => $this->hydrate($job)]);
    }

    private function index(Request $request): Response
    {
        if ($denied = $this->requireGlobalCapability('view_opportunities')) {
            return $denied;
        }
        $q = $request->query();
        $where  = [];
        $params = [];
        foreach (['conference_id', 'company_id', 'opportunity_id'] as $col) {
            $val = $this->intOrNull($q[$col] ?? null);
            if ($val !== null) {
                $where[]  = "$col = ?";
                $params[] = $val;
            }
        }
        if (!empty($q['job_type']) && Modes::isValidMode((string) $q['job_type'])) {
            $where[]  = 'job_type = ?';
            $params[] = (string) $q['job_type'];
        }
        if (!empty($q['status']) && in_array($q['status'], ['pending', 'processing', 'completed', 'failed'], true)) {
            $where[]  = 'status = ?';
            $params[] = (string) $q['status'];
        }
        $limit = max(1, min(100, (int) ($q['limit'] ?? 20)));

        $sql = 'SELECT * FROM opportunity_research_jobs';
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY id DESC LIMIT ' . $limit;

        $rows = array_map(fn(array $r) => $this->hydrate($r), $this->db->all($sql, $params));
        return $this->ok(['jobs' => $rows]);
    }

    private function import(Request $request, int $jobId): Response
    {
        if ($denied = $this->requireGlobalCapability('manage_opportunities')) {
            return $denied;
        }
        $job = $this->loadJob($jobId);
        if ($job === null) {
            return $this->notFound('Research job not found');
        }
        if ($job['status'] !== 'completed') {
            return Response::json(['error' => 'This research job has no completed results to import yet.'], 422);
        }

        $selections = $request->body();
        if (!is_array($selections)) {
            $selections = [];
        }

        $this->db->pdo()->beginTransaction();
        try {
            $summary = Importer::import($this->db, $job, $selections, $this->userId());
            $this->db->pdo()->commit();
        } catch (\InvalidArgumentException $e) {
            $this->db->pdo()->rollBack();
            return Response::json(['error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            throw $e;
        }

        if (!empty($job['opportunity_id'])) {
            log_opportunity_activity($this->db, (int) $job['opportunity_id'], $this->userId(), 'research_imported', ['job_type' => $job['job_type'], 'summary' => $summary]);
        }

        return $this->ok(['summary' => $summary, 'job' => $this->hydrate($this->loadJob($jobId))]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** null = no scope problem; non-null = a 422 error message. */
    private function validateScope(string $jobType, ?int $conferenceId, ?int $companyId): ?string
    {
        $scope = Modes::SCOPE[$jobType] ?? null;
        if ($scope === null) {
            return null;
        }
        if ($scope === 'conference' || $scope === 'conference_or_company') {
            if ($conferenceId !== null && !$this->db->one('SELECT id FROM opportunity_conferences WHERE id = ?', [$conferenceId])) {
                return 'conference_id does not refer to an existing conference.';
            }
        }
        if ($scope === 'company' || $scope === 'conference_or_company') {
            if ($companyId !== null && !$this->db->one('SELECT id FROM opportunity_companies WHERE id = ?', [$companyId])) {
                return 'company_id does not refer to an existing company.';
            }
        }
        if ($scope === 'conference' && $conferenceId === null) {
            return 'conference_id is required for this job_type.';
        }
        if ($scope === 'company' && $companyId === null) {
            return 'company_id is required for this job_type.';
        }
        if ($scope === 'conference_or_company' && $conferenceId === null && $companyId === null) {
            return 'Either conference_id or company_id is required for this job_type.';
        }
        return null;
    }

    private function loadJob(int $jobId): ?array
    {
        $job = $this->db->one('SELECT * FROM opportunity_research_jobs WHERE id = ?', [$jobId]);
        return $job ?: null;
    }

    /** Decode the two JSON text columns into real arrays for the API response, rather than making the client double-parse a JSON-in-JSON string. */
    private function hydrate(array $job): array
    {
        $job['input_json']  = json_decode((string) ($job['input_json'] ?? '') ?: '{}', true) ?: [];
        $job['result_json'] = $job['result_json'] !== null ? (json_decode((string) $job['result_json'], true) ?: null) : null;
        return $job;
    }

    private function intOrNull(mixed $value): ?int
    {
        return (is_numeric($value) && (int) $value > 0) ? (int) $value : null;
    }
}
