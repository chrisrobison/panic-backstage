<?php
declare(strict_types=1);

namespace Panic\Opportunities\Research;

use Panic\Ai\ClaudeCli;
use Panic\Database;

use function Panic\log_opportunity_activity;

/**
 * Background-worker glue for one `opportunity_research_jobs` row — called
 * only by src/Jobs/JobWorker.php's `opportunity_research` case, never on a
 * public request thread (see Jobs.php's docblock: "Do not perform long
 * research synchronously").
 *
 * Loads the job's scope record (conference/company, if any) straight from
 * our own database — never from the model — builds the mode's prompt
 * (Modes::buildPrompt(), pure), spawns the Claude CLI with WebSearch/
 * WebFetch enabled and nothing else (Ai\ClaudeCli::promptWithTools()),
 * validates the reply (Modes::validateResult() — untrusted external input,
 * never trusted directly), and persists status/result/error.
 *
 * Idempotent / safe to re-run: a `completed` job is a no-op (worker
 * restarts or a duplicate background_jobs dispatch can't double-charge
 * usage or overwrite a good result with a worse retry), and every attempt
 * resets the row to `processing` before doing any work. On ANY failure
 * (subprocess/timeout, malformed JSON, a structural validation failure) the
 * row is marked `failed` with a human-readable `error` — and the exception
 * is then rethrown so JobQueue's own bounded exponential backoff (see
 * Jobs::create()'s `max_attempts = 2`) decides whether to retry, mirroring
 * Leads\PublicInquiryFollowup's "throw on real failure, let the queue's own
 * retry policy own the decision" convention rather than swallowing errors
 * here.
 */
final class Runner
{
    private const ALLOWED_TOOLS = ['WebSearch', 'WebFetch'];

    /**
     * Stateless — no constructor/instance state needed (unlike e.g.
     * Leads\PublicInquiryFollowup, which takes $root to hand to a
     * collaborator). Static so JobWorker doesn't need to instantiate this
     * just to call one method.
     */
    public static function run(Database $db, int $researchJobId): void
    {
        $job = $db->one('SELECT * FROM opportunity_research_jobs WHERE id = ?', [$researchJobId]);
        if ($job === null) {
            // Its scope row (conference/company) cascaded it away since it
            // was enqueued — an obsolete job, not a poison one.
            return;
        }
        if ($job['status'] === 'completed') {
            return;
        }

        $db->run("UPDATE opportunity_research_jobs SET status = 'processing' WHERE id = ?", [$researchJobId]);

        try {
            $jobType = (string) $job['job_type'];
            $input   = json_decode((string) ($job['input_json'] ?? '') ?: '{}', true) ?: [];
            $scope   = self::loadScope($db, $jobType, $job);

            [$system, $user] = Modes::buildPrompt($jobType, $input, $scope);

            $model   = trim((string) (getenv('OPPORTUNITY_RESEARCH_MODEL') ?: '')) ?: 'sonnet';
            $timeout = (int) (getenv('OPPORTUNITY_RESEARCH_TIMEOUT_SECONDS') ?: 240);
            if ($timeout <= 0) {
                $timeout = 240;
            }
            $maxResults = (int) (getenv('OPPORTUNITY_RESEARCH_MAX_RESULTS') ?: 25);
            $maxBudget  = trim((string) (getenv('AI_ASSISTANT_MAX_BUDGET_USD') ?: ''));

            $outcome = ClaudeCli::promptWithTools(
                $system,
                $user,
                self::ALLOWED_TOOLS,
                $model,
                $timeout,
                $maxBudget !== '' ? (float) $maxBudget : null
            );
            if (!$outcome['ok']) {
                throw new \RuntimeException((string) $outcome['error']);
            }

            $decoded = self::extractJsonObject((string) $outcome['result']);
            if ($decoded === null) {
                throw new \RuntimeException('The AI did not return a valid JSON object.');
            }

            $validated = Modes::validateResult($jobType, $decoded, $maxResults > 0 ? $maxResults : 25);

            $db->run(
                "UPDATE opportunity_research_jobs
                 SET status = 'completed', result_json = ?, error = NULL, completed_at = NOW()
                 WHERE id = ?",
                [json_encode($validated, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $researchJobId]
            );

            if (!empty($job['opportunity_id'])) {
                log_opportunity_activity($db, (int) $job['opportunity_id'], null, 'research_completed', ['job_type' => $jobType]);
            }
        } catch (\Throwable $e) {
            $db->run(
                "UPDATE opportunity_research_jobs SET status = 'failed', error = ?, completed_at = NOW() WHERE id = ?",
                [mb_substr($e->getMessage(), 0, 2000), $researchJobId]
            );
            throw $e;
        }
    }

    /**
     * Real, authoritative context for the prompt — always read from our own
     * DB, never accepted as free-form request input (the model must never
     * be able to smuggle a fake "our own conference/company data" fact into
     * its own prompt). discover_conferences additionally gets this tenant's
     * own venue name/city so "relevant to us" has a real anchor.
     *
     * @return array<string,mixed>
     */
    private static function loadScope(Database $db, string $jobType, array $job): array
    {
        $scope = [];

        if ($jobType === 'discover_conferences') {
            $venue = $db->one('SELECT name, city FROM venues LIMIT 1');
            $scope['venue_name'] = $venue['name'] ?? null;
            $scope['venue_city'] = $venue['city'] ?? null;
            return $scope;
        }

        if (!empty($job['conference_id'])) {
            $conference = $db->one(
                'SELECT name, starts_at, ends_at, city, state, website_url FROM opportunity_conferences WHERE id = ?',
                [(int) $job['conference_id']]
            );
            if ($conference) {
                $scope = array_merge($scope, [
                    'conference_name' => $conference['name'],
                    'name'            => $conference['name'],
                    'starts_at'       => $conference['starts_at'],
                    'ends_at'         => $conference['ends_at'],
                    'city'            => $conference['city'],
                    'state'           => $conference['state'],
                    'website_url'     => $conference['website_url'],
                ]);
            }
        }

        if (!empty($job['company_id'])) {
            $company = $db->one(
                'SELECT name, domain, website_url, industry, hq_city, hq_state FROM opportunity_companies WHERE id = ?',
                [(int) $job['company_id']]
            );
            if ($company) {
                $scope = array_merge($scope, [
                    'company_name' => $company['name'],
                    // research_company's prompt/context-block reads a bare
                    // `name` when there's no conference also in scope.
                    'name'         => $scope['name'] ?? $company['name'],
                    'domain'       => $company['domain'],
                    // Don't let a company's own website_url clobber a
                    // conference's, when both are present (generate_outreach_angles).
                    'website_url'  => $scope['website_url'] ?? $company['website_url'],
                    'industry'     => $company['industry'],
                    'hq_city'      => $company['hq_city'],
                    'hq_state'     => $company['hq_state'],
                ]);
            }
        }

        return $scope;
    }

    /**
     * Pull the first top-level `{...}` block out of the model's reply —
     * lenient by necessity (the CLI has no schema-enforced output mode; see
     * Ai\ClaudeCli's class docblock for the same tradeoff), since the model
     * occasionally wraps otherwise-correct JSON in a sentence or code fence
     * despite being told not to. Returns null (never throws) on anything
     * that doesn't decode to a JSON object.
     *
     * @return array<string,mixed>|null
     */
    private static function extractJsonObject(string $text): ?array
    {
        if (!preg_match('/\{.*\}/s', trim($text), $m)) {
            return null;
        }
        $decoded = json_decode($m[0], true);
        return is_array($decoded) ? $decoded : null;
    }
}
