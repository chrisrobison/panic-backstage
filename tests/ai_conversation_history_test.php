<?php
/**
 * Hermetic tests for the pure history-shaping helpers behind the AI
 * Assistant's multi-turn memory (src/Ai/Assistant.php) — a fresh `claude -p`
 * process has no memory of its own between /ai/ask calls, so prior turns
 * are replayed into the system prompt via these two functions. See the
 * MAX_HISTORY_* constants' docblock in Assistant.php for why this exists.
 *
 * Both trimHistoryToBudget() and formatHistory() are pure (no DB, no I/O)
 * so they're exercised directly here — DB-touching loadHistory() itself is
 * exercised implicitly by the live end-to-end verification documented in
 * docs/AI-ASSISTANT-PLAN.md, not re-tested hermetically.
 *
 * Run with: php tests/ai_conversation_history_test.php
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Panic\Ai\Assistant;

$passed = 0;
$failed = 0;

function ok(bool $cond, string $label): void {
    global $passed, $failed;
    if ($cond) { echo "  ✓ $label\n"; $passed++; }
    else        { echo "  ✗ FAIL: $label\n"; $failed++; }
}

echo "\n=== Ai\\Assistant conversation history ===\n\n";

// ── trimHistoryToBudget() ────────────────────────────────────────────────
$short = [
    ['role' => 'user', 'content' => 'hi'],
    ['role' => 'assistant', 'content' => 'hello'],
];
ok(Assistant::trimHistoryToBudget($short, 12000) === $short,
    'a short history well under budget is returned unchanged');

ok(Assistant::trimHistoryToBudget([], 12000) === [],
    'empty history stays empty');

$rows = [
    ['role' => 'user', 'content' => str_repeat('a', 100)],
    ['role' => 'assistant', 'content' => str_repeat('b', 100)],
    ['role' => 'user', 'content' => str_repeat('c', 100)],
    ['role' => 'assistant', 'content' => str_repeat('d', 100)],
];
// Total = 400 chars. Budget of 250 must drop the OLDEST turns first,
// keeping the most recent ones (the ones actually relevant to a follow-up).
$trimmed = Assistant::trimHistoryToBudget($rows, 250);
ok(count($trimmed) === 2, 'trimming to a 250-char budget from 400 total drops down to the 2 most recent turns (200 chars)');
ok($trimmed[0]['content'][0] === 'c' && $trimmed[1]['content'][0] === 'd',
    'the turns kept are the newest ones (c, d), not the oldest (a, b)');

// A single turn larger than the whole budget is still returned alone
// (never trimmed below empty just because it's the last one) — the caller
// simply gets a system prompt with one long turn rather than a crash.
$oneHuge = [['role' => 'user', 'content' => str_repeat('x', 500)]];
ok(Assistant::trimHistoryToBudget($oneHuge, 10) === $oneHuge,
    'a lone turn bigger than the budget is kept rather than emptied out');

ok(array_is_list(Assistant::trimHistoryToBudget($rows, 250)),
    'trimHistoryToBudget() re-indexes its result as a plain list');

// ── formatHistory() ──────────────────────────────────────────────────────
ok(Assistant::formatHistory([]) === '', 'formatHistory() of no turns is an empty string');

$formatted = Assistant::formatHistory([
    ['role' => 'user', 'content' => 'Any Tuesday events this month?'],
    ['role' => 'assistant', 'content' => "There's a room conflict on 2026-08-21."],
]);
ok($formatted === "User: Any Tuesday events this month?\nAssistant: There's a room conflict on 2026-08-21.",
    'formatHistory() renders alternating "User:"/"Assistant:" lines, oldest first');

ok(str_contains(Assistant::formatHistory([['role' => 'assistant', 'content' => '  padded  ']]), 'Assistant: padded'),
    'formatHistory() trims whitespace out of each turn\'s content');

// Any role other than 'user' is rendered as "Assistant:" — loadHistory()'s
// own SQL already restricts rows to role IN ('user','assistant'), so this
// is just formatHistory() being conservative rather than mis-attributing a
// stray role to the user.
ok(str_starts_with(Assistant::formatHistory([['role' => 'assistant', 'content' => 'x']]), 'Assistant:'),
    'a non-"user" role formats as "Assistant:"');

echo "\nAi\\Assistant conversation history: $passed passed, $failed failed.\n";
exit($failed > 0 ? 1 : 0);
