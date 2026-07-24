<?php
declare(strict_types=1);

namespace Panic;

use Panic\Tenant\TenantContext;

/**
 * Public payee-info submission endpoint — no JWT auth required.
 *
 * Accessible only with a valid, unexpired, single-use token issued when
 * staff requested a promoter/band's mailing address + W-9 (see
 * Events\Payee::sendRequest()). Mirrors ContractSigningEndpoint's
 * token-hash pattern.
 *
 *   GET  /api/payee-request/{token}          load the form (marks viewed)
 *   POST /api/payee-request/{token}/submit   submit address + W-9 upload
 *
 * Security:
 *   - Only sha256(token) is ever stored; the raw token lives only in the
 *     one email it was minted for.
 *   - Token expires after PAYEE_REQUEST_TOKEN_TTL_HOURS (default 336h = 14d).
 *   - Invalidated (nulled) once submitted.
 *   - The W-9 itself is stored as an opaque uploaded file — SSN/EIN never
 *     touch this schema as structured data — outside the public web root
 *     (storage/payees/ or clients/{slug}/payees/), never served by a public
 *     URL; only staff can retrieve it via Events\Payee::downloadW9().
 */
final class PayeeSubmissionEndpoint extends BaseEndpoint
{
    private const TERMINAL = ['submitted', 'voided', 'expired'];

    /** Real-bytes-detected MIME → extension. Never trust the client's filename/extension. */
    private const ALLOWED_MIME = [
        'application/pdf' => 'pdf',
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
    ];

    public function handle(Request $request): Response
    {
        $token  = (string) ($this->params['token'] ?? '');
        $action = (string) ($this->params['action'] ?? '');

        if ($token === '') {
            return Response::json(['error' => 'Invalid link'], 400);
        }

        return match ($action) {
            ''       => $this->loadForm($token, $request),
            'submit' => $this->submit($token, $request),
            default  => Response::json(['error' => 'Unknown action'], 404),
        };
    }

    // ── Load form ──────────────────────────────────────────────────────────────

    private function loadForm(string $token, Request $request): Response
    {
        if ($request->method() !== 'GET') {
            return Response::methodNotAllowed();
        }

        [$req, $error] = $this->resolveToken($token);
        if ($error) {
            return Response::json(['error' => $error], 410);
        }

        $event = $this->db->one(
            'SELECT e.title, v.name AS venue_name FROM events e JOIN venues v ON v.id = e.venue_id WHERE e.id = ?',
            [(int) $req['event_id']]
        );
        $payee = $this->db->one('SELECT * FROM payees WHERE id = ?', [(int) $req['payee_id']]);

        // Mark viewed — idempotent, only advances from 'sent'.
        if ($req['status'] === 'sent') {
            $this->db->run("UPDATE payee_requests SET status = 'viewed', viewed_at = NOW() WHERE id = ?", [(int) $req['id']]);
        }

        return $this->ok([
            'request' => [
                'id'              => (int) $req['id'],
                'recipient_name'  => $req['recipient_name'],
                'recipient_email' => $req['recipient_email'],
                'expires_at'      => $req['token_expires_at'],
            ],
            'event' => [
                'title'      => $event['title']      ?? '',
                'venue_name' => $event['venue_name']  ?? '',
            ],
            'payee' => $payee ? [
                'name'                  => $payee['name'],
                'phone'                 => $payee['phone'],
                'company'               => $payee['company'],
                'mailing_address_line1' => $payee['mailing_address_line1'],
                'mailing_address_line2' => $payee['mailing_address_line2'],
                'mailing_city'          => $payee['mailing_city'],
                'mailing_state'         => $payee['mailing_state'],
                'mailing_zip'           => $payee['mailing_zip'],
                'mailing_country'       => $payee['mailing_country'] ?: 'US',
                'w9_on_file'            => !empty($payee['w9_file_path']),
                'w9_original_filename'  => $payee['w9_original_filename'],
            ] : null,
        ]);
    }

    // ── Submit ─────────────────────────────────────────────────────────────────

    private function submit(string $token, Request $request): Response
    {
        if ($request->method() !== 'POST') {
            return Response::methodNotAllowed();
        }

        [$req, $error] = $this->resolveToken($token);
        if ($error) {
            return Response::json(['error' => $error], 410);
        }

        $payeeId = (int) $req['payee_id'];
        $payee   = $this->db->one('SELECT * FROM payees WHERE id = ?', [$payeeId]);
        if (!$payee) {
            return Response::json(['error' => 'Payee record not found.'], 404);
        }

        $b = $request->body();
        $name    = trim((string) ($b['name']    ?? ''));
        $phone   = trim((string) ($b['phone']   ?? ''));
        $company = trim((string) ($b['company'] ?? ''));
        $line1   = trim((string) ($b['mailing_address_line1'] ?? ''));
        $line2   = trim((string) ($b['mailing_address_line2'] ?? ''));
        $city    = trim((string) ($b['mailing_city']    ?? ''));
        $state   = trim((string) ($b['mailing_state']   ?? ''));
        $zip     = trim((string) ($b['mailing_zip']     ?? ''));
        $country = trim((string) ($b['mailing_country'] ?? '')) ?: 'US';

        $missing = [];
        if ($line1 === '') $missing[] = 'mailing address';
        if ($city  === '') $missing[] = 'city';
        if ($state === '') $missing[] = 'state';
        if ($zip   === '') $missing[] = 'ZIP code';
        if ($missing) {
            return Response::json(['error' => 'Please fill in: ' . implode(', ', $missing) . '.'], 422);
        }

        // A W-9 is required the first time; once one is on file, submitting
        // again (e.g. just to correct an address) doesn't force a re-upload.
        $hasExistingW9 = !empty($payee['w9_file_path']);
        $file = $request->files()['w9'] ?? null;
        $uploadError = $file['error'] ?? UPLOAD_ERR_NO_FILE;

        $w9Path = null;
        $w9OriginalName = null;
        if ($uploadError !== UPLOAD_ERR_NO_FILE) {
            if ($uploadError !== UPLOAD_ERR_OK) {
                $message = match ($uploadError) {
                    UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File is too large.',
                    default => 'Upload failed — please try again.',
                };
                return Response::json(['error' => $message], 422);
            }
            if (($file['size'] ?? 0) > 10 * 1024 * 1024) {
                return Response::json(['error' => 'Your W-9 file must be 10MB or smaller.'], 422);
            }
            $mime = mime_content_type($file['tmp_name']) ?: '';
            if (!isset(self::ALLOWED_MIME[$mime])) {
                return Response::json(['error' => 'Please upload your W-9 as a PDF, JPG, or PNG (detected: ' . $mime . ').'], 422);
            }
            [$w9Path, $w9OriginalName] = $this->storeW9($payeeId, $file, self::ALLOWED_MIME[$mime]);
            if ($w9Path === null) {
                return Response::json(['error' => 'Could not store your W-9 upload — please try again.'], 500);
            }
        } elseif (!$hasExistingW9) {
            return Response::json(['error' => 'Please attach your completed W-9.'], 422);
        }

        $sets   = [
            'phone = ?', 'company = ?', 'mailing_address_line1 = ?', 'mailing_address_line2 = ?',
            'mailing_city = ?', 'mailing_state = ?', 'mailing_zip = ?', 'mailing_country = ?',
        ];
        $params = [
            $phone ?: null, $company ?: null, $line1, $line2 ?: null,
            $city, $state, $zip, $country,
        ];
        if ($name !== '' && trim((string) $payee['name']) === '') {
            $sets[] = 'name = ?';
            $params[] = $name;
        }
        if ($w9Path !== null) {
            $sets[] = 'w9_file_path = ?';
            $sets[] = 'w9_original_filename = ?';
            $sets[] = 'w9_uploaded_at = NOW()';
            $params[] = $w9Path;
            $params[] = $w9OriginalName;
        }
        $params[] = $payeeId;
        $this->db->run('UPDATE payees SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);

        $ip = Request::clientIp();
        $ua = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 512);
        $this->db->run(
            "UPDATE payee_requests
             SET status = 'submitted', submitted_at = NOW(), ip_address = ?, user_agent = ?,
                 token_hash = NULL, token_expires_at = NULL
             WHERE id = ?",
            [$ip, $ua, (int) $req['id']]
        );

        log_activity($this->db, (int) $req['event_id'], null, 'payee info submitted', ['payee_id' => $payeeId]);

        $this->notifyAdmins((int) $req['event_id'], $payeeId);

        return $this->ok(['ok' => true, 'message' => 'Thank you — your information has been received.']);
    }

    // ── Token resolution ──────────────────────────────────────────────────────

    /** @return array{0: array|null, 1: string|null} [request_row, error_message] */
    private function resolveToken(string $token): array
    {
        if (strlen($token) < 32) {
            return [null, 'Invalid link.'];
        }

        $hash = hash('sha256', $token);
        $req  = $this->db->one('SELECT * FROM payee_requests WHERE token_hash = ? LIMIT 1', [$hash]);
        if (!$req) {
            return [null, 'This link is invalid or has already been used.'];
        }

        if ($req['token_expires_at'] && db_timestamp_to_epoch((string) $req['token_expires_at']) < time()) {
            $this->db->run("UPDATE payee_requests SET status = 'expired' WHERE id = ?", [(int) $req['id']]);
            return [null, 'This link has expired. Please contact the venue to request a new one.'];
        }

        if (in_array($req['status'], self::TERMINAL, true)) {
            return [null, 'This link is no longer active.'];
        }

        return [$req, null];
    }

    // ── Storage ────────────────────────────────────────────────────────────────

    /** @return array{0: string|null, 1: string|null} [relative_path, original_filename] */
    private function storeW9(int $payeeId, array $file, string $ext): array
    {
        $dir = TenantContext::clientDir($this->root) . '/payees/' . $payeeId;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $filename = 'w9-' . time() . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
        $target   = $dir . '/' . $filename;
        if (!move_uploaded_file($file['tmp_name'], $target)) {
            return [null, null];
        }
        // Relative to project root, matching how ContractSigningEndpoint /
        // Events\Payee::downloadW9() read files back (root + '/' + path).
        $relative = ltrim(substr($dir, strlen($this->root)), '/') . '/' . $filename;
        return [$relative, (string) ($file['name'] ?? 'w9')];
    }

    // ── Notifications ──────────────────────────────────────────────────────────

    private function notifyAdmins(int $eventId, int $payeeId): void
    {
        try {
            $event = $this->db->one('SELECT title FROM events WHERE id = ?', [$eventId]);
            $payee = $this->db->one('SELECT name, email FROM payees WHERE id = ?', [$payeeId]);
            $admins = $this->db->all(
                "SELECT name, email, notify_event_updates FROM users WHERE role = 'venue_admin' AND email IS NOT NULL AND email != '' AND is_hidden = 0"
            );
            if (!$admins) {
                return;
            }
            $mailer = new Mailer($this->root, $this->db);
            $appUrl = rtrim((string) (getenv('APP_URL') ?: ''), '/');

            foreach ($admins as $admin) {
                if (!NotificationPreferences::wants($admin, NotificationPreferences::EVENT_UPDATES)) {
                    continue;
                }
                $mailer->sendTemplate(
                    $admin['email'],
                    'W-9 & mailing address received: ' . ($event['title'] ?? 'Event'),
                    'payee-info-submitted',
                    [
                        'admin_name'  => $admin['name'],
                        'payee_name'  => (string) ($payee['name'] ?? $payee['email'] ?? ''),
                        'event_title' => (string) ($event['title'] ?? ''),
                        'event_url'   => $appUrl . '/#event-' . $eventId,
                    ]
                );
            }
        } catch (\Throwable $e) {
            error_log('PayeeSubmissionEndpoint::notifyAdmins failed: ' . $e->getMessage());
        }
    }
}
