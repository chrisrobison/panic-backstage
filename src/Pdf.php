<?php

declare(strict_types=1);

namespace Panic;

/**
 * Minimal, dependency-free, from-scratch PDF object writer.
 *
 * This is NOT a general-purpose PDF library — it implements exactly the
 * primitives PhysicalTicketRenderer needs to lay out a print-ready ticket:
 * exact-dimension pages, filled rectangles (used for both vector QR modules
 * and crop marks), thin lines (crop marks), text set in the 14 standard PDF
 * fonts, and JPEG/PNG raster image XObjects for optional artwork.
 *
 * Deliberately hand-rolled rather than pulling in a Composer PDF library —
 * matches this codebase's existing convention (see QrCode.php, also a
 * from-scratch encoder with zero dependencies) and the brief's explicit
 * requirement to avoid unnecessary dependencies. The whole point of a
 * physical print run is exact, predictable geometry; writing the bytes
 * directly makes that geometry a small set of checkable numbers instead of
 * something inferred from how a browser/HTML engine happens to render.
 *
 * Fonts: Helvetica / Helvetica-Bold, the two of PDF's 14 "standard" fonts
 * used here. These do NOT require embedding font program bytes — every
 * PDF-compliant viewer and print RIP is required to resolve them without an
 * embedded font file, and they render as fully vector (outline) glyphs, not
 * a raster substitute. This is a deliberate scope decision: it satisfies
 * "vector text" and "print-safe rendering" without writing a font subsetting
 * engine. It does NOT satisfy a literal reading of "embed every font as a
 * file" — there is no embedded font PROGRAM in the output, only a reference
 * to a font every conformant reader already has. See the feature summary for
 * why this tradeoff was made.
 *
 * Coordinates: standard PDF space — origin at the page's bottom-left corner,
 * x right, y up, in points (1 inch = 72pt). Callers doing top-down layout
 * should convert (e.g. `$pageHeightPt - $fromTop`).
 *
 * Text-width measurement (textWidth()/wrapText()) uses an approximate,
 * hand-built per-character-class width table for Helvetica/Helvetica-Bold —
 * NOT the exact Adobe AFM metrics. It is deliberately biased slightly wide
 * (see charWidthThousandths()) so layout/bounds decisions err on the side of
 * more room, not text that silently overflows a box. Good enough for the
 * short labels/numbers a ticket carries; not a general typesetting engine.
 */
final class Pdf
{
    private const PT_PER_IN = 72.0;

    /** @var array<int,array{dict:string,stream:?string}> objNum => object */
    private array $objects = [];
    private int $nextNum = 1;

    private int $catalogNum;
    private int $pagesNum;
    private int $fontRegularNum;
    private int $fontBoldNum;

    /** @var array<int,array{w:float,h:float,contentNum:int,imageRefs:array<string,int>}> */
    private array $pages = [];

    private ?int $currentPageIndex = null;
    private string $currentStream = '';
    /** @var array<string,int> XObject name (e.g. "Im3") => image obj num, this page only */
    private array $currentImageRefs = [];

    /** @var array<string,array{objNum:int,w:int,h:int}> registered images by caller-chosen id, reusable across pages */
    private array $images = [];
    private int $imageCounter = 0;

    public function __construct()
    {
        $this->catalogNum = $this->reserveObject();
        $this->pagesNum   = $this->reserveObject();
        $this->fontRegularNum = $this->setObject($this->reserveObject(),
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>');
        $this->fontBoldNum = $this->setObject($this->reserveObject(),
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>');
    }

    // ── page lifecycle ──────────────────────────────────────────────────────────

    /** Start a new page of the given exact size (points). Flushes any prior page. */
    public function beginPage(float $widthPt, float $heightPt): void
    {
        $this->flushCurrentPage();
        $this->currentPageIndex = count($this->pages);
        $this->pages[$this->currentPageIndex] = ['w' => $widthPt, 'h' => $heightPt, 'contentNum' => 0, 'imageRefs' => []];
        $this->currentStream = '';
        $this->currentImageRefs = [];
    }

    private function flushCurrentPage(): void
    {
        if ($this->currentPageIndex === null) {
            return;
        }
        $contentNum = $this->addStreamObject('', $this->currentStream);
        $this->pages[$this->currentPageIndex]['contentNum'] = $contentNum;
        $this->pages[$this->currentPageIndex]['imageRefs'] = $this->currentImageRefs;
    }

    public function pageCount(): int
    {
        return count($this->pages) + ($this->currentPageIndex !== null ? 0 : 0);
        // currentPageIndex, if set, is already counted in $this->pages.
    }

    // ── drawing primitives (operate on the page currently open via beginPage()) ──

    /** Filled rectangle, solid black or white (the only two colors a ticket needs). */
    public function fillRect(float $x, float $y, float $w, float $h, bool $black = true): void
    {
        $g = $black ? '0' : '1';
        $this->currentStream .= sprintf("%s g\n%.3F %.3F %.3F %.3F re f\n", $g, $x, $y, $w, $h);
    }

    /** Thin stroked line (crop marks). */
    public function line(float $x1, float $y1, float $x2, float $y2, float $widthPt = 0.5, bool $black = true): void
    {
        $g = $black ? '0' : '1';
        $this->currentStream .= sprintf("%s G\n%.3F w\n%.3F %.3F m %.3F %.3F l S\n", $g, $widthPt, $x1, $y1, $x2, $y2);
    }

    /**
     * Draw text with its baseline at ($x,$y). $align controls how $x is
     * interpreted: 'left' (default), 'center', or 'right'.
     */
    public function text(float $x, float $y, string $text, float $size, bool $bold = false, string $align = 'left'): void
    {
        $text = self::toWinAnsi($text);
        if ($align !== 'left') {
            $w = self::textWidth($text, $size, $bold);
            $x = $align === 'center' ? $x - $w / 2 : $x - $w;
        }
        $font = $bold ? 'F2' : 'F1';
        $this->currentStream .= sprintf(
            "BT\n/%s %.3F Tf\n%.3F %.3F Td\n(%s) Tj\nET\n",
            $font, $size, $x, $y, self::escapeText($text)
        );
    }

    /**
     * Draw a previously-registered image (see addJpegImage()/addPngImage())
     * scaled to fill the box ($x,$y,$w,$h).
     */
    public function drawImage(string $imageId, float $x, float $y, float $w, float $h): void
    {
        if (!isset($this->images[$imageId])) {
            throw new \RuntimeException("Pdf::drawImage: unknown image id '{$imageId}'");
        }
        if ($this->currentPageIndex === null) {
            throw new \RuntimeException('Pdf::drawImage: no page open (call beginPage() first)');
        }
        $xobjName = $this->xobjNameFor($imageId);
        $this->currentStream .= sprintf("q\n%.3F 0 0 %.3F %.3F %.3F cm\n/%s Do\nQ\n", $w, $h, $x, $y, $xobjName);
    }

    private function xobjNameFor(string $imageId): string
    {
        foreach ($this->currentImageRefs as $name => $objNum) {
            if ($objNum === $this->images[$imageId]['objNum']) {
                return $name;
            }
        }
        $name = 'Im' . (++$this->imageCounter);
        $this->currentImageRefs[$name] = $this->images[$imageId]['objNum'];
        return $name;
    }

    // ── image registration ──────────────────────────────────────────────────────

    /**
     * Register a JPEG for later drawImage() calls. The original compressed
     * bytes are embedded verbatim (DCTDecode passthrough) — lossless,
     * no re-encoding, no external library.
     *
     * @return string image id to pass to drawImage()
     */
    public function addJpegImage(string $bytes, string $id): string
    {
        $info = @getimagesizefromstring($bytes);
        if ($info === false || $info[2] !== IMAGETYPE_JPEG) {
            throw new \InvalidArgumentException('addJpegImage: not a valid JPEG');
        }
        $w = (int) $info[0];
        $h = (int) $info[1];
        $channels = $info['channels'] ?? 3;
        $colorSpace = $channels === 1 ? '/DeviceGray' : '/DeviceRGB';

        $objNum = $this->addStreamObject(
            "/Type /XObject /Subtype /Image /Width {$w} /Height {$h} /ColorSpace {$colorSpace} /BitsPerComponent 8 /Filter /DCTDecode",
            $bytes
        );
        $this->images[$id] = ['objNum' => $objNum, 'w' => $w, 'h' => $h];
        return $id;
    }

    /**
     * Register a PNG for later drawImage() calls. Decoded via GD (already a
     * confirmed runtime dependency — QrCode.php's PNG path uses it) into raw
     * RGB samples, alpha flattened onto white, then re-embedded as a
     * FlateDecode raw-sample image. No arbitrary PDF/SVG artwork support —
     * see PhysicalTicketRenderer's docblock for that scope cut.
     *
     * @return string image id to pass to drawImage()
     */
    public function addPngImage(string $bytes, string $id): string
    {
        $src = @imagecreatefromstring($bytes);
        if ($src === false) {
            throw new \InvalidArgumentException('addPngImage: not a valid image');
        }
        $w = imagesx($src);
        $h = imagesy($src);

        // Flatten onto a white background so a transparent/alpha PNG never
        // lets artwork show through as unintended holes on a printed ticket.
        $flat = imagecreatetruecolor($w, $h);
        $white = imagecolorallocate($flat, 255, 255, 255);
        imagefilledrectangle($flat, 0, 0, $w, $h, $white);
        imagealphablending($flat, true);
        imagecopy($flat, $src, 0, 0, 0, 0, $w, $h);
        imagedestroy($src);

        $raw = '';
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $rgb = imagecolorat($flat, $x, $y);
                $raw .= chr(($rgb >> 16) & 0xFF) . chr(($rgb >> 8) & 0xFF) . chr($rgb & 0xFF);
            }
        }
        imagedestroy($flat);

        $compressed = (string) gzcompress($raw, 6);
        $objNum = $this->addStreamObject(
            "/Type /XObject /Subtype /Image /Width {$w} /Height {$h} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /FlateDecode",
            $compressed
        );
        $this->images[$id] = ['objNum' => $objNum, 'w' => $w, 'h' => $h];
        return $id;
    }

    // ── text metrics (approximate — see class docblock) ─────────────────────────

    public static function textWidth(string $text, float $size, bool $bold = false): float
    {
        $total = 0;
        $len = strlen($text);
        for ($i = 0; $i < $len; $i++) {
            $total += self::charWidthThousandths($text[$i], $bold);
        }
        return $total / 1000.0 * $size;
    }

    /**
     * Break $text into lines that each fit within $maxWidthPt at $size, up to
     * $maxLines. If the text still doesn't fit, the last line is truncated
     * with an ellipsis rather than silently overflowing the box.
     *
     * @return array<int,string>
     */
    public static function wrapText(string $text, float $maxWidthPt, float $size, bool $bold, int $maxLines): array
    {
        $words = preg_split('/\s+/', trim($text)) ?: [];
        $lines = [];
        $current = '';
        foreach ($words as $word) {
            $candidate = $current === '' ? $word : $current . ' ' . $word;
            if (self::textWidth($candidate, $size, $bold) <= $maxWidthPt || $current === '') {
                $current = $candidate;
            } else {
                $lines[] = $current;
                $current = $word;
                if (count($lines) >= $maxLines) {
                    break;
                }
            }
        }
        if ($current !== '' && count($lines) < $maxLines) {
            $lines[] = $current;
        }
        if (count($lines) > $maxLines) {
            $lines = array_slice($lines, 0, $maxLines);
        }
        // Truncate the final line with an ellipsis if it still overflows
        // (e.g. one absurdly long unbroken word) or if words were dropped.
        $consumed = implode(' ', $lines);
        if ($consumed !== trim($text) && $lines !== []) {
            $last = $lines[count($lines) - 1];
            while (self::textWidth($last . '…', $size, $bold) > $maxWidthPt && strlen($last) > 1) {
                $last = substr($last, 0, -1);
            }
            $lines[count($lines) - 1] = rtrim($last) . '…';
        }
        return $lines;
    }

    /** Approximate Helvetica/Helvetica-Bold width in 1/1000 em, by character class. */
    private static function charWidthThousandths(string $char, bool $bold): int
    {
        if ($char === ' ') {
            return 278;
        }
        if (ctype_upper($char)) {
            return $bold ? 722 : 667;
        }
        if (ctype_digit($char)) {
            return 556;
        }
        if (ctype_lower($char)) {
            return $bold ? 556 : 500;
        }
        // Punctuation/symbols default — biased slightly wide on purpose (see
        // class docblock: bounds checks should err toward "more room needed",
        // not toward text that silently overflows).
        return $bold ? 400 : 350;
    }

    // ── low-level object plumbing ───────────────────────────────────────────────

    private function reserveObject(): int
    {
        return $this->nextNum++;
    }

    /** @return int the object number that was written */
    private function setObject(int $num, string $fullDict): int
    {
        $this->objects[$num] = ['dict' => $fullDict, 'stream' => null];
        return $num;
    }

    /**
     * Add a new object carrying a stream. $dictInner is the dict's keys
     * WITHOUT the surrounding << >> and WITHOUT /Length (computed here).
     */
    private function addStreamObject(string $dictInner, string $stream): int
    {
        $num = $this->reserveObject();
        $dict = '<< ' . trim($dictInner) . ' /Length ' . strlen($stream) . ' >>';
        $this->objects[$num] = ['dict' => $dict, 'stream' => $stream];
        return $num;
    }

    /** PDF string literal escaping: backslash, ( and ) must be escaped. */
    private static function escapeText(string $s): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s);
    }

    /**
     * Best-effort UTF-8 -> Windows-1252 (WinAnsiEncoding is a superset of
     * Latin-1/close to CP1252 for the printable range) so an event title with
     * a curly quote or em-dash renders as that character instead of mojibake.
     * Falls back to stripping anything that still can't be represented.
     */
    private static function toWinAnsi(string $utf8): string
    {
        if ($utf8 === '') {
            return '';
        }
        $converted = @iconv('UTF-8', 'CP1252//TRANSLIT//IGNORE', $utf8);
        if ($converted !== false && $converted !== '') {
            return $converted;
        }
        // iconv unavailable or failed entirely — strip to ASCII rather than
        // emit raw UTF-8 bytes into a Latin-1-encoded PDF string.
        return preg_replace('/[^\x20-\x7E]/', '', $utf8) ?? '';
    }

    // ── output ───────────────────────────────────────────────────────────────────

    /** Finalize and return the complete PDF file bytes. */
    public function output(): string
    {
        $this->flushCurrentPage();
        $this->currentPageIndex = null; // guard against accidental further drawing

        // Build /Pages and each /Page dict now that every page is known.
        $kids = [];
        foreach ($this->pages as $page) {
            $pageNum = $this->reserveObject();
            $imgDictEntries = '';
            foreach ($page['imageRefs'] as $name => $objNum) {
                $imgDictEntries .= "/{$name} {$objNum} 0 R ";
            }
            $resources = '/Resources << /Font << /F1 ' . $this->fontRegularNum . ' 0 R /F2 ' . $this->fontBoldNum . ' 0 R >>'
                . ($imgDictEntries !== '' ? ' /XObject << ' . trim($imgDictEntries) . ' >>' : '')
                . ' >>';
            $dict = sprintf(
                '<< /Type /Page /Parent %d 0 R /MediaBox [0 0 %.3F %.3F] %s /Contents %d 0 R >>',
                $this->pagesNum, $page['w'], $page['h'], $resources, $page['contentNum']
            );
            $this->setObject($pageNum, $dict);
            $kids[] = $pageNum;
        }

        $this->setObject($this->pagesNum, sprintf(
            '<< /Type /Pages /Kids [%s] /Count %d >>',
            implode(' ', array_map(static fn(int $n): string => "{$n} 0 R", $kids)),
            count($kids)
        ));
        $this->setObject($this->catalogNum, sprintf('<< /Type /Catalog /Pages %d 0 R >>', $this->pagesNum));

        return $this->serialize();
    }

    private function serialize(): string
    {
        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0 => 0];
        $maxNum = 0;

        ksort($this->objects, SORT_NUMERIC);
        foreach ($this->objects as $num => $obj) {
            $maxNum = max($maxNum, $num);
            $offsets[$num] = strlen($out);
            if ($obj['stream'] !== null) {
                $out .= "{$num} 0 obj\n{$obj['dict']}\nstream\n{$obj['stream']}\nendstream\nendobj\n";
            } else {
                $out .= "{$num} 0 obj\n{$obj['dict']}\nendobj\n";
            }
        }

        $xrefStart = strlen($out);
        $out .= "xref\n0 " . ($maxNum + 1) . "\n";
        $out .= "0000000000 65535 f \n";
        for ($n = 1; $n <= $maxNum; $n++) {
            $offset = $offsets[$n] ?? 0;
            $out .= sprintf("%010d 00000 n \n", $offset);
        }

        $out .= "trailer\n<< /Size " . ($maxNum + 1) . " /Root {$this->catalogNum} 0 R >>\n";
        $out .= "startxref\n{$xrefStart}\n%%EOF\n";

        return $out;
    }

    public static function inchesToPoints(float $inches): float
    {
        return $inches * self::PT_PER_IN;
    }
}
