<?php
declare(strict_types=1);

namespace Panic;

/**
 * Immutable audit log helper for contract lifecycle events.
 *
 * Every call appends one row to contract_audit_log.
 * No rows in that table are ever updated or deleted from application code.
 *
 * Recognised actions (not exhaustive — any string is valid):
 *   contract_created            contract_previewed         contract_sent
 *   contract_resent             contract_voided            contract_fully_executed
 *   signer_link_opened          signer_consented           signer_signed
 *   signer_declined             contract_countersigned
 *   pdf_generated               pdf_hash_created
 *   webhook_received            webhook_verification_failed
 *   provider_error
 */
final class ContractAuditLog
{
    /**
     * Append one row to contract_audit_log.  Never throws — failures are
     * logged to the PHP error log so an audit problem never breaks a request.
     */
    public static function append(
        Database $db,
        int $contractId,
        string $action,
        ?int $signerId = null,
        ?string $ip = null,
        ?string $userAgent = null,
        array $meta = []
    ): void {
        try {
            $db->run(
                'INSERT INTO contract_audit_log
                    (contract_id, signer_id, action, ip_address, user_agent, metadata_json)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [
                    $contractId,
                    $signerId,
                    $action,
                    $ip ? substr($ip, 0, 45) : null,
                    $userAgent ? substr($userAgent, 0, 512) : null,
                    !empty($meta) ? json_encode($meta) : null,
                ]
            );
        } catch (\Throwable $e) {
            error_log("ContractAuditLog::append failed for contract {$contractId}: " . $e->getMessage());
        }
    }

    /**
     * Append a row using the current HTTP request's IP + User-Agent.
     * Convenience wrapper for web-request paths.
     */
    public static function appendFromRequest(
        Database $db,
        int $contractId,
        string $action,
        ?int $signerId = null,
        array $meta = []
    ): void {
        self::append(
            $db,
            $contractId,
            $action,
            $signerId,
            self::clientIp(),
            substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 512),
            $meta
        );
    }

    // ── Private helpers ────────────────────────────────────────────────────────

    private static function clientIp(): ?string
    {
        $xff = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($xff !== '') {
            return trim(explode(',', $xff)[0]);
        }
        return isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : null;
    }
}
