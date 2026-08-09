<?php
/**
 * Hermetic push-notification tests — no Firebase, no network, no database.
 *
 * Covers the parts of the Firebase integration that are pure logic and are
 * therefore the parts most worth pinning down: OAuth assertion encoding, FCM
 * response classification (which decides whether a device gets retired), push
 * preference filtering, and notification payload generation.
 *
 * Nothing here contacts FCM, so an unconfigured CI environment runs the whole
 * file exactly as a configured one would.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Panic\Notifications\FcmClient;
use Panic\Notifications\PushMessage;
use Panic\Notifications\PushMessages;
use Panic\Notifications\PushPreferences;

$passed = 0;
$failed = 0;

function ok(bool $condition, string $label): void
{
    global $passed, $failed;
    if ($condition) {
        echo "  ✓ {$label}\n";
        $passed++;
    } else {
        echo "  ✗ FAIL: {$label}\n";
        $failed++;
    }
}

echo "\n=== Push notifications (hermetic) ===\n\n";

// ── base64url ────────────────────────────────────────────────────────────────
echo "base64url encoding\n";

ok(FcmClient::base64UrlEncode('') === '', 'empty input encodes to empty');
// "sure." is the canonical RFC 4648 example that exercises '=' padding.
ok(FcmClient::base64UrlEncode('sure.') === 'c3VyZS4', 'padding is stripped');
ok(
    !str_contains(FcmClient::base64UrlEncode(hex2bin('fbffbe') ?: ''), '+')
        && !str_contains(FcmClient::base64UrlEncode(hex2bin('fbffbe') ?: ''), '/'),
    '+ and / are replaced with the URL-safe alphabet'
);
ok(FcmClient::base64UrlEncode(hex2bin('fbff') ?: '') === '-_8', 'byte values that produce + and / map to - and _');

// ── JWT assertion construction ───────────────────────────────────────────────
echo "\nOAuth JWT assertion\n";

// A throwaway key generated in-process: no fixture on disk, nothing secret.
$keyResource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
if ($keyResource === false) {
    echo "  ! OpenSSL could not generate a test key; skipping assertion tests\n";
} else {
    openssl_pkey_export($keyResource, $privateKeyPem);
    $publicKeyPem = (string) openssl_pkey_get_details($keyResource)['key'];

    $issuedAt = 1_700_000_000;
    $assertion = FcmClient::buildAssertion(
        'sender@panic-backstage.iam.gserviceaccount.com',
        (string) $privateKeyPem,
        'https://oauth2.googleapis.com/token',
        $issuedAt
    );

    $segments = explode('.', $assertion);
    ok(count($segments) === 3, 'assertion has three dot-separated segments');

    $decode = static fn (string $segment): array => (array) json_decode(
        (string) base64_decode(strtr($segment, '-_', '+/') . str_repeat('=', (4 - strlen($segment) % 4) % 4)),
        true
    );

    $header = $decode($segments[0]);
    $claims = $decode($segments[1]);

    ok(($header['alg'] ?? '') === 'RS256', 'header declares RS256');
    ok(($header['typ'] ?? '') === 'JWT', 'header declares typ JWT');
    ok(($claims['iss'] ?? '') === 'sender@panic-backstage.iam.gserviceaccount.com', 'iss is the service-account client_email');
    ok(($claims['aud'] ?? '') === 'https://oauth2.googleapis.com/token', 'aud is the token endpoint');
    ok(($claims['scope'] ?? '') === 'https://www.googleapis.com/auth/firebase.messaging', 'scope is firebase.messaging');
    ok(($claims['iat'] ?? 0) === $issuedAt, 'iat is the supplied issue time');
    ok(($claims['exp'] ?? 0) === $issuedAt + 3600, 'exp is one hour after iat');
    ok(!str_contains($assertion, '='), 'no base64 padding leaks into the assertion');

    // The signature must actually verify — this is what catches a wrong
    // signing input (e.g. signing the claims alone instead of header.claims).
    $signature = (string) base64_decode(
        strtr($segments[2], '-_', '+/') . str_repeat('=', (4 - strlen($segments[2]) % 4) % 4)
    );
    $verified = openssl_verify("{$segments[0]}.{$segments[1]}", $signature, $publicKeyPem, OPENSSL_ALGO_SHA256);
    ok($verified === 1, 'RS256 signature verifies against the public key');
}

// ── FCM response classification ──────────────────────────────────────────────
echo "\nFCM response classification\n";

$fcmError = static fn (int $code, string $status, array $details = [], string $message = ''): array => [
    'error' => array_filter([
        'code'    => $code,
        'status'  => $status,
        'message' => $message,
        'details' => $details,
    ]),
];

ok(FcmClient::classify(200, ['name' => 'projects/x/messages/1']) === FcmClient::OUTCOME_SENT, '200 is a successful send');

// Dead registrations: disable the row, never retry.
ok(
    FcmClient::classify(404, $fcmError(404, 'NOT_FOUND', [['errorCode' => 'UNREGISTERED']])) === FcmClient::OUTCOME_DROP,
    'UNREGISTERED retires the subscription'
);
ok(FcmClient::classify(404, []) === FcmClient::OUTCOME_DROP, 'a bare 404 retires the subscription');
ok(
    FcmClient::classify(403, $fcmError(403, 'PERMISSION_DENIED', [['errorCode' => 'SENDER_ID_MISMATCH']])) === FcmClient::OUTCOME_DROP,
    'SENDER_ID_MISMATCH retires the subscription (token belongs to another project)'
);

// A malformed TOKEN is the device's problem; a malformed MESSAGE is ours.
// Both are 400/INVALID_ARGUMENT, so this distinction is the one that keeps a
// bug in our payload from silently unsubscribing every user.
$badToken = $fcmError(
    400,
    'INVALID_ARGUMENT',
    [
        ['errorCode' => 'INVALID_ARGUMENT'],
        ['fieldViolations' => [['field' => 'message.token', 'description' => 'not a valid FCM registration token']]],
    ]
);
ok(FcmClient::classify(400, $badToken) === FcmClient::OUTCOME_DROP, 'INVALID_ARGUMENT blaming message.token retires the subscription');

$badPayload = $fcmError(
    400,
    'INVALID_ARGUMENT',
    [['fieldViolations' => [['field' => 'message.data[0].value', 'description' => 'must be a string']]]]
);
ok(FcmClient::classify(400, $badPayload) === FcmClient::OUTCOME_CONFIG, 'INVALID_ARGUMENT blaming the payload is NOT treated as a dead token');

// Transient: throw and let the queue's backoff own the retry.
ok(FcmClient::classify(429, $fcmError(429, 'RESOURCE_EXHAUSTED', [['errorCode' => 'QUOTA_EXCEEDED']])) === FcmClient::OUTCOME_RETRY, '429 is retryable');
ok(FcmClient::classify(503, $fcmError(503, 'UNAVAILABLE')) === FcmClient::OUTCOME_RETRY, '503 UNAVAILABLE is retryable');
ok(FcmClient::classify(500, $fcmError(500, 'INTERNAL')) === FcmClient::OUTCOME_RETRY, '500 INTERNAL is retryable');

// Credentials/config: throw, but not as a transient condition.
ok(FcmClient::classify(401, $fcmError(401, 'UNAUTHENTICATED')) === FcmClient::OUTCOME_CONFIG, '401 is a configuration failure');
ok(FcmClient::classify(403, $fcmError(403, 'PERMISSION_DENIED')) === FcmClient::OUTCOME_CONFIG, 'plain 403 is a configuration failure');

// ── Collapse key ─────────────────────────────────────────────────────────────
echo "\nCollapse key\n";

$collapse = FcmClient::collapseKey('lead-assigned:12345');
ok(strlen($collapse) <= 32, 'collapse key fits the 32-character Web Push Topic limit');
ok(preg_match('/^[A-Za-z0-9_-]+$/', $collapse) === 1, 'collapse key is URL-safe base64');
ok($collapse === FcmClient::collapseKey('lead-assigned:12345'), 'collapse key is stable for the same dedupe key');
ok($collapse !== FcmClient::collapseKey('lead-assigned:12346'), 'different dedupe keys collapse differently');

// ── Preference filtering ─────────────────────────────────────────────────────
echo "\nPush preference filtering\n";

ok(PushPreferences::wants(['push_contracts' => 1], PushPreferences::CONTRACTS), 'opted-in user wants the category');
ok(!PushPreferences::wants(['push_contracts' => 0], PushPreferences::CONTRACTS), 'opted-out user does not');
// Unlike EMAIL preferences (which default TRUE for non-user recipients such
// as VENUE_MANAGER_EMAIL), a missing push column means "we do not know they
// opted in" — every push recipient is a real user row, so stay quiet.
ok(!PushPreferences::wants([], PushPreferences::CONTRACTS), 'a missing push column defaults to NOT sending');
ok(
    PushPreferences::wants(['notify_contracts' => 1], PushPreferences::CONTRACTS) === false,
    'the email preference column does not satisfy the push preference'
);
ok(PushPreferences::isKey('push_booking_updates'), 'known push key is recognized');
ok(!PushPreferences::isKey('notify_contracts'), 'an email key is not a push key');
ok(!PushPreferences::isKey('push_booking_updates; DROP TABLE users'), 'an injection-shaped key is rejected');
ok(count(PushPreferences::KEYS) === 4, 'four push categories are defined');

// ── Notification payload generation ──────────────────────────────────────────
echo "\nNotification payloads\n";

$lead = [
    'id'           => 4321,
    'contact_name' => 'Dana Ruiz',
    'contact_org'  => 'Acme Events',
    'event_name'   => 'private event',
    'event_type'   => 'private',
    'desired_date' => '2026-10-14',
];

$inquiry = PushMessages::newBookingInquiry($lead);
ok($inquiry->title === 'New booking inquiry', 'new-inquiry title');
ok($inquiry->body === 'Acme Events — October 14 private event', 'new-inquiry body reads "<org> — <date> <what>"');
ok($inquiry->url === '#inbox-unassigned', 'new inquiry deep-links to the unassigned queue');
ok($inquiry->category === PushPreferences::BOOKING_UPDATES, 'new inquiry is gated by the booking-updates preference');

$assigned = PushMessages::leadAssigned($lead, 99);
ok($assigned->title === 'Booking inquiry assigned to you', 'assignment title');
ok($assigned->url === '#inbox-mine', 'assignment deep-links to the assignee\'s own queue');
ok($assigned->category === PushPreferences::TASK_ASSIGNMENTS, 'assignment is gated by the assignments preference');
// Keyed on the assignment, not the lead: a lead reassigned twice must produce
// two notifications, not one swallowed by a stale dedupe key.
ok($assigned->dedupeKey === 'lead-assigned:99', 'assignment dedupe key varies per assignment');
ok(PushMessages::leadAssigned($lead, 100)->dedupeKey !== $assigned->dedupeKey, 'a second assignment of the same lead dedupes separately');

$contract = ['id' => 456, 'title' => 'Performance Agreement', 'event_id' => 77];
$eventLabel = PushMessages::eventLabel(['id' => 77, 'title' => 'The Dead Widgets', 'event_date' => '2026-08-19']);
ok($eventLabel === 'The Dead Widgets — Aug 19', 'event label reads "<title> — <short date>"');

$signed = PushMessages::contractAction($contract, 'signed', $eventLabel);
ok($signed->title === 'Contract signed', 'signed contract title');
ok($signed->body === 'The Dead Widgets — Aug 19', 'signed contract body names the show');
ok($signed->url === '#contract-456', 'contract deep-links to that contract');
ok($signed->eventId === 77, 'contract notification carries the related event id');
ok(PushMessages::contractAction($contract, 'declined')->title === 'Contract declined', 'declined contract title');
ok(PushMessages::contractAction($contract, 'declined')->body === 'Performance Agreement', 'body falls back to the contract title with no event');

// Missing/partial lead data must still produce something sendable.
ok(PushMessages::leadSummary([]) === 'New inquiry', 'an empty lead still yields a usable body');
ok(PushMessages::leadSummary(['contact_name' => 'Sam']) === 'Sam', 'name-only lead summarizes to the name');
ok(PushMessages::leadSummary(['contact_name' => 'Sam', 'desired_date' => '0000-00-00']) === 'Sam', 'a zero date is ignored');

// ── FCM data payload rules ───────────────────────────────────────────────────
echo "\nFCM data payload\n";

$message = new PushMessage('push_contracts', 'Title', 'Body', '#contract-1', 'contract', 5, 9, 'k');
$data = $message->dataPayload();
ok(
    array_reduce($data, static fn (bool $carry, $value): bool => $carry && is_string($value), true),
    'every data value is a string (FCM v1 rejects anything else)'
);
ok($data['entity_id'] === '5' && $data['event_id'] === '9', 'integer ids are stringified, not dropped');

$sparse = (new PushMessage('push_contracts', 'T', 'B', '#x'))->dataPayload();
ok(!array_key_exists('entity_id', $sparse), 'absent optional fields are omitted rather than sent as ""');
ok(!array_key_exists('dedupe_key', $sparse), 'absent dedupe key is omitted');
ok($sparse['url'] === '#x', 'the deep link is always present in the data payload');

// Round-tripping through the job payload must not lose anything.
$restored = PushMessage::fromArray($message->toArray());
ok($restored->dataPayload() == $data, 'a message survives the JobQueue payload round-trip intact');

echo "\nPush notifications: {$passed} passed, {$failed} failed.\n";
exit($failed > 0 ? 1 : 0);
