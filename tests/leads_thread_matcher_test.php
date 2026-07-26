<?php
/**
 * DB-backed tests for src/Leads/ThreadMatcher.php — the header/subject
 * matching that lets scripts/ingest-booking-email.php fold an obvious reply
 * into its existing lead instead of creating a second one.
 *
 * REQUIRES A REAL MYSQL DATABASE — point DB_* / .env at a throwaway database
 * (or the dev copy) before running this directly; it is excluded from
 * tests/run-php-tests.sh's default (hermetic) pass — opt in with
 * RUN_DB_TESTS=1. Everything this script creates is tagged with a random
 * marker email domain and deleted in a finally block regardless of
 * pass/fail (same convention as tests/ai_phase2_db_test.php).
 *
 * Run with: RUN_DB_TESTS=1 php tests/leads_thread_matcher_test.php
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Panic\Database;
use Panic\Env;
use Panic\Leads\ThreadMatcher;

$root = dirname(__DIR__);
Env::load($root . '/.env');

$passed = 0;
$failed = 0;

function ok(bool $cond, string $label): void {
    global $passed, $failed;
    if ($cond) { echo "  ✓ $label\n"; $passed++; }
    else        { echo "  ✗ FAIL: $label\n"; $failed++; }
}

echo "\n=== ThreadMatcher (DB-backed) ===\n\n";

try {
    $db = new Database();
    $db->one('SELECT 1');
} catch (\Throwable $e) {
    fwrite(STDERR, "Could not connect to the database configured in .env: {$e->getMessage()}\n");
    exit(1);
}

$marker = bin2hex(random_bytes(4));
$leadIds = [];

try {
    $matcher = new ThreadMatcher();

    // ── Scenario A: header match (In-Reply-To/References hit an existing
    //    lead_messages.external_message_id) ──────────────────────────────────
    $leadA = $db->insert(
        "INSERT INTO leads (contact_email, notes) VALUES (?, ?)",
        ["headertest-{$marker}@example.invalid", 'PB TEST ThreadMatcher A']
    );
    $leadIds[] = $leadA;
    $db->insert(
        "INSERT INTO lead_messages (lead_id, direction, channel, status, subject, external_message_id)
         VALUES (?, 'inbound', 'email', 'received', ?, ?)",
        [$leadA, 'Booking inquiry', "orig-{$marker}@example.invalid"]
    );

    $found = $matcher->findLeadId($db, ["unrelated-{$marker}@x.invalid", "orig-{$marker}@example.invalid"], null, null);
    ok($found === $leadA, 'header match: a References id hitting external_message_id resolves the lead');

    $found = $matcher->findLeadId($db, ["nothing-matches-{$marker}@x.invalid"], null, null);
    ok($found === null, 'header match: no id overlap → null');

    // ── Scenario B: subject + sender fallback (no header overlap) ───────────
    $leadB = $db->insert(
        "INSERT INTO leads (contact_email, notes) VALUES (?, ?)",
        ["subjecttest-{$marker}@example.invalid", 'PB TEST ThreadMatcher B']
    );
    $leadIds[] = $leadB;
    $db->insert(
        "INSERT INTO lead_messages (lead_id, direction, channel, status, subject)
         VALUES (?, 'inbound', 'email', 'received', ?)",
        [$leadB, "Booking inquiry — August {$marker}"]
    );

    $found = $matcher->findLeadId($db, [], "subjecttest-{$marker}@example.invalid", "Re: Booking inquiry — August {$marker}");
    ok($found === $leadB, 'subject fallback: normalized Re: subject + same contact_email resolves the lead');

    $found = $matcher->findLeadId($db, [], "subjecttest-{$marker}@example.invalid", "Completely different subject {$marker}");
    ok($found === null, 'subject fallback: different subject, same sender → null');

    $found = $matcher->findLeadId($db, [], "someone-else-{$marker}@example.invalid", "Re: Booking inquiry — August {$marker}");
    ok($found === null, 'subject fallback: same subject, different sender → null');

    $found = $matcher->findLeadId($db, [], null, "Re: Booking inquiry — August {$marker}");
    ok($found === null, 'subject fallback: no contact_email to key off of → null');

    // ── Header match takes precedence over a coincidental subject match ─────
    $leadC = $db->insert(
        "INSERT INTO leads (contact_email, notes) VALUES (?, ?)",
        ["subjecttest-{$marker}@example.invalid", 'PB TEST ThreadMatcher C']
    );
    $leadIds[] = $leadC;
    $db->insert(
        "INSERT INTO lead_messages (lead_id, direction, channel, status, subject, external_message_id)
         VALUES (?, 'inbound', 'email', 'received', ?, ?)",
        [$leadC, "Booking inquiry — August {$marker}", "orig-c-{$marker}@example.invalid"]
    );
    $found = $matcher->findLeadId(
        $db,
        ["orig-c-{$marker}@example.invalid"],
        "subjecttest-{$marker}@example.invalid",
        "Re: Booking inquiry — August {$marker}"
    );
    ok($found === $leadC, 'header match wins over the subject fallback when both could apply');
} finally {
    foreach ($leadIds as $id) {
        $db->run('DELETE FROM leads WHERE id = ?', [$id]);
    }
}

echo "\nThreadMatcher: $passed passed, $failed failed.\n";
exit($failed > 0 ? 1 : 0);
