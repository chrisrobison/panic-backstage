<?php
declare(strict_types=1);

namespace Panic;

/**
 * Admin endpoints for duplicate-account detection and merge.
 *
 *   GET  /api/users/duplicates           list likely-same-person pairs (read-only)
 *   POST /api/users/merge                merge loser_id into survivor_id (atomic)
 *
 * Both require the manage_users global capability (venue_admin). The actual
 * detection + merge logic lives in Panic\UserMerge; this class is the thin HTTP
 * adapter (auth gate, request parsing, error -> status mapping).
 *
 * Kernel routing: this endpoint is reached via the `users` segment with an
 * `action` of either "duplicates" (GET) or "merge" (POST). See integration_notes.
 */
final class Duplicates extends BaseEndpoint
{
    public function handle(Request $request): Response
    {
        if ($denied = $this->requireGlobalCapability('manage_users')) {
            return $denied;
        }

        $action = (string) ($this->params['action'] ?? '');

        return match (true) {
            $request->method() === 'GET'  && $action === 'duplicates' => $this->duplicates(),
            $request->method() === 'POST' && $action === 'merge'      => $this->merge($request),
            default => Response::methodNotAllowed(),
        };
    }

    private function duplicates(): Response
    {
        $engine = new UserMerge($this->db);
        return $this->ok(['duplicates' => $engine->findDuplicates()]);
    }

    private function merge(Request $request): Response
    {
        $survivorId = (int) $request->body('survivor_id', 0);
        $loserId    = (int) $request->body('loser_id', 0);
        $confirm    = \Panic\boolish($request->body('confirm', false)) === 1;
        $override   = \Panic\boolish($request->body('override_role', false)) === 1;

        if ($survivorId <= 0 || $loserId <= 0) {
            return Response::json(['error' => 'survivor_id and loser_id are required'], 422);
        }
        if (!$confirm) {
            return Response::json(['error' => 'confirm must be true to perform a merge'], 422);
        }
        if ($survivorId === $loserId) {
            return Response::json(['error' => 'survivor_id and loser_id must differ'], 422);
        }
        if ($loserId === $this->userId()) {
            return Response::json(['error' => 'You cannot merge your own account away'], 422);
        }

        $engine = new UserMerge($this->db);
        try {
            $result = $engine->merge($survivorId, $loserId, $override, $this->userId());
        } catch (RoleMismatchException $e) {
            return Response::json([
                'error'         => 'Roles differ; resubmit with override_role:true to proceed.',
                'survivor_role' => $e->survivorRole,
                'loser_role'    => $e->loserRole,
            ], 409);
        } catch (MergeValidationException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }

        return $this->ok($result);
    }
}
