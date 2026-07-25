<?php
declare(strict_types=1);

namespace Panic\Ai;

use Panic\BaseEndpoint;
use Panic\Capabilities;
use Panic\RateLimiter;
use Panic\Request;
use Panic\Response;

/**
 * POST /api/ai/ask — the AI Assistant drawer's only route in Phase 1.
 * {conversation_id?, event_id?, message} -> {conversation_id, reply}
 *
 * Read-only: the model gets exactly two MCP tools (get_event, list_events —
 * see scripts/ai-mcp-server.php), never Claude Code's own Bash/Read/Write/
 * Edit tools, and never any MCP server but ours. There is no write/propose/
 * apply route here at all yet — that's Phase 2 (see docs/AI-ASSISTANT-PLAN.md).
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

    /** Reject absurdly long prompts before ever spawning a subprocess. */
    private const MAX_MESSAGE_LENGTH = 4000;

    /** Phase 1: read-only tools only. Phase 2 adds propose_* here once apply/UI exists. */
    private const ALLOWED_TOOLS = ['mcp__panic__get_event', 'mcp__panic__list_events'];

    public function handle(Request $request): Response
    {
        if ($denied = $this->requireAuth()) {
            return $denied;
        }
        if ($denied = $this->requireGlobalCapability('use_ai_assistant')) {
            return $denied;
        }

        $action = $this->params['action'] ?? null;
        if ($action !== 'ask') {
            // Phase 2's /api/ai/proposals/* routes aren't implemented yet —
            // this endpoint intentionally has exactly one route right now.
            return $this->notFound();
        }
        if ($request->method() !== 'POST') {
            return Response::methodNotAllowed();
        }

        return $this->ask($request);
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

        try {
            $reply = $this->runClaude($message, $userId, $role, $effectiveEventId, $eventContext);
        } catch (\RuntimeException $e) {
            return Response::json(['error' => $e->getMessage()], 502);
        }

        $this->db->run(
            "INSERT INTO ai_messages (conversation_id, role, content) VALUES (?, 'assistant', ?)",
            [$conversationId, $reply]
        );
        $this->db->run('UPDATE ai_conversations SET updated_at = NOW() WHERE id = ?', [$conversationId]);

        return $this->ok(['conversation_id' => $conversationId, 'reply' => $reply]);
    }

    /**
     * Spawn `claude -p` headless, scoped to exactly the two Phase 1 MCP
     * tools, and return its final text reply. Throws RuntimeException (with
     * a user-safe message) on timeout or failure.
     */
    private function runClaude(string $message, int $userId, string $role, ?int $eventId, ?array $eventContext): string
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
                            'PB_ACTING_USER_ID' => (string) $userId,
                            'PB_ACTING_ROLE'    => $role,
                            'PB_EVENT_ID'       => $eventId !== null ? (string) $eventId : '',
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
        $prompt = 'You are the read-only AI assistant embedded in Panic Backstage, a venue booking and '
            . 'event-management app. You help venue staff answer questions about events using the '
            . 'get_event and list_events tools — those are your only tools, and you cannot make any '
            . 'changes to the system: no writes, no emails, no files, no shell. If asked to change, '
            . 'delete, cancel, or email anything, say plainly that you can currently only answer '
            . 'questions and that changes have to be made in the app directly.'
            . "\n\n"
            . 'Always call a tool to look up real data rather than guessing or inventing event details, '
            . 'dates, or contact information. If a tool call reports no access, tell the user plainly '
            . 'that they do not have access to that event rather than working around it or making '
            . 'something up. Keep answers concise and grounded only in what the tools actually return.';

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
