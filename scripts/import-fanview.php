<?php
declare(strict_types=1);

/**
 * Import the ticketing provider's "Fan View" export into the contacts table.
 *
 *   php scripts/import-fanview.php [path/to/export.csv]
 *
 * Defaults to database/fanview.csv. The export has a 3-line preamble
 * ("Report: Fan View" / "Group: ..." / blank), then a header row beginning
 * "User ID", the data rows, and a trailing blank + "Generated:" / "Tixr Studio"
 * footer. We key on the provider User ID, so re-running UPSERTs (no dupes).
 */

$root = dirname(__DIR__);
require $root . '/src/bootstrap.php';
\Panic\Env::load($root . '/.env');

$file = $argv[1] ?? ($root . '/database/fanview.csv');
if (!is_readable($file)) {
    fwrite(STDERR, "Cannot read CSV: {$file}\n");
    exit(1);
}

$db = new \Panic\Database();
$pdo = $db->pdo();

// Expected header → 0-based column index, located dynamically so a reordered
// export still maps correctly.
$wanted = [
    'User ID', 'First Name', 'Last Name', 'Email', 'Primary Phone', 'Gender',
    'Birthday', 'Events', 'Q Events', 'Tickets', 'USD Spend', 'Follows',
    'Last Interaction', 'Influencer ID', 'Marketing Opted In?', 'Opt-In Date',
];

$toDate = static function (?string $v): ?string {
    $v = trim((string) $v);
    if ($v === '') return null;
    $ts = strtotime($v); // slash dates are parsed US-style (m/d/Y)
    return $ts === false ? null : date('Y-m-d', $ts);
};
$toDateTime = static function (?string $v): ?string {
    $v = trim((string) $v);
    if ($v === '') return null;
    $ts = strtotime($v);
    return $ts === false ? null : date('Y-m-d H:i:s', $ts);
};
$toInt = static fn ($v): int => (int) round((float) trim((string) $v));

$fh = fopen($file, 'r');
if ($fh === false) {
    fwrite(STDERR, "Failed to open CSV.\n");
    exit(1);
}

$map = null;
$insert = $pdo->prepare(
    'INSERT INTO contacts
        (external_id, source, first_name, last_name, email, phone, gender, birthday,
         events_count, q_events_count, tickets_count, usd_spend, follows,
         last_interaction, influencer_id, marketing_opted_in, opt_in_date)
     VALUES (:external_id, :source, :first_name, :last_name, :email, :phone, :gender, :birthday,
         :events_count, :q_events_count, :tickets_count, :usd_spend, :follows,
         :last_interaction, :influencer_id, :marketing_opted_in, :opt_in_date)
     ON DUPLICATE KEY UPDATE
         first_name=VALUES(first_name), last_name=VALUES(last_name), email=VALUES(email),
         phone=VALUES(phone), gender=VALUES(gender), birthday=VALUES(birthday),
         events_count=VALUES(events_count), q_events_count=VALUES(q_events_count),
         tickets_count=VALUES(tickets_count), usd_spend=VALUES(usd_spend), follows=VALUES(follows),
         last_interaction=VALUES(last_interaction), influencer_id=VALUES(influencer_id),
         marketing_opted_in=VALUES(marketing_opted_in), opt_in_date=VALUES(opt_in_date)'
);

$rows = 0; $skipped = 0;
$pdo->beginTransaction();
try {
    while (($cells = fgetcsv($fh)) !== false) {
        $first = trim((string) ($cells[0] ?? ''));
        // Locate the header row, then build the column map.
        if ($map === null) {
            if ($first === 'User ID') {
                $headers = array_map(fn ($h) => trim((string) $h), $cells);
                $map = [];
                foreach ($wanted as $name) {
                    $idx = array_search($name, $headers, true);
                    if ($idx === false) {
                        throw new \RuntimeException("Missing expected column: {$name}");
                    }
                    $map[$name] = $idx;
                }
            }
            continue; // skip preamble + the header line itself
        }
        // Data ends at the blank row / "Generated:" / "Tixr Studio" footer.
        if ($first === '' || !ctype_digit($first)) {
            $skipped++;
            continue;
        }
        $get = fn (string $name): string => trim((string) ($cells[$map[$name]] ?? ''));
        $email = strtolower($get('Email'));
        $gender = strtolower($get('Gender'));
        $insert->execute([
            ':external_id'        => (int) $get('User ID'),
            ':source'             => 'tixr',
            ':first_name'         => $get('First Name') ?: null,
            ':last_name'          => $get('Last Name') ?: null,
            ':email'              => $email !== '' ? $email : null,
            ':phone'              => $get('Primary Phone') ?: null,
            ':gender'             => $gender !== '' ? $gender : null,
            ':birthday'           => $toDate($get('Birthday')),
            ':events_count'       => $toInt($get('Events')),
            ':q_events_count'     => $toInt($get('Q Events')),
            ':tickets_count'      => $toInt($get('Tickets')),
            ':usd_spend'          => (float) ($get('USD Spend') ?: 0),
            ':follows'            => $toInt($get('Follows')),
            ':last_interaction'   => $toDateTime($get('Last Interaction')),
            ':influencer_id'      => $get('Influencer ID') ?: null,
            ':marketing_opted_in' => strcasecmp($get('Marketing Opted In?'), 'yes') === 0 ? 1 : 0,
            ':opt_in_date'        => $toDate($get('Opt-In Date')),
        ]);
        $rows++;
    }
    $pdo->commit();
} catch (\Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "Import failed: {$e->getMessage()}\n");
    exit(1);
} finally {
    fclose($fh);
}

if ($map === null) {
    fwrite(STDERR, "No 'User ID' header row found — is this a Fan View export?\n");
    exit(1);
}

$total = (int) $pdo->query('SELECT COUNT(*) FROM contacts')->fetchColumn();
$opted = (int) $pdo->query('SELECT COUNT(*) FROM contacts WHERE marketing_opted_in = 1')->fetchColumn();
echo "Imported/updated {$rows} contact(s) ({$skipped} non-data line(s) skipped).\n";
echo "Contacts now: {$total} total, {$opted} marketing opted-in.\n";
