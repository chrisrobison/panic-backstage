<?php
/**
 * DB-backed regression test for the SEO-friendly public event page
 * (public_slug + /e/{slug} — see database/migrations/105_add_event_public_slug.sql,
 * src/Events/EventRowHelpers.php, src/Events/PublicEventLookup.php,
 * src/PublicEventPage.php, Support::event_public_path()).
 *
 * Creates a real event through the actual POST /api/events endpoint (so
 * Events::create() -> assignPublicSlug() runs exactly as it does in
 * production), exercises the lookup/rendering path end to end, then deletes
 * the fixture in a finally block. Opt in with RUN_DB_TESTS=1 — same
 * convention as events_clone_db_test.php.
 *
 * Run with: RUN_DB_TESTS=1 php tests/public_event_page_db_test.php
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Panic\Auth;
use Panic\Database;
use Panic\Env;
use Panic\Events;
use Panic\Events\PublicEventLookup;
use Panic\PublicEventPage;
use Panic\Request;
use Panic\Response;

$root = dirname(__DIR__);
Env::load($root . '/.env');
putenv('SHEET_SYNC_ENABLED=0');
putenv('GCAL_SYNC_ENABLED=0');

$passed = 0;
$failed = 0;
function ok(bool $condition, string $label): void {
    global $passed, $failed;
    if ($condition) { echo "  \xE2\x9C\x93 $label\n"; $passed++; }
    else { echo "  \xE2\x9C\x97 FAIL: $label\n"; $failed++; }
}

function responseValue(Response $response, string $property): mixed {
    $reflection = new ReflectionProperty(Response::class, $property);
    $reflection->setAccessible(true);
    return $reflection->getValue($response);
}

echo "\n=== Public event page (SEO slug + /e/{slug}) ===\n\n";

try {
    $db = new Database();
    $db->one('SELECT 1');
} catch (Throwable $error) {
    fwrite(STDERR, "Could not connect to the configured database: {$error->getMessage()}\n");
    exit(1);
}

$venue = $db->one('SELECT id FROM venues ORDER BY id LIMIT 1');
$admin = $db->one("SELECT id, name, email, role FROM users WHERE role = 'venue_admin' ORDER BY id LIMIT 1");
if (!$venue || !$admin) {
    fwrite(STDERR, "public_event_page_db_test.php needs a venue and venue_admin user.\n");
    exit(1);
}
$venueId = (int) $venue['id'];

$auth = new Auth();
$auth->setUser($admin);
$marker = 'PB TEST PUBLIC PAGE 1999 — ' . bin2hex(random_bytes(4));
$created = [];

try {
    $cursor = new DateTimeImmutable('+520 days');
    while ($db->one(
        "SELECT id FROM events WHERE venue_id = ? AND status NOT IN ('empty','canceled') AND date = ?",
        [$venueId, $cursor->format('Y-m-d')]
    )) {
        $cursor = $cursor->modify('+1 day');
    }
    $date = $cursor->format('Y-m-d');

    // Title starts with digits ("1999...") deliberately — regression coverage
    // for uniquePublicSlug()'s numeric-slug guard (a slug that's all digits
    // would be indistinguishable from a numeric id in PublicEventLookup's
    // ctype_digit() branch).
    $endpoint = new Events($db, $auth, [], $root);
    $request = new Request('POST', '/api/events', [], [
        'title' => $marker,
        'date' => $date,
        'venue_id' => $venueId,
        'event_type' => 'live_music',
        'status' => 'confirmed',
        'public_visibility' => '1',
        'description_public' => 'A throwaway fixture for the public-event-page regression test.',
    ], [], []);
    $response = $endpoint->handle($request);
    ok(responseValue($response, 'status') === 200, 'create endpoint returns 200');
    $eventId = (int) (responseValue($response, 'body')['id'] ?? 0);
    ok($eventId > 0, 'event created');
    if ($eventId > 0) $created[] = $eventId;

    $row = $db->one('SELECT id, title, public_slug FROM events WHERE id = ?', [$eventId]);
    $slug = (string) ($row['public_slug'] ?? '');
    ok($slug !== '', 'assignPublicSlug() populated public_slug on create');
    ok(!ctype_digit($slug), 'public_slug is never purely numeric, even for a title that contains only digits after the marker');
    ok(str_starts_with($slug, 'pb-test-public-page-1999'), 'public_slug is title-derived');

    // A title that slugifies to *entirely* digits (e.g. "1999") would be
    // indistinguishable from a numeric id in PublicEventLookup's
    // ctype_digit() branch — uniquePublicSlug() must refuse to produce one.
    // No DB row needed: exercised directly via the trait, like Series'
    // equivalent uniquePublicSlug().
    $helperHost = new class ($db) {
        use \Panic\Events\EventRowHelpers;
        public function __construct(public Database $db) {}
        public function uniquePublicSlugFor(string $title): string {
            $method = new ReflectionMethod($this, 'uniquePublicSlug');
            $method->setAccessible(true);
            return $method->invoke($this, $title);
        }
    };
    $numericSlug = $helperHost->uniquePublicSlugFor('1999');
    ok(!ctype_digit($numericSlug), "uniquePublicSlug('1999') is not purely numeric (got \"$numericSlug\")");

    ok(\Panic\event_public_path($row) === 'e/' . rawurlencode($slug), 'event_public_path() prefers /e/{public_slug}');

    $byPublicSlug = PublicEventLookup::resolve($db, $slug);
    ok($byPublicSlug !== null && (int) $byPublicSlug['id'] === $eventId, 'PublicEventLookup resolves by public_slug');
    $byId = PublicEventLookup::resolve($db, (string) $eventId);
    ok($byId !== null && (int) $byId['id'] === $eventId, 'PublicEventLookup resolves by numeric id (legacy links)');

    $page = new PublicEventPage($db, $auth, ['slug' => $slug], $root);
    $pageResponse = $page->handle(new Request('GET', '/e/' . $slug, [], [], [], []));
    ok(responseValue($pageResponse, 'status') === 200, 'PublicEventPage renders 200 for a public event');
    $html = (string) responseValue($pageResponse, 'body');
    ok(str_contains($html, '<title>' . htmlspecialchars($marker, ENT_QUOTES) . ' - Panic Backstage</title>'), 'renders the real event title, not the generic shell title');
    ok(str_contains($html, 'rel="canonical" href="' . htmlspecialchars(getenv('APP_URL') . '/e/' . $slug, ENT_QUOTES) . '"'), 'canonical link points at the /e/{slug} address');
    ok(str_contains($html, 'application/ld+json'), 'renders schema.org JSON-LD for search rich results');
    ok(str_contains($html, '<pb-public-event-page>'), 'embeds the same interactive shell as event.html');

    // Hiding the event (public_visibility=0) must 404 the page immediately —
    // this is the same gate PublicEvents (JSON API) enforces, and it's the
    // thing the "Hide Public Page" button in the event workspace relies on.
    $db->run('UPDATE events SET public_visibility = 0 WHERE id = ?', [$eventId]);
    $hiddenPage = new PublicEventPage($db, $auth, ['slug' => $slug], $root);
    $hiddenResponse = $hiddenPage->handle(new Request('GET', '/e/' . $slug, [], [], [], []));
    ok(responseValue($hiddenResponse, 'status') === 404, 'hiding the event 404s its /e/{slug} page immediately');
    ok(PublicEventLookup::resolve($db, $slug) === null, 'PublicEventLookup refuses a non-public event by its own slug');
} finally {
    if ($created) {
        $placeholders = implode(',', array_fill(0, count($created), '?'));
        $db->run("DELETE FROM events WHERE id IN ($placeholders)", $created);
    }
}

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
