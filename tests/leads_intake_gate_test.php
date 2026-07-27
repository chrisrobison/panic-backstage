<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Panic\Leads\IntakeGate;

$passed = 0;
$failed = 0;
function ok(bool $condition, string $label): void {
    global $passed, $failed;
    if ($condition) { echo "  PASS $label\n"; $passed++; }
    else { echo "  FAIL $label\n"; $failed++; }
}

$gate = new IntakeGate();
$lead = ['desired_date' => null, 'projected_attendance' => null];

$jotform = $gate->evaluate('', $lead, ['parse_method' => 'jotform', 'body_text' => '']);
ok($jotform['accept'], 'structured Jotform submissions always pass');

$newsletter = $gate->evaluate(
    "From: news@example.com\nList-Unsubscribe: <https://example.com/unsub>\n\nThis week",
    $lead,
    ['parse_method' => 'heuristic', 'from_email' => 'news@example.com', 'body_text' => 'This week']
);
ok(!$newsletter['accept'], 'mailing-list messages are quarantined');

$forwardedNewsletter = $gate->evaluate(
    "From: info@example.com\nSubject: The Week in Events\n\nBody",
    $lead,
    [
        'parse_method' => 'heuristic',
        'from_email' => 'info@example.com',
        'subject' => 'The Week in Events',
        'body_text' => 'View this email in your browser. Eight great events and concerts this week. Unsubscribe from these updates.',
    ]
);
ok(!$forwardedNewsletter['accept'], 'newsletter content is quarantined even when list headers were stripped');

$campaignLinks = implode(' ', array_map(
    static fn (int $n): string => "https://click.mailchimpapp.com/story/{$n}",
    range(1, 8)
));
$campaign = $gate->evaluate(
    "From: updates@example.com\nSubject: Upcoming events\n\n{$campaignLinks}",
    $lead,
    [
        'parse_method' => 'heuristic',
        'from_email' => 'updates@example.com',
        'subject' => 'Upcoming events',
        'body_text' => "Here are this week's shows and concerts. {$campaignLinks}",
    ]
);
ok(!$campaign['accept'], 'campaign-provider and link-density fingerprints quarantine marketing mail');

$auto = $gate->evaluate(
    "Auto-Submitted: auto-generated\n\nYour profile report",
    $lead,
    ['parse_method' => 'heuristic', 'from_email' => 'updates@example.com', 'body_text' => 'Your profile report']
);
ok(!$auto['accept'], 'automatically submitted notices are quarantined');

$inquiry = $gate->evaluate(
    "From: person@example.com\nSubject: Private event inquiry\n\nHello",
    $lead,
    ['parse_method' => 'heuristic', 'from_email' => 'person@example.com', 'subject' => 'Private event inquiry', 'body_text' => 'We need a venue for 120 guests.']
);
ok($inquiry['accept'], 'ordinary booking prose passes');

$inquiryWithLink = $gate->evaluate(
    "From: artist@example.com\nSubject: Booking inquiry\n\nHello",
    $lead,
    [
        'parse_method' => 'heuristic',
        'from_email' => 'artist@example.com',
        'subject' => 'Booking inquiry',
        'body_text' => 'Our band would like to book a show. Music: https://example.com/listen',
    ]
);
ok($inquiryWithLink['accept'], 'a real inquiry with an ordinary link is not mistaken for a newsletter');

$ticketNotice = $gate->evaluate(
    "From: marketing-emails@example.com\nSubject: Access your tickets\n\nHello",
    $lead,
    [
        'parse_method' => 'heuristic',
        'from_email' => 'marketing-emails@example.com',
        'subject' => 'Access your tickets',
        'body_text' => 'Access your tickets for the upcoming event in our app.',
    ]
);
ok(!$ticketNotice['accept'], 'automated ticket/account notifications are quarantined');

$dateOnly = $gate->evaluate(
    "From: person@example.com\nSubject: October 14\n\nHello",
    ['desired_date' => '2026-10-14', 'projected_attendance' => null],
    ['parse_method' => 'heuristic', 'from_email' => 'person@example.com', 'subject' => 'October 14', 'body_text' => 'Is this open?']
);
ok($dateOnly['accept'], 'a parsed desired date is a sufficient inquiry signal');

$noise = $gate->evaluate(
    "From: person@example.com\nSubject: Quick question\n\nCan you call me?",
    $lead,
    ['parse_method' => 'heuristic', 'from_email' => 'person@example.com', 'subject' => 'Quick question', 'body_text' => 'Can you call me?']
);
ok(!$noise['accept'], 'ambiguous mail without booking facts is quarantined');

echo "\nIntakeGate: $passed passed, $failed failed.\n";
exit($failed > 0 ? 1 : 0);
