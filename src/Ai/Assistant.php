<?php
declare(strict_types=1);

namespace Panic\Ai;

use Panic\BaseEndpoint;
use Panic\Capabilities;
use Panic\Events\Series;
use Panic\RateLimiter;
use Panic\Request;
use Panic\Response;
use function Panic\log_activity;

/**
 * The AI Assistant drawer's backend.
 *
 *   POST   /api/ai/ask                   {conversation_id?, event_id?, message}
 *                                         -> {conversation_id, reply, proposal?}
 *   POST   /api/ai/proposals/{id}/apply  -> executes a stored, not-yet-expired
 *                                         proposal — human-clicked only, never
 *                                         reachable from the model itself.
 *   DELETE /api/ai/proposals/{id}        -> discards a pending proposal.
 *
 * The model gets a fixed MCP tool set (see scripts/ai-mcp-server.php), never
 * Claude Code's own Bash/Read/Write/Edit tools, and never any MCP server but
 * ours. Read tools (get_event, list_events) return data directly. Write
 * tools (propose_booker_update, propose_recurring_series) only ever INSERT a
 * row into ai_action_proposals describing a computed diff — there is no
 * apply_* tool the model can call. Applying is this class's applyProposal(),
 * reachable only via the POST /api/ai/proposals/{id}/apply route above,
 * which a human triggers by clicking "Apply" on the diff card in the
 * drawer. See docs/AI-ASSISTANT-PLAN.md, "Guardrails, restated".
 *
 * Spawns `claude -p ...` per request, in a scoped temp dir cleaned up in
 * `finally`, modeled directly on Events\GenerateFlyer::runCodex() (escape
 * every variable shell argument, hard timeout, temp-dir hygiene).
 */
final class Assistant extends BaseEndpoint
{
    /** Bucket: ai_ask:user:{id} — bounds runaway usage against the shared subscription quota, not a dollar cost. */
    private const RATE_LIMIT_MAX_PER_HOUR = 30;
    private const RATE_LIMIT_WINDOW_SECONDS = 3600;

    /** Bucket: ai_apply:user:{id} — a tighter cap than ai_ask since this bucket gates actual writes. */
    private const APPLY_RATE_LIMIT_MAX_PER_HOUR = 10;

    /** Reject absurdly long prompts before ever spawning a subprocess. */
    private const MAX_MESSAGE_LENGTH = 4000;

    private const ALLOWED_TOOLS = [
        'mcp__panic__get_event',
        'mcp__panic__list_events',
        'mcp__panic__propose_booker_update',
        'mcp__panic__propose_recurring_series',
    ];

    public function handle(Request $request): Response
    {
        if ($denied = $this->requireAuth()) {
            return $denied;
        }
        if ($denied = $this->requireGlobalCapability('use_ai_assistant')) {
            return $denied;
        }

        $action = $this->params['action'] ?? null;
        $id     = $this->params['id'] ?? null;
        $sub    = $this->params['sub'] ?? null;

        if ($action === 'ask') {
            return $request->method() === 'POST' ? $this->ask($request) : Response::methodNotAllowed();
        }
        if ($action === 'proposals' && $id !== null) {
            if ($sub === 'apply') {
                return $request->method() === 'POST' ? $this->applyProposal((int) $id) : Response::methodNotAllowed();
            }
            if ($sub === null) {
                return $request->method() === 'DELETE' ? $this->discardProposal((int) $id) : Response::methodNotAllowed();
            }
        }

        return $this->notFound();
    }

    private function ask(Request $request): Response
    {
        $userId = $this->userId();
        $role   = $this->role();

        if (RateLimiter::tooMany($this->db, 'ai_ask:user:' . $userId, self::RATE_LIMIT_MAX_PER_HOUR, self::RATE_LIMIT_WINDOW_SECONDS)) {
            return Response::json(['error' => 'Too many AI requests — please wait a bit and try again.'], 429);
        }

        $message = trim((string) ($request->body('message') ?? ''));
        if ($message === '') {
            return Response::json(['error' => 'message is required'], 422);
        }
        if (mb_strlen($message) > self::MAX_MESSAGE_LENGTH) {
            return Response::json(['error' => 'message is too long (max ' . self::MAX_MESSAGE_LENGTH . ' characters)'], 422);
        }

        $conversationId = $this->intOrNull($request->body('conversation_id'));
        $eventIdInput   = $this->intOrNull($request->body('event_id'));

        // Resolve + authorize the event context (if any) before touching the
        // conversation row — a user can't scope a conversation, new or
        // existing, to an event they can't read.
        $eventContext = null;
        if ($eventIdInput !== null) {
            if (!Capabilities::hasEvent($this->db, $eventIdInput, $userId, $role, 'read_event')) {
                return $this->forbidden('No access to that event.');
            }
            $eventContext = EventContext::curated($this->db, $eventIdInput);
        }

        $effectiveEventId = $eventIdInput;

        if ($conversationId !== null) {
            $conversation = $this->db->one(
                'SELECT id, user_id, event_id FROM ai_conversations WHERE id = ?',
                [$conversationId]
            );
            if (!$conversation || (int) $conversation['user_id'] !== $userId) {
                return $this->notFound('Conversation not found.');
            }
            if ($effectiveEventId === null && $conversation['event_id'] !== null) {
                // This turn didn't pass a fresh event_id, but the conversation
                // carried one from before — re-check access (it could have
                // changed since) rather than trusting the stored row blindly.
                $priorEventId = (int) $conversation['event_id'];
                if (Capabilities::hasEvent($this->db, $priorEventId, $userId, $role, 'read_event')) {
                    $effectiveEventId = $priorEventId;
                    $eventContext = EventContext::curated($this->db, $priorEventId);
                }
                // else: access was revoked since — silently degrade to no
                // event context rather than error the whole conversation out.
            }
        } else {
            $conversationId = $this->db->insert(
                'INSERT INTO ai_conversations (user_id, event_id) VALUES (?, ?)',
                [$userId, $eventIdInput]
            );
        }

        $this->db->run(
            "INSERT INTO ai_messages (conversation_id, role, content) VALUES (?, 'user', ?)",
            [$conversationId, $message]
        );

        // Watermark: the highest ai_action_proposals.id that already existed
        // for this conversation before we spawn `claude`. Any propose_* tool
        // call the model makes during this turn INSERTs a new row with a
        // higher id — comparing against this watermark afterward (rather
        // than a timestamp, which risks clock-skew edge cases) is how we
        // find "the proposal this turn produced, if any" without threading
        // any extra plumbing through the MCP subprocess boundary.
        $proposalWatermark = (int) ($this->db->one(
            'SELECT COALESCE(MAX(id), 0) AS m FROM ai_action_proposals WHERE conversation_id = ?',
            [$conversationId]
        )['m'] ?? 0);

        try {
            $reply = $this->runClaude($message, $userId, $role, $conversationId, $effectiveEventId, $eventContext);
        } catch (\RuntimeException $e) {
            return Response::json(['error' => $e->getMessage()], 502);
        }

        $this->db->run(
            "INSERT INTO ai_messages (conversation_id, role, content) VALUES (?, 'assistant', ?)",
            [$conversationId, $reply]
        );
        $this->db->run('UPDATE ai_conversations SET updated_at = NOW() WHERE id = ?', [$conversationId]);

        $proposal = $this->db->one(
            'SELECT id, tool_name, diff_json, expires_at FROM ai_action_proposals
             WHERE conversation_id = ? AND id > ? AND status = \'pending\'
             ORDER BY id DESC LIMIT 1',
            [$conversationId, $proposalWatermark]
        );

        $payload = ['conversation_id' => $conversationId, 'reply' => $reply];
        if ($proposal) {
            $payload['proposal'] = [
                'id'         => (int) $proposal['id'],
                'tool_name'  => $proposal['tool_name'],
                'diff'       => json_decode($proposal['diff_json'], true),
                'expires_at' => $proposal['expires_at'],
            ];
        }

        return $this->ok($payload);
    }

    /**
     * POST /api/ai/proposals/{id}/apply — the ONE place in this codebase
     * that turns an AI proposal into a real write. Only reachable via a
     * human-clicked REST call (never a model tool); re-validates ownership,
     * pending status, and expiry before touching anything, then re-runs the
     * exact same validation the write would go through if a human had done
     * it directly through the app (Series::attemptCreate() /
     * BookerUpdate::apply()) rather than trusting the stored diff blindly —
     * the underlying data may have changed since the proposal was computed.
     */
    private function applyProposal(int $proposalId): Response
    {
        $userId = $this->userId();
        $role   = $this->role();

        if (RateLimiter::tooMany($this->db, 'ai_apply:user:' . $userId, self::APPLY_RATE_LIMIT_MAX_PER_HOUR, self::RATE_LIMIT_WINDOW_SECONDS)) {
            return Response::json(['error' => 'Too many AI apply actions — please wait a bit and try again.'], 429);
        }

        $loaded = $this->loadOwnedPendingProposal($proposalId, $userId);
        if ($loaded instanceof Response) {
            return $loaded;
        }
        $args = json_decode((string) $loaded['args_json'], true);
        if (!is_array($args)) {
            $args = [];
        }

        // Every write below runs through Database::setActor() so db_history
        // attributes it (and can undo it) as an AI-assisted change distinct
        // from an ordinary human edit — see docs/AI-ASSISTANT-PLAN.md,
        // "Audit & rate limiting".
        $this->db->setActor('ai:' . $userId);

        $result = match ($loaded['tool_name']) {
            'propose_booker_update'    => $this->applyBookerUpdate($args, $userId, $role),
            'propose_recurring_series' => $this->applyRecurringSeries($args, $userId, $role),
            default                    => ['ok' => false, 'status' => 500, 'error' => 'Unknown proposal type.'],
        };

        if ($result['ok']) {
            $this->db->run(
                "UPDATE ai_action_proposals SET status = 'applied', applied_at = NOW(), applied_by_user_id = ? WHERE id = ?",
                [$userId, $proposalId]
            );
            return $this->ok($result['payload']);
        }

        $payload = ['error' => $result['error']];
        if (isset($result['horizon_date'])) {
            $payload['horizon_date'] = $result['horizon_date'];
            $payload['beyond_horizon_dates'] = $result['beyond_horizon_dates'];
        }
        if (isset($result['conflict_dates'])) {
            $payload['conflict_dates'] = $result['conflict_dates'];
        }
        return Response::json($payload, $result['status']);
    }

    /** DELETE /api/ai/proposals/{id} — human-clicked "Discard". Idempotent: only a still-pending proposal is touched. */
    private function discardProposal(int $proposalId): Response
    {
        $userId = $this->userId();
        $proposal = $this->db->one('SELECT id, user_id, status FROM ai_action_proposals WHERE id = ?', [$proposalId]);
        if (!$proposal || (int) $proposal['user_id'] !== $userId) {
            return $this->notFound('Proposal not found.');
        }
        if ($proposal['status'] === 'pending') {
            $this->db->run("UPDATE ai_action_proposals SET status = 'discarded' WHERE id = ?", [$proposalId]);
        }
        return $this->ok(['ok' => true]);
    }

    /**
     * Load a proposal by id, verifying it belongs to $userId and is still
     * pending and unexpired. Returns the proposal row, or an error Response
     * ready to hand straight back to the caller.
     */
    private function loadOwnedPendingProposal(int $proposalId, int $userId): array|Response
    {
        $proposal = $this->db->one('SELECT * FROM ai_action_proposals WHERE id = ?', [$proposalId]);
        if (!$proposal || (int) $proposal['user_id'] !== $userId) {
            return $this->notFound('Proposal not found.');
        }
        if ($proposal['status'] !== 'pending') {
            return Response::json(['error' => 'This proposal is no longer pending (status: ' . $proposal['status'] . ').'], 409);
        }
        if (strtotime((string) $proposal['expires_at']) < time()) {
            $this->db->run("UPDATE ai_action_proposals SET status = 'expired' WHERE id = ?", [$proposalId]);
            return Response::json(['error' => 'This proposal has expired — ask again to get a fresh one.'], 409);
        }
        return $proposal;
    }

    /**
     * @return array{ok: bool, status?: int, error?: string, payload?: array}
     */
    private function applyBookerUpdate(array $args, int $userId, string $role): array
    {
        $eventIds = is_array($args['event_ids'] ?? null) ? array_map('intval', $args['event_ids']) : [];
        $fields   = BookerUpdate::sanitizeFields(is_array($args['fields'] ?? null) ? $args['fields'] : []);
        if (!$eventIds || !$fields) {
            return ['ok' => false, 'status' => 422, 'error' => 'This proposal has no valid changes to apply.'];
        }

        // Re-check edit_event access NOW, at apply time, rather than trusting
        // the set of events matched when the proposal was first computed —
        // access (or the events themselves) may have changed since.
        $db = $this->db;
        $authorizedIds = array_values(array_filter(
            $eventIds,
            static fn(int $eventId): bool => Capabilities::hasEvent($db, $eventId, $userId, $role, 'edit_event')
        ));
        if (!$authorizedIds) {
            return ['ok' => false, 'status' => 403, 'error' => 'You no longer have edit access to any of the events in this proposal.'];
        }

        $updated = BookerUpdate::apply($this->db, $authorizedIds, $fields);
        foreach ($authorizedIds as $eventId) {
            log_activity($this->db, $eventId, $userId, 'booker info updated via AI assistant', ['fields' => array_keys($fields)]);
        }

        return ['ok' => true, 'payload' => ['updated_count' => $updated, 'event_ids' => $authorizedIds]];
    }

    /**
     * @return array{ok: bool, status?: int, error?: string, payload?: array}
     */
    private function applyRecurringSeries(array $args, int $userId, string $role): array
    {
        $eventId = (int) ($args['event_id'] ?? 0);
        $dates   = is_array($args['dates'] ?? null) ? array_values(array_filter(array_map('strval', $args['dates']))) : [];
        $description = isset($args['description']) && is_string($args['description']) ? trim($args['description']) : null;
        if ($eventId <= 0 || !$dates) {
            return ['ok' => false, 'status' => 422, 'error' => 'This proposal is missing required data.'];
        }

        // Delegates to Series::attemptCreate() — the exact same validation
        // (capability, occurrence cap, 90-day horizon, room conflicts) and
        // insert logic the human "Create recurring events" button runs, so
        // this can never create a series the human flow wouldn't also allow.
        $series = new Series($this->db, $this->auth, [], $this->root);
        $result = $series->attemptCreate(
            $eventId, $dates, $description, null, 'after_count', null, count($dates) + 1, $userId, $role
        );
        if (!$result['ok']) {
            return $result;
        }

        return ['ok' => true, 'payload' => ['series_id' => $result['series_id'], 'created_event_ids' => $result['created_event_ids']]];
    }

    /**
     * Spawn `claude -p` headless, scoped to the current ALLOWED_TOOLS, and
     * return its final text reply. Throws RuntimeException (with a
     * user-safe message) on timeout or failure.
     */
    private function runClaude(string $message, int $userId, string $role, int $conversationId, ?int $eventId, ?array $eventContext): string
    {
        $bin            = getenv('CLAUDE_CLI_BIN') ?: '/home/cdr/.local/bin/claude';
        $model          = getenv('AI_ASSISTANT_MODEL') ?: 'sonnet';
        $timeoutSeconds = (int) (getenv('AI_ASSISTANT_TIMEOUT_SECONDS') ?: 60);
        if ($timeoutSeconds <= 0) {
            $timeoutSeconds = 60;
        }
        $maxBudget = trim((string) (getenv('AI_ASSISTANT_MAX_BUDGET_USD') ?: ''));

        set_time_limit($timeoutSeconds + 30);

        $tmpDir = sys_get_temp_dir() . '/pb-ai-' . bin2hex(random_bytes(6));
        if (!mkdir($tmpDir, 0700, true) && !is_dir($tmpDir)) {
            throw new \RuntimeException('Could not prepare the AI assistant request.');
        }

        try {
            $mcpConfigPath     = $tmpDir . '/mcp-config.json';
            $systemPromptPath  = $tmpDir . '/system-prompt.txt';
            $mcpServerScript   = dirname(__DIR__, 2) . '/scripts/ai-mcp-server.php';

            file_put_contents($mcpConfigPath, json_encode([
                'mcpServers' => [
                    'panic' => [
                        'command' => 'php',
                        'args'    => [$mcpServerScript],
                        'env'     => [
                            'PB_ACTING_USER_ID'  => (string) $userId,
                            'PB_ACTING_ROLE'     => $role,
                            'PB_EVENT_ID'        => $eventId !== null ? (string) $eventId : '',
                            'PB_CONVERSATION_ID' => (string) $conversationId,
                        ],
                    ],
                ],
            ], JSON_PRETTY_PRINT));

            file_put_contents($systemPromptPath, $this->buildSystemPrompt($eventContext));

            // Literal flags are static, safe strings (never interpolated
            // from request input) and left unescaped; every value that
            // could vary — binary path, temp-file paths, model name, the
            // user's own message — goes through escapeshellarg(). Mirrors
            // Events\GenerateFlyer::runCodex()'s exact pattern. `timeout
            // --signal=KILL` gives a real, process-level hard timeout (not
            // just PHP's own set_time_limit(), which can't kill a hung
            // child) so a stuck `claude` process can never hang this worker
            // past $timeoutSeconds.
            // This app's own .env sets ANTHROPIC_API_KEY for an unrelated
            // feature (the booking-email importer's freeform-extraction
            // fallback — see .env.example). That var reaches this process's
            // environment via putenv() (Env::load()) and would otherwise be
            // inherited straight through exec() into the `claude` child,
            // silently switching it from the intended OAuth/subscription
            // auth to metered API-key billing (and printing a warning line
            // onto the same stdout stream --output-format json writes to,
            // corrupting the JSON this code decodes below). `env -u` strips
            // both possible forms of that key from the child's environment
            // — confirmed necessary and sufficient by hand while building
            // this endpoint.
            $cmd = 'env -u ANTHROPIC_API_KEY -u ANTHROPIC_API_KEY_FILE'
                 . ' timeout --signal=KILL ' . escapeshellarg($timeoutSeconds . 's')
                 . ' ' . escapeshellarg($bin)
                 . ' -p ' . escapeshellarg($message)
                 . ' --output-format json'
                 . ' --no-session-persistence'
                 . ' --tools ' . escapeshellarg('')
                 . ' --mcp-config ' . escapeshellarg($mcpConfigPath)
                 . ' --strict-mcp-config'
                 . ' --allowedTools ' . implode(' ', array_map('escapeshellarg', self::ALLOWED_TOOLS))
                 . ' --permission-mode bypassPermissions'
                 . ' --append-system-prompt-file ' . escapeshellarg($systemPromptPath)
                 . ' --model ' . escapeshellarg($model);
            if ($maxBudget !== '') {
                $cmd .= ' --max-budget-usd ' . escapeshellarg($maxBudget);
            }
            // `claude` inherits this PHP process's stdin when spawned via
            // exec(); left open (as it is under both `php -S` and PHP-FPM),
            // the CLI stalls ~3s waiting to see if piped input is coming and
            // then prints a "no stdin data received" warning onto the SAME
            // stream as --output-format json, corrupting the JSON this code
            // is about to decode. Redirecting from /dev/null makes the CLI
            // see immediate EOF instead, removing both the delay and the
            // corruption — confirmed by hand while building this endpoint.
            $cmd .= ' < /dev/null 2>&1';

            exec($cmd, $lines, $exitCode);
            $output = implode("\n", $lines);

            if ($exitCode !== 0) {
                // GNU `timeout --signal=KILL` reports 137 on a hard kill.
                if ($exitCode === 137 || $exitCode === 124) {
                    throw new \RuntimeException('The AI assistant took too long to respond. Try a narrower question.');
                }
                throw new \RuntimeException('The AI assistant failed to respond: ' . mb_substr($output, 0, 500));
            }

            $decoded = json_decode($output, true);
            if (!is_array($decoded) || !isset($decoded['result']) || !is_string($decoded['result'])) {
                throw new \RuntimeException('The AI assistant returned an unexpected response.');
            }
            if (!empty($decoded['is_error'])) {
                throw new \RuntimeException('The AI assistant reported an error: ' . mb_substr((string) $decoded['result'], 0, 500));
            }

            return $decoded['result'];
        } finally {
            $this->rrmdir($tmpDir);
        }
    }

    private function buildSystemPrompt(?array $eventContext): string
    {
        $prompt = 'You are the AI assistant embedded in Panic Backstage, a venue booking and '
            . 'event-management app. You help venue staff answer questions about events using the '
            . 'get_event and list_events tools, and can PROPOSE two kinds of change using '
            . 'propose_booker_update and propose_recurring_series. Those four tools are your only '
            . 'tools, and proposing is as far as you can ever go: calling a propose_* tool only computes '
            . 'a diff and stores it for a human to review — it does not change anything by itself. There '
            . 'is no way for you to actually apply a change, delete anything, cancel anything, or send '
            . 'email — only a human clicking "Apply" in the app can do that. After a successful propose_* '
            . 'call, tell the user their proposal is ready to review in the panel; never say the change '
            . '"has been made" or "is done" — it has not. If asked to do something outside these four '
            . 'tools (delete an event, change its status, send an email, run arbitrary code, etc.), say '
            . 'plainly that you cannot do that and that it has to be done in the app directly.'
            . "\n\n"
            . 'Always call a tool to look up real data rather than guessing or inventing event details, '
            . 'dates, or contact information. If a tool call reports no access, tell the user plainly '
            . 'that they do not have access to that event rather than working around it or making '
            . 'something up. For propose_recurring_series, compute the date list yourself from what the '
            . 'user asked for (e.g. "every Tuesday for 8 weeks") — the tool will tell you if a date is '
            . 'invalid, beyond the 90-day booking horizon, or conflicts with an existing booking; fix and '
            . 'retry rather than guessing around a rejection. Keep answers concise and grounded only in '
            . 'what the tools actually return.';

        if ($eventContext !== null && $eventContext !== []) {
            $prompt .= "\n\nThe user currently has this event open in their workspace. Treat questions "
                . "like \"this event\" or \"it\" as referring to this event unless they clearly ask about "
                . "something else:\n" . json_encode($eventContext, JSON_PRETTY_PRINT);
        }

        return $prompt;
    }

    private function intOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        return is_numeric($value) ? (int) $value : null;
    }

    /** Recursively delete a directory and all its contents. Mirrors GenerateFlyer::rrmdir(). */
    private function rrmdir(string $dir): void
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
