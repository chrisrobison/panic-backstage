<?php
declare(strict_types=1);

namespace Panic\Processes\CenterStage;

use Panic\Database;
use Panic\Processes\Runtime\Engine;

/**
 * The one real "external event resumes a wait" wiring for Phase 3: when a
 * contract is fully signed (either the in-app e-sign flow in
 * ContractSigningEndpoint::finalizeContract(), or an external provider
 * webhook in ContractWebhooks::handleAllSigned() — both already write
 * `events.status = 'booked'` directly with no shared hook to plug into), a
 * one-line call to onContractSigned() here resumes any process instance
 * that's genuinely waiting on that event's signature.
 *
 * Deliberately NOT wired for "customer replied" (await_customer_response) —
 * there's no inbound-email-reply-to-inquiry correlation in this codebase to
 * hook into, so that wait stays operator-resumed from Live Cases for now.
 *
 * Every call here is wrapped by the caller in a try/catch (see the two call
 * sites) so a bug in the process engine can never block the real contract-
 * signing flow a customer is waiting on — that flow's existing behavior is
 * preserved unconditionally.
 */
final class ProcessBridge
{
    public static function onContractSigned(Database $db, int $eventId, int $contractId): void
    {
        $waits = $db->all(
            "SELECT w.id AS wait_id, w.process_instance_id
             FROM process_waits w
             JOIN process_instances i ON i.id = w.process_instance_id
             WHERE i.entity_type = 'event' AND i.entity_id = ? AND w.status = 'waiting' AND w.awaited_event = 'contract.signed'",
            [$eventId]
        );
        if (!$waits) {
            return;
        }
        $engine = new Engine($db, BookingHandlers::registry());
        foreach ($waits as $wait) {
            $engine->resumeWait(
                (int) $wait['process_instance_id'],
                (int) $wait['wait_id'],
                null,
                "Resumed automatically — contract #$contractId was fully signed.",
                'system (contract signing)'
            );
        }
    }
}
