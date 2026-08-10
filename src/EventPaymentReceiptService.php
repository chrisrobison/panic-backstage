<?php
declare(strict_types=1);

namespace Panic;

/** Builds, renders, and delivers private client receipts for event payments. */
final class EventPaymentReceiptService
{
    private const WKHTMLTOPDF = '/usr/bin/wkhtmltopdf';

    public function __construct(
        private readonly Database $db,
        private readonly string $root
    ) {}

    /** Ensure a payment has the token used by its return/download URLs. */
    public function ensureToken(int $paymentId): string
    {
        $row = $this->db->one('SELECT receipt_token FROM event_payments WHERE id = ?', [$paymentId]);
        if ($row === null) {
            throw new \RuntimeException("Payment {$paymentId} not found");
        }
        $existing = (string) ($row['receipt_token'] ?? '');
        if ($existing !== '') {
            return $existing;
        }

        $token = bin2hex(random_bytes(32));
        $this->db->run(
            'UPDATE event_payments SET receipt_token = ? WHERE id = ? AND receipt_token IS NULL',
            [$token, $paymentId]
        );
        $row = $this->db->one('SELECT receipt_token FROM event_payments WHERE id = ?', [$paymentId]);
        $stored = (string) ($row['receipt_token'] ?? '');
        if ($stored === '') {
            throw new \RuntimeException("Could not mint receipt token for payment {$paymentId}");
        }
        return $stored;
    }

    /** Load the token-authorized, client-safe receipt data. */
    public function load(int $paymentId, string $token): ?array
    {
        if ($paymentId <= 0 || strlen($token) !== 64 || !ctype_xdigit($token)) {
            return null;
        }

        $row = $this->db->one(
            'SELECT p.id payment_id, p.event_id, p.payment_type, p.amount, p.currency,
                    p.status payment_status, p.method, p.received_at, p.checkout_provider,
                    p.checkout_payment_ref, p.receipt_token, p.receipt_emailed_at,
                    e.external_id, e.title event_title, e.date event_date,
                    e.end_date event_end_date, e.promoter_name, e.promoter_email,
                    e.client_org, e.booker_name, e.booker_email,
                    v.name venue_name, v.address venue_address, v.city venue_city,
                    v.state venue_state, v.phone venue_phone, v.website_url venue_website,
                    v.timezone venue_timezone
             FROM event_payments p
             JOIN events e ON e.id = p.event_id
             LEFT JOIN venues v ON v.id = e.venue_id
             WHERE p.id = ? AND p.receipt_token = ? AND p.status != \'voided\'
             LIMIT 1',
            [$paymentId, $token]
        );
        if ($row === null || !hash_equals((string) $row['receipt_token'], $token)) {
            return null;
        }

        $items = $this->db->all(
            "SELECT description, category, amount, currency
             FROM event_ledger_entries
             WHERE event_id = ? AND line_type = 'revenue' AND is_void = 0
             ORDER BY created_at, id",
            [(int) $row['event_id']]
        );
        $itemTotal = array_reduce($items, static fn(float $sum, array $item): float => $sum + (float) $item['amount'], 0.0);
        if ($items === [] || abs($itemTotal - (float) $row['amount']) > 0.005) {
            $items = [[
                'description' => self::paymentLabel((string) $row['payment_type']) . ' — ' . (string) $row['event_title'],
                'category'    => (string) $row['payment_type'],
                'amount'      => $row['amount'],
                'currency'    => $row['currency'],
            ]];
        }
        $row['items'] = $items;
        $row['receipt_number'] = sprintf(
            'RCT-%s-%06d',
            preg_replace('/[^A-Za-z0-9-]/', '', (string) ($row['external_id'] ?: $row['event_id'])),
            $paymentId
        );
        return $row;
    }

    public function receiptUrl(int $paymentId, string $token): string
    {
        return rtrim((string) (getenv('APP_URL') ?: ''), '/')
            . '/api/public/payments/' . $paymentId . '/receipt?token=' . rawurlencode($token);
    }

    /** Render a complete receipt page. Controls are excluded from PDF output. */
    public function renderHtml(array $data, bool $interactive = true): string
    {
        $h = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $currency = strtoupper((string) ($data['currency'] ?? 'USD'));
        $money = static fn($value): string => ($currency === 'USD' ? '$' : $currency . ' ')
            . number_format((float) $value, 2);

        $timezone = (string) ($data['venue_timezone'] ?: 'America/Los_Angeles');
        try {
            $zone = new \DateTimeZone($timezone);
        } catch (\Throwable) {
            $zone = new \DateTimeZone('America/Los_Angeles');
        }
        $paidAt = 'Pending';
        if (!empty($data['received_at'])) {
            $stamp = db_timestamp_to_epoch((string) $data['received_at']);
            $date = (new \DateTimeImmutable('@' . $stamp))->setTimezone($zone);
            $paidAt = $date->format('F j, Y \a\t g:i A T');
        }
        $eventDate = !empty($data['event_date'])
            ? (new \DateTimeImmutable((string) $data['event_date'], $zone))->format('F j, Y')
            : '';
        if (!empty($data['event_end_date']) && $data['event_end_date'] !== $data['event_date']) {
            $eventDate .= '–' . (new \DateTimeImmutable((string) $data['event_end_date'], $zone))->format('F j, Y');
        }

        $client = array_values(array_filter([
            $data['client_org'] ?? null,
            $data['promoter_name'] ?? null,
            $data['promoter_email'] ?? null,
        ], static fn($v): bool => trim((string) $v) !== ''));
        $address = trim(implode(', ', array_filter([
            $data['venue_address'] ?? null,
            trim((string) ($data['venue_city'] ?? '') . ', ' . (string) ($data['venue_state'] ?? ''), ' ,'),
        ])));
        $rows = '';
        foreach ($data['items'] as $item) {
            $description = (string) ($item['description'] ?: self::paymentLabel((string) ($item['category'] ?? 'payment')));
            $rows .= '<tr><td>' . $h($description) . '</td><td class="amount">' . $h($money($item['amount'])) . '</td></tr>';
        }

        $provider = self::paymentLabel((string) ($data['method'] ?: $data['checkout_provider'] ?: 'credit_card'));
        $confirmation = trim((string) ($data['checkout_payment_ref'] ?? ''));
        $confirmationHtml = $confirmation !== ''
            ? '<div><span>Confirmation:&nbsp;</span><strong>' . $h($confirmation) . '</strong></div>'
            : '';
        $controls = '';
        if ($interactive) {
            $download = rtrim((string) (getenv('APP_URL') ?: ''), '/')
                . '/api/public/payments/' . (int) $data['payment_id'] . '/download?token=' . rawurlencode((string) $data['receipt_token']);
            $controls = '<nav class="actions"><button type="button" onclick="window.print()">Print receipt</button>'
                . '<a href="' . $h($download) . '">Download PDF</a></nav>';
        }

        $title = 'Receipt ' . (string) $data['receipt_number'];
        return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="robots" content="noindex,nofollow,noarchive,nosnippet">'
            . '<meta name="referrer" content="no-referrer"><title>' . $h($title) . '</title>'
            . '<style>' . self::css() . '</style></head><body>' . $controls
            . '<main class="receipt"><header><div><h1>' . $h($data['venue_name'] ?: 'Receipt') . '</h1>'
            . ($address !== '' ? '<p>' . $h($address) . '</p>' : '')
            . (!empty($data['venue_phone']) ? '<p>' . $h($data['venue_phone']) . '</p>' : '')
            . '</div><div class="title"><b>RECEIPT</b><span>' . $h($data['receipt_number']) . '</span><em>PAID</em></div></header>'
            . '<section class="parties"><div><h2>Received From</h2>'
            . ($client !== [] ? implode('', array_map(static fn($v): string => '<p>' . htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>', $client)) : '<p>Client</p>')
            . '</div><div><h2>Event</h2><p><strong>' . $h($data['event_title']) . '</strong></p><p>' . $h($eventDate) . '</p></div></section>'
            . '<table><thead><tr><th>Description</th><th class="amount">Amount</th></tr></thead><tbody>' . $rows . '</tbody>'
            . '<tfoot><tr><td>Total Paid</td><td class="amount">' . $h($money($data['amount'])) . '</td></tr></tfoot></table>'
            . '<section class="details"><div><span>Payment date:&nbsp;</span><strong>' . $h($paidAt) . '</strong></div>'
            . '<div><span>Payment method:&nbsp;</span><strong>' . $h($provider) . '</strong></div>' . $confirmationHtml . '</section>'
            . '<footer>Thank you. This receipt confirms that payment was received in full for the amount shown above.</footer>'
            . '</main></body></html>';
    }

    public function renderPdf(array $data): string
    {
        if (!is_executable(self::WKHTMLTOPDF)) {
            throw new \RuntimeException('wkhtmltopdf is not installed');
        }
        $args = '--quiet --page-size Letter --margin-top 0.5in --margin-right 0.5in'
            . ' --margin-bottom 0.5in --margin-left 0.5in --encoding utf-8 - -';
        $proc = proc_open(self::WKHTMLTOPDF . ' ' . $args, [
            0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
        ], $pipes);
        if (!is_resource($proc)) {
            throw new \RuntimeException('Could not start receipt PDF renderer');
        }
        fwrite($pipes[0], $this->renderHtml($data, false));
        fclose($pipes[0]);
        $pdf = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        if ($exit !== 0 || !str_starts_with($pdf, '%PDF')) {
            throw new \RuntimeException("Receipt PDF generation failed ({$exit}): {$stderr}");
        }
        return $pdf;
    }

    /** Send a PDF receipt and private download link. Returns true on MTA acceptance. */
    public function email(array $data): bool
    {
        $recipient = trim((string) ($data['promoter_email'] ?: $data['booker_email'] ?: ''));
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            error_log('Receipt email skipped: no valid client email for event payment ' . (int) $data['payment_id']);
            return false;
        }
        $url = $this->receiptUrl((int) $data['payment_id'], (string) $data['receipt_token']);
        $amount = (strtoupper((string) $data['currency']) === 'USD' ? '$' : strtoupper((string) $data['currency']) . ' ')
            . number_format((float) $data['amount'], 2);
        $name = trim((string) ($data['promoter_name'] ?? '')) ?: 'there';
        $text = "Hi {$name},\n\nWe received your {$amount} payment for {$data['event_title']}.\n\n"
            . "View, print, or download your receipt:\n{$url}\n\nThank you,\n{$data['venue_name']}";
        $html = '<p>Hi ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . ',</p><p>We received your <strong>'
            . htmlspecialchars($amount, ENT_QUOTES, 'UTF-8') . '</strong> payment for '
            . htmlspecialchars((string) $data['event_title'], ENT_QUOTES, 'UTF-8') . '.</p><p><a href="'
            . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">View, print, or download your receipt</a></p><p>Thank you,<br>'
            . htmlspecialchars((string) $data['venue_name'], ENT_QUOTES, 'UTF-8') . '</p>';
        $attachments = [];
        try {
            $attachments[] = [
                'filename' => strtolower((string) $data['receipt_number']) . '.pdf',
                'mime'     => 'application/pdf',
                'bytes'    => $this->renderPdf($data),
            ];
        } catch (\Throwable $e) {
            error_log('Receipt PDF attachment failed for payment ' . (int) $data['payment_id'] . ': ' . $e->getMessage());
        }
        $mailer = new Mailer($this->root, $this->db);
        $mailer->send($recipient, 'Payment receipt — ' . (string) $data['event_title'], $text, $html, 'event-payment-receipt', [], $attachments);
        return $mailer->deliveryAccepted();
    }

    private static function paymentLabel(string $value): string
    {
        return ucwords(str_replace('_', ' ', $value));
    }

    private static function css(): string
    {
        return <<<'CSS'
*{box-sizing:border-box}body{margin:0;background:#f5f3ee;color:#171717;font-family:Arial,sans-serif}.actions{max-width:760px;margin:24px auto 0;display:flex;justify-content:flex-end;gap:10px}.actions a,.actions button{border:1px solid #171717;background:#fff;color:#171717;border-radius:5px;padding:10px 15px;text-decoration:none;font:600 14px Arial;cursor:pointer}.receipt{width:760px;max-width:calc(100% - 32px);margin:18px auto 48px;background:#fff;padding:42px 48px;box-shadow:0 3px 20px #0002}.receipt header{display:flex;justify-content:space-between;gap:24px;border-bottom:2px solid #171717;padding-bottom:18px}.receipt h1{font:normal 25px Georgia,serif;margin:0 0 6px}.receipt p{margin:3px 0;line-height:1.4}.title{text-align:right;display:flex;flex-direction:column;align-items:flex-end;gap:5px}.title b{font-size:28px;letter-spacing:.1em}.title span{font-size:13px}.title em{background:#dcfce7;color:#166534;border-radius:99px;padding:5px 12px;font:700 12px Arial;letter-spacing:.06em}.parties{display:grid;grid-template-columns:1fr 1fr;gap:40px;margin:28px 0}.parties h2{font-size:11px;text-transform:uppercase;letter-spacing:.08em;color:#666;margin:0 0 7px}table{width:100%;border-collapse:collapse}th{text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.08em;color:#666;border-bottom:1px solid #171717;padding:0 0 7px}td{padding:12px 0;border-bottom:1px solid #ddd;vertical-align:top}.amount{text-align:right;white-space:nowrap}tfoot td{font-size:18px;font-weight:700;border-top:2px solid #171717;border-bottom:0;padding-top:13px}.details{margin-top:28px;border:1px solid #ccc;padding:14px 18px}.details div{display:grid;grid-template-columns:140px 1fr;gap:12px;padding:5px 0;font-size:13px}.details span{color:#666}.details strong{padding-left:12px;word-break:break-all}footer{margin-top:30px;color:#555;font-size:12px;line-height:1.5}@media(max-width:600px){.receipt{padding:28px 22px}.parties{grid-template-columns:1fr;gap:20px}.details div{grid-template-columns:1fr}.details strong{padding-left:0}.actions{margin-right:16px}}@media print{body{background:#fff}.actions{display:none}.receipt{width:auto;max-width:none;margin:0;padding:0;box-shadow:none}}
CSS;
    }
}
