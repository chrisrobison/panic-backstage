<?php
declare(strict_types=1);

namespace Panic\Promote\Adapters;

/**
 * Email campaign adapter — supports Mailchimp (v3) and SendGrid (v3 Marketing).
 *
 * Required config (from promote_credentials: access_token + config JSON):
 *   access_token          API key (Mailchimp private key or SendGrid API key)
 *   config.provider       'mailchimp' | 'sendgrid'
 *   config.list_id        Mailchimp audience ID  OR  SendGrid list ID
 *   config.from_name      Display name for the From header
 *   config.from_email     Reply-to / From email address
 *   config.sender_id      (SendGrid only) numeric Sender Authentication ID
 *
 * Return shape: {status, external_url, error_message, response_json}
 *   status: 'sent' | 'queued' | 'failed'
 */
final class EmailAdapter
{
    public function __construct(
        private readonly string $provider,
        private readonly string $apiKey,
        private readonly string $listId,
        private readonly string $fromName,
        private readonly string $fromEmail,
        private readonly int    $sgSenderId = 0,
    ) {}

    /**
     * Create and dispatch an email campaign.
     *
     * @param  array       $event       Full event row (joined with venues)
     * @param  array       $post        promote_posts row
     * @param  string      $subject     Email subject (from email variant title)
     * @param  string      $bodyText    Plain-text body (from email variant body)
     * @param  string      $sendMode    'now' | 'scheduled'
     * @param  string|null $scheduledAt ISO datetime for scheduled sends (UTC)
     */
    public function dispatch(
        array   $event,
        array   $post,
        string  $subject,
        string  $bodyText,
        string  $sendMode,
        ?string $scheduledAt = null,
    ): array {
        return match (strtolower($this->provider)) {
            'mailchimp' => $this->mailchimp($event, $post, $subject, $bodyText, $sendMode, $scheduledAt),
            'sendgrid'  => $this->sendgrid($event, $post, $subject, $bodyText, $sendMode, $scheduledAt),
            default     => [
                'status'        => 'failed',
                'external_url'  => null,
                'error_message' => "Unknown email provider '{$this->provider}'. Set provider to 'mailchimp' or 'sendgrid' in Promote Settings.",
                'response_json' => null,
            ],
        };
    }

    // ── Mailchimp v3 ─────────────────────────────────────────────────────────

    private function mailchimp(
        array   $event,
        array   $post,
        string  $subject,
        string  $bodyText,
        string  $sendMode,
        ?string $scheduledAt,
    ): array {
        // API key format: <key>-<dc>  e.g. "abc123def456-us21"
        $dc = 'us1';
        if (preg_match('/-([a-z]{2}\d+)$/', $this->apiKey, $m)) {
            $dc = $m[1];
        }
        $base = "https://{$dc}.api.mailchimp.com/3.0";

        // 1. Create campaign
        $campaignName  = ($post['title'] ?? $event['title'] ?? 'Campaign') . ' — ' . date('Y-m-d');
        $createPayload = [
            'type'       => 'regular',
            'recipients' => ['list_id' => $this->listId],
            'settings'   => [
                'subject_line' => $subject,
                'title'        => $campaignName,
                'from_name'    => $this->fromName ?: getenv('VENUE_NAME') ?: getenv('MAIL_FROM_NAME') ?: 'Venue',
                'reply_to'     => $this->fromEmail,
            ],
        ];

        $created = $this->mcRequest('POST', "$base/campaigns", $createPayload);
        if (isset($created['_error'])) {
            return $this->fail($created['_error'], $created['_raw'] ?? null);
        }

        $campaignId = $created['id'] ?? null;
        if (!$campaignId) {
            return $this->fail('Mailchimp did not return a campaign ID.', json_encode($created) ?: null);
        }

        // 2. Set campaign content (HTML + plain-text)
        $html = $this->buildHtml($bodyText, $event, 'mailchimp');
        $contentResult = $this->mcRequest('PUT', "$base/campaigns/$campaignId/content", [
            'html'       => $html,
            'plain_text' => $bodyText . "\n\n*|UNSUB|*",
        ]);
        if (isset($contentResult['_error'])) {
            return $this->fail($contentResult['_error'], $contentResult['_raw'] ?? null);
        }

        // Build a human-readable archive URL from web_id (best-effort)
        $webId       = $created['web_id'] ?? null;
        $externalUrl = $webId
            ? "https://{$dc}.admin.mailchimp.com/campaigns/show/?id={$webId}"
            : null;

        // 3. Send or schedule
        if ($sendMode === 'scheduled' && $scheduledAt) {
            $scheduleTime = gmdate('Y-m-d\TH:i:s\+00:00', strtotime($scheduledAt));
            $schedResult  = $this->mcRequest('POST', "$base/campaigns/$campaignId/actions/schedule", [
                'schedule_time' => $scheduleTime,
            ]);
            if (isset($schedResult['_error'])) {
                return $this->fail($schedResult['_error'], $schedResult['_raw'] ?? null);
            }
            return [
                'status'        => 'queued',
                'external_url'  => $externalUrl,
                'error_message' => null,
                'response_json' => json_encode(['campaign_id' => $campaignId, 'scheduled_at' => $scheduleTime]) ?: null,
            ];
        }

        $sendResult = $this->mcRequest('POST', "$base/campaigns/$campaignId/actions/send", []);
        if (isset($sendResult['_error'])) {
            return $this->fail($sendResult['_error'], $sendResult['_raw'] ?? null);
        }

        return [
            'status'        => 'sent',
            'external_url'  => $externalUrl,
            'error_message' => null,
            'response_json' => json_encode(['campaign_id' => $campaignId]) ?: null,
        ];
    }

    /** Make a Mailchimp API call (basic auth: any_string:apiKey). */
    private function mcRequest(string $method, string $url, array $payload): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD        => 'anystring:' . $this->apiKey,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_POSTFIELDS     => $method !== 'GET' ? json_encode($payload) : null,
            CURLOPT_TIMEOUT        => 20,
        ]);
        $raw  = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = json_decode($raw, true) ?? [];
        if ($code >= 400) {
            $title  = $decoded['title']  ?? $decoded['message'] ?? "HTTP $code";
            $detail = $decoded['detail'] ?? '';
            return ['_error' => "Mailchimp: $title" . ($detail ? " — $detail" : ''), '_raw' => $raw];
        }
        return $decoded;
    }

    // ── SendGrid v3 Marketing ─────────────────────────────────────────────────

    private function sendgrid(
        array   $event,
        array   $post,
        string  $subject,
        string  $bodyText,
        string  $sendMode,
        ?string $scheduledAt,
    ): array {
        $base         = 'https://api.sendgrid.com/v3';
        $campaignName = ($post['title'] ?? $event['title'] ?? 'Campaign') . ' — ' . date('Y-m-d');
        $html         = $this->buildHtml($bodyText, $event, 'sendgrid');

        $emailConfig = [
            'subject'       => $subject,
            'html_content'  => $html,
            'plain_content' => $bodyText,
        ];
        if ($this->sgSenderId > 0) {
            $emailConfig['sender_id'] = $this->sgSenderId;
        }

        // 1. Create single send
        $payload = [
            'name'         => $campaignName,
            'send_to'      => ['list_ids' => [$this->listId]],
            'email_config' => $emailConfig,
        ];

        $created = $this->sgRequest('POST', "$base/marketing/singlesends", $payload);
        if (isset($created['_error'])) {
            return $this->fail($created['_error'], $created['_raw'] ?? null);
        }

        $singlesendId = $created['id'] ?? null;
        if (!$singlesendId) {
            return $this->fail('SendGrid did not return a single send ID.', json_encode($created) ?: null);
        }

        $externalUrl = "https://mc.sendgrid.com/single-sends/$singlesendId/summary";

        // 2. Schedule or send now
        $sendAt = ($sendMode === 'scheduled' && $scheduledAt)
            ? gmdate('Y-m-d\TH:i:s\Z', strtotime($scheduledAt))
            : 'now';

        $schedResult = $this->sgRequest('PUT', "$base/marketing/singlesends/$singlesendId/schedule", [
            'send_at' => $sendAt,
        ]);
        if (isset($schedResult['_error'])) {
            return $this->fail($schedResult['_error'], $schedResult['_raw'] ?? null);
        }

        return [
            'status'        => $sendAt === 'now' ? 'sent' : 'queued',
            'external_url'  => $externalUrl,
            'error_message' => null,
            'response_json' => json_encode(['singlesend_id' => $singlesendId, 'send_at' => $sendAt]) ?: null,
        ];
    }

    /** Make a SendGrid API call (Bearer auth). */
    private function sgRequest(string $method, string $url, array $payload): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_TIMEOUT        => 20,
        ]);
        $raw  = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = json_decode($raw, true) ?? [];
        if ($code >= 400) {
            $errors = $decoded['errors'] ?? [];
            $msg    = !empty($errors)
                ? ($errors[0]['message'] ?? "HTTP $code")
                : ($decoded['message'] ?? "HTTP $code");
            return ['_error' => "SendGrid: $msg", '_raw' => $raw];
        }
        return $decoded;
    }

    // ── HTML builder ──────────────────────────────────────────────────────────

    /**
     * Wrap plain-text body in a minimal, mobile-friendly HTML email.
     * Uses inline styles (no external CSS) for maximum client compat.
     * Injects the correct unsubscribe merge tag per provider.
     */
    private function buildHtml(string $bodyText, array $event, string $provider): string
    {
        $venueName = htmlspecialchars($event['venue_name'] ?? getenv('VENUE_NAME') ?: 'Venue', ENT_QUOTES | ENT_HTML5);
        $bodyHtml  = nl2br(htmlspecialchars($bodyText, ENT_QUOTES | ENT_HTML5));

        $unsub = match ($provider) {
            'mailchimp' => '<a href="*|UNSUB|*" style="color:#999">Unsubscribe</a>',
            'sendgrid'  => '<%asm_global_unsubscribe_url%>',
            default     => '',
        };

        return <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
          <meta charset="UTF-8">
          <meta name="viewport" content="width=device-width,initial-scale=1.0">
        </head>
        <body style="margin:0;padding:0;background:#f4f4f4;font-family:Arial,Helvetica,sans-serif">
          <table width="100%" cellpadding="0" cellspacing="0" role="presentation"
                 style="background:#f4f4f4;padding:32px 16px">
            <tr><td>
              <table width="600" align="center" cellpadding="0" cellspacing="0" role="presentation"
                     style="background:#ffffff;border-radius:8px;padding:40px;max-width:100%">
                <tr>
                  <td style="color:#1a1a1a;font-size:16px;line-height:1.7;padding-bottom:32px">
                    $bodyHtml
                  </td>
                </tr>
                <tr>
                  <td style="padding-top:24px;border-top:1px solid #eeeeee;
                              color:#999999;font-size:12px;text-align:center;line-height:1.6">
                    $venueName &middot; San Francisco, CA<br>
                    $unsub
                  </td>
                </tr>
              </table>
            </td></tr>
          </table>
        </body>
        </html>
        HTML;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function fail(string $message, ?string $raw): array
    {
        return [
            'status'        => 'failed',
            'external_url'  => null,
            'error_message' => $message,
            'response_json' => $raw,
        ];
    }
}
