<?php
declare(strict_types=1);

namespace Panic;

function slugify(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
    return trim($value, '-') ?: 'item';
}

function boolish(mixed $value): int
{
    return in_array($value, [1, '1', true, 'true', 'on', 'yes'], true) ? 1 : 0;
}

function date_or_null(mixed $value): ?string
{
    return $value ? (string) $value : null;
}

/**
 * Parse a DATETIME/TIMESTAMP string read back from the DB into a Unix epoch,
 * treating it as UTC. The DB session is pinned to UTC (see Database.php /
 * Database/Connection.php), independent of the app's display timezone
 * (America/Los_Angeles, set in bootstrap.php for human-facing formatting).
 *
 * Use this — not bare strtotime() — anywhere a DB timestamp produced by
 * NOW()/CURRENT_TIMESTAMP is compared against time() or another epoch (token
 * expiry, rate-limit windows, etc). strtotime() on an unsuffixed string
 * parses it in the ambient default timezone, which would silently skew the
 * comparison by the offset between UTC and America/Los_Angeles.
 */
function db_timestamp_to_epoch(?string $value): ?int
{
    if ($value === null || $value === '') {
        return null;
    }
    $ts = strtotime($value . ' UTC');
    return $ts !== false ? $ts : null;
}

/**
 * Relative path (no host) to an event's public-facing page.
 *
 * Prefers the pretty, SEO-friendly /e/{public_slug} address (migration 105):
 * public_slug is assigned once at creation (Events\EventRowHelpers::
 * assignPublicSlug()) and never changes again, unlike the mutable `slug`
 * column, which is regenerated from title+date on every edit (see
 * Events::update()) and would silently break any link built from it. Falls
 * back to the old id-keyed query string for the (now rare) event row that
 * predates this column or otherwise never got a slug assigned — the id
 * never changes either, so that link never breaks — keeping every
 * previously shared/printed/QR-coded link resolving indefinitely.
 *
 * Callers that need an absolute URL should prefix this with their own
 * app-base URL (see Feed::eventUrl(), EventEmailComposer::eventUrl(),
 * PublicTickets::checkout(), Events\GenerateQr::publicUrl()).
 */
function event_public_path(array $event): string
{
    if (!empty($event['public_slug'])) {
        return 'e/' . rawurlencode((string) $event['public_slug']);
    }
    return 'event.html?id=' . rawurlencode((string) $event['id']);
}

/** Stable public page for a recurring series; it resolves the next occurrence. */
function series_public_path(array $series): string
{
    return 'event.html?series=' . rawurlencode((string) $series['public_slug']);
}

function log_activity(Database $db, int $eventId, ?int $userId, string $action, array $details = []): void
{
    $db->run(
        'INSERT INTO event_activity_log (event_id, user_id, action, details_json) VALUES (?, ?, ?, ?)',
        [$eventId, $userId, $action, json_encode($details)]
    );
}

/**
 * Same shape as log_activity() above, but for the per-contact audit trail
 * (contact_activity — see database/migrations/055_listmaster_extras.sql)
 * shown in ListMaster's contact detail "Activity" tab. Written from both
 * Contacts.php (contact created/updated, tag assigned/removed) and
 * MailingLists.php (list joined/left, status changed, CSV import) so a
 * contact's full history reads as one timeline regardless of which endpoint
 * touched it.
 */
function log_contact_activity(Database $db, int $contactId, ?int $userId, string $type, string $message, array $details = []): void
{
    $db->run(
        'INSERT INTO contact_activity (contact_id, user_id, type, message, details_json) VALUES (?, ?, ?, ?, ?)',
        [$contactId, $userId, $type, $message, $details ? json_encode($details) : null]
    );
}

/**
 * Same shape as log_contact_activity() above, but for the standalone Tasks
 * app's per-task audit trail (task_activity — see database/migrations/
 * 069_add_tasks_app.sql), shown in the task detail panel's Activity feed
 * alongside task_comments rows.
 */
function log_task_activity(Database $db, int $taskId, ?int $userId, string $action, array $details = []): void
{
    $db->run(
        'INSERT INTO task_activity (task_id, user_id, action, details_json) VALUES (?, ?, ?, ?)',
        [$taskId, $userId, $action, $details ? json_encode($details) : null]
    );
}

/**
 * Audit trail for the process-graph designer (see database/migrations/
 * 066_add_process_automation.sql). Every draft save, publish, and manual
 * instance intervention writes one row here — it's what backs the History
 * tab's "Graph changes, executions, failures, and audit events" list.
 * $before/$after are typically a version's graph_json (decoded) or a small
 * before/after state diff for instance operations; either may be omitted.
 */
function log_process_audit(
    Database $db,
    int $definitionId,
    ?int $versionId,
    ?int $userId,
    string $action,
    array $before = [],
    array $after = [],
    ?string $note = null
): void {
    $db->run(
        'INSERT INTO process_audit_log (process_definition_id, process_version_id, actor_user_id, action, before_json, after_json, note) VALUES (?, ?, ?, ?, ?, ?, ?)',
        [
            $definitionId,
            $versionId,
            $userId,
            $action,
            $before ? json_encode($before, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
            $after ? json_encode($after, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
            $note,
        ]
    );
}

/**
 * Same shape as log_activity() above, but for the Opportunities module's
 * per-opportunity audit trail (opportunity_activities — see
 * database/migrations/109_add_opportunities_module.sql), read back by
 * GET /api/opportunities/{id}/activities. Written from src/Opportunities.php
 * (create, stage/field changes) and from src/Opportunities/Notes.php +
 * Signals.php when a note/signal links to an opportunity.
 */
function log_opportunity_activity(Database $db, int $opportunityId, ?int $userId, string $action, array $details = []): void
{
    $db->run(
        'INSERT INTO opportunity_activities (opportunity_id, created_by, action, details_json) VALUES (?, ?, ?, ?)',
        [$opportunityId, $userId, $action, $details ? json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null]
    );
}

/**
 * Create a directory (recursively), throwing if it could not be created.
 *
 * PHP's mkdir() only emits a warning on failure, so unchecked calls turn a
 * permissions problem into a confusing failure much further downstream: a
 * signature PNG that was never written, a contract PDF path recorded in the
 * DB that points at nothing, or codex reporting that CODEX_HOME "does not
 * exist". Throwing here names the offending path and the OS error instead.
 *
 * The usual cause of a failure is a directory under storage/ created by a
 * CLI run (as the shell user) with a mode that excludes the web server user.
 * Everything the app writes to under storage/ should be mode 2775 with group
 * www-data so that both users can write and the setgid bit keeps new
 * subdirectories in the same group.
 */
function ensure_dir(string $dir, int $mode = 0775): void
{
    if (is_dir($dir)) {
        return;
    }
    // The second is_dir() covers a concurrent request that won the race
    // between our check and our mkdir().
    if (!@mkdir($dir, $mode, true) && !is_dir($dir)) {
        $err = error_get_last()['message'] ?? 'unknown error';
        throw new \RuntimeException("Could not create directory {$dir}: {$err}");
    }
}

/**
 * Write a file, throwing if the write fails or is silently truncated
 * (a short write means a full disk — see the "No space left on device"
 * entries in the Apache error log — and must not be reported as success).
 */
function write_file(string $path, string $bytes): void
{
    $written = @file_put_contents($path, $bytes);
    if ($written === false) {
        $err = error_get_last()['message'] ?? 'unknown error';
        throw new \RuntimeException("Could not write {$path}: {$err}");
    }
    if ($written !== strlen($bytes)) {
        throw new \RuntimeException(
            "Short write to {$path}: wrote {$written} of " . strlen($bytes) . ' bytes'
        );
    }
}

/**
 * Append-only audit trail for the Booking Inbox (see database/migrations/
 * 076_add_booking_inbox_audit.sql). One row per meaningful action —
 * ingestion, viewing, assignment, claim, expiration, reassignment, response,
 * draft create/edit, status change, classification + human correction,
 * routing decision, manager override, onboarding, decline/archive,
 * duplicate/spam marking, attachment access, export, automation
 * execution/failure. No endpoint updates or deletes rows here — call this
 * and nothing else writes to lead_audit_log.
 *
 * $leadId may be null for lead-independent actions (routing rule edits,
 * bulk export attempts). $userId may be null when the actor is automation
 * (the SLA sweep, auto-routing, auto-acknowledgment) rather than a person.
 */
function log_lead_activity(Database $db, ?int $leadId, ?int $userId, string $action, array $details = []): void
{
    $db->run(
        'INSERT INTO lead_audit_log (lead_id, user_id, action, details_json, ip_address) VALUES (?, ?, ?, ?, ?)',
        [
            $leadId,
            $userId,
            $action,
            $details ? json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]
    );
}
