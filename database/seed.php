<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/src/bootstrap.php';

Panic\Env::load($root . '/.env');

$host = getenv('DB_HOST') ?: '127.0.0.1';
$port = getenv('DB_PORT') ?: '3306';
$user = getenv('DB_USER') ?: 'root';
$password = getenv('DB_PASSWORD') ?: '';
$dbName = getenv('DB_NAME') ?: 'panic_backstage';

try {
    $rootPdo = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $rootPdo->exec(file_get_contents($root . '/schema.sql'));
} catch (PDOException $error) {
    fwrite(STDERR, "Could not connect to MySQL with the configured credentials. Update .env and run again.\n");
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}

$pdo = new PDO("mysql:host=$host;port=$port;dbname=$dbName;charset=utf8mb4", $user, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach (['event_activity_log','event_invites','event_settlements','event_schedule_items','event_assets','event_blockers','event_tasks','event_lineup','bands','event_collaborators','events','event_templates','venues','users'] as $table) {
    $pdo->exec("TRUNCATE TABLE $table");
}
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');

$templates = [
    ['Punk Rock Karaoke Night', 'karaoke', 'Punk Rock Karaoke', 'Grab the mic and sing loud. A weekly punk karaoke night for regulars, first-timers, and anyone ready for the chorus.', 0, '21+', ['Confirm KJ/host','Confirm song catalog','Confirm projection/display setup','Confirm microphones','Create/update flyer','Publish event page','Post to social media','Confirm door/staff coverage','Print or export signup sheet'], [['Staff call','staff_call','18:30'],['KJ setup','other','19:00'],['Doors','doors','20:00'],['Karaoke starts','set','21:00'],['Last call for singers','other','23:30'],['Event end','curfew','00:00']]],
    ['Three-Band Local Show', 'live_music', 'Local Band Showcase', 'Three local bands, one loud night on Broadway.', 12, '21+', ['Confirm headliner','Confirm support bands','Collect band bios/photos/logos','Confirm ticket price','Confirm load-in','Confirm backline needs','Create flyer','Approve flyer','Publish event page','Configure ticket link','Post social promo','Confirm door staff','Create night-of-show run sheet','Settle payouts'], [['Load-in','load_in','17:00'],['Soundcheck','soundcheck','18:00'],['Doors','doors','20:00'],['Opener set','set','20:30'],['Changeover','changeover','21:10'],['Middle band set','set','21:25'],['Changeover','changeover','22:05'],['Headliner set','set','22:20'],['Curfew/event end','curfew','23:30']]],
    ['Open Mic Night', 'open_mic', 'Open Mic Night', 'A low-pressure night for songs, poems, comedy, and experiments.', 0, '21+', ['Confirm host','Confirm signup process','Confirm equipment','Create/update recurring flyer','Publish event page','Post social reminder','Confirm house rules'], [['Staff call','staff_call','18:30'],['Signup opens','other','19:00'],['Doors','doors','19:30'],['Open mic starts','set','20:00'],['Event end','curfew','23:00']]],
    ['Promoter Night', 'promoter_night', 'Promoter Night', 'A promoter-led bill with door terms and guest list rules confirmed in advance.', 15, '21+', ['Confirm promoter agreement','Confirm lineup','Confirm ticket split/door split','Collect flyer','Approve public copy','Publish event page','Confirm guest list rules','Confirm door settlement process','Confirm staff and sound'], [['Load-in','load_in','18:00'],['Doors','doors','21:00'],['First act','set','21:30'],['Event end','curfew','01:00']]],
    ['Special Legacy Event', 'special_event', 'Special Legacy Event', 'A special event honoring the venue history with invited guests and legacy performers.', 25, '21+', ['Confirm performer/guest','Confirm ticket price','Confirm press copy','Confirm flyer/poster','Approve announcement','Publish event page','Configure ticketing','Confirm guest list/VIP list','Confirm photographer','Confirm settlement terms','Prepare post-event recap'], [['Staff call','staff_call','17:00'],['VIP doors','doors','18:30'],['Program starts','set','19:30'],['Event end','curfew','23:00']]],
];

$stmt = $pdo->prepare('INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, ?)');
$stmt->execute(['Mabuhay Admin', 'admin@mabuhay.local', password_hash('changeme', PASSWORD_DEFAULT), 'venue_admin']);
$adminId = (int) $pdo->lastInsertId();

$stmt = $pdo->prepare('INSERT INTO venues (name, slug, address, city, state, timezone) VALUES (?, ?, ?, ?, ?, ?)');
$stmt->execute(['Mabuhay Gardens', 'mabuhay-gardens', '443 Broadway', 'San Francisco', 'CA', 'America/Los_Angeles']);
$venueId = (int) $pdo->lastInsertId();

$templateStmt = $pdo->prepare('INSERT INTO event_templates (venue_id, name, event_type, default_title, default_description_public, default_ticket_price, default_age_restriction, checklist_json, schedule_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
foreach ($templates as [$name, $type, $title, $copy, $price, $age, $tasks, $schedule]) {
    $templateStmt->execute([
        $venueId,
        $name,
        $type,
        $title,
        $copy,
        $price,
        $age,
        json_encode(array_map(fn ($task) => ['title' => $task], $tasks)),
        json_encode(array_map(fn ($item) => ['title' => $item[0], 'item_type' => $item[1], 'start_time' => $item[2]], $schedule)),
    ]);
}

$addDays = fn (int $days) => (new DateTimeImmutable("+$days days"))->format('Y-m-d');
$events = [
    ['Punk Rock Karaoke', 'punk-rock-karaoke', 'karaoke', 'published', $addDays(1), '20:00', '21:00', 0, 1],
    ['Local Band Showcase', 'local-band-showcase', 'live_music', 'confirmed', $addDays(3), '19:00', '20:00', 12, 0],
    ['Open Mic Night', 'open-mic-night', 'open_mic', 'needs_assets', $addDays(5), '19:00', '20:00', 0, 0],
    ['Promoter Night', 'promoter-night', 'promoter_night', 'ready_to_announce', $addDays(8), '21:00', '21:30', 15, 0],
    ['Empty/Hold Night', 'empty-hold-night', 'special_event', 'hold', $addDays(10), null, null, 0, 0],
];

$eventStmt = $pdo->prepare("INSERT INTO events (venue_id, title, slug, event_type, status, description_public, description_internal, date, doors_time, show_time, age_restriction, ticket_price, public_visibility, owner_user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, '21+', ?, ?, ?)");
$eventIds = [];
foreach ($events as $event) {
    $eventStmt->execute([$venueId, $event[0], $event[1], $event[2], $event[3], "{$event[0]} at Mabuhay Gardens.", 'Seeded MVP event.', $event[4], $event[5], $event[6], $event[7], $event[8], $adminId]);
    $eventIds[] = (int) $pdo->lastInsertId();
}

$pdo->exec("INSERT INTO bands (name, contact_email, instagram_url, bio) VALUES ('The Broadway Static', 'static@example.com', 'https://instagram.com/broadwaystatic', 'Loud local punk.'), ('North Beach Feedback', 'feedback@example.com', NULL, 'Garage rock from San Francisco.')");
$pdo->prepare('INSERT INTO event_lineup (event_id, band_id, billing_order, display_name, set_time, set_length_minutes, payout_terms, status) VALUES (?, 1, 1, "The Broadway Static", "20:30", 40, "Door split", "confirmed"), (?, 2, 2, "North Beach Feedback", "21:30", 45, "Door split", "tentative")')->execute([$eventIds[1], $eventIds[1]]);
$pdo->prepare('INSERT INTO event_tasks (event_id, title, status, priority, due_date) VALUES (?, "Approve flyer", "todo", "high", ?), (?, "Configure ticket link", "todo", "high", ?), (?, "Confirm host", "done", "normal", ?)')->execute([$eventIds[1], $addDays(1), $eventIds[1], $addDays(2), $eventIds[2], $addDays(3)]);
$pdo->prepare('INSERT INTO event_blockers (event_id, title, description, owner_user_id, status, due_date) VALUES (?, "Waiting on flyer approval", "Need final approval before announcing.", ?, "open", ?)')->execute([$eventIds[1], $adminId, $addDays(1)]);
$pdo->prepare('INSERT INTO event_schedule_items (event_id, title, item_type, start_time) VALUES (?, "Load-in", "load_in", "17:00"), (?, "Doors", "doors", "19:00"), (?, "Opener set", "set", "20:30")')->execute([$eventIds[1], $eventIds[1], $eventIds[1]]);

echo "Seed complete. Login: admin@mabuhay.local / changeme\n";
