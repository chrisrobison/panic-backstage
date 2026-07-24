<?php
declare(strict_types=1);

namespace Panic\Events;

use Panic\BaseEndpoint;
use Panic\Mailer;
use Panic\Request;
use Panic\Response;
use function Panic\log_activity;

/**
 * Per-event "payee info" (mailing address + W-9) request/status.
 *
 *   GET  /api/events/{id}/payee        current payee-on-file + latest request status
 *   GET  /api/events/{id}/payee/w9     download the payee's W-9 file (staff only)
 *   POST /api/events/{id}/payee        send (or re-send) the request email
 *   POST /api/events/{id}/payee/void   void the current outstanding request
 *
 * The promoter/band never gets a backstage account, so the actual collection
 * happens over a separate, unauthenticated, token-protected flow — see
 * PayeeSubmissionEndpoint (public/payee-request.html) — mirroring how
 * Contracts::sendForSignature() / ContractSigningEndpoint hand off an
 * external signer to a token-gated public page. The W-9 itself is never
 * parsed into structured tax-ID fields here — the payee uploads their own
 * completed/signed PDF and we only ever store the file.
 *
 * `payees` is a reusable profile keyed by email — a repeat promoter/band
 * only has to submit their address/W-9 once; a later request just confirms
 * or refreshes what's already on file.
 */
final class Payee extends BaseEndpoint
{
    /** Statuses that count as "still outstanding" — a new request voids these. */
    private const ACTIVE_STATUSES = ['pending', 'sent', 'viewed'];

    public function handle(Request $request): Response
    {
        $eventId = $this->requireEventId();
        $action  = (string) ($this->params['action'] ?? '');
        $method  = $request->method();

        if ($method === 'GET' && $action === 'w9') {
            if ($denied = $this->requireEventCapability($eventId, 'manage_payments')) {
                return $denied;
            }
            return $this->downloadW9($eventId);
        }
        if ($method === 'GET') {
            if ($denied = $this->requireEventCapability($eventId, 'read_event')) {
                return $denied;
            }
            return $this->view($eventId);
        }
        if ($method === 'POST') {
            if ($denied = $this->requireEventCapability($eventId, 'manage_payments')) {
                return $denied;
            }
            return $action === 'void' ? $this->void($eventId) : $this->sendRequest($request, $eventId);
        }
        return Response::methodNotAllowed();
    }

    private function view(int $eventId): Response
    {
        $req = $this->latestRequest($eventId);
        $payee = $req ? $this->db->one('SELECT * FROM payees WHERE id = ?', [(int) $req['payee_id']]) : null;

        return $this->ok([
            'payee'   => $payee ? $this->publicPayee($payee) : null,
            'request' => $req ? [
                'id'              => (int) $req['id'],
                'status'          => $req['status'],
                'recipient_name'  => $req['recipient_name'],
                'recipient_email' => $req['recipient_email'],
                'sent_at'         => $req['sent_at'],
                'viewed_at'       => $req['viewed_at'],
                'submitted_at'    => $req['submitted_at'],
                'expires_at'      => $req['token_expires_at'],
            ] : null,
        ]);
    }

    /** Shape a `payees` row for the frontend — never exposes the raw storage path. */
    private function publicPayee(array $payee): array
    {
        return [
            'id'                    => (int) $payee['id'],
            'name'                  => $payee['name'],
            'email'                 => $payee['email'],
            'phone'                 => $payee['phone'],
            'company'               => $payee['company'],
            'mailing_address_line1' => $payee['mailing_address_line1'],
            'mailing_address_line2' => $payee['mailing_address_line2'],
            'mailing_city'          => $payee['mailing_city'],
            'mailing_state'         => $payee['mailing_state'],
            'mailing_zip'           => $payee['mailing_zip'],
            'mailing_country'       => $payee['mailing_country'],
            'has_w9'                => !empty($payee['w9_file_path']),
            'w9_original_filename'  => $payee['w9_original_filename'],
            'w9_uploaded_at'        => $payee['w9_uploaded_at'],
        ];
    }

    private function latestRequest(int $eventId): ?array
    {
        return $this->db->one(
            'SELECT * FROM payee_requests WHERE event_id = ? ORDER BY created_at DESC LIMIT 1',
            [$eventId]
        );
    }

    private function sendRequest(Request $request, int $eventId): Response
    {
        $event = $this->db->one('SELECT title, promoter_name, promoter_email, booker_name, booker_email FROM events WHERE id = ?', [$eventId]);
        if (!$event) {
            return $this->notFound();
        }

        $b = $request->body();
        $email = trim((string) ($b['recipient_email'] ?? '')) ?: (string) ($event['promoter_email'] ?: $event['booker_email']);
        $name  = trim((string) ($b['recipient_name']  ?? '')) ?: (string) ($event['promoter_name']  ?: $event['booker_name']);

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return Response::json(['error' => 'A valid recipient email is required.'], 422);
        }

        // Upsert the reusable payee profile by email — first request from a
        // given address creates it; a later one (same or a different event)
        // just reuses whatever's already on file.
        $payee = $this->db->one('SELECT id, name FROM payees WHERE email = ?', [$email]);
        if ($payee) {
            $payeeId = (int) $payee['id'];
            if (trim((string) $payee['name']) === '' && $name !== '') {
                $this->db->run('UPDATE payees SET name = ? WHERE id = ?', [$name, $payeeId]);
            }
        } else {
            $payeeId = $this->db->insert('INSERT INTO payees (name, email) VALUES (?, ?)', [$name, $email]);
        }

        // Void any still-outstanding request for THIS event (resend scenario)
        // — a payee may have unrelated active requests for other events too,
        // those are untouched.
        $this->db->run(
            "UPDATE payee_requests SET status = 'voided', token_hash = NULL, token_expires_at = NULL
             WHERE event_id = ? AND status IN ('pending','sent','viewed')",
            [$eventId]
        );

        $ttlHours = max(1, (int) (getenv('PAYEE_REQUEST_TOKEN_TTL_HOURS') ?: 336)); // 14 days
        $appUrl   = rtrim((string) (getenv('APP_URL') ?: ''), '/');

        $rawToken     = $this->auth->generateToken(48);
        $tokenHash    = $this->auth->hashToken($rawToken);
        $expiresEpoch = time() + $ttlHours * 3600;
        // UTC, to match db_timestamp_to_epoch()'s read-side assumption — see
        // the identical comment in Contracts::sendForSignature().
        $expiresAt    = gmdate('Y-m-d H:i:s', $expiresEpoch);

        $requestId = $this->db->insert(
            'INSERT INTO payee_requests (event_id, payee_id, recipient_name, recipient_email, status, token_hash, token_expires_at, sent_at, created_by_user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?)',
            [$eventId, $payeeId, $name, $email, 'sent', $tokenHash, $expiresAt, $this->userId()]
        );

        try {
            $mailer = new Mailer($this->root, $this->db);
            $mailer->sendTemplate(
                $email,
                'Please submit your W-9 & mailing address: ' . ($event['title'] ?? 'Your Event'),
                'payee-info-request',
                [
                    'recipient_name'  => $name,
                    'event_title'     => (string) ($event['title'] ?? ''),
                    'submission_url'  => $appUrl . '/payee-request.html?token=' . urlencode($rawToken),
                    'expires_date'    => date('F j, Y', $expiresEpoch),
                    'venue_name'      => (string) (getenv('MAIL_FROM_NAME') ?: 'The Venue'),
                ]
            );
        } catch (\Throwable $e) {
            error_log('Events\\Payee::sendRequest mail failed to ' . $email . ': ' . $e->getMessage());
        }

        log_activity($this->db, $eventId, $this->userId(), 'payee info requested', ['email' => $email]);

        return $this->ok(['ok' => true, 'id' => $requestId]);
    }

    private function void(int $eventId): Response
    {
        $req = $this->latestRequest($eventId);
        if (!$req || !in_array($req['status'], self::ACTIVE_STATUSES, true)) {
            return Response::json(['error' => 'There is no outstanding request to void.'], 422);
        }
        $this->db->run(
            "UPDATE payee_requests SET status = 'voided', token_hash = NULL, token_expires_at = NULL WHERE id = ?",
            [(int) $req['id']]
        );
        log_activity($this->db, $eventId, $this->userId(), 'payee info request voided', []);
        return $this->ok(['ok' => true]);
    }

    private function downloadW9(int $eventId): Response
    {
        $req = $this->latestRequest($eventId);
        $payee = $req ? $this->db->one('SELECT * FROM payees WHERE id = ?', [(int) $req['payee_id']]) : null;
        $path = (string) ($payee['w9_file_path'] ?? '');
        if ($path === '' || !is_file($this->root . '/' . $path)) {
            return Response::json(['error' => 'No W-9 is on file for this event yet.'], 404);
        }

        $bytes = (string) file_get_contents($this->root . '/' . $path);
        $mime  = mime_content_type($this->root . '/' . $path) ?: 'application/octet-stream';
        $safe  = preg_replace('/[^\w\s.-]/', '', (string) ($payee['w9_original_filename'] ?: 'w9')) ?: 'w9';

        log_activity($this->db, $eventId, $this->userId(), 'payee W-9 downloaded', ['payee_id' => (int) $payee['id']]);

        return new Response($bytes, 200, [
            'Content-Type'        => $mime,
            'Content-Disposition' => 'attachment; filename="' . $safe . '"',
            'Content-Length'      => (string) strlen($bytes),
            'Cache-Control'       => 'private, no-cache',
        ]);
    }
}
