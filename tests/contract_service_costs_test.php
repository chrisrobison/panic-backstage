<?php
/** Contract service-cost disclosure rules (GitHub issue #24). */
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Panic\ContractRenderer;

$passed = 0;
$failed = 0;
function ok(bool $condition, string $label): void
{
    global $passed, $failed;
    echo ($condition ? "  ✓ " : "  ✗ FAIL: ") . $label . "\n";
    $condition ? $passed++ : $failed++;
}

echo "\n=== Contract service-cost disclosure ===\n\n";

$security = [[
    'id' => 1,
    'title' => 'Security Staffing',
    'included' => true,
    'body_template' => 'Security staffing: {{security_count}} guard(s). {{security_terms}}',
    'required_fields_json' => json_encode(['security_count', 'security_rate', 'security_paid_by']),
]];
$production = [[
    'id' => 2,
    'title' => 'Production',
    'included' => true,
    'body_template' => 'Sound technician — {{sound_tech_terms}}',
    'required_fields_json' => json_encode(['sound_rate', 'sound_paid_by']),
]];

$base = [
    'title' => 'Test Contract',
    'contract_type' => 'promoter_show',
    'security_count' => 1,
    'security_rate' => 30,
    'security_paid_by' => 'venue',
    'sound_tech_included' => 1,
    'sound_rate' => 30,
    'sound_paid_by' => 'venue',
    'lighting_tech_included' => 0,
];

$venueContext = ContractRenderer::context($base, null, null);
$venueSecurity = ContractRenderer::render($base, $security, $venueContext);
$venueSound = ContractRenderer::render($base, $production, $venueContext);
ok(!str_contains($venueSecurity['text'], '$30.00'), 'venue-covered security hides the internal hourly rate');
ok(str_contains($venueSecurity['text'], "Venue's cost"), 'venue-covered security says the Venue absorbs the cost');
ok(!str_contains($venueSound['text'], '$30.00'), 'venue-covered sound hides the internal hourly rate');
ok(str_contains($venueSound['text'], "Venue's cost"), 'venue-covered sound says the Venue absorbs the cost');

$missingSecurity = ContractRenderer::missingFields($security, ContractRenderer::context([
    ...$base,
    'security_rate' => null,
], null, null)['tokens']);
ok($missingSecurity === [], 'venue-covered security rate is not a missing contract term');
$missingSound = ContractRenderer::missingFields($production, ContractRenderer::context([
    ...$base,
    'sound_rate' => null,
], null, null)['tokens']);
ok($missingSound === [], 'venue-covered sound rate is not a missing contract term');

$promoter = [
    ...$base,
    'security_paid_by' => 'promoter',
    'sound_paid_by' => 'promoter',
];
$promoterContext = ContractRenderer::context($promoter, null, null);
$promoterSecurity = ContractRenderer::render($promoter, $security, $promoterContext);
$promoterSound = ContractRenderer::render($promoter, $production, $promoterContext);
ok(str_contains($promoterSecurity['text'], '$30.00 per hour'), 'promoter-paid security prints the hourly rate');
ok(str_contains($promoterSecurity['text'], 'borne by the Promoter'), 'security wording names the responsible payer');
ok(str_contains($promoterSound['text'], '$30.00 per hour'), 'promoter-paid sound prints the hourly rate');
ok(str_contains($promoterSound['text'], 'payable by the Promoter'), 'sound wording names the responsible payer');

$missingPromoter = ContractRenderer::missingFields($security, ContractRenderer::context([
    ...$promoter,
    'security_rate' => null,
], null, null)['tokens']);
ok(($missingPromoter[0]['key'] ?? null) === 'security_rate', 'a non-venue payer still requires the service rate');

$notIncluded = ContractRenderer::context([
    ...$base,
    'sound_tech_included' => 0,
], null, null);
ok($notIncluded['tokens']['sound_tech_terms'] === 'not included', 'sound not included renders without any price language');
ok(in_array('sound_rate', ContractRenderer::DEAL_COLUMNS, true), 'sound rate is a persisted deal term');
ok(in_array('sound_paid_by', ContractRenderer::DEAL_COLUMNS, true), 'sound payer is a persisted deal term');

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
