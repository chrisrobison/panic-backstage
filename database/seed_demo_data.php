<?php
declare(strict_types=1);

/**
 * Generic demo-data seeder: one venue, a starter set of event templates,
 * a handful of sample events/bands/staff/tasks so a fresh install or a
 * newly-provisioned tenant has something to look at immediately, and one
 * admin login.
 *
 * Deliberately venue-agnostic — no hardcoded venue name, address, or admin
 * email. Callers supply those via $opts:
 *
 *   php database/seed.php                    — local dev, generic placeholders
 *   Panic\Tenant\TenantProvisioner::provision() — real venue name + admin email
 *                                                 supplied when the tenant was created
 *
 * Truncation (if you need to reset existing demo data first) is the caller's
 * responsibility — this function only inserts, so it's safe to call once
 * against a freshly-created, empty database.
 */

namespace Panic;

/**
 * @param \PDO $pdo Connected to the target database (single-tenant or a tenant DB).
 * @param array{
 *   venue_name?: string,
 *   venue_slug?: string,
 *   timezone?: string,
 *   admin_name?: string,
 *   admin_email?: string,
 *   admin_password?: string,
 * } $opts
 * @return array{admin_email: string, admin_password: string, venue_id: int}
 */
function seed_demo_data(\PDO $pdo, string $root, array $opts = []): array
{
    $venueName     = $opts['venue_name']     ?? 'Demo Venue';
    $venueSlug     = $opts['venue_slug']     ?? 'demo-venue';
    $timezone      = $opts['timezone']       ?? 'America/Los_Angeles';
    $adminName     = $opts['admin_name']     ?? 'Admin';
    $adminEmail    = $opts['admin_email']    ?? 'admin@venue.local';
    $adminPassword = $opts['admin_password'] ?? 'changeme';

    $stmt = $pdo->prepare('INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, ?)');
    $stmt->execute([$adminName, $adminEmail, password_hash($adminPassword, PASSWORD_DEFAULT), 'venue_admin']);
    $adminId = (int) $pdo->lastInsertId();

    $stmt = $pdo->prepare('INSERT INTO venues (name, slug, timezone) VALUES (?, ?, ?)');
    $stmt->execute([$venueName, $venueSlug, $timezone]);
    $venueId = (int) $pdo->lastInsertId();

    $templates = [
        ['Karaoke + Open Mic', 'karaoke', 'Karaoke + Open Mic', 'Two-part night: open mic for songs, poems, and experiments, then karaoke takes over. Bring originals or sing along — all experience levels welcome.', 0, '21+', ['Confirm KJ/host','Confirm sound + microphones','Confirm projection/display setup','Confirm signup process (open mic + karaoke)','Create/update recurring flyer','Publish event page','Post social reminder','Confirm door/staff coverage'], [['Staff call','staff_call','18:30'],['Open mic signups open','other','19:00'],['Doors','doors','19:30'],['Open mic','set','20:00'],['Karaoke begins','set','21:30'],['Last call for singers','other','23:30'],['Event end','curfew','00:00']]],
        ['Three-Band Local Show', 'live_music', 'Local Band Showcase', 'Three local bands, one loud night.', 12, '21+', ['Confirm headliner','Confirm support bands','Collect band bios/photos/logos','Confirm ticket price','Confirm load-in','Confirm backline needs','Create flyer','Approve flyer','Publish event page','Configure ticket link','Post social promo','Confirm door staff','Create night-of-show run sheet','Settle payouts'], [['Load-in','load_in','17:00'],['Soundcheck','soundcheck','18:00'],['Doors','doors','20:00'],['Opener set','set','20:30'],['Changeover','changeover','21:10'],['Middle band set','set','21:25'],['Changeover','changeover','22:05'],['Headliner set','set','22:20'],['Curfew/event end','curfew','23:30']]],
        ['Open Mic Night', 'open_mic', 'Open Mic Night', 'A low-pressure night for songs, poems, comedy, and experiments.', 0, '21+', ['Confirm host','Confirm signup process','Confirm equipment','Create/update recurring flyer','Publish event page','Post social reminder','Confirm house rules'], [['Staff call','staff_call','18:30'],['Signup opens','other','19:00'],['Doors','doors','19:30'],['Open mic starts','set','20:00'],['Event end','curfew','23:00']]],
        ['Promoter Night', 'promoter_night', 'Promoter Night', 'A promoter-led bill with door terms and guest list rules confirmed in advance.', 15, '21+', ['Confirm promoter agreement','Confirm lineup','Confirm ticket split/door split','Collect flyer','Approve public copy','Publish event page','Confirm guest list rules','Confirm door settlement process','Confirm staff and sound'], [['Load-in','load_in','18:00'],['Doors','doors','21:00'],['First act','set','21:30'],['Event end','curfew','01:00']]],
        ['Special Legacy Event', 'special_event', 'Special Legacy Event', 'A special event honoring the venue history with invited guests and legacy performers.', 25, '21+', ['Confirm performer/guest','Confirm ticket price','Confirm press copy','Confirm flyer/poster','Approve announcement','Publish event page','Configure ticketing','Confirm guest list/VIP list','Confirm photographer','Confirm settlement terms','Prepare post-event recap'], [['Staff call','staff_call','17:00'],['VIP doors','doors','18:30'],['Program starts','set','19:30'],['Event end','curfew','23:00']]],
        ['Swing Dancing Night', 'promoter_night', 'Swing Dancing Night', 'An evening of swing dancing with a beginner lesson followed by social dancing and a live band or DJ. Hosted by an experienced swing dance instructor.', 10, '21+', ['Confirm dance host/instructor','Confirm beginner lesson format and length','Confirm band or DJ','Confirm dance floor layout','Create/update flyer','Publish event page','Post social promo','Confirm door/staff coverage','Confirm sound setup and monitor needs'], [['Staff call','staff_call','18:00'],['Setup and floor clear','other','18:30'],['Doors','doors','19:00'],['Beginner lesson','set','19:30'],['Social dancing starts','other','20:30'],['Live band/DJ set','set','21:00'],['Event end','curfew','00:00']]],
        ['General Event', 'special_event', 'General Event', 'A general-purpose template for one-off or uncategorized shows. Update the title, type, and schedule from the event page after creation.', 0, '21+', ['Confirm date and venue','Confirm staff coverage','Create/update flyer','Publish event page','Confirm sound setup'], [['Staff call','staff_call','17:30'],['Doors','doors','19:00'],['Event','set','20:00'],['Event end','curfew','23:00']]],
    ];

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

    $addDays = fn (int $days) => (new \DateTimeImmutable("+$days days"))->format('Y-m-d');
    $events = [
        ['Punk Rock Karaoke', 'punk-rock-karaoke', 'karaoke', 'published', $addDays(1), '20:00', '21:00', 0, 1, 'Tonight is ready: host confirmed, door staff assigned, and the signup sheet is printed.'],
        ['Local Band Showcase', 'local-band-showcase', 'live_music', 'confirmed', $addDays(3), '19:00', '20:00', 12, 0, 'Main demo event. Resolve the flyer approval item, publish the page, and review the run sheet.'],
        ['Open Mic Night', 'open-mic-night', 'open_mic', 'needs_assets', $addDays(5), '19:00', '20:00', 0, 0, 'Recurring community night awaiting this week\'s social square.'],
        ['Promoter Night', 'promoter-night', 'promoter_night', 'ready_to_announce', $addDays(8), '21:00', '21:30', 15, 0, 'Announcement copy is approved and this show is ready to publish.'],
        ['Legacy Benefit Night', 'legacy-benefit-night', 'special_event', 'completed', $addDays(-2), '18:30', '19:30', 25, 1, 'Completed benefit show with settlement entered for the demo.'],
        ['Empty/Hold Night', 'empty-hold-night', 'special_event', 'empty', $addDays(10), null, null, 0, 0, 'Open hold for a future booking conversation.'],
    ];

    $eventStmt = $pdo->prepare("INSERT INTO events (venue_id, title, slug, event_type, status, description_public, description_internal, date, doors_time, show_time, age_restriction, ticket_price, public_visibility, owner_user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, '21+', ?, ?, ?)");
    $eventIds = [];
    foreach ($events as $event) {
        $eventStmt->execute([$venueId, $event[0], $event[1], $event[2], $event[3], "{$event[0]} at {$venueName}.", $event[9], $event[4], $event[5], $event[6], $event[7], $event[8], $adminId]);
        $eventIds[] = (int) $pdo->lastInsertId();
    }

    // Insert bands one at a time and capture the real auto-increment IDs —
    // schema.sql preserves live production AUTO_INCREMENT counters, so a
    // fresh install does not necessarily start band IDs at 1.
    $bandStmt = $pdo->prepare('INSERT INTO bands (name, contact_email, instagram_url, bio) VALUES (?, ?, ?, ?)');
    $bands = [
        ['Radio Static', 'static@example.com', 'https://instagram.com/radiostatic', 'Loud local punk.'],
        ['Feedback Loop', 'feedback@example.com', null, 'Garage rock four-piece.'],
        ['Echo Chamber', 'echo@example.com', null, 'Sharp hooks and louder amps.'],
    ];
    $bandIds = [];
    foreach ($bands as $band) {
        $bandStmt->execute($band);
        $bandIds[] = (int) $pdo->lastInsertId();
    }
    $pdo->prepare('INSERT INTO event_lineup (event_id, band_id, billing_order, display_name, set_time, set_length_minutes, payout_terms, status) VALUES (?, ?, 1, "Radio Static", "20:30", 40, "Door split", "confirmed"), (?, ?, 2, "Feedback Loop", "21:30", 45, "Door split", "confirmed"), (?, ?, 3, "Echo Chamber", "22:30", 45, "Door split", "tentative")')
        ->execute([$eventIds[1], $bandIds[0], $eventIds[1], $bandIds[1], $eventIds[1], $bandIds[2]]);
    $pdo->prepare('INSERT INTO event_lineup (event_id, billing_order, display_name, set_time, set_length_minutes, payout_terms, status) VALUES (?, 1, "Karaoke Host", "21:00", 150, "Flat host fee", "confirmed")')->execute([$eventIds[0]]);
    $pdo->prepare('INSERT INTO event_tasks (event_id, title, status, priority, due_date) VALUES (?, "Approve final flyer", "todo", "high", ?), (?, "Configure ticket link", "todo", "high", ?), (?, "Confirm backline", "in_progress", "normal", ?), (?, "Confirm host", "done", "normal", ?), (?, "Post announcement", "todo", "high", ?)')->execute([$eventIds[1], $addDays(1), $eventIds[1], $addDays(2), $eventIds[1], $addDays(2), $eventIds[2], $addDays(3), $eventIds[3], $addDays(1)]);
    $pdo->prepare('INSERT INTO event_blockers (event_id, title, description, owner_user_id, status, due_date) VALUES (?, "Waiting on flyer approval", "Need final artwork approval before announcing.", ?, "open", ?), (?, "Waiting on ticket link", "Promoter needs to send the live ticketing URL.", ?, "waiting", ?)')->execute([$eventIds[1], $adminId, $addDays(1), $eventIds[1], $adminId, $addDays(2)]);
    $pdo->prepare('INSERT INTO event_schedule_items (event_id, title, item_type, start_time, end_time) VALUES (?, "Load-in", "load_in", "17:00", "17:45"), (?, "Soundcheck", "soundcheck", "18:00", "19:00"), (?, "Doors", "doors", "19:00", NULL), (?, "Opener set", "set", "20:30", "21:10"), (?, "Headliner set", "set", "22:30", "23:15"), (?, "Curfew", "curfew", "23:30", NULL)')->execute([$eventIds[1], $eventIds[1], $eventIds[1], $eventIds[1], $eventIds[1], $eventIds[1]]);
    $pdo->prepare('INSERT INTO event_schedule_items (event_id, title, item_type, start_time) VALUES (?, "Staff call", "staff_call", "18:30"), (?, "Doors", "doors", "20:00"), (?, "Karaoke starts", "set", "21:00")')->execute([$eventIds[0], $eventIds[0], $eventIds[0]]);

    $assetDir = $root . '/storage/uploads/events/' . $eventIds[1];
    if (!is_dir($assetDir)) {
        mkdir($assetDir, 0775, true);
    }
    $flyerName = 'demo-local-band-showcase.svg';
    $flyerVenueLabel = htmlspecialchars($venueName, ENT_XML1 | ENT_QUOTES);
    file_put_contents($assetDir . '/' . $flyerName, '<svg xmlns="http://www.w3.org/2000/svg" width="900" height="1200" viewBox="0 0 900 1200"><rect width="900" height="1200" fill="#101318"/><rect x="54" y="54" width="792" height="1092" fill="none" stroke="#ef4338" stroke-width="18"/><text x="88" y="210" fill="#fff" font-family="Arial,sans-serif" font-size="78" font-weight="800">LOCAL BAND</text><text x="88" y="300" fill="#fff" font-family="Arial,sans-serif" font-size="78" font-weight="800">SHOWCASE</text><text x="88" y="430" fill="#ef4338" font-family="Arial,sans-serif" font-size="44" font-weight="700">' . $flyerVenueLabel . '</text><text x="88" y="540" fill="#fff" font-family="Arial,sans-serif" font-size="40">Radio Static</text><text x="88" y="610" fill="#fff" font-family="Arial,sans-serif" font-size="40">Feedback Loop</text><text x="88" y="680" fill="#fff" font-family="Arial,sans-serif" font-size="40">Echo Chamber</text><text x="88" y="1010" fill="#fff" font-family="Arial,sans-serif" font-size="38">Doors 7 PM / Show 8 PM / 21+</text></svg>');
    $pdo->prepare('INSERT INTO event_assets (event_id, asset_type, title, filename, original_filename, file_path, uploaded_by_user_id, approval_status, notes) VALUES (?, "flyer", "Local Band Showcase flyer", ?, ?, ?, ?, "needs_review", "Demo flyer ready for approval.")')->execute([$eventIds[1], $flyerName, $flyerName, 'uploads/events/' . $eventIds[1] . '/' . $flyerName, $adminId]);
    $pdo->prepare('INSERT INTO event_settlements (event_id, gross_ticket_sales, tickets_sold, bar_sales, expenses, band_payouts, promoter_payout, venue_net, notes, settled_by_user_id) VALUES (?, 2750, 110, 1840, 420, 1300, 250, 2620, "Benefit night settled after merch and door count reconciliation.", ?)')->execute([$eventIds[4], $adminId]);
    $pdo->prepare('INSERT INTO event_activity_log (event_id, user_id, action, details_json) VALUES (?, ?, "demo data seeded", ?), (?, ?, "settlement saved", ?)')->execute([$eventIds[1], $adminId, json_encode(['story' => 'Resolve open items, approve flyer, publish page']), $eventIds[4], $adminId, json_encode(['tickets_sold' => 110])]);

    // Sample staff roster — most night-of-show staff have no backstage login.
    $staff = [
        ['Sam Reyes',     'sam@demo.local',    '415-555-0101', 'manager',   45.00],
        ['Dee Cruz',      null,                '415-555-0102', 'security',  28.00],
        ['Jordan Park',   null,                '415-555-0103', 'security',  28.00],
        ['Aly Tan',       'aly@demo.local',    '415-555-0104', 'bartender', 22.00],
        ['Mo Sandoval',   null,                '415-555-0105', 'barback',   18.00],
        ['Riley Quinn',   null,                '415-555-0106', 'door',      20.00],
        ['Casey Lopez',   'casey@demo.local',  '415-555-0107', 'sound',     35.00],
        ['Robin Vega',    null,                '415-555-0108', 'lighting',  30.00],
        ['Pat Nakamura',  null,                '415-555-0109', 'stagehand', 22.00],
    ];
    $staffStmt = $pdo->prepare('INSERT INTO staff_members (name, email, phone, default_role, hourly_rate) VALUES (?, ?, ?, ?, ?)');
    $staffIds = [];
    foreach ($staff as $row) {
        $staffStmt->execute($row);
        $staffIds[] = (int) $pdo->lastInsertId();
    }

    // Pre-staff the Local Band Showcase as a demo.
    $staffingStmt = $pdo->prepare('INSERT INTO event_staffing (event_id, staff_member_id, role, call_time, end_time, hourly_rate, status) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $staffingShifts = [
        [$staffIds[0], 'manager',   '17:00', '00:00', 45.00, 'confirmed'],
        [$staffIds[6], 'sound',     '17:00', '23:30', 35.00, 'confirmed'],
        [$staffIds[7], 'lighting',  '17:00', '23:30', 30.00, 'scheduled'],
        [$staffIds[1], 'security',  '19:30', '00:00', 28.00, 'confirmed'],
        [$staffIds[2], 'security',  '19:30', '00:00', 28.00, 'scheduled'],
        [$staffIds[3], 'bartender', '19:00', '00:00', 22.00, 'confirmed'],
        [$staffIds[4], 'barback',   '19:00', '00:00', 18.00, 'scheduled'],
        [$staffIds[5], 'door',      '19:30', '23:00', 20.00, 'confirmed'],
    ];
    foreach ($staffingShifts as $shift) {
        $staffingStmt->execute([$eventIds[1], $shift[0], $shift[1], $shift[2], $shift[3], $shift[4], $shift[5]]);
    }

    // A handful of CRM contacts so the Contacts page (KPIs, table, search) has
    // something real to render on a fresh install instead of its empty state.
    $contacts = [
        ['Jordan',  'Levy',    'jordan.levy@demo.local',  '415-555-0201', 3, 145.00, 1],
        ['Avery',   'Levinson','avery.levinson@demo.local', '415-555-0202', 1, 40.00,  0],
        ['Morgan',  'Cruz',    'morgan.cruz@demo.local',  '415-555-0203', 5, 260.00, 1],
        ['Sam',     'Iverson', null,                       null,           0, 0.00,   0],
    ];
    $contactStmt = $pdo->prepare(
        'INSERT INTO contacts (source, first_name, last_name, email, phone, tickets_count, usd_spend, marketing_opted_in, opt_in_date, last_interaction)
         VALUES ("manual", ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    foreach ($contacts as $c) {
        $contactStmt->execute([
            $c[0], $c[1], $c[2], $c[3], $c[4], $c[5], $c[6],
            $c[6] ? $addDays(-30) : null,
            $c[4] > 0 ? $addDays(-7) : null,
        ]);
    }

    // Contract clause library + starter templates (idempotent, venue-agnostic).
    require_once $root . '/database/seed_contracts.php';
    seed_contract_library($pdo);

    return [
        'admin_email'    => $adminEmail,
        'admin_password' => $adminPassword,
        'venue_id'       => $venueId,
        'event_ids'      => $eventIds,
        // "Local Band Showcase" — the most fully-populated demo event (flyer,
        // tasks, blockers, run-of-show, activity log). Good default fixture
        // for anything that needs a real, non-trivial event id (e.g.
        // tests/ui's UI_EVENT_ID).
        'primary_event_id' => $eventIds[1] ?? null,
    ];
}
