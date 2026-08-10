<?php
declare(strict_types=1);

namespace Panic;

/** Public, opaque-token-gated receipt page and PDF download. */
final class EventPaymentReceipts extends BaseEndpoint
{
    public function handle(Request $request): Response
    {
        if ($request->method() !== 'GET') {
            return Response::methodNotAllowed();
        }
        $paymentId = (int) ($this->params['paymentId'] ?? 0);
        $action = (string) ($this->params['action'] ?? 'receipt');
        $token = trim((string) $request->query('token', ''));
        $service = new EventPaymentReceiptService($this->db, $this->root);
        $data = $service->load($paymentId, $token);
        if ($data === null) {
            return $this->html($this->statusPage('Receipt unavailable', 'This receipt link is invalid or no longer available.'), 404);
        }
        if (($data['payment_status'] ?? '') !== 'received') {
            if ((string) $request->query('checkout', '') === 'canceled') {
                return $this->html($this->statusPage('Payment canceled', 'No payment was recorded. You may safely close this page.'));
            }
            return $this->html($this->statusPage(
                'Confirming your payment',
                'Your payment was submitted. This page will refresh automatically as soon as the confirmation arrives.',
                3
            ));
        }
        if ($action === 'download') {
            try {
                $pdf = $service->renderPdf($data);
            } catch (\Throwable $e) {
                error_log('Receipt PDF download failed for payment ' . $paymentId . ': ' . $e->getMessage());
                return $this->html($this->statusPage('PDF unavailable', 'The printable receipt is available, but its PDF could not be generated. Please use your browser’s Print command.'), 500);
            }
            return Response::download($pdf, strtolower((string) $data['receipt_number']) . '.pdf', 'application/pdf')
                ->withHeader('Cache-Control', 'no-store')
                ->withHeader('Referrer-Policy', 'no-referrer');
        }
        return $this->html($service->renderHtml($data));
    }

    private function html(string $body, int $status = 200): Response
    {
        return new Response($body, $status, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
            'X-Frame-Options' => 'DENY',
        ]);
    }

    private function statusPage(string $title, string $message, ?int $refresh = null): string
    {
        $h = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $meta = $refresh !== null ? '<meta http-equiv="refresh" content="' . $refresh . '">' : '';
        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="robots" content="noindex,nofollow,noarchive"><meta name="referrer" content="no-referrer">' . $meta
            . '<title>' . $h($title) . '</title><style>body{margin:0;min-height:100vh;display:grid;place-items:center;background:#f5f3ee;color:#171717;font-family:Arial,sans-serif}.card{background:#fff;border:1px solid #d5d1c8;border-radius:8px;max-width:520px;margin:20px;padding:42px;text-align:center;box-shadow:0 4px 18px #0001}h1{font:normal 28px Georgia,serif;margin:0 0 14px}p{color:#555;line-height:1.6;margin:0}</style></head><body><main class="card"><h1>'
            . $h($title) . '</h1><p>' . $h($message) . '</p></main></body></html>';
    }
}
