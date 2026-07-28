<?php
/**
 * DB-backed Booking Inbox row scoping + claim eligibility for a restricted
 * promoter, vs. a Trusted booker (staff/manage_booking_inbox).
 *
 * Restricted-booker claim eligibility policy under test (see
 * LeadsInbox::canClaim()'s docblock / docs/booking-inbox.md):
 *
 *   | Role                        | May claim when                                            |
 *   |------------------------------|------------------------------------------------------------|
 *   | manage_booking_inbox         | any visible lead with no active claim                      |
 *   | claim_leads only (promoter)  | assigned to them, or unassigned+unclaimed in open triage    |
 *   |                              | (new/classified) — never someone else's assigned lead      |
 *
 * A restricted booker also needs to be able to *see* an open-triage lead
 * (BaseEndpoint::leadScopeSql()) to ever reach a claim on it — this file
 * covers both the visibility and the claim-endpoint outcome together so a
 * regression in either one is caught.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Panic\Auth;
use Panic\Database;
use Panic\Env;
use Panic\Inbox;
use Panic\LeadsInbox;
use Panic\Request;
use Panic\Response;

Env::load(dirname(__DIR__) . '/.env');

$passed = 0;
$failed = 0;
function ok(bool $condition, string $label): void
{
    global $passed, $failed;
    echo ($condition ? "  ✓ " : "  ✗ FAIL: ") . $label . "\n";
    $condition ? $passed++ : $failed++;
}
function payload(Response $response): array
{
    $reflection = new ReflectionClass($response);
    $status = $reflection->getProperty('status');
    $body = $reflection->getProperty('body');
    return ['status' => $status->getValue($response), 'body' => $body->getValue($response)];
}
function detailOf(Database $db, Auth $auth, int $leadId): array
{
    return payload((new LeadsInbox($db, $auth, ['leadId' => $leadId, 'child' => 'detail'], dirname(__DIR__)))
        ->handle(new Request('GET', '/', [], [], [], [])));
}
function claim(Database $db, Auth $auth, int $leadId): array
{
    return payload((new LeadsInbox($db, $auth, ['leadId' => $leadId, 'child' => 'claim'], dirname(__DIR__)))
        ->handle(new Request('POST', '/', [], [], [], [])));
}

echo "\n=== Booking Inbox role scope + claim eligibility (DB-backed) ===\n\n";

$db = new Database();
$marker = bin2hex(random_bytes(4));
$promoterId = $db->insert(
    "INSERT INTO users (name,email,role,is_hidden) VALUES (?,?,'promoter',0)",
    ["PB Test Promoter {$marker}", "promoter-{$marker}@example.invalid"]
);
$otherUserId = $db->insert(
    "INSERT INTO users (name,email,role,is_hidden) VALUES (?,?,'staff',0)",
    ["PB Test Other Staff {$marker}", "other-staff-{$marker}@example.invalid"]
);
$trustedId = $db->insert(
    "INSERT INTO users (name,email,role,is_hidden) VALUES (?,?,'staff',0)",
    ["PB Test Trusted {$marker}", "trusted-{$marker}@example.invalid"]
);
$promoter = $db->one('SELECT * FROM users WHERE id = ?', [$promoterId]);
$trusted = $db->one('SELECT * FROM users WHERE id = ?', [$trustedId]);

$assignedToSelfId = 0;
$assignedToOtherId = 0;
$unassignedOpenTriageId = 0;
$unassignedNonTriageId = 0;
$leadIds = [];

try {
    $assignedToSelfId = $db->insert(
        "INSERT INTO leads (status,source,contact_email,event_name,assigned_to_user_id)
         VALUES ('assigned','other',?,?,?)",
        ["assigned-self-{$marker}@example.invalid", "PB Assigned Self {$marker}", $promoterId]
    );
    $assignedToOtherId = $db->insert(
        "INSERT INTO leads (status,source,contact_email,event_name,assigned_to_user_id)
         VALUES ('assigned','other',?,?,?)",
        ["assigned-other-{$marker}@example.invalid", "PB Assigned Other {$marker}", $otherUserId]
    );
    $unassignedOpenTriageId = $db->insert(
        "INSERT INTO leads (status,source,contact_email,event_name)
         VALUES ('new','other',?,?)",
        ["open-triage-{$marker}@example.invalid", "PB Open Triage {$marker}"]
    );
    // Unassigned but NOT in the open triage status set — a restricted
    // booker should be able to neither see nor claim this one.
    $unassignedNonTriageId = $db->insert(
        "INSERT INTO leads (status,source,contact_email,event_name)
         VALUES ('awaiting_customer','other',?,?)",
        ["non-triage-{$marker}@example.invalid", "PB Non Triage {$marker}"]
    );
    $leadIds = [$assignedToSelfId, $assignedToOtherId, $unassignedOpenTriageId, $unassignedNonTriageId];

    $auth = new Auth();
    $auth->setUser($promoter);

    // ── Visibility ──────────────────────────────────────────────────────
    $selfDetail = detailOf($db, $auth, $assignedToSelfId);
    ok($selfDetail['status'] === 200 && (int) ($selfDetail['body']['lead']['id'] ?? 0) === $assignedToSelfId,
        'promoter can open an inquiry assigned to them');

    $otherDetail = detailOf($db, $auth, $assignedToOtherId);
    ok($otherDetail['status'] === 403, 'promoter cannot open an inquiry assigned to someone else');

    $openTriageDetail = detailOf($db, $auth, $unassignedOpenTriageId);
    ok($openTriageDetail['status'] === 200, 'promoter can open an unassigned open-triage inquiry');
    ok(($openTriageDetail['body']['capabilities']['claim'] ?? null) === true,
        'capabilities.claim is true for that open-triage inquiry');

    $nonTriageDetail = detailOf($db, $auth, $unassignedNonTriageId);
    ok($nonTriageDetail['status'] === 403,
        'promoter cannot open an unassigned inquiry outside the open triage status set');

    $list = payload((new Inbox($db, $auth, ['action' => 'list'], dirname(__DIR__)))->handle(
        new Request('GET', '/', ['view' => 'all'], [], [], [])
    ));
    $ids = array_map('intval', array_column($list['body']['leads'] ?? [], 'id'));
    ok(in_array($assignedToSelfId, $ids, true), 'scoped list includes the inquiry assigned to the promoter');
    ok(in_array($unassignedOpenTriageId, $ids, true), 'scoped list includes the unassigned open-triage inquiry');
    ok(!in_array($assignedToOtherId, $ids, true), 'scoped list excludes an inquiry assigned to someone else');
    ok(!in_array($unassignedNonTriageId, $ids, true), 'scoped list excludes an unassigned non-triage inquiry');

    // ── Claim eligibility (POST /claim) ─────────────────────────────────
    $claimAssignedOther = claim($db, $auth, $assignedToOtherId);
    ok($claimAssignedOther['status'] === 403, 'restricted user cannot claim a lead assigned to someone else');

    $claimAssignedSelf = claim($db, $auth, $assignedToSelfId);
    ok($claimAssignedSelf['status'] === 200 && ($claimAssignedSelf['body']['claimed'] ?? false) === true,
        'restricted user can claim a lead assigned to them');

    $claimUnassigned = claim($db, $auth, $unassignedOpenTriageId);
    ok($claimUnassigned['status'] === 200 && ($claimUnassigned['body']['claimed'] ?? false) === true,
        'restricted user can claim an unassigned open-triage lead');

    // Trusted booker (manage_booking_inbox) may claim any unclaimed visible
    // lead — including one assigned to somebody else entirely.
    $trustedAuth = new Auth();
    $trustedAuth->setUser($trusted);
    $claimTrusted = claim($db, $trustedAuth, $assignedToOtherId);
    ok($claimTrusted['status'] === 200 && ($claimTrusted['body']['claimed'] ?? false) === true,
        'trusted booker (manage_booking_inbox) can claim any unclaimed visible lead');
} finally {
    if ($leadIds !== []) {
        $db->run('DELETE FROM lead_claims WHERE lead_id IN (' . implode(',', array_fill(0, count($leadIds), '?')) . ')', $leadIds);
        $db->run('DELETE FROM lead_audit_log WHERE lead_id IN (' . implode(',', array_fill(0, count($leadIds), '?')) . ')', $leadIds);
        $db->run('DELETE FROM leads WHERE id IN (' . implode(',', array_fill(0, count($leadIds), '?')) . ')', $leadIds);
    }
    $db->run('DELETE FROM users WHERE id IN (?, ?, ?)', [$promoterId, $otherUserId, $trustedId]);
}

echo "\nBooking Inbox role scope + claim eligibility: {$passed} passed, {$failed} failed.\n";
exit($failed > 0 ? 1 : 0);
