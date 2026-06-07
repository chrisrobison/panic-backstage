<?php
declare(strict_types=1);

namespace Panic;

/**
 * Self-contained QR Code generator that renders an SVG for arbitrary text.
 *
 * Served at GET /assets/qr.svg?text=<payload> (the kernel/router forward that
 * exact path here). Zero runtime dependencies: this is a from-scratch QR
 * encoder (model 2, byte mode, ECC level M) — no Composer / gd / imagick.
 *
 * Output is image/svg+xml so it embeds directly in <img src> tags, ticket
 * pages, and HTML emails. The payload is our short plaintext ticket token, so
 * the encoded data stays small and scannable.
 */
final class QrCode extends BaseEndpoint
{
    public function handle(Request $request): Response
    {
        if ($request->method() !== 'GET') {
            return Response::methodNotAllowed();
        }

        $text = (string) $request->query('text', '');
        if ($text === '') {
            // 1x1 transparent placeholder keeps <img> tags from showing a broken icon.
            return new Response(
                '<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"></svg>',
                200,
                ['Content-Type' => 'image/svg+xml', 'Cache-Control' => 'no-store']
            );
        }

        $size   = (int) ($request->query('size', '240') ?? 240);
        $size   = max(64, min(1024, $size));
        $margin = 4;

        try {
            $matrix = $this->encode($text);
        } catch (\Throwable $e) {
            return new Response(
                '<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"></svg>',
                200,
                ['Content-Type' => 'image/svg+xml', 'Cache-Control' => 'no-store']
            );
        }

        $svg = $this->renderSvg($matrix, $size, $margin);
        return new Response($svg, 200, [
            'Content-Type'  => 'image/svg+xml',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    private function renderSvg(array $matrix, int $size, int $margin): string
    {
        $count = count($matrix);
        $dim   = $count + 2 * $margin;
        $rects = '';
        for ($r = 0; $r < $count; $r++) {
            for ($c = 0; $c < $count; $c++) {
                if ($matrix[$r][$c]) {
                    $x = $c + $margin;
                    $y = $r + $margin;
                    $rects .= "M{$x},{$y}h1v1h-1z";
                }
            }
        }
        return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $size . '" height="' . $size
            . '" viewBox="0 0 ' . $dim . ' ' . $dim . '" shape-rendering="crispEdges">'
            . '<rect width="' . $dim . '" height="' . $dim . '" fill="#ffffff"/>'
            . '<path d="' . $rects . '" fill="#000000"/></svg>';
    }

    // ---- QR encoder (byte mode, ECC level M) ----

    /** @return array<int,array<int,int>> matrix of 0/1 */
    private function encode(string $data): array
    {
        $ecLevel = 'M';
        $version = $this->pickVersion(strlen($data), $ecLevel);
        $bits    = $this->buildDataBits($data, $version, $ecLevel);
        $codewords = $this->bitsToCodewords($bits, $version, $ecLevel);
        $final   = $this->interleave($codewords, $version, $ecLevel);
        return $this->buildMatrix($final, $version, $ecLevel);
    }

    private function pickVersion(int $len, string $ecLevel): int
    {
        for ($v = 1; $v <= 40; $v++) {
            $cap = $this->dataCapacityCodewords($v, $ecLevel);
            // header: 4 (mode) + char-count-indicator bits, +4 terminator margin -> bytes
            $ccBits = $v < 10 ? 8 : 16;
            $needBits = 4 + $ccBits + $len * 8;
            if ($cap * 8 >= $needBits) {
                return $v;
            }
        }
        throw new \RuntimeException('Payload too large for QR encoding');
    }

    private function buildDataBits(string $data, int $version, string $ecLevel): string
    {
        $len = strlen($data);
        $ccBits = $version < 10 ? 8 : 16;
        $bits = '0100'; // byte mode
        $bits .= str_pad(decbin($len), $ccBits, '0', STR_PAD_LEFT);
        for ($i = 0; $i < $len; $i++) {
            $bits .= str_pad(decbin(ord($data[$i])), 8, '0', STR_PAD_LEFT);
        }
        $capacityBits = $this->dataCapacityCodewords($version, $ecLevel) * 8;
        // terminator
        $bits .= str_repeat('0', min(4, max(0, $capacityBits - strlen($bits))));
        // pad to byte boundary
        if (strlen($bits) % 8 !== 0) {
            $bits .= str_repeat('0', 8 - (strlen($bits) % 8));
        }
        // pad bytes
        $pad = ['11101100', '00010001'];
        $i = 0;
        while (strlen($bits) < $capacityBits) {
            $bits .= $pad[$i % 2];
            $i++;
        }
        return $bits;
    }

    /** @return array<int,int> data codewords */
    private function bitsToCodewords(string $bits, int $version, string $ecLevel): array
    {
        $cw = [];
        for ($i = 0, $n = strlen($bits); $i < $n; $i += 8) {
            $cw[] = bindec(substr($bits, $i, 8));
        }
        return $cw;
    }

    /**
     * Split data codewords into blocks, compute EC codewords per block, and
     * interleave per the QR spec. Returns the full bit-string to place.
     */
    private function interleave(array $dataCodewords, int $version, string $ecLevel): string
    {
        [$ecPerBlock, $group1Blocks, $group1Cw, $group2Blocks, $group2Cw] = $this->ecBlockInfo($version, $ecLevel);

        $blocks = [];
        $pos = 0;
        for ($b = 0; $b < $group1Blocks; $b++) {
            $blocks[] = array_slice($dataCodewords, $pos, $group1Cw);
            $pos += $group1Cw;
        }
        for ($b = 0; $b < $group2Blocks; $b++) {
            $blocks[] = array_slice($dataCodewords, $pos, $group2Cw);
            $pos += $group2Cw;
        }

        $ecBlocks = [];
        foreach ($blocks as $block) {
            $ecBlocks[] = $this->reedSolomon($block, $ecPerBlock);
        }

        // interleave data
        $result = [];
        $maxData = max($group1Cw, $group2Cw);
        for ($i = 0; $i < $maxData; $i++) {
            foreach ($blocks as $block) {
                if (isset($block[$i])) {
                    $result[] = $block[$i];
                }
            }
        }
        // interleave EC
        for ($i = 0; $i < $ecPerBlock; $i++) {
            foreach ($ecBlocks as $ec) {
                if (isset($ec[$i])) {
                    $result[] = $ec[$i];
                }
            }
        }

        $bits = '';
        foreach ($result as $byte) {
            $bits .= str_pad(decbin($byte), 8, '0', STR_PAD_LEFT);
        }
        // remainder bits
        $bits .= str_repeat('0', $this->remainderBits($version));
        return $bits;
    }

    // ---- Reed-Solomon over GF(256) ----

    private static array $expTable = [];
    private static array $logTable = [];

    private function initGalois(): void
    {
        if (self::$expTable !== []) {
            return;
        }
        $exp = array_fill(0, 512, 0);
        $log = array_fill(0, 256, 0);
        $x = 1;
        for ($i = 0; $i < 255; $i++) {
            $exp[$i] = $x;
            $log[$x] = $i;
            $x <<= 1;
            if ($x & 0x100) {
                $x ^= 0x11d;
            }
        }
        for ($i = 255; $i < 512; $i++) {
            $exp[$i] = $exp[$i - 255];
        }
        self::$expTable = $exp;
        self::$logTable = $log;
    }

    private function gfMul(int $a, int $b): int
    {
        if ($a === 0 || $b === 0) {
            return 0;
        }
        return self::$expTable[(self::$logTable[$a] + self::$logTable[$b]) % 255];
    }

    /** @return array<int,int> EC codewords */
    private function reedSolomon(array $data, int $ecCount): array
    {
        $this->initGalois();
        $generator = $this->rsGenerator($ecCount);
        $msg = array_merge($data, array_fill(0, $ecCount, 0));
        $dataLen = count($data);
        for ($i = 0; $i < $dataLen; $i++) {
            $coef = $msg[$i];
            if ($coef === 0) {
                continue;
            }
            for ($j = 0; $j < count($generator); $j++) {
                $msg[$i + $j] ^= $this->gfMul($generator[$j], $coef);
            }
        }
        return array_slice($msg, $dataLen);
    }

    private function rsGenerator(int $ecCount): array
    {
        $g = [1];
        for ($i = 0; $i < $ecCount; $i++) {
            $next = array_fill(0, count($g) + 1, 0);
            for ($j = 0; $j < count($g); $j++) {
                $next[$j] ^= $g[$j];
                $next[$j + 1] ^= $this->gfMul($g[$j], self::$expTable[$i]);
            }
            $g = $next;
        }
        return $g;
    }

    // ---- Matrix construction ----

    private function buildMatrix(string $bits, int $version, string $ecLevel): array
    {
        $size = 17 + $version * 4;
        $matrix = array_fill(0, $size, array_fill(0, $size, null));
        $reserved = array_fill(0, $size, array_fill(0, $size, false));

        $this->placeFinder($matrix, $reserved, 0, 0);
        $this->placeFinder($matrix, $reserved, 0, $size - 7);
        $this->placeFinder($matrix, $reserved, $size - 7, 0);
        $this->placeSeparators($matrix, $reserved, $size);
        $this->placeAlignment($matrix, $reserved, $version, $size);
        $this->placeTiming($matrix, $reserved, $size);
        // dark module
        $matrix[$size - 8][8] = 1;
        $reserved[$size - 8][8] = true;
        $this->reserveFormatAreas($reserved, $size);

        $this->placeData($matrix, $reserved, $bits, $size);

        // choose mask 0 (deterministic; acceptable for short tokens)
        $mask = 0;
        $this->applyMask($matrix, $reserved, $mask, $size);
        $this->placeFormatInfo($matrix, $ecLevel, $mask, $size);

        // collapse nulls to 0
        for ($r = 0; $r < $size; $r++) {
            for ($c = 0; $c < $size; $c++) {
                $matrix[$r][$c] = (int) ($matrix[$r][$c] ?? 0);
            }
        }
        return $matrix;
    }

    private function placeFinder(array &$m, array &$res, int $row, int $col): void
    {
        for ($r = -1; $r <= 7; $r++) {
            for ($c = -1; $c <= 7; $c++) {
                $rr = $row + $r;
                $cc = $col + $c;
                if ($rr < 0 || $cc < 0 || $rr >= count($m) || $cc >= count($m)) {
                    continue;
                }
                $isBorder = ($r >= 0 && $r <= 6 && ($c === 0 || $c === 6))
                    || ($c >= 0 && $c <= 6 && ($r === 0 || $r === 6));
                $isCenter = ($r >= 2 && $r <= 4 && $c >= 2 && $c <= 4);
                $m[$rr][$cc] = ($isBorder || $isCenter) ? 1 : 0;
                $res[$rr][$cc] = true;
            }
        }
    }

    private function placeSeparators(array &$m, array &$res, int $size): void
    {
        // handled implicitly by finder loop writing the -1..7 border as 0
    }

    private function placeAlignment(array &$m, array &$res, int $version, int $size): void
    {
        if ($version < 2) {
            return;
        }
        $positions = $this->alignmentPositions($version);
        foreach ($positions as $r) {
            foreach ($positions as $c) {
                // skip finder overlaps
                if (($r <= 8 && $c <= 8) || ($r <= 8 && $c >= $size - 9) || ($r >= $size - 9 && $c <= 8)) {
                    continue;
                }
                for ($dr = -2; $dr <= 2; $dr++) {
                    for ($dc = -2; $dc <= 2; $dc++) {
                        $val = (max(abs($dr), abs($dc)) !== 1) ? 1 : 0;
                        $m[$r + $dr][$c + $dc] = $val;
                        $res[$r + $dr][$c + $dc] = true;
                    }
                }
            }
        }
    }

    private function placeTiming(array &$m, array &$res, int $size): void
    {
        for ($i = 8; $i < $size - 8; $i++) {
            $bit = ($i % 2 === 0) ? 1 : 0;
            if (!$res[6][$i]) {
                $m[6][$i] = $bit;
                $res[6][$i] = true;
            }
            if (!$res[$i][6]) {
                $m[$i][6] = $bit;
                $res[$i][6] = true;
            }
        }
    }

    private function reserveFormatAreas(array &$res, int $size): void
    {
        for ($i = 0; $i <= 8; $i++) {
            $res[8][$i] = true;
            $res[$i][8] = true;
        }
        for ($i = 0; $i < 8; $i++) {
            $res[8][$size - 1 - $i] = true;
            $res[$size - 1 - $i][8] = true;
        }
    }

    private function placeData(array &$m, array $res, string $bits, int $size): void
    {
        $dir = -1;
        $row = $size - 1;
        $col = $size - 1;
        $idx = 0;
        $len = strlen($bits);
        while ($col > 0) {
            if ($col === 6) {
                $col--;
            }
            while (true) {
                for ($i = 0; $i < 2; $i++) {
                    $c = $col - $i;
                    if (!$res[$row][$c]) {
                        $bit = $idx < $len ? (int) $bits[$idx] : 0;
                        $m[$row][$c] = $bit;
                        $idx++;
                    }
                }
                $row += $dir;
                if ($row < 0 || $row >= $size) {
                    $row -= $dir;
                    $dir = -$dir;
                    break;
                }
            }
            $col -= 2;
        }
    }

    private function applyMask(array &$m, array $res, int $mask, int $size): void
    {
        for ($r = 0; $r < $size; $r++) {
            for ($c = 0; $c < $size; $c++) {
                if ($res[$r][$c]) {
                    continue;
                }
                $flip = match ($mask) {
                    0 => ($r + $c) % 2 === 0,
                    default => false,
                };
                if ($flip) {
                    $m[$r][$c] ^= 1;
                }
            }
        }
    }

    private function placeFormatInfo(array &$m, string $ecLevel, int $mask, int $size): void
    {
        $ecBits = ['L' => 0b01, 'M' => 0b00, 'Q' => 0b11, 'H' => 0b10][$ecLevel];
        $data = ($ecBits << 3) | $mask;
        $rem = $data;
        for ($i = 0; $i < 10; $i++) {
            $rem = ($rem << 1) ^ (((($rem >> 9) & 1) === 1) ? 0x537 : 0);
        }
        $bitsVal = (($data << 10) | $rem) ^ 0x5412;
        $arr = [];
        for ($i = 14; $i >= 0; $i--) {
            $arr[] = ($bitsVal >> $i) & 1;
        }
        // around top-left
        $coords1 = [
            [8, 0], [8, 1], [8, 2], [8, 3], [8, 4], [8, 5], [8, 7], [8, 8],
            [7, 8], [5, 8], [4, 8], [3, 8], [2, 8], [1, 8], [0, 8],
        ];
        foreach ($coords1 as $k => [$r, $c]) {
            $m[$r][$c] = $arr[$k];
        }
        // around top-right / bottom-left
        $coords2 = [
            [$size - 1, 8], [$size - 2, 8], [$size - 3, 8], [$size - 4, 8],
            [$size - 5, 8], [$size - 6, 8], [$size - 7, 8],
            [8, $size - 8], [8, $size - 7], [8, $size - 6], [8, $size - 5],
            [8, $size - 4], [8, $size - 3], [8, $size - 2], [8, $size - 1],
        ];
        foreach ($coords2 as $k => [$r, $c]) {
            $m[$r][$c] = $arr[$k];
        }
    }

    // ---- spec tables ----

    private function dataCapacityCodewords(int $version, string $ecLevel): int
    {
        [$ec, $g1b, $g1c, $g2b, $g2c] = $this->ecBlockInfo($version, $ecLevel);
        return $g1b * $g1c + $g2b * $g2c;
    }

    private function remainderBits(int $version): int
    {
        $map = [
            1 => 0, 2 => 7, 3 => 7, 4 => 7, 5 => 7, 6 => 7,
            7 => 0, 8 => 0, 9 => 0, 10 => 0, 11 => 0, 12 => 0, 13 => 0,
            14 => 3, 15 => 3, 16 => 3, 17 => 3, 18 => 3, 19 => 3, 20 => 3,
            21 => 4, 22 => 4, 23 => 4, 24 => 4, 25 => 4, 26 => 4, 27 => 4,
            28 => 3, 29 => 3, 30 => 3, 31 => 3, 32 => 3, 33 => 3, 34 => 3,
            35 => 0, 36 => 0, 37 => 0, 38 => 0, 39 => 0, 40 => 0,
        ];
        return $map[$version] ?? 0;
    }

    private function alignmentPositions(int $version): array
    {
        $table = [
            1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30],
            6 => [6, 34], 7 => [6, 22, 38], 8 => [6, 24, 42], 9 => [6, 26, 46],
            10 => [6, 28, 50],
        ];
        return $table[$version] ?? [];
    }

    /**
     * EC block structure for level M, versions 1-10 (sufficient for short
     * token payloads). [ecCodewordsPerBlock, g1Blocks, g1Cw, g2Blocks, g2Cw].
     */
    private function ecBlockInfo(int $version, string $ecLevel): array
    {
        // Level M only (we encode at M).
        $m = [
            1  => [10, 1, 16, 0, 0],
            2  => [16, 1, 28, 0, 0],
            3  => [26, 1, 44, 0, 0],
            4  => [18, 2, 32, 0, 0],
            5  => [24, 2, 43, 0, 0],
            6  => [16, 4, 27, 0, 0],
            7  => [18, 4, 31, 0, 0],
            8  => [22, 2, 38, 2, 39],
            9  => [22, 3, 36, 2, 37],
            10 => [26, 4, 43, 1, 44],
        ];
        if (!isset($m[$version])) {
            throw new \RuntimeException('Unsupported QR version for level M');
        }
        return $m[$version];
    }
}
