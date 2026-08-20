<?php

declare(strict_types=1);

namespace Panic;

/**
 * Lays out ONE physical ticket onto an already-open Pdf page: venue/event
 * branding, ticket type + price, the human-readable ticket number, the
 * vector QR, and small print. Pure layout — no PDF byte-format knowledge
 * (that lives in Pdf.php) and no database access (the caller, PhysicalTicket
 * PdfGenerator, supplies everything as plain arrays already loaded).
 *
 * Draws in TRIM-BOX-LOCAL coordinates: (0,0) is the bottom-left corner of the
 * finished (post-trim) ticket, (0, $heightPt) top-left, y increasing upward,
 * regardless of how much bleed/crop-mark margin surrounds it on the actual
 * page. render() adds $originX/$originY once per drawing call to place that
 * trim box at the right spot on the page PhysicalTicketPdfGenerator opened.
 *
 * Artwork scope: PNG/JPEG background images only (see Pdf.php's docblock).
 * Arbitrary PDF/SVG artwork would need a real PDF/SVG parser to embed
 * faithfully — out of scope here, and not required by any of the brief's
 * acceptance criteria, all of which are satisfiable with a text-only ticket.
 */
final class PhysicalTicketRenderer
{
    /** QR quiet zone, in modules, matching QrCode.php's own PNG/SVG renderers. */
    private const QUIET_ZONE_MODULES = 4;

    /** Minimum quiet-zone width the print-validation pass will accept, in points. */
    private const MIN_QUIET_ZONE_PT = 2.0;

    /**
     * Render one ticket. All arrays are plain associative data (already
     * fetched from the DB by the caller) — this method touches no storage.
     *
     * @param array{id:int,printed_number:string,token:string} $ticket
     * @param array{name:?string,seller_label:?string} $batch
     * @param array{title:string,date:?string,doors_time:?string,show_time:?string} $event
     * @param array{name:string,price_cents:int,currency:string} $ticketType
     * @param array{name:?string,address:?string,city:?string,state:?string} $venue
     * @param string $ticketUrl Full https://host/t/{token} URL (already built by caller)
     * @param ?string $artworkImageId Pdf image id previously registered via addPngImage()/addJpegImage(), or null
     *
     * @return array{
     *   trim_box: array{x:float,y:float,w:float,h:float},
     *   qr_box: array{x:float,y:float,w:float,h:float},
     *   qr_module_count:int,
     *   quiet_zone_pt:float
     * } geometry actually used, for PhysicalTicketPdfGenerator's print-validation pass.
     */
    public function render(
        Pdf $pdf,
        float $originX,
        float $originY,
        float $widthPt,
        float $heightPt,
        array $ticket,
        array $batch,
        array $event,
        array $ticketType,
        array $venue,
        string $ticketUrl,
        ?string $artworkImageId
    ): array {
        // Artwork, if present, is drawn first and covers the FULL page area
        // handed to us by the generator (which is the bleed box when bleed >
        // 0) — the generator passes originX/originY/widthPt/heightPt for the
        // artwork call separately; see PhysicalTicketPdfGenerator. Here we
        // only draw the trim-box content (branding, QR, text), which is
        // always positioned relative to the finished (post-trim) ticket.

        $pad = 8.0; // inner margin inside the trim box that no text crosses
        $innerW = $widthPt - 2 * $pad;
        $y = $heightPt - $pad - 10; // cursor, top-down

        $center = fn(float $localY): float => $originY + $localY;
        $cx = $originX + $widthPt / 2;

        // ── venue branding ──────────────────────────────────────────────
        $venueName = strtoupper(trim((string) ($venue['name'] ?? 'Mabuhay Gardens')));
        $pdf->text($cx, $center($y), $venueName, 8.5, true, 'center');
        $y -= 12;

        $pdf->text($cx, $center($y), 'ADMIT ONE', 6, false, 'center');
        $y -= 12;

        // ── event title (wraps up to 2 lines, shrinks the box below it) ──
        $title = strtoupper(trim((string) ($event['title'] ?? '')));
        $titleLines = Pdf::wrapText($title, $innerW, 10, true, 2);
        foreach ($titleLines as $line) {
            $pdf->text($cx, $center($y), $line, 10, true, 'center');
            $y -= 12;
        }

        // ── date / doors / show time ─────────────────────────────────────
        $dateLine = $this->formatDate((string) ($event['date'] ?? ''));
        if ($dateLine !== '') {
            $pdf->text($cx, $center($y), $dateLine, 8, false, 'center');
            $y -= 11;
        }
        $timeLine = $this->formatTimeLine($event);
        if ($timeLine !== '') {
            $pdf->text($cx, $center($y), $timeLine, 8, false, 'center');
            $y -= 11;
        }

        $y -= 4;
        $pdf->line($originX + $pad, $center($y), $originX + $widthPt - $pad, $center($y), 0.5);
        $y -= 12;

        // ── ticket type + price ──────────────────────────────────────────
        $typeName = strtoupper(trim((string) ($ticketType['name'] ?? '')));
        $pdf->text($cx, $center($y), $typeName, 9, true, 'center');
        $y -= 12;

        $price = (int) ($ticketType['price_cents'] ?? 0);
        $priceLabel = $price > 0 ? self::money($price, (string) ($ticketType['currency'] ?? 'USD')) : 'FREE';
        $pdf->text($cx, $center($y), $priceLabel, 9, false, 'center');
        $y -= 14;

        $pdf->line($originX + $pad, $center($y), $originX + $widthPt - $pad, $center($y), 0.5);
        $y -= 14;

        // ── QR block ──────────────────────────────────────────────────────
        // Reserve space for everything BELOW the QR first (ticket number,
        // fallback code, venue line, footer), so the QR box size is whatever
        // is left — never guessed independently of the rest of the layout
        // (that's how text could end up crossing the trim boundary).
        $belowQrHeight = 12 /* ticket number */ + 9 /* fallback code */
            + ($this->venueLine($venue) !== '' ? 9 : 0)
            + 9 /* panicbooking.com */
            + ($this->batchLine($batch) !== '' ? 8 : 0)
            + $pad;

        $qrAreaTop = $y;
        $qrAreaBottom = $pad + $belowQrHeight;
        $qrAvailable = max(40.0, $qrAreaTop - $qrAreaBottom);
        $qrBoxPt = min($qrAvailable, $innerW);
        $qrY = $qrAreaTop - $qrBoxPt;
        $qrX = $originX + ($widthPt - $qrBoxPt) / 2;

        $matrix = QrCode::matrix($ticketUrl);
        $moduleCount = count($matrix);
        $moduleSize = $qrBoxPt / ($moduleCount + 2 * self::QUIET_ZONE_MODULES);
        $quietZonePt = $moduleSize * self::QUIET_ZONE_MODULES;

        // White quiet-zone box FIRST — this is what guarantees no artwork
        // (or anything else) sits behind the QR, per the brief's requirement.
        $pdf->fillRect($qrX, $center($qrY), $qrBoxPt, $qrBoxPt, false);
        for ($r = 0; $r < $moduleCount; $r++) {
            for ($c = 0; $c < $moduleCount; $c++) {
                if ($matrix[$r][$c]) {
                    $mx = $qrX + ($c + self::QUIET_ZONE_MODULES) * $moduleSize;
                    $my = $qrY + $qrBoxPt - ($r + self::QUIET_ZONE_MODULES + 1) * $moduleSize;
                    $pdf->fillRect($mx, $center($my), $moduleSize, $moduleSize, true);
                }
            }
        }

        $y = $qrY - 10;

        // ── ticket number ────────────────────────────────────────────────
        $number = (string) $ticket['printed_number'];
        $pdf->text($cx, $center($y), 'Ticket #' . $number, 9, true, 'center');
        $y -= 11;

        // ── fallback short code (NOT the QR secret in full — see docblock) ─
        $fallback = $this->fallbackCode((string) $ticket['token']);
        $pdf->text($cx, $center($y), $fallback, 6, false, 'center');
        $y -= 10;

        // ── venue address ────────────────────────────────────────────────
        $venueLine = $this->venueLine($venue);
        if ($venueLine !== '') {
            $pdf->text($cx, $center($y), $venueLine, 6, false, 'center');
            $y -= 9;
        }

        $pdf->text($cx, $center($y), 'panicbooking.com', 6, false, 'center');
        $y -= 9;

        // ── batch / seller info, deliberately tiny ───────────────────────
        $batchLine = $this->batchLine($batch);
        if ($batchLine !== '') {
            $pdf->text($cx, $center($y), $batchLine, 5, false, 'center');
        }

        return [
            'trim_box' => ['x' => $originX, 'y' => $originY, 'w' => $widthPt, 'h' => $heightPt],
            'qr_box'   => ['x' => $qrX, 'y' => $originY + $qrY, 'w' => $qrBoxPt, 'h' => $qrBoxPt],
            'qr_module_count' => $moduleCount,
            'quiet_zone_pt'   => $quietZonePt,
        ];
    }

    /**
     * Draw optional background artwork covering the full area handed in
     * (which may be the bleed box, larger than the trim box) — called
     * separately and BEFORE render(), so it sits behind everything else.
     */
    public function renderArtwork(Pdf $pdf, string $imageId, float $x, float $y, float $w, float $h): void
    {
        $pdf->drawImage($imageId, $x, $y, $w, $h);
    }

    /**
     * A short, human-copyable fallback for the rare case a QR can't be
     * scanned (a smudge, a bad print run) — deliberately NOT the full secret
     * token: only the first 8 characters, split for readability. Per the
     * brief: "Do not place the random QR token prominently on the ticket
     * unless needed as a small fallback code" — this is that fallback, kept
     * short and printed tiny, not the credential itself.
     */
    private function fallbackCode(string $token): string
    {
        $head = substr($token, 0, 8);
        return substr($head, 0, 4) . '-' . substr($head, 4, 4);
    }

    private function venueLine(array $venue): string
    {
        $parts = array_filter([
            trim((string) ($venue['address'] ?? '')),
            trim((string) ($venue['city'] ?? '')) . (
                !empty($venue['state']) ? ', ' . trim((string) $venue['state']) : ''
            ),
        ], static fn(string $s): bool => $s !== '' && $s !== ',');
        return implode(" \xC2\xB7 ", $parts);
    }

    private function batchLine(array $batch): string
    {
        $bits = array_filter([
            !empty($batch['name']) ? 'Batch: ' . trim((string) $batch['name']) : null,
            !empty($batch['seller_label']) ? 'Seller: ' . trim((string) $batch['seller_label']) : null,
        ]);
        return implode("  \xC2\xB7  ", $bits);
    }

    private function formatDate(string $date): string
    {
        if ($date === '') {
            return '';
        }
        $ts = strtotime($date);
        return $ts !== false ? date('l, F j', $ts) : $date;
    }

    private function formatTimeLine(array $event): string
    {
        $bits = [];
        $doors = trim((string) ($event['doors_time'] ?? ''));
        $show  = trim((string) ($event['show_time'] ?? ''));
        if ($doors !== '') {
            $bits[] = 'Doors ' . $this->formatTime($doors);
        }
        if ($show !== '') {
            $bits[] = 'Show ' . $this->formatTime($show);
        }
        return implode("  \xC2\xB7  ", $bits);
    }

    private function formatTime(string $time): string
    {
        $ts = strtotime($time);
        return $ts !== false ? date('g:i A', $ts) : $time;
    }

    private static function money(int $cents, string $currency): string
    {
        $symbol = $currency === 'USD' ? '$' : ($currency . ' ');
        return $symbol . number_format($cents / 100, 2);
    }

    /** Minimum acceptable quiet-zone width, for PhysicalTicketPdfGenerator's validation pass. */
    public static function minQuietZonePt(): float
    {
        return self::MIN_QUIET_ZONE_PT;
    }
}
