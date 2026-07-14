<?php
declare(strict_types=1);

/**
 * Panic Backstage — trial signup provisioning worker
 *
 * The marketing site (panicbackstage.com/www/signup.php) only records a
 * trial request and reserves the subdomain — it appends a JSON line with
 * "status":"pending_provision" to
 *   ../../www/storage/trials.ndjson.php
 * and tells the visitor to check their email. This script is the "separate
 * worker" that README always said should exist to actually fulfil that
 * promise: it drains that queue, provisions each tenant (super `tenants` +
 * `tenant_domains` rows, tenant database via TenantProvisioner), and emails
 * the admin a real magic sign-in link at their new
 * `<subdomain>.panicbackstage.com` instance.
 *
 * Before this script existed, every trial signup — including real prospects
 * — sat at "pending_provision" forever with no instance and no email ever
 * sent. See the 2026-07-14 incident: signups going back a week were stuck.
 *
 * Usage:
 *   php scripts/process-trials.php [--dry-run] [--retry-failed]
 *
 *   --dry-run       Report what would happen; make no DB/filesystem changes
 *                    and send no mail.
 *   --retry-failed  Also reprocess rows previously marked "failed" (normal
 *                    runs skip them so a broken row doesn't retry forever
 *                    unattended — check the log, fix the cause, then rerun
 *                    with this flag or hand-edit the row's status back to
 *                    "pending_provision").
 *
 * Idempotent: safe to run again after a partial failure. Tenant lookup is by
 * slug (reuses an existing "provisioning"-status tenant row instead of
 * inserting a duplicate), TenantProvisioner::provision() itself is
 * idempotent, and the domain insert is skipped if it already exists.
 *
 * Intended to run on a schedule via cron-process-trials.sh.
 */

require __DIR__ . '/../src/bootstrap.php';

use Panic\Auth;
use Panic\Database;
use Panic\Database\Connection;
use Panic\Env;
use Panic\Mailer;
use Panic\Tenant\TenantContext;
use Panic\Tenant\TenantProvisioner;

$root = dirname(__DIR__);
Env::load($root . '/.env');

$args        = array_slice($argv, 1);
$dryRun      = in_array('--dry-run', $args, true);
$retryFailed = in_array('--retry-failed', $args, true);

$superDbName = (string) (getenv('SUPER_DB_NAME') ?: '');
if ($superDbName === '') {
    fwrite(STDERR, "SUPER_DB_NAME is not set — multi-tenant mode is off, nothing to provision.\n");
    exit(1);
}

// The marketing site is a sibling directory to this app:
//   .../panicbackstage.com/app/scripts/process-trials.php  (this file)
//   .../panicbackstage.com/www/storage/trials.ndjson.php    (the queue)
$trialsFile = dirname($root) . '/www/storage/trials.ndjson.php';
if (!is_file($trialsFile)) {
    fwrite(STDERR, "No trials file at {$trialsFile} — nothing to do.\n");
    exit(0);
}

// Reserved subdomains that must never be provisioned as a tenant, mirroring
// signup.php's own reserved list (defence in depth against a stale/edited
// queue file, not the primary guard).
$reserved = ['www', 'app', 'api', 'admin', 'staging', 'help', 'support', 'mail', 'blog', 'status', 'panic', 'backstage'];

$fh = fopen($trialsFile, 'c+');
if ($fh === false) {
    fwrite(STDERR, "Could not open {$trialsFile}\n");
    exit(1);
}
flock($fh, LOCK_EX);
$raw = stream_get_contents($fh);
$lines = explode("\n", (string) $raw);

$guardLine = null;
$records   = [];  // ordered list of ['raw' => original line, 'row' => decoded array|null]
foreach ($lines as $i => $line) {
    if (trim($line) === '') {
        continue;
    }
    $decoded = json_decode($line, true);
    if (!is_array($decoded)) {
        // The PHP guard line (or any other non-JSON line) — keep as-is, first
        // occurrence is treated as the guard.
        if ($guardLine === null) {
            $guardLine = $line;
        }
        continue;
    }
    $records[] = $decoded;
}
if ($guardLine === null) {
    $guardLine = '<?php http_response_code(403); exit; ?>';
}

$changed = false;
$summary = ['provisioned' => 0, 'skipped' => 0, 'failed' => 0];

foreach ($records as &$row) {
    $status = (string) ($row['status'] ?? '');
    if ($status !== 'pending_provision' && !($retryFailed && $status === 'failed')) {
        continue;
    }

    $reference = (string) ($row['reference'] ?? '?');
    $subdomain = strtolower(trim((string) ($row['subdomain'] ?? '')));
    $venue     = trim((string) ($row['venue'] ?? ''));
    $name      = trim((string) ($row['name'] ?? ''));
    $email     = trim((string) ($row['email'] ?? ''));

    if ($subdomain === '' || $venue === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo "[{$reference}] SKIP — malformed record (missing subdomain/venue/email)\n";
        $summary['skipped']++;
        continue;
    }
    if (in_array($subdomain, $reserved, true)) {
        echo "[{$reference}] SKIP — subdomain '{$subdomain}' is reserved\n";
        $summary['skipped']++;
        continue;
    }

    $domain = $subdomain . '.panicbackstage.com';
    $dbName = 'panic_backstage_' . str_replace('-', '_', $subdomain);

    echo "[{$reference}] {$venue} <{$email}> -> https://{$domain}\n";
    if ($dryRun) {
        echo "  (dry-run) would provision tenant slug={$subdomain} db={$dbName}\n";
        continue;
    }

    try {
        $super = Connection::super();

        // Reuse an existing tenant row for this slug if one already exists
        // (e.g. a prior run got partway through), instead of inserting a
        // duplicate — CREATE would fail on the unique slug constraint anyway,
        // but this makes a retry after a mid-way failure clean.
        $stmt = $super->prepare('SELECT * FROM tenants WHERE slug = ? LIMIT 1');
        $stmt->execute([$subdomain]);
        $tenantRow = $stmt->fetch();

        if (!$tenantRow) {
            $ins = $super->prepare(
                'INSERT INTO tenants (slug, name, database_name, admin_name, admin_email, status)
                 VALUES (?, ?, ?, ?, ?, \'provisioning\')'
            );
            $ins->execute([$subdomain, $venue, $dbName, $name !== '' ? $name : null, $email]);
            $tenantId = (int) $super->lastInsertId();
            $stmt = $super->prepare('SELECT * FROM tenants WHERE id = ? LIMIT 1');
            $stmt->execute([$tenantId]);
            $tenantRow = $stmt->fetch();
        }

        TenantProvisioner::provision($tenantRow);
        $super->prepare("UPDATE tenants SET status = 'active' WHERE id = ?")->execute([$tenantRow['id']]);

        // Domain mapping — skip if it's already there (retry safety).
        $exists = $super->prepare('SELECT id FROM tenant_domains WHERE domain = ? LIMIT 1');
        $exists->execute([$domain]);
        if (!$exists->fetch()) {
            $super->prepare(
                'INSERT INTO tenant_domains (tenant_id, domain, is_primary) VALUES (?, ?, 1)'
            )->execute([$tenantRow['id'], $domain]);
        }

        // ── Send the onboarding email ────────────────────────────────────────
        // Mirrors AuthEndpoint::requestMagicLink, run in-process against the
        // new tenant's own database and its own domain (not the global
        // APP_URL, which is meaningless in SaaS mode).
        $tenantPdo = Connection::tenant($dbName);
        TenantContext::setCurrent(new TenantContext(
            array_merge($tenantRow, ['domain' => $domain]),
            $tenantPdo
        ));

        $auth  = new Auth();
        $token = $auth->generateToken(24);
        $hash  = $auth->hashToken($token);
        $tenantDb = new Database($tenantPdo);
        $tenantDb->run(
            'INSERT INTO magic_link_tokens (email, token_hash, expires_at)
             VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR))',
            [$email, $hash]
        );

        $link = "https://{$domain}/login.html?token={$token}";
        (new Mailer($root, $tenantDb))->sendTemplate(
            $email,
            'Your Backstage login link',
            'magic-link',
            ['login_url' => $link]
        );
        TenantContext::setCurrent(null);

        $row['status']      = 'provisioned';
        $row['provisioned_at'] = gmdate('c');
        $changed = true;
        $summary['provisioned']++;
        echo "  OK — provisioned + emailed {$email}\n";
    } catch (\Throwable $e) {
        TenantContext::setCurrent(null);
        $row['status'] = 'failed';
        $row['error']  = $e->getMessage();
        $changed = true;
        $summary['failed']++;
        echo "  FAILED — {$e->getMessage()}\n";
    }
}
unset($row);

if ($changed && !$dryRun) {
    $out = $guardLine . "\n";
    foreach ($records as $row) {
        $out .= json_encode($row, JSON_UNESCAPED_SLASHES) . "\n";
    }
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, $out);
}

flock($fh, LOCK_UN);
fclose($fh);

printf(
    "Done. provisioned=%d skipped=%d failed=%d%s\n",
    $summary['provisioned'],
    $summary['skipped'],
    $summary['failed'],
    $dryRun ? ' (dry-run)' : ''
);
exit($summary['failed'] > 0 ? 1 : 0);
