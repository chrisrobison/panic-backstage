<?php

/**
 * Panic Backstage — backfill cid: images in historical outbox rows
 *
 * Inline images (e.g. ticket QR PNGs) are embedded in outgoing mail as MIME
 * parts referenced by `cid:` URLs. The admin outbox stored only the HTML body,
 * so those references can't resolve in the browser and show as broken images.
 *
 * New sends are fixed at write time (Mailer::logToOutbox inlines them as data:
 * URIs). This script repairs rows that were stored BEFORE that fix by recovering
 * the original image bytes from the on-disk .eml copies and rewriting each
 * `cid:{id}` reference to a self-contained `data:` URI.
 *
 * The join key is the cid token itself: it is a globally-unique random string
 * that appears both in the stored html_body and as `Content-ID: <id>` in the
 * matching .eml file, so no timestamp/recipient heuristics are needed.
 *
 * Mail copies / DB scope (mirrors Mailer + migrate.php):
 *   single-tenant →  storage/mail/        + default DB
 *   multi-tenant  →  clients/<slug>/mail/  + each tenant DB
 *
 * Usage:
 *   php scripts/backfill-outbox-cid-images.php [--dry-run]
 *       Single-tenant (default) OR, when SUPER_DB_NAME is set, every tenant.
 *   php scripts/backfill-outbox-cid-images.php tenant <database> [--dry-run]
 *       Backfill one specific tenant database.
 *
 * --dry-run reports what WOULD change without writing.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/src/bootstrap.php';

Panic\Env::load($root . '/.env');

$argv   = $_SERVER['argv'] ?? [];
$args   = array_slice($argv, 1);
$dryRun = in_array('--dry-run', $args, true);
$pos    = array_values(array_filter($args, static fn ($a) => !str_starts_with($a, '--')));
$command = $pos[0] ?? '';
$argDb   = $pos[1] ?? null;

try {
    if ($command === 'tenant') {
        if (!$argDb) {
            fwrite(STDERR, "Usage: backfill-outbox-cid-images.php tenant <database> [--dry-run]\n");
            exit(1);
        }
        $db      = new Panic\Database(Panic\Database\Connection::tenant($argDb));
        $mailDir = tenantMailDir($root, $argDb);
        backfillScope($db, $mailDir, "tenant:{$argDb}", $dryRun);
    } elseif (getenv('SUPER_DB_NAME')) {
        // Multi-tenant: iterate every tenant in the super registry.
        $tenants = Panic\Database\Connection::super()
            ->query('SELECT slug, database_name FROM tenants ORDER BY slug')
            ->fetchAll(PDO::FETCH_ASSOC);

        if (!$tenants) {
            echo "No tenants found in super registry.\n";
            exit(0);
        }
        foreach ($tenants as $t) {
            $slug    = (string) $t['slug'];
            $dbName  = (string) $t['database_name'];
            $db      = new Panic\Database(Panic\Database\Connection::tenant($dbName));
            $mailDir = $root . '/clients/' . $slug . '/mail';
            backfillScope($db, $mailDir, "tenant:{$slug}", $dryRun);
        }
    } else {
        // Single-tenant (legacy) mode.
        $db      = new Panic\Database();
        $mailDir = $root . '/storage/mail';
        backfillScope($db, $mailDir, 'single-tenant', $dryRun);
    }
} catch (\Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
    exit(1);
}

echo "\nDone.\n";

// ─────────────────────────────────────────────────────────────────────────────

/**
 * Resolve a tenant database name to its clients/<slug>/mail directory by looking
 * the slug up in the super registry. Falls back to a *-keyed search if needed.
 */
function tenantMailDir(string $root, string $dbName): string
{
    try {
        $stmt = Panic\Database\Connection::super()
            ->prepare('SELECT slug FROM tenants WHERE database_name = ? LIMIT 1');
        $stmt->execute([$dbName]);
        $slug = (string) ($stmt->fetchColumn() ?: '');
        if ($slug !== '') {
            return $root . '/clients/' . $slug . '/mail';
        }
    } catch (\Throwable) {
        // Super registry unavailable — fall through to single-tenant path.
    }
    return $root . '/storage/mail';
}

/**
 * Backfill one DB scope: build a cid→bytes index from its .eml files, then
 * rewrite every outbox row that still references cid: images.
 */
function backfillScope(Panic\Database $db, string $mailDir, string $label, bool $dryRun): void
{
    echo "── {$label} ──\n";
    echo "   mail dir: {$mailDir}\n";

    $index = buildCidIndex($mailDir);
    echo '   indexed ' . count($index) . " cid image part(s) from .eml files\n";

    $rows = $db->all("SELECT id, html_body FROM outbox WHERE html_body LIKE '%cid:%'");
    echo '   ' . count($rows) . " outbox row(s) reference cid:\n";

    $updated = 0;
    $unresolved = 0;
    foreach ($rows as $row) {
        $id   = (int) $row['id'];
        $html = (string) $row['html_body'];

        [$newHtml, $resolved, $missing] = rewriteCidImages($html, $index);
        $unresolved += $missing;

        if ($resolved === 0 || $newHtml === $html) {
            if ($missing > 0) {
                echo "   • row #{$id}: {$missing} cid ref(s) unresolved (no matching .eml) — skipped\n";
            }
            continue;
        }

        if ($dryRun) {
            echo "   • row #{$id}: would inline {$resolved} image(s)"
               . ($missing ? ", {$missing} unresolved" : '') . "\n";
        } else {
            $db->run('UPDATE outbox SET html_body = ? WHERE id = ?', [$newHtml, $id]);
            echo "   • row #{$id}: inlined {$resolved} image(s)"
               . ($missing ? ", {$missing} unresolved" : '') . "\n";
        }
        $updated++;
    }

    $verb = $dryRun ? 'would update' : 'updated';
    echo "   → {$verb} {$updated} row(s)"
       . ($unresolved ? ", {$unresolved} cid ref(s) had no matching image" : '') . "\n\n";
}

/**
 * Scan every *.eml file in $mailDir and return a map of bare Content-ID => raw
 * image bytes. cid tokens are globally unique so a flat map across all files is
 * safe (no collisions between sends).
 *
 * @return array<string,string>
 */
function buildCidIndex(string $mailDir): array
{
    $index = [];
    if (!is_dir($mailDir)) {
        return $index;
    }
    foreach (glob($mailDir . '/*.eml') ?: [] as $file) {
        $raw = @file_get_contents($file);
        if ($raw === false || $raw === '') {
            continue;
        }
        // Match each MIME part that carries a Content-ID and base64 body.
        // Body runs until the next boundary line (begins with "--"); base64
        // never contains "-", so a line starting with "--" is always a boundary.
        if (!preg_match_all(
            '/Content-ID:\s*<([^>]+)>.*?\r?\n\r?\n(.*?)\r?\n--/s',
            $raw,
            $matches,
            PREG_SET_ORDER
        )) {
            continue;
        }
        foreach ($matches as $m) {
            $cid    = trim($m[1]);
            $b64    = preg_replace('/\s+/', '', $m[2]);
            $bytes  = base64_decode((string) $b64, true);
            if ($cid !== '' && $bytes !== false && $bytes !== '' && !isset($index[$cid])) {
                $index[$cid] = $bytes;
            }
        }
    }
    return $index;
}

/**
 * Rewrite cid: references in $html to data: URIs using $index.
 *
 * @param array<string,string> $index  cid => raw image bytes
 * @return array{0:string,1:int,2:int}  [newHtml, resolvedCount, unresolvedCount]
 */
function rewriteCidImages(string $html, array $index): array
{
    if (!preg_match_all('/cid:([^"\'\s>)]+)/', $html, $m)) {
        return [$html, 0, 0];
    }
    $resolved = 0;
    $missing  = 0;
    $seen     = [];
    foreach (array_unique($m[1]) as $cid) {
        if (!isset($index[$cid])) {
            $missing++;
            continue;
        }
        if (isset($seen[$cid])) {
            continue;
        }
        $seen[$cid] = true;
        $bytes   = $index[$cid];
        $mime    = detectImageMime($bytes);
        $dataUri = 'data:' . $mime . ';base64,' . base64_encode($bytes);
        $html    = preg_replace('/cid:' . preg_quote($cid, '/') . '/', $dataUri, $html);
        $resolved++;
    }
    return [$html, $resolved, $missing];
}

/** Best-effort image MIME sniff from leading magic bytes; defaults to PNG. */
function detectImageMime(string $bytes): string
{
    if (strncmp($bytes, "\x89PNG\r\n\x1a\n", 8) === 0) {
        return 'image/png';
    }
    if (strncmp($bytes, "\xFF\xD8\xFF", 3) === 0) {
        return 'image/jpeg';
    }
    if (strncmp($bytes, 'GIF8', 4) === 0) {
        return 'image/gif';
    }
    return 'image/png';
}
