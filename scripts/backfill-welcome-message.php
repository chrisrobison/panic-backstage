<?php

/**
 * Panic Backstage — backfill the welcome message into every user's inbox
 *
 * Drops the one-time system welcome (template = 'welcome') into the Inbox of
 * every existing user who doesn't already have it. No email is sent — the
 * message is inserted straight into the `messages` table. New users get the
 * same greeting automatically on first app load (see Me + WelcomeMessage).
 *
 * Idempotent: re-running only inserts for users still missing the message, so
 * it's safe to run repeatedly.
 *
 * DB scope (mirrors migrate.php / the cid backfill):
 *   single-tenant →  default DB (DB_*)
 *   multi-tenant  →  every tenant DB when SUPER_DB_NAME is set
 *
 * Usage:
 *   php scripts/backfill-welcome-message.php [--dry-run]
 *       Single-tenant (default) OR, when SUPER_DB_NAME is set, every tenant.
 *   php scripts/backfill-welcome-message.php tenant <database> [--dry-run]
 *       Backfill one specific tenant database.
 *
 * --dry-run reports what WOULD be inserted without writing.
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
            fwrite(STDERR, "Usage: backfill-welcome-message.php tenant <database> [--dry-run]\n");
            exit(1);
        }
        withTenantAppUrl($argDb, static function () use ($argDb, $dryRun) {
            $db = new Panic\Database(Panic\Database\Connection::tenant($argDb));
            backfillWelcome($db, "tenant:{$argDb}", $dryRun);
        });
    } elseif (getenv('SUPER_DB_NAME')) {
        // Multi-tenant: iterate every tenant in the super registry.
        $tenants = Panic\Database\Connection::super()
            ->query('SELECT id, slug, database_name FROM tenants ORDER BY slug')
            ->fetchAll(PDO::FETCH_ASSOC);

        if (!$tenants) {
            echo "No tenants found in super registry.\n";
            exit(0);
        }
        foreach ($tenants as $t) {
            $slug   = (string) $t['slug'];
            $dbName = (string) $t['database_name'];
            // Point welcome links at this tenant's own domain.
            $domain = tenantPrimaryDomain((int) $t['id']);
            $prev   = getenv('APP_URL') ?: '';
            if ($domain !== null) {
                putenv('APP_URL=https://' . $domain);
            }
            $db = new Panic\Database(Panic\Database\Connection::tenant($dbName));
            backfillWelcome($db, "tenant:{$slug}", $dryRun);
            putenv('APP_URL=' . $prev);
        }
    } else {
        // Single-tenant (legacy) mode.
        $db = new Panic\Database();
        backfillWelcome($db, 'single-tenant', $dryRun);
    }
} catch (\Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
    exit(1);
}

echo "\nDone.\n";

// ─────────────────────────────────────────────────────────────────────────────

/**
 * Insert the welcome message for every user in $db that doesn't already have it.
 */
function backfillWelcome(Panic\Database $db, string $label, bool $dryRun): void
{
    echo "── {$label} ──\n";

    try {
        $db->one('SELECT 1 FROM messages LIMIT 1');
    } catch (\Throwable) {
        echo "   messages table not found — run migrations first; skipping.\n\n";
        return;
    }

    $users = $db->all('SELECT id, name, email FROM users');
    $inserted = 0;
    $skipped  = 0;

    foreach ($users as $u) {
        $uid = (int) $u['id'];
        $has = $db->one(
            'SELECT 1 FROM messages WHERE recipient_user_id = ? AND template = ? LIMIT 1',
            [$uid, Panic\WelcomeMessage::TEMPLATE]
        );
        if ($has) {
            $skipped++;
            continue;
        }
        if ($dryRun) {
            $inserted++;
            continue;
        }
        if (Panic\WelcomeMessage::ensureFor($db, $uid, $u['name'] ?? null, $u['email'] ?? null)) {
            $inserted++;
        } else {
            $skipped++;
        }
    }

    $verb = $dryRun ? 'would add' : 'added';
    echo "   {$verb} {$inserted}, skipped {$skipped} (already had it), of " . count($users) . " user(s)\n\n";
}

/**
 * Best-effort lookup of a tenant's primary domain from the super registry so
 * welcome links point at the right hostname.
 */
function tenantPrimaryDomain(int $tenantId): ?string
{
    try {
        $stmt = Panic\Database\Connection::super()
            ->prepare('SELECT domain FROM tenant_domains WHERE tenant_id = ? ORDER BY id LIMIT 1');
        $stmt->execute([$tenantId]);
        $domain = $stmt->fetchColumn();
        return $domain ? (string) $domain : null;
    } catch (\Throwable) {
        return null;
    }
}

/**
 * Run $fn with APP_URL temporarily pointed at the given tenant database's
 * primary domain (restored afterward). Used by the `tenant <db>` command.
 */
function withTenantAppUrl(string $dbName, callable $fn): void
{
    $prev   = getenv('APP_URL') ?: '';
    $domain = null;
    try {
        $stmt = Panic\Database\Connection::super()
            ->prepare('SELECT id FROM tenants WHERE database_name = ? LIMIT 1');
        $stmt->execute([$dbName]);
        $tenantId = (int) ($stmt->fetchColumn() ?: 0);
        if ($tenantId > 0) {
            $domain = tenantPrimaryDomain($tenantId);
        }
    } catch (\Throwable) {
        // Super registry unavailable — keep the existing APP_URL.
    }
    if ($domain !== null) {
        putenv('APP_URL=https://' . $domain);
    }
    try {
        $fn();
    } finally {
        putenv('APP_URL=' . $prev);
    }
}
