<?php
declare(strict_types=1);

namespace Panic\Ai;

/**
 * Shared helper for spawning the local `claude` CLI headless for a single,
 * tool-less prompt — used by features that just want one text/JSON reply
 * (Leads\Classifier, LeadEmailParser) rather than a full multi-turn,
 * tool-using session. This rides the CLI's own logged-in OAuth/subscription
 * session on the box, the same as Ai\Assistant::runClaude() does for the AI
 * Assistant drawer, so classification/extraction costs nothing beyond the
 * shared subscription quota instead of metered per-token API billing.
 *
 * Ai\Assistant.php doesn't use this class — it needs MCP tools and replayed
 * conversation history that this helper deliberately doesn't support — but
 * both spawn the same binary the same guarded way (env-stripped, hard
 * timeout, temp-dir hygiene); see runClaude()'s docblock there for the
 * reasoning behind each flag, mirrored here.
 *
 * No API-key path: unlike the Messages API's `output_config.format.
 * json_schema`, the CLI has no schema-enforced output mode, so JSON
 * conformance here is "ask nicely in the system prompt + parse leniently"
 * rather than guaranteed — callers should already treat a malformed/partial
 * reply as "unclassified" (see Classifier::normalizeSentinels() and its
 * array_intersect_key() over FIELDS), which was already true for the old
 * direct-API path too since the model could still return something
 * schema-adjacent-but-not-quite.
 *
 * Operational note: this spawns `claude` as whatever OS user the calling
 * PHP process runs as. The OAuth session lives in that user's own
 * ~/.claude/.credentials.json (mode 600, owner-only) — a process running as
 * a *different* user (e.g. a web server pool user) will find the binary but
 * fail to authenticate, and isAvailable()/promptJson() will degrade to
 * false/null exactly as if the CLI weren't installed at all. Confirm the
 * process actually invoking this runs as the same user that ran `claude
 * login` before relying on it.
 *
 * In production this class runs as www-data (PHP-FPM), which is a member of
 * the cdr group, so `chmod g+r ~/.claude/.credentials.json` is enough to let
 * it read cdr's session — but the CLI resets that file back to mode 600 on
 * every OAuth token refresh, so the fix has to be re-applied continuously,
 * not once. scripts/cron-fix-claude-cli-perms.sh does that (runs every
 * minute as cdr, since a www-data-owned process can't chmod a file it
 * doesn't own). See Ai\Assistant::runClaude()'s docblock for the full
 * writeup — this class hit the identical failure mode.
 */
final class ClaudeCli
{
    private const DEFAULT_TIMEOUT_SECONDS = 60;

    /**
     * Whether the configured `claude` binary exists and is executable.
     * Doesn't (can't, cheaply) verify the OAuth session itself is valid for
     * the current OS user — see the class docblock's operational note —
     * just that there's a binary worth trying.
     */
    public static function isAvailable(): bool
    {
        return self::binPath() !== null;
    }

    /**
     * Run a single-shot headless prompt and return the assistant's raw
     * reply text, or null on any failure (missing binary, timeout, non-zero
     * exit, malformed output-envelope) — callers should treat null exactly
     * like "AI unavailable right now", never surface it as a user-facing
     * error.
     */
    public static function prompt(string $system, string $user, ?string $model = null, ?int $timeoutSeconds = null): ?string
    {
        $bin = self::binPath();
        if ($bin === null) {
            return null;
        }
        $model = $model ?: 'opus';
        $timeoutSeconds = ($timeoutSeconds !== null && $timeoutSeconds > 0) ? $timeoutSeconds : self::DEFAULT_TIMEOUT_SECONDS;

        $tmpDir = sys_get_temp_dir() . '/pb-ai-' . bin2hex(random_bytes(6));
        if (!mkdir($tmpDir, 0700, true) && !is_dir($tmpDir)) {
            return null;
        }

        try {
            $systemPromptPath = $tmpDir . '/system-prompt.txt';
            file_put_contents($systemPromptPath, $system);

            // See Ai\Assistant::runClaude()'s docblock for why each of these
            // is necessary: `env -u` keeps any app-configured
            // ANTHROPIC_API_KEY from silently flipping this CLI call from
            // OAuth/subscription auth to metered billing (and corrupting
            // the JSON stdout stream with a warning line); `timeout
            // --signal=KILL` gives a real process-level hard timeout;
            // `--tools ''` + no --mcp-config keeps this call tool-less
            // (nothing here should ever act, only answer); `< /dev/null`
            // avoids the ~3s stdin-wait stall the CLI otherwise does when
            // spawned from a PHP process that leaves stdin open.
            //
            // HOME=self::homeDir() is pinned explicitly rather than left to
            // the child's inherited environment: when PHP is invoked by
            // PHP-FPM (pool user e.g. www-data), the worker's HOME is
            // unset/wrong for finding the OAuth session at
            // ~/.claude/.credentials.json — the CLI falls back to
            // os.homedir(), which resolves off the *process's own UID*
            // (www-data -> /var/www, not /home/cdr where `claude login` was
            // actually run). Confirmed by hand: with HOME unset, this
            // silently "succeeds" at the process level but authenticates as
            // nobody, returning `is_error: true` with zero token usage
            // rather than a loud failure.
            $cmd = 'env -u ANTHROPIC_API_KEY -u ANTHROPIC_API_KEY_FILE'
                 . ' HOME=' . escapeshellarg(self::homeDir())
                 . ' timeout --signal=KILL ' . escapeshellarg($timeoutSeconds . 's')
                 . ' ' . escapeshellarg($bin)
                 . ' -p ' . escapeshellarg($user)
                 . ' --output-format json'
                 . ' --no-session-persistence'
                 . ' --tools ' . escapeshellarg('')
                 . ' --permission-mode bypassPermissions'
                 . ' --append-system-prompt-file ' . escapeshellarg($systemPromptPath)
                 . ' --model ' . escapeshellarg($model)
                 . ' < /dev/null 2>&1';

            exec($cmd, $lines, $exitCode);
            if ($exitCode !== 0) {
                return null;
            }

            $decoded = json_decode(implode("\n", $lines), true);
            if (!is_array($decoded) || !isset($decoded['result']) || !is_string($decoded['result']) || !empty($decoded['is_error'])) {
                return null;
            }

            return $decoded['result'];
        } finally {
            self::rrmdir($tmpDir);
        }
    }

    /**
     * Run a single-shot prompt expecting a JSON object reply, and return it
     * decoded — or null on any failure. Lenient by necessity (see the class
     * docblock's "No API-key path" note): pulls out the first top-level
     * `{...}` block rather than requiring the whole reply to be bare JSON,
     * since the model occasionally wraps it in a sentence or a ```json
     * fence despite being told not to.
     *
     * @return array<string,mixed>|null
     */
    public static function promptJson(string $system, string $user, ?string $model = null, ?int $timeoutSeconds = null): ?array
    {
        $text = self::prompt($system, $user, $model, $timeoutSeconds);
        if ($text === null) {
            return null;
        }
        if (!preg_match('/\{.*\}/s', trim($text), $m)) {
            return null;
        }
        $parsed = json_decode($m[0], true);
        return is_array($parsed) ? $parsed : null;
    }

    /**
     * Run a single-shot headless prompt with a specific, narrow set of
     * built-in tools enabled (e.g. ['WebSearch', 'WebFetch']) instead of the
     * fully tool-less mode prompt()/promptJson() use above. Added for
     * Opportunities' AI research jobs (src/Opportunities/Research/Runner.php,
     * Phase 7 — see docs/OPPORTUNITIES-IMPLEMENTATION.md and the spec's
     * "Security model" section) rather than copying runClaude()'s subprocess
     * plumbing a second time.
     *
     * Same guardrails as prompt(): env-stripped, hard `timeout --signal=KILL`,
     * isolated 0700 temp dir cleaned up in `finally`, HOME pinned, no session
     * persistence, JSON output, stdin from /dev/null. Additionally passes
     * `--mcp-config`+`--strict-mcp-config` with an EMPTY server list — no MCP
     * server is actually needed for web research, but this guarantees the
     * model has zero MCP tools available on top of `--tools` only ever
     * naming the specific built-ins the caller allowlists — belt and
     * suspenders per the spec's explicit "strict MCP configuration" bullet.
     *
     * Unlike prompt(), which collapses every failure to a bare null (its
     * callers only ever need "AI unavailable, degrade quietly"), this
     * returns a small result shape so a failure can be surfaced to a human
     * as a specific, useful error message (a durable `opportunity_research_
     * jobs.error` column, not a silent degrade) — see the spec's "bad/
     * invalid model JSON fails safely" acceptance criterion.
     *
     * @param list<string> $allowedTools e.g. ['WebSearch', 'WebFetch'] — never empty, never a shell/file/edit tool.
     * @return array{ok: bool, result: ?string, error: ?string}
     */
    public static function promptWithTools(
        string $system,
        string $user,
        array $allowedTools,
        ?string $model = null,
        ?int $timeoutSeconds = null,
        ?float $maxBudgetUsd = null
    ): array {
        if (!$allowedTools) {
            return ['ok' => false, 'result' => null, 'error' => 'No tools were allowed for this request.'];
        }
        $bin = self::binPath();
        if ($bin === null) {
            return ['ok' => false, 'result' => null, 'error' => 'The AI research feature is not available on this deployment (Claude CLI not found).'];
        }
        $model = $model ?: 'sonnet';
        $timeoutSeconds = ($timeoutSeconds !== null && $timeoutSeconds > 0) ? $timeoutSeconds : self::DEFAULT_TIMEOUT_SECONDS;

        $tmpDir = sys_get_temp_dir() . '/pb-ai-research-' . bin2hex(random_bytes(6));
        if (!mkdir($tmpDir, 0700, true) && !is_dir($tmpDir)) {
            return ['ok' => false, 'result' => null, 'error' => 'Could not prepare the research request.'];
        }

        try {
            $systemPromptPath = $tmpDir . '/system-prompt.txt';
            $mcpConfigPath    = $tmpDir . '/mcp-config.json';
            file_put_contents($systemPromptPath, $system);
            // Empty mcpServers object (not array) — matches the shape
            // `claude` itself expects for "no servers configured".
            file_put_contents($mcpConfigPath, json_encode(['mcpServers' => new \stdClass()], JSON_PRETTY_PRINT));

            // See prompt()'s docblock above for why each flag below is
            // necessary — identical reasoning, just with a non-empty
            // --tools list and an (empty) --mcp-config/--strict-mcp-config
            // pair added on top.
            $cmd = 'env -u ANTHROPIC_API_KEY -u ANTHROPIC_API_KEY_FILE'
                 . ' HOME=' . escapeshellarg(self::homeDir())
                 . ' timeout --signal=KILL ' . escapeshellarg($timeoutSeconds . 's')
                 . ' ' . escapeshellarg($bin)
                 . ' -p ' . escapeshellarg($user)
                 . ' --output-format json'
                 . ' --no-session-persistence'
                 . ' --tools ' . escapeshellarg(implode(',', $allowedTools))
                 . ' --mcp-config ' . escapeshellarg($mcpConfigPath)
                 . ' --strict-mcp-config'
                 . ' --permission-mode bypassPermissions'
                 . ' --append-system-prompt-file ' . escapeshellarg($systemPromptPath)
                 . ' --model ' . escapeshellarg($model);
            if ($maxBudgetUsd !== null && $maxBudgetUsd > 0) {
                $cmd .= ' --max-budget-usd ' . escapeshellarg((string) $maxBudgetUsd);
            }
            $cmd .= ' < /dev/null 2>&1';

            exec($cmd, $lines, $exitCode);
            $output = implode("\n", $lines);

            if ($exitCode !== 0) {
                if ($exitCode === 137 || $exitCode === 124) {
                    return ['ok' => false, 'result' => null, 'error' => 'The research request took too long and was stopped.'];
                }
                return ['ok' => false, 'result' => null, 'error' => 'The research request failed: ' . mb_substr($output, 0, 500)];
            }

            $decoded = json_decode($output, true);
            if (!is_array($decoded) || !isset($decoded['result']) || !is_string($decoded['result'])) {
                return ['ok' => false, 'result' => null, 'error' => 'The AI returned an unexpected response envelope.'];
            }
            if (!empty($decoded['is_error'])) {
                return ['ok' => false, 'result' => null, 'error' => 'The AI reported an error: ' . mb_substr($decoded['result'], 0, 500)];
            }

            return ['ok' => true, 'result' => $decoded['result'], 'error' => null];
        } finally {
            self::rrmdir($tmpDir);
        }
    }

    /** Absolute path to the `claude` binary, or null if unset/not executable. */
    private static function binPath(): ?string
    {
        $bin = trim((string) (getenv('CLAUDE_CLI_BIN') ?: '/home/cdr/.local/bin/claude'));
        return ($bin !== '' && is_executable($bin)) ? $bin : null;
    }

    /** Home directory of the OS user that ran `claude login` — see the HOME= note above. */
    private static function homeDir(): string
    {
        return trim((string) (getenv('CLAUDE_CLI_HOME') ?: '/home/cdr'));
    }

    /** Recursively delete a directory and all its contents. Mirrors Ai\Assistant::rrmdir(). */
    private static function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }
}
