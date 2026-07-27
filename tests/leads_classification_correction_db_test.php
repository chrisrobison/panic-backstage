<?php
/**
 * DB-backed merge and mirror behavior for human classification corrections.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Panic\Database;
use Panic\Env;
use Panic\Leads\Classifier;

Env::load(dirname(__DIR__) . '/.env');

$passed = 0;
$failed = 0;
function ok(bool $condition, string $label): void
{
    global $passed, $failed;
    echo ($condition ? "  ✓ " : "  ✗ FAIL: ") . $label . "\n";
    $condition ? $passed++ : $failed++;
}

echo "\n=== Classification correction (DB-backed) ===\n\n";

$db = new Database();
$user = $db->one("SELECT id FROM users WHERE role = 'venue_admin' AND is_hidden = 0 ORDER BY id LIMIT 1");
if ($user === null) {
    fwrite(STDERR, "A visible venue administrator is required for this test.\n");
    exit(1);
}

$leadId = $db->insert(
    "INSERT INTO leads (status,source,contact_email,event_name) VALUES ('new','other',?,?)",
    ['classification-' . bin2hex(random_bytes(4)) . '@example.invalid', 'PB Classification Test']
);

try {
    $db->insert(
        "INSERT INTO lead_classifications
         (lead_id,source,extracted_json,is_current)
         VALUES (?,'ai',?,1)",
        [$leadId, json_encode(['event_category' => 'concert', 'music_genre' => 'punk'])]
    );

    (new Classifier(false))->recordCorrection($db, $leadId, (int) $user['id'], [
        'music_genre' => 'post-punk',
        'attendance' => 325,
        'budget' => 4500,
    ]);

    $current = $db->one('SELECT source, extracted_json FROM lead_classifications WHERE lead_id = ? AND is_current = 1', [$leadId]);
    $extracted = json_decode((string) $current['extracted_json'], true);
    ok($current['source'] === 'human_correction', 'correction becomes the current classification');
    ok($extracted['event_category'] === 'concert', 'unmodified extracted fields are preserved');
    ok($extracted['music_genre'] === 'post-punk', 'corrected extracted field replaces the AI value');

    $lead = $db->one('SELECT event_category,music_genre,projected_attendance,budget FROM leads WHERE id = ?', [$leadId]);
    ok(
        $lead['event_category'] === 'concert'
        && $lead['music_genre'] === 'post-punk'
        && (int) $lead['projected_attendance'] === 325
        && (float) $lead['budget'] === 4500.0,
        'corrected routing/list fields are mirrored onto the inquiry'
    );
} finally {
    $db->run('DELETE FROM leads WHERE id = ?', [$leadId]);
}

echo "\nClassification correction DB: {$passed} passed, {$failed} failed.\n";
exit($failed > 0 ? 1 : 0);
