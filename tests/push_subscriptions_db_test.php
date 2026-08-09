<?php
/**
 * DB-backed push registration lifecycle and per-user scoping.
 *
 * Creates its own throwaway users, registrations and queued jobs and removes
 * every one of them in the finally block — this suite runs against whatever
 * database .env points at, which may be production (see the repo's testing
 * notes). Nothing here contacts Firebase: the enqueue path is exercised with
 * an in-process config, and delivery is never invoked.
 *
 * Opt in with RUN_DB_TESTS=1 (see tests/run-php-tests.sh).
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Panic\Database;
use Panic\Env;
use Panic\Notifications\PushMessages;
use Panic\Notifications\PushNotifier;
use Panic\Notifications\PushSubscriptions;

Env::load(dirname(__DIR__) . '/.env');

$passed = 0;
$failed = 0;
function ok(bool $condition, string $label): void
{
    global $passed, $failed;
    echo ($condition ? "  ✓ " : "  ✗ FAIL: ") . $label . "\n";
    $condition ? $passed++ : $failed++;
}

echo "\n=== Push subscriptions (DB-backed) ===\n\n";

$db = new Database();
$marker = bin2hex(random_bytes(4));

$aliceId = $db->insert(
    "INSERT INTO users (name,email,role,is_hidden) VALUES (?,?,'venue_admin',0)",
    ["PB Push User A {$marker}", "push-a-{$marker}@example.invalid"]
);
$bobId = $db->insert(
    "INSERT INTO users (name,email,role,is_hidden) VALUES (?,?,'venue_admin',0)",
    ["PB Push User B {$marker}", "push-b-{$marker}@example.invalid"]
);

$laptopToken = "pb-test-laptop-{$marker}";
$phoneToken  = "pb-test-phone-{$marker}";
$bobToken    = "pb-test-bob-{$marker}";

try {
    // ── Registration ─────────────────────────────────────────────────────────
    echo "Registration\n";

    $laptopId = PushSubscriptions::upsert($db, $aliceId, $laptopToken, 'Chrome on Linux', 'Linux', 'UA/1.0');
    ok($laptopId > 0, 'registering a device returns its id');

    $rows = PushSubscriptions::listForUser($db, $aliceId);
    ok(count($rows) === 1, 'the device appears in the owner\'s list');
    ok($rows[0]['device_label'] === 'Chrome on Linux', 'the device label round-trips');
    // The whole point of hashing: the API surface must never hand back a token.
    ok(!array_key_exists('token', $rows[0]), 'the listing never includes the registration token');
    ok(
        (string) $db->one('SELECT token_hash FROM push_subscriptions WHERE id = ?', [$laptopId])['token_hash']
            === hash('sha256', $laptopToken),
        'the stored hash is the SHA-256 of the token'
    );

    // ── Idempotent re-registration ───────────────────────────────────────────
    echo "\nRe-registration\n";

    $again = PushSubscriptions::upsert($db, $aliceId, $laptopToken, 'Chrome on Linux', 'Linux', 'UA/2.0');
    ok($again === $laptopId, 're-registering the same token updates the same row');
    ok(count(PushSubscriptions::listForUser($db, $aliceId)) === 1, 're-registration creates no duplicate row');

    // A disabled device coming back must switch itself on again — that is how
    // a user recovers from an FCM-retired registration without support.
    PushSubscriptions::disable($db, $laptopId, 'UNREGISTERED');
    ok((int) $db->one('SELECT enabled FROM push_subscriptions WHERE id = ?', [$laptopId])['enabled'] === 0, 'a dead registration can be disabled');
    ok(PushSubscriptions::enabledForUsers($db, [$aliceId]) === [], 'a disabled device is not a delivery target');
    PushSubscriptions::upsert($db, $aliceId, $laptopToken, null, null, 'UA/3.0');
    ok((int) $db->one('SELECT enabled FROM push_subscriptions WHERE id = ?', [$laptopId])['enabled'] === 1, 're-registering re-enables a retired device');
    ok(
        $db->one('SELECT device_label FROM push_subscriptions WHERE id = ?', [$laptopId])['device_label'] === 'Chrome on Linux',
        'a re-registration without a label keeps the existing one'
    );

    // ── Multiple devices ─────────────────────────────────────────────────────
    echo "\nMultiple devices\n";

    $phoneId = PushSubscriptions::upsert($db, $aliceId, $phoneToken, 'Safari on iOS', 'iOS', 'UA/1.0');
    ok($phoneId !== $laptopId, 'a second token creates a second row');
    ok(count(PushSubscriptions::listForUser($db, $aliceId)) === 2, 'one user may hold several devices');
    ok(count(PushSubscriptions::enabledForUsers($db, [$aliceId])) === 2, 'both devices are delivery targets');

    // ── Cross-user isolation ─────────────────────────────────────────────────
    echo "\nCross-user isolation\n";

    $bobSubId = PushSubscriptions::upsert($db, $bobId, $bobToken, 'Bob\'s phone', 'Android', 'UA/1.0');
    ok(count(PushSubscriptions::listForUser($db, $bobId)) === 1, 'another user sees only their own device');
    ok(
        array_column(PushSubscriptions::listForUser($db, $aliceId), 'id') === [$phoneId, $laptopId]
            || !in_array($bobSubId, array_column(PushSubscriptions::listForUser($db, $aliceId), 'id'), true),
        'one user never sees another user\'s registrations'
    );
    ok(PushSubscriptions::findByToken($db, $aliceId, $bobToken) === null, 'a token cannot be looked up across users');

    // The security model for DELETE is the user_id predicate, not a separate
    // authorization check — so this is the assertion that matters most here.
    ok(!PushSubscriptions::deleteForUser($db, $aliceId, $bobSubId), 'deleting another user\'s subscription by id does nothing');
    ok(
        $db->one('SELECT id FROM push_subscriptions WHERE id = ?', [$bobSubId]) !== null,
        'the other user\'s subscription still exists after the attempt'
    );
    ok(!PushSubscriptions::deleteByTokenForUser($db, $aliceId, $bobToken), 'deleting another user\'s subscription by token does nothing');

    // A token registered again by a DIFFERENT user moves to that user, so the
    // previous owner stops being pushed on a device that is no longer theirs.
    PushSubscriptions::upsert($db, $bobId, $phoneToken, 'Shared device', 'iOS', 'UA/1.0');
    ok(count(PushSubscriptions::listForUser($db, $aliceId)) === 1, 're-registering a shared token moves it off the previous user');
    ok(count(PushSubscriptions::listForUser($db, $bobId)) === 2, 'the shared device now belongs to the signed-in user');

    // ── Removal ──────────────────────────────────────────────────────────────
    echo "\nRemoval\n";

    ok(PushSubscriptions::deleteForUser($db, $aliceId, $laptopId), 'a user can delete their own device by id');
    ok(count(PushSubscriptions::listForUser($db, $aliceId)) === 0, 'the deleted device is gone');
    ok(!PushSubscriptions::deleteForUser($db, $aliceId, $laptopId), 'deleting an already-deleted device reports not found');

    PushSubscriptions::upsert($db, $aliceId, $laptopToken, 'Chrome on Linux', 'Linux', 'UA/1.0');
    ok(PushSubscriptions::deleteByTokenForUser($db, $aliceId, $laptopToken), 'a browser can unregister itself by token');

    // ── Queue dispatch ───────────────────────────────────────────────────────
    echo "\nQueue dispatch\n";

    // Turn push on for this process only. No service-account file, so nothing
    // can attempt a send even if something tried to.
    foreach ([
        'FIREBASE_PUSH_ENABLED'        => '1',
        'FIREBASE_PROJECT_ID'          => 'pb-test',
        'FIREBASE_WEB_API_KEY'         => 'test-key',
        'FIREBASE_MESSAGING_SENDER_ID' => '1234567890',
        'FIREBASE_APP_ID'              => '1:1234567890:web:abc',
        'FIREBASE_VAPID_PUBLIC_KEY'    => 'test-vapid',
    ] as $key => $value) {
        $_ENV[$key] = $value;
        putenv("{$key}={$value}");
    }

    $notifier = new PushNotifier();
    ok($notifier->isEnabled(), 'the notifier reports enabled once configured');

    $lead = ['id' => 1, 'contact_org' => 'Acme Events', 'desired_date' => '2026-10-14'];
    $message = PushMessages::leadAssigned($lead, 1);

    // No registered device ⇒ nothing to queue.
    ok(
        $notifier->notifyUsers($db, [$aliceId], $message, null, "pb-test-none-{$marker}") === 0,
        'a recipient with no registered device queues nothing'
    );

    PushSubscriptions::upsert($db, $aliceId, $laptopToken, 'Chrome on Linux', 'Linux', 'UA/1.0');
    $jobId = $notifier->notifyUsers($db, [$aliceId], $message, null, "pb-test-job-{$marker}");
    ok($jobId > 0, 'a recipient with a device queues a job');

    $job = $db->one('SELECT job_type, payload_json FROM background_jobs WHERE id = ?', [$jobId]);
    ok((string) $job['job_type'] === 'push_notification', 'the job uses the push_notification type');
    $payload = json_decode((string) $job['payload_json'], true);
    ok($payload['user_ids'] === [$aliceId], 'the payload carries only eligible recipient ids');
    ok($payload['message']['url'] === '#inbox-mine', 'the payload carries the deep link');
    ok(!str_contains((string) $job['payload_json'], $laptopToken), 'the queued payload never contains a registration token');

    // Opting out of the category must stop delivery even with a live device.
    $db->run('UPDATE users SET push_task_assignments = 0 WHERE id = ?', [$aliceId]);
    ok(
        $notifier->notifyUsers($db, [$aliceId], $message, null, "pb-test-optout-{$marker}") === 0,
        'a user opted out of the category is not queued'
    );
    $db->run('UPDATE users SET push_task_assignments = 1 WHERE id = ?', [$aliceId]);

    // You should not get a phone alert for something you just did yourself.
    ok(
        $notifier->notifyUsers($db, [$aliceId], $message, $aliceId, "pb-test-self-{$marker}") === 0,
        'the acting user is excluded from their own action'
    );

    // unique_key must actually dedupe a repeat enqueue.
    $repeat = $notifier->notifyUsers($db, [$aliceId], $message, null, "pb-test-job-{$marker}");
    ok($repeat === $jobId, 'the same unique_key returns the existing job instead of queueing a second');

    // With push unconfigured the whole path must be inert — this is what makes
    // the notify() calls in Booking Inbox / contract signing zero-risk.
    $_ENV['FIREBASE_PUSH_ENABLED'] = '';
    putenv('FIREBASE_PUSH_ENABLED=');
    $off = new PushNotifier();
    ok(!$off->isEnabled(), 'a blank FIREBASE_PUSH_ENABLED disables push');
    ok(
        $off->notifyUsers($db, [$aliceId], $message, null, "pb-test-off-{$marker}") === 0,
        'an unconfigured install queues nothing at all'
    );
} finally {
    $db->run("DELETE FROM background_jobs WHERE unique_key LIKE ?", ["pb-test-%{$marker}"]);
    // push_subscriptions has ON DELETE CASCADE on user_id, so removing the
    // throwaway users removes their registrations too — asserted below.
    $db->run('DELETE FROM users WHERE id IN (?, ?)', [$aliceId, $bobId]);
    $orphans = $db->one(
        'SELECT COUNT(*) c FROM push_subscriptions WHERE user_id IN (?, ?)',
        [$aliceId, $bobId]
    );
    ok((int) $orphans['c'] === 0, 'deleting a user cascades away their push registrations');
}

echo "\nPush subscriptions: {$passed} passed, {$failed} failed.\n";
exit($failed > 0 ? 1 : 0);
