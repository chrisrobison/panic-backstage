<?php
/**
 * DB-backed claim lifecycle and single-active-claim constraint.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Panic\Database;
use Panic\Env;
use Panic\Leads\ClaimService;

Env::load(dirname(__DIR__) . '/.env');

$passed = 0;
$failed = 0;
function ok(bool $condition, string $label): void
{
    global $passed, $failed;
    echo ($condition ? "  ✓ " : "  ✗ FAIL: ") . $label . "\n";
    $condition ? $passed++ : $failed++;
}

echo "\n=== ClaimService (DB-backed) ===\n\n";

$db = new Database();
$users = $db->all('SELECT id FROM users WHERE is_hidden = 0 ORDER BY id LIMIT 2');
if (count($users) < 2) {
    fwrite(STDERR, "Two visible users are required for this test.\n");
    exit(1);
}

$leadId = $db->insert(
    "INSERT INTO leads (status, source, contact_email, notes) VALUES ('new','other',?,?)",
    ['claim-test-' . bin2hex(random_bytes(4)) . '@example.invalid', 'PB TEST ClaimService']
);

try {
    $service = new ClaimService();
    $lead = $db->one('SELECT * FROM leads WHERE id = ?', [$leadId]);
    $first = $service->claim($db, $lead, (int) $users[0]['id']);
    ok($first['ok'] === true, 'first user claims the inquiry');

    $second = $service->claim($db, $lead, (int) $users[1]['id']);
    ok($second['ok'] === false && ($second['code'] ?? null) === 409, 'second user cannot claim the same inquiry');

    $active = $db->one("SELECT COUNT(*) n FROM lead_claims WHERE lead_id = ? AND status = 'active'", [$leadId]);
    ok((int) $active['n'] === 1, 'database contains exactly one active claim');

    $fresh = $db->one('SELECT * FROM leads WHERE id = ?', [$leadId]);
    $service->release($db, $fresh, (int) $users[0]['id'], 'DB regression test');
    $afterRelease = $db->one("SELECT COUNT(*) n FROM lead_claims WHERE lead_id = ? AND status = 'active'", [$leadId]);
    ok((int) $afterRelease['n'] === 0, 'release clears the active claim slot');

    $fresh = $db->one('SELECT * FROM leads WHERE id = ?', [$leadId]);
    $after = $service->claim($db, $fresh, (int) $users[1]['id']);
    ok($after['ok'] === true, 'another user can claim after release');
} finally {
    $db->run('DELETE FROM leads WHERE id = ?', [$leadId]);
}

echo "\nClaimService DB: {$passed} passed, {$failed} failed.\n";
exit($failed > 0 ? 1 : 0);
