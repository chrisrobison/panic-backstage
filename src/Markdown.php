<?php
declare(strict_types=1);

namespace Panic;

/**
 * Dependency-free Markdown -> HTML renderer + frontmatter parser for the
 * Staff Handbook & Compliance system (docs/staff/**). Deliberately small
 * and hand-written, matching this repo's "no Composer runtime deps"
 * convention (see QrCode.php / Pdf.php for the same pattern applied to
 * other formats).
 *
 * Not a full CommonMark implementation -- supports exactly what the staff
 * handbook content uses: headings (with auto id + TOC), paragraphs, bold/
 * italic/code spans, links, images, fenced code blocks, block quotes,
 * ordered/unordered lists, GFM-style pipe tables, and horizontal rules.
 * Anything fancier (nested lists beyond one level, footnotes, etc.) simply
 * falls back to a plain paragraph rather than mis-rendering.
 */
final class Markdown
{
    /**
     * Split a raw document into (frontmatter array, remaining body).
     * Frontmatter is a simple `---\nkey: value\n---` block at the very top
     * -- not full YAML, just line-based `key: value` pairs. `true`/`false`
     * become booleans; everything else stays a string (callers cast as
     * needed, e.g. version numbers).
     *
     * @return array{0: array<string,mixed>, 1: string}
     */
    public static function splitFrontmatter(string $raw): array
    {
        $raw = str_replace("\r\n", "\n", $raw);
        if (!str_starts_with($raw, "---\n") && $raw !== "---") {
            return [[], $raw];
        }
        $end = strpos($raw, "\n---", 3);
        if ($end === false) {
            return [[], $raw];
        }
        $block = substr($raw, 4, $end - 4);
        $body = substr($raw, $end + 4);
        $body = ltrim($body, "\n");

        $meta = [];
        foreach (explode("\n", $block) as $line) {
            $line = rtrim($line);
            if ($line === '' || !str_contains($line, ':')) {
                continue;
            }
            [$key, $value] = explode(':', $line, 2);
            $key = trim($key);
            $value = trim($value);
            $value = trim($value, "\"'");
            if ($value === '') {
                $meta[$key] = null;
            } elseif ($value === 'true') {
                $meta[$key] = true;
            } elseif ($value === 'false') {
                $meta[$key] = false;
            } else {
                $meta[$key] = $value;
            }
        }
        return [$meta, $body];
    }

    /**
     * Render Markdown body to HTML. Returns the HTML plus a flat table of
     * contents (level 2/3 headings) with the same slug ids used in the
     * HTML, so a caller can build an in-page nav without re-parsing.
     *
     * @return array{html: string, toc: list<array{level:int,text:string,id:string}>}
     */
    public static function render(string $body): array
    {
        $body = str_replace("\r\n", "\n", $body);
        $lines = explode("\n", $body);
        $blocks = self::splitBlocks($lines);

        $html = [];
        $toc = [];
        $usedIds = [];

        foreach ($blocks as $block) {
            [$type, $data] = $block;
            switch ($type) {
                case 'heading':
                    [$level, $text] = $data;
                    $id = self::slugify($text, $usedIds);
                    $html[] = sprintf(
                        '<h%1$d id="%2$s">%3$s<a class="heading-anchor" href="#%2$s" aria-hidden="true">#</a></h%1$d>',
                        $level,
                        $id,
                        self::inline($text)
                    );
                    if ($level >= 2 && $level <= 3) {
                        $toc[] = ['level' => $level, 'text' => strip_tags(self::inline($text)), 'id' => $id];
                    }
                    break;

                case 'hr':
                    $html[] = '<hr>';
                    break;

                case 'code':
                    [$lang, $code] = $data;
                    $langClass = $lang !== '' ? ' class="language-' . self::escapeAttr($lang) . '"' : '';
                    $html[] = "<pre><code{$langClass}>" . self::escapeHtml($code) . "</code></pre>";
                    break;

                case 'blockquote':
                    $inner = self::render(implode("\n", $data));
                    $html[] = '<blockquote>' . $inner['html'] . '</blockquote>';
                    break;

                case 'ul':
                case 'ol':
                    $tag = $type === 'ul' ? 'ul' : 'ol';
                    $items = array_map(fn($item) => '<li>' . self::inline($item) . '</li>', $data);
                    $html[] = "<{$tag}>" . implode('', $items) . "</{$tag}>";
                    break;

                case 'table':
                    $html[] = self::renderTable($data);
                    break;

                case 'paragraph':
                    $html[] = '<p>' . self::inline(implode(' ', $data)) . '</p>';
                    break;
            }
        }

        return ['html' => implode("\n", $html), 'toc' => $toc];
    }

    /** @param list<string> $lines */
    private static function splitBlocks(array $lines): array
    {
        $blocks = [];
        $i = 0;
        $n = count($lines);

        while ($i < $n) {
            $line = $lines[$i];

            if (trim($line) === '') {
                $i++;
                continue;
            }

            // Fenced code block
            if (preg_match('/^```\s*(\S*)\s*$/', $line, $m)) {
                $lang = $m[1];
                $code = [];
                $i++;
                while ($i < $n && !preg_match('/^```\s*$/', $lines[$i])) {
                    $code[] = $lines[$i];
                    $i++;
                }
                $i++; // skip closing fence
                $blocks[] = ['code', [$lang, implode("\n", $code)]];
                continue;
            }

            // Heading
            if (preg_match('/^(#{1,6})\s+(.+?)\s*#*\s*$/', $line, $m)) {
                $blocks[] = ['heading', [strlen($m[1]), $m[2]]];
                $i++;
                continue;
            }

            // Horizontal rule
            if (preg_match('/^(-{3,}|\*{3,}|_{3,})\s*$/', $line)) {
                $blocks[] = ['hr', null];
                $i++;
                continue;
            }

            // Block quote (collect contiguous >-prefixed lines)
            if (str_starts_with(ltrim($line), '>')) {
                $quote = [];
                while ($i < $n && str_starts_with(ltrim($lines[$i]), '>')) {
                    $quote[] = preg_replace('/^\s*>\s?/', '', $lines[$i]);
                    $i++;
                }
                $blocks[] = ['blockquote', $quote];
                continue;
            }

            // Table: header row + separator row (---|---)
            if (str_contains($line, '|') && $i + 1 < $n && preg_match('/^\s*\|?\s*:?-+:?\s*(\|\s*:?-+:?\s*)*\|?\s*$/', $lines[$i + 1])) {
                $header = self::splitTableRow($line);
                $align = self::splitTableRow($lines[$i + 1]);
                $rows = [];
                $i += 2;
                while ($i < $n && str_contains($lines[$i], '|') && trim($lines[$i]) !== '') {
                    $rows[] = self::splitTableRow($lines[$i]);
                    $i++;
                }
                $blocks[] = ['table', ['header' => $header, 'align' => $align, 'rows' => $rows]];
                continue;
            }

            // Unordered list
            if (preg_match('/^\s*[-*+]\s+(.+)$/', $line)) {
                $items = [];
                while ($i < $n && preg_match('/^\s*[-*+]\s+(.+)$/', $lines[$i], $m)) {
                    $items[] = $m[1];
                    $i++;
                }
                $blocks[] = ['ul', $items];
                continue;
            }

            // Ordered list
            if (preg_match('/^\s*\d+[.)]\s+(.+)$/', $line)) {
                $items = [];
                while ($i < $n && preg_match('/^\s*\d+[.)]\s+(.+)$/', $lines[$i], $m)) {
                    $items[] = $m[1];
                    $i++;
                }
                $blocks[] = ['ol', $items];
                continue;
            }

            // Paragraph: collect until blank line or a line that starts a new block type
            $para = [$line];
            $i++;
            while (
                $i < $n
                && trim($lines[$i]) !== ''
                && !preg_match('/^(#{1,6})\s+/', $lines[$i])
                && !preg_match('/^```/', $lines[$i])
                && !str_starts_with(ltrim($lines[$i]), '>')
                && !preg_match('/^\s*[-*+]\s+/', $lines[$i])
                && !preg_match('/^\s*\d+[.)]\s+/', $lines[$i])
            ) {
                $para[] = $lines[$i];
                $i++;
            }
            $blocks[] = ['paragraph', $para];
        }

        return $blocks;
    }

    /** @return list<string> */
    private static function splitTableRow(string $line): array
    {
        $line = trim($line);
        $line = trim($line, '|');
        return array_map('trim', explode('|', $line));
    }

    /** @param array{header:list<string>,align:list<string>,rows:list<list<string>>} $table */
    private static function renderTable(array $table): string
    {
        $aligns = array_map(function ($a) {
            $a = trim($a);
            $left = str_starts_with($a, ':');
            $right = str_ends_with($a, ':');
            if ($left && $right) return ' style="text-align:center"';
            if ($right) return ' style="text-align:right"';
            if ($left) return ' style="text-align:left"';
            return '';
        }, $table['align']);

        $out = '<div class="table-scroll"><table><thead><tr>';
        foreach ($table['header'] as $idx => $cell) {
            $out .= '<th' . ($aligns[$idx] ?? '') . '>' . self::inline($cell) . '</th>';
        }
        $out .= '</tr></thead><tbody>';
        foreach ($table['rows'] as $row) {
            $out .= '<tr>';
            foreach ($row as $idx => $cell) {
                $out .= '<td' . ($aligns[$idx] ?? '') . '>' . self::inline($cell) . '</td>';
            }
            $out .= '</tr>';
        }
        $out .= '</tbody></table></div>';
        return $out;
    }

    /** Inline formatting: bold, italic, code spans, links, images, autolinks. */
    private static function inline(string $text): string
    {
        $text = self::escapeHtml($text);

        // Code spans first (protect their contents from further inline rules)
        $text = preg_replace_callback('/`([^`]+)`/', function ($m) {
            return '<code>' . $m[1] . '</code>';
        }, $text);

        // Images: ![alt](src)
        $text = preg_replace_callback('/!\[([^\]]*)\]\(([^)\s]+)(?:\s+"([^"]*)")?\)/', function ($m) {
            $title = isset($m[3]) && $m[3] !== '' ? ' title="' . self::escapeAttr($m[3]) . '"' : '';
            return '<img src="' . self::escapeAttr($m[2]) . '" alt="' . self::escapeAttr($m[1]) . '"' . $title . '>';
        }, $text);

        // Links: [text](href)
        $text = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)(?:\s+"([^"]*)")?\)/', function ($m) {
            $title = isset($m[3]) && $m[3] !== '' ? ' title="' . self::escapeAttr($m[3]) . '"' : '';
            return '<a href="' . self::escapeAttr($m[2]) . '"' . $title . '>' . $m[1] . '</a>';
        }, $text);

        // Autolinks: <https://...>
        $text = preg_replace_callback('/&lt;(https?:\/\/[^\s&]+)&gt;/', function ($m) {
            return '<a href="' . self::escapeAttr($m[1]) . '">' . $m[1] . '</a>';
        }, $text);

        // Bold: **text** or __text__
        $text = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $text);
        $text = preg_replace('/__(.+?)__/s', '<strong>$1</strong>', $text);

        // Italic: *text* or _text_ (single, not adjacent to already-consumed **)
        $text = preg_replace('/(?<!\*)\*([^*\n]+)\*(?!\*)/', '<em>$1</em>', $text);
        $text = preg_replace('/(?<!_)_([^_\n]+)_(?!_)/', '<em>$1</em>', $text);

        return $text;
    }

    private static function escapeHtml(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function escapeAttr(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** @param array<string,true> $used */
    private static function slugify(string $text, array &$used): string
    {
        $slug = strtolower(trim($text));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');
        if ($slug === '') {
            $slug = 'section';
        }
        $base = $slug;
        $n = 2;
        while (isset($used[$slug])) {
            $slug = $base . '-' . $n;
            $n++;
        }
        $used[$slug] = true;
        return $slug;
    }
}
