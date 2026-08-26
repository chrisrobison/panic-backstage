<?php
/**
 * Hermetic tests for the pure prompt-building/result-validation logic
 * behind Opportunities' AI research jobs (Phase 7 —
 * src/Opportunities/Research/Modes.php). No DB, no `claude` CLI, no
 * network — every method under test is a static, side-effect-free function
 * operating on plain arrays, so the untrusted-model-output validation rules
 * (the part most worth pinning down with tests, since a malformed reply is
 * the normal case for an LLM, not the exception) can be exercised directly.
 *
 * Run with: php tests/opportunity_research_modes_test.php
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Panic\Opportunities\Research\Modes;

$passed = 0;
$failed = 0;

function ok(bool $cond, string $label): void {
    global $passed, $failed;
    if ($cond) { echo "  ✓ $label\n"; $passed++; }
    else        { echo "  ✗ FAIL: $label\n"; $failed++; }
}

function throws(callable $fn): ?string {
    try {
        $fn();
        return null;
    } catch (\Throwable $e) {
        return $e->getMessage();
    }
}

echo "\n=== Opportunities\\Research\\Modes ===\n\n";

// ── isValidMode() / SCOPE ────────────────────────────────────────────────
ok(Modes::isValidMode('discover_conferences'), 'discover_conferences is a valid mode');
ok(Modes::isValidMode('research_company'), 'research_company is a valid mode');
ok(!Modes::isValidMode('delete_everything'), 'an unknown mode string is rejected');
ok(count(Modes::MODES) === 6, 'exactly the 6 spec-defined modes exist');
ok(Modes::SCOPE['discover_conferences'] === null, 'discover_conferences needs no scope');
ok(Modes::SCOPE['research_conference'] === 'conference', 'research_conference is conference-scoped');
ok(Modes::SCOPE['research_company'] === 'company', 'research_company is company-scoped');
ok(Modes::SCOPE['generate_outreach_angles'] === 'conference_or_company', 'generate_outreach_angles allows either scope');

// ── validateInput() ──────────────────────────────────────────────────────
ok(Modes::validateInput('research_company', ['location' => 'ignored']) === [],
    'non-discover_conferences modes ignore/require no free-form input');

$err = throws(fn() => Modes::validateInput('discover_conferences', []));
ok($err !== null && str_contains($err, 'location'), 'discover_conferences without location throws a clear error');

$input = Modes::validateInput('discover_conferences', [
    'location' => '  San Francisco, CA  ',
    'date_from' => '2026-09-01',
    'date_to' => 'not-a-date',
    'venue_context' => str_repeat('x', 600),
]);
ok($input['location'] === 'San Francisco, CA', 'location is trimmed');
ok($input['date_from'] === '2026-09-01', 'a valid date_from is kept');
ok($input['date_to'] === null, 'an invalid date_to is nulled rather than rejecting the whole request');
ok(mb_strlen($input['venue_context']) === 500, 'venue_context is truncated to its cap');

// ── buildPrompt() ─────────────────────────────────────────────────────────
[$system, $user] = Modes::buildPrompt('discover_conferences', $input, ['venue_name' => 'Mabuhay Gardens', 'venue_city' => 'San Francisco']);
ok(str_contains($system, 'WebSearch'), 'the system preamble mentions the allowed tools');
ok(str_contains($system, 'never invent'), 'the system preamble carries the anti-fabrication instruction');
ok(str_contains($user, 'Mabuhay Gardens'), 'discover_conferences prompt includes the real venue name from scope, not a hard-coded one');
ok(str_contains($user, 'San Francisco, CA'), 'discover_conferences prompt includes the requested location');

[, $userConf] = Modes::buildPrompt('research_conference', [], ['conference_name' => 'Test Conf 2026', 'name' => 'Test Conf 2026', 'city' => 'Oakland']);
ok(str_contains($userConf, 'Test Conf 2026'), 'research_conference prompt includes the real conference name');
ok(!str_contains($userConf, 'Dreamforce'), 'no hard-coded Dreamforce example leaks into a generated prompt');

$errMode = throws(fn() => Modes::buildPrompt('not_a_mode', [], []));
ok($errMode !== null, 'buildPrompt() rejects an unknown mode');

// ── validateResult(): discover_conferences ───────────────────────────────
$errEmpty = throws(fn() => Modes::validateResult('discover_conferences', ['conferences' => []], 25));
ok($errEmpty !== null, 'zero conferences found is a hard failure, not a silent empty success');

$errMissingKey = throws(fn() => Modes::validateResult('discover_conferences', ['nope' => []], 25));
ok($errMissingKey !== null, 'a missing top-level "conferences" key is a structural failure');

$result = Modes::validateResult('discover_conferences', [
    'conferences' => [
        [
            'name' => 'Big Tech Summit', 'starts_on' => '2026-10-05', 'ends_on' => 'garbage-date',
            'website_url' => 'javascript:alert(1)', 'source_urls' => ['https://example.com/a', 'not-a-url', 'https://example.com/a'],
            'why_relevant' => str_repeat('y', 3000), 'confidence' => 5.0,
        ],
        ['name' => ''], // no name -> dropped entirely, not a structural failure
    ],
], 25);
$conf = $result['conferences'][0];
ok(count($result['conferences']) === 1, 'an item with an empty required name is dropped, not kept as a blank row');
ok($conf['starts_on'] === '2026-10-05', 'a valid date passes through');
ok($conf['ends_on'] === null, 'an unparsable date is nulled, not rejected wholesale');
ok($conf['website_url'] === null, 'a javascript: URL is never accepted as website_url');
ok($conf['source_urls'] === ['https://example.com/a'], 'source_urls drops invalid entries and de-dupes');
ok(mb_strlen($conf['why_relevant']) === 2000, 'why_relevant is truncated to its cap');
ok($conf['confidence'] === 1.0, 'an out-of-range confidence is clamped to the valid max');

// ── validateResult(): find_target_companies (role/confidence enums) ─────
$companies = Modes::validateResult('find_target_companies', [
    'companies' => [
        ['name' => 'Acme Corp', 'role' => 'sponsor', 'confidence' => 'high'],
        ['name' => 'Shady LLC', 'role' => 'made_up_role', 'confidence' => 'super-duper'],
    ],
], 25)['companies'];
ok($companies[0]['role'] === 'sponsor' && $companies[0]['confidence'] === 'high', 'a valid role/confidence pair passes through unchanged');
ok($companies[1]['role'] === 'unknown', 'an out-of-enum role is normalized rather than rejecting the whole item');
ok($companies[1]['confidence'] === 'medium', 'an out-of-enum confidence defaults to medium');

// ── validateResult(): research_company (never invent a named person) ────
$company = Modes::validateResult('research_company', [
    'company' => ['industry' => 'Software'],
    'buyer_roles' => [
        ['title' => 'Director of Events', 'name' => 'Jane Doe', 'source_url' => 'https://example.com/team'],
        ['title' => 'Head of Facilities', 'name' => 'Fabricated Person', 'email' => 'fake@fabricated.example'],
    ],
], 25);
ok($company['buyer_roles'][0]['name'] === 'Jane Doe', 'a named person WITH a real source is kept');
ok($company['buyer_roles'][1]['name'] === null, 'a named person WITHOUT a source is stripped — never trust an unattributed name');
ok($company['buyer_roles'][0]['email'] === null && $company['buyer_roles'][1]['email'] === null,
    'email is ALWAYS nulled regardless of source — never invent/trust a personal email address');

// ── validateResult(): side_events type enum + host/event required ───────
$sideEvents = Modes::validateResult('research_side_events', [
    'side_events' => [
        ['host_company' => 'Acme', 'event_name' => 'Welcome Mixer', 'type' => 'mixer'],
        ['host_company' => 'Acme', 'event_name' => 'Mystery Event', 'type' => 'not_a_real_type'],
        ['host_company' => '', 'event_name' => 'Should Be Dropped'],
    ],
], 25)['side_events'];
ok(count($sideEvents) === 2, 'an item missing a required field (empty host_company) is dropped');
ok($sideEvents[1]['type'] === 'other', 'an out-of-enum side-event type falls back to "other"');

// ── validateResult(): generate_outreach_angles ───────────────────────────
$angles = Modes::validateResult('generate_outreach_angles', [
    'angles' => [['title' => 'VIP Reception', 'description' => 'A curated evening.', 'rationale' => 'Strong exec turnout.']],
], 25)['angles'];
ok($angles[0]['title'] === 'VIP Reception', 'a well-formed angle passes through');

echo "\nOpportunities\\Research\\Modes: $passed passed, $failed failed.\n";
exit($failed > 0 ? 1 : 0);
