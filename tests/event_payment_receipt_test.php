<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

$passed = 0;
$failed = 0;
function check(bool $condition, string $label): void {
    global $passed, $failed;
    $condition ? $passed++ : $failed++;
    echo '  ' . ($condition ? '✓' : '✗ FAIL:') . " {$label}\n";
}

// Rendering is pure; bypass the DB-bearing constructor to keep this test
// hermetic and exercise the same document builder used by web/PDF/email.
$service = (new ReflectionClass(Panic\EventPaymentReceiptService::class))->newInstanceWithoutConstructor();
$data = [
    'payment_id' => 8, 'event_id' => 670491, 'payment_type' => 'client_payment',
    'amount' => '1951.25', 'currency' => 'USD', 'payment_status' => 'received',
    'method' => 'square', 'received_at' => '2026-08-09 19:10:11',
    'checkout_provider' => 'square', 'checkout_payment_ref' => 'square-confirmation',
    'receipt_token' => str_repeat('a', 64), 'receipt_number' => 'RCT-EVT-236-000008',
    'external_id' => 'EVT-236', 'event_title' => 'Lydia <Breen>',
    'event_date' => '2026-08-08', 'event_end_date' => '2026-08-09',
    'promoter_name' => 'Lydia Breen', 'promoter_email' => 'lydia@example.com',
    'client_org' => null, 'booker_name' => null, 'booker_email' => null,
    'venue_name' => 'Mabuhay Gardens', 'venue_address' => '443 Broadway',
    'venue_city' => 'San Francisco', 'venue_state' => 'CA', 'venue_phone' => '415-555-1212',
    'venue_website' => null, 'venue_timezone' => 'America/Los_Angeles',
    'items' => [
        ['description' => 'Two-day venue rental', 'category' => 'rental_fee', 'amount' => '1820.00', 'currency' => 'USD'],
        ['description' => 'Security', 'category' => 'other_revenue', 'amount' => '131.25', 'currency' => 'USD'],
    ],
];

$html = $service->renderHtml($data);
check(str_contains($html, 'RCT-EVT-236-000008'), 'receipt number is rendered');
check(str_contains($html, '$1,951.25'), 'paid total is rendered');
check(str_contains($html, '$1,820.00') && str_contains($html, '$131.25'), 'itemization is rendered');
check(str_contains($html, 'August 9, 2026 at 12:10 PM PDT'), 'UTC payment time is shown in the venue timezone');
check(str_contains($html, 'Download PDF') && str_contains($html, '/api/public/payments/8/download?token='), 'interactive receipt links to its token-gated PDF');
check(!str_contains($html, 'Lydia <Breen>') && str_contains($html, 'Lydia &lt;Breen&gt;'), 'client-controlled values are HTML escaped');

$pdfHtml = $service->renderHtml($data, false);
check(!str_contains($pdfHtml, 'Download PDF') && !str_contains($pdfHtml, 'window.print'), 'PDF document omits browser controls');

if (is_executable('/usr/bin/wkhtmltopdf')) {
    $pdf = $service->renderPdf($data);
    check(str_starts_with($pdf, '%PDF') && strlen($pdf) > 1000, 'PDF renderer returns a non-empty PDF');
}

echo "\nEvent payment receipt: {$passed} passed, {$failed} failed.\n";
exit($failed === 0 ? 0 : 1);
