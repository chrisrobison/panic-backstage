<?php
declare(strict_types=1);

namespace Panic;

use Panic\Leads\Acknowledgment;
use Panic\Leads\Classifier;
use Panic\Leads\RoutingEngine;

/**
 * Public booking-inquiry intake (unauthenticated, cross-origin).
 *
 *   POST    /api/public/inquiries   create a lead from an embedded widget
 *   OPTIONS /api/public/inquiries   CORS preflight
 *
 * Backs public/assets/panic-booking-inquiry.js — a <panic-booking-inquiry>
 * web component meant to be dropped into a page on a completely different
 * domain (a venue's own marketing site, a promoter's landing page, etc).
 * That makes this the one write endpoint in the app that must both
 * (a) accept unauthenticated requests, and (b) answer its own CORS
 * preflight, since Kernel has no global CORS layer — every other endpoint
 * either runs same-origin or is read-only (see Feed::renderJson() for the
 * other place `Access-Control-Allow-Origin: *` shows up, for the same
 * cross-origin-widget reason).
 *
 * Every submission lands in the same `leads` table the internal Leads
 * pipeline already reads (source='website', status='new') — see
 * src/Leads.php for triage/evaluation/convert-to-event. There is no
 * separate inbox to check.
 *
 * Anti-spam, cheapest checks first:
 *   - honeypot field (`company`, see the widget) must be blank. A bot that
 *     fills every field trips this; a human never sees the field at all
 *     (visually hidden, but present in the DOM and tab order is skipped).
 *     Tripping it returns the same success response as a real submission —
 *     telling a bot it was caught only teaches it to try harder.
 *   - a per-IP and a per-email rate limit via RateLimiter (same mechanism
 *     the auth endpoints use against brute-forcing/mailbombing).
 *   - required-field + basic shape validation (email format, length caps).
 * None of this is bulletproof against a determined attacker, but it's the
 * same bar the rest of the unauthenticated surface holds itself to.
 */
final class PublicInquiry extends BaseEndpoint
{
    private const MAX_STR_LEN     = 255;
    private const MAX_PHONE_LEN   = 60;
    private const MAX_MESSAGE_LEN = 4000;

    private const EVENT_TYPES = [
        'private_event', 'wedding', 'corporate', 'concert', 'comedy',
        'community', 'fundraiser', 'other',
    ];

    public function handle(Request $request): Response
    {
        if ($request->method() === 'OPTIONS') {
            return $this->preflight();
        }
        if ($request->method() !== 'POST') {
            return $this->respond(['error' => 'Method not allowed'], 405);
        }
        return $this->create($request);
    }

    private function create(Request $request): Response
    {
        $b = $request->body();
        $ip = Request::clientIp() ?? 'unknown';

        // Honeypot: a real visitor never populates this (hidden from view
        // and from the tab order in the widget's markup). Bots that fill
        // every input do. Respond exactly like a successful submission so
        // there's no observable signal that anything was different.
        if (trim((string) ($b['company'] ?? '')) !== '') {
            return $this->respond(['ok' => true]);
        }

        $email = trim((string) ($b['contact_email'] ?? ''));
        if (RateLimiter::tooMany($this->db, 'public-inquiry:ip:' . $ip, 8, 600)
            || ($email !== '' && RateLimiter::tooMany($this->db, 'public-inquiry:email:' . strtolower($email), 3, 3600))
        ) {
            return $this->respond(['error' => 'Too many inquiries. Please try again later.'], 429);
        }

        $name = trim((string) ($b['contact_name'] ?? ''));
        if ($name === '') {
            return $this->respond(['error' => 'contact_name is required'], 422);
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->respond(['error' => 'A valid contact_email is required'], 422);
        }
        $message = trim((string) ($b['message'] ?? $b['notes'] ?? ''));
        if ($message === '') {
            return $this->respond(['error' => 'message is required'], 422);
        }

        $eventType = (string) ($b['event_type'] ?? '');
        if ($eventType !== '' && !in_array($eventType, self::EVENT_TYPES, true)) {
            $eventType = 'other';
        }

        $desiredDate = $this->parseDate($b['desired_date'] ?? null);
        if (($b['desired_date'] ?? '') !== '' && $desiredDate === null) {
            return $this->respond(['error' => 'desired_date must be a valid date (YYYY-MM-DD)'], 422);
        }
        $desiredDateAlt = $this->parseDate($b['desired_date_alt'] ?? null);

        $attendance = null;
        if (isset($b['projected_attendance']) && $b['projected_attendance'] !== '') {
            $attendance = max(0, min(100000, (int) $b['projected_attendance']));
        }

        $budget = null;
        if (isset($b['budget']) && $b['budget'] !== '') {
            $budget = max(0, min(99999999.99, (float) $b['budget']));
        }

        $id = $this->db->insert(
            'INSERT INTO leads (status, source, contact_name, contact_email, contact_org, contact_phone,
             event_name, event_type, desired_date, desired_date_alt, projected_attendance, budget, notes,
             risk_level)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [
                'new',
                'website',
                $this->clip($name, self::MAX_STR_LEN),
                $this->clip($email, self::MAX_STR_LEN),
                $this->clip((string) ($b['contact_org'] ?? ''), self::MAX_STR_LEN) ?: null,
                $this->clip((string) ($b['contact_phone'] ?? ''), self::MAX_PHONE_LEN) ?: null,
                $this->clip((string) ($b['event_name'] ?? ''), self::MAX_STR_LEN) ?: null,
                $eventType ?: null,
                $desiredDate,
                $desiredDateAlt,
                $attendance,
                $budget,
                $this->clip($message, self::MAX_MESSAGE_LEN),
                'unknown',
            ]
        );

        $origin = $request->header('Origin') ?: $request->header('Referer') ?: 'unknown origin';
        $this->db->run(
            "INSERT INTO lead_notes (lead_id, user_id, type, body) VALUES (?, NULL, 'audit', ?)",
            [$id, "Submitted via embedded booking-inquiry widget from {$origin} (IP {$ip})"]
        );

        // Booking Inbox conversation feed — same message the widget submitted,
        // normalized, so it renders in the Conversation tab like any other
        // inbound message (see database/migrations/072_add_booking_inbox_messages.sql).
        $messageRowId = $this->db->insert(
            'INSERT INTO lead_messages (lead_id, direction, channel, status, from_name, from_email, subject, body_text, checksum)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$id, 'inbound', 'manual', 'received', $name, $email, 'Website booking inquiry', $message, hash('sha256', $message)]
        );

        $this->classifyAndAcknowledge($id, $message, $messageRowId);
        $this->notifyAdmins($id, $name, $email);

        return $this->respond(['ok' => true]);
    }

    /**
     * Best-effort AI classification + auto-acknowledgment for a freshly
     * created website inquiry. Never allowed to fail the request — a slow or
     * unavailable Anthropic call, or a mail hiccup, must not turn a
     * successful public submission into a 500 for the visitor.
     */
    private function classifyAndAcknowledge(int $leadId, string $message, int $messageRowId): void
    {
        try {
            $apiKey = getenv('ANTHROPIC_API_KEY') ?: null;
            $classifier = new Classifier($apiKey);
            if ($classifier->isEnabled()) {
                $classifier->classify($this->db, $leadId, $message, 'Website booking inquiry', $messageRowId);
            }
        } catch (\Throwable $e) {
            @error_log("public-inquiry classification failed for lead {$leadId}: {$e->getMessage()}");
        }

        try {
            $lead = $this->db->one('SELECT * FROM leads WHERE id = ?', [$leadId]);
            if ($lead !== null) {
                (new RoutingEngine())->route($this->db, $lead);
            }
        } catch (\Throwable $e) {
            @error_log("public-inquiry routing failed for lead {$leadId}: {$e->getMessage()}");
        }

        try {
            $lead = $this->db->one('SELECT * FROM leads WHERE id = ?', [$leadId]);
            if ($lead !== null) {
                (new Acknowledgment($this->root))->maybeSend($this->db, $lead);
            }
        } catch (\Throwable $e) {
            @error_log("public-inquiry auto-acknowledgment failed for lead {$leadId}: {$e->getMessage()}");
        }
    }

    /** Best-effort admin alert so a public inquiry doesn't sit unseen in the pipeline. Never throws. */
    private function notifyAdmins(int $leadId, string $name, string $email): void
    {
        try {
            $admins = $this->db->all(
                "SELECT name, email, notify_event_updates FROM users
                 WHERE role = 'venue_admin' AND email IS NOT NULL AND email != '' AND email NOT LIKE '%.local' AND is_hidden = 0"
            );
            if (!$admins) {
                return;
            }
            $link = rtrim((string) (getenv('APP_URL') ?: ''), '/') . '/#leads';
            $subject = "[Backstage] New booking inquiry: " . $name;
            $text = "A new booking inquiry came in through the website widget.\n\n"
                . "From: {$name} <{$email}>\n\n"
                . "Review it in the Leads pipeline: {$link}\n";

            $mailer = new Mailer($this->root, $this->db);
            foreach ($admins as $admin) {
                if (!NotificationPreferences::wants($admin, NotificationPreferences::EVENT_UPDATES)) {
                    continue;
                }
                $mailer->send($admin['email'], $subject, $text);
            }
        } catch (\Throwable $e) {
            @error_log("public-inquiry notification failed for lead {$leadId}: {$e->getMessage()}");
        }
    }

    private function parseDate(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            return null;
        }
        $ts = strtotime($value);
        return $ts !== false ? date('Y-m-d', $ts) : null;
    }

    private function clip(string $value, int $max): string
    {
        $value = trim($value);
        return mb_strlen($value) > $max ? mb_substr($value, 0, $max) : $value;
    }

    /** JSON body + CORS headers — every response from this endpoint needs both. */
    private function respond(array $body, int $status = 200): Response
    {
        return new Response($body, $status, [
            'Content-Type'                 => 'application/json; charset=utf-8',
            'Cache-Control'                => 'no-store',
            'Access-Control-Allow-Origin'  => '*',
            'Access-Control-Allow-Methods' => 'POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Accept',
        ]);
    }

    /** CORS preflight response. Wide-open origin: no cookies/credentials are read or set, and the
     *  response never echoes anything the caller didn't already send — any site may embed the widget. */
    private function preflight(): Response
    {
        return new Response(null, 204, [
            'Access-Control-Allow-Origin'  => '*',
            'Access-Control-Allow-Methods' => 'POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Accept',
            'Access-Control-Max-Age'       => '86400',
        ]);
    }
}
