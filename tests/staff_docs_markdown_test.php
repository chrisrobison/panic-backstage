<?php
/**
 * Tests for Panic\Markdown (the Staff Handbook & Compliance renderer) —
 * frontmatter parsing, heading/anchor/TOC generation, and the inline/block
 * rendering the handbook content actually relies on. No DB.
 *
 * Run with: php tests/staff_docs_markdown_test.php
 */

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use Panic\Markdown;
use Panic\StaffDocs;

$passed = 0;
$failed = 0;

function ok(bool $cond, string $label): void
{
    global $passed, $failed;
    if ($cond) { echo "  ✓ $label\n"; $passed++; }
    else        { echo "  ✗ FAIL: $label\n"; $failed++; }
}

echo "\n=== Staff Docs Markdown tests ===\n\n";

// --- Frontmatter -----------------------------------------------------------

$raw = <<<MD
---
title: Test Doc
slug: test-doc
document_type: policy
version: 0.2
effective_date:
requires_acknowledgment: true
status: draft
---

# Heading

Body text.
MD;

[$meta, $body] = Markdown::splitFrontmatter($raw);
ok($meta['title'] === 'Test Doc', 'frontmatter: title parsed');
ok($meta['slug'] === 'test-doc', 'frontmatter: slug parsed');
ok($meta['document_type'] === 'policy', 'frontmatter: document_type parsed');
ok($meta['version'] === '0.2', 'frontmatter: version parsed as string');
ok($meta['effective_date'] === null, 'frontmatter: blank value is null, not empty string');
ok($meta['requires_acknowledgment'] === true, 'frontmatter: "true" coerced to bool true');
ok(!str_contains($body, '---'), 'frontmatter: delimiter stripped from body');
ok(str_starts_with(trim($body), '# Heading'), 'frontmatter: body starts at the real content');

[$noMeta, $plainBody] = Markdown::splitFrontmatter("# No frontmatter here\n\nJust text.");
ok($noMeta === [], 'a document with no frontmatter block returns an empty meta array');
ok(str_starts_with($plainBody, '# No frontmatter'), 'and the body is returned untouched');

// --- Heading anchors + TOC ---------------------------------------------------

$body = "# Title\n\n## First Section\n\nSome text.\n\n## Second Section\n\nMore text.\n\n### A Sub-heading\n\nDeep text.\n\n## First Section\n\nA duplicate heading text.\n";
$rendered = Markdown::render($body);
ok(str_contains($rendered['html'], 'id="title"'), 'h1 gets a slugified id');
ok(str_contains($rendered['html'], 'id="first-section"'), 'h2 gets a slugified id');
ok(count($rendered['toc']) === 4, 'TOC includes every h2/h3 (4 headings, h1 excluded)');
ok($rendered['toc'][0]['id'] === 'first-section', 'first TOC entry id matches its heading');
ok($rendered['toc'][3]['id'] === 'first-section-2', 'duplicate heading text gets a disambiguated id, not a collision');
ok(str_contains($rendered['html'], 'heading-anchor'), 'headings render a deep-link anchor');

// --- Inline formatting, lists, tables, blockquotes --------------------------

$mixed = "Some **bold**, *italic*, and `code`, plus a [link](https://example.com).\n\n"
    . "- one\n- two\n\n1. first\n2. second\n\n"
    . "> **TODO — Management decision required:** figure this out\n\n"
    . "| A | B |\n|---|---|\n| 1 | 2 |\n";
$out = Markdown::render($mixed)['html'];
ok(str_contains($out, '<strong>bold</strong>'), 'bold renders');
ok(str_contains($out, '<em>italic</em>'), 'italic renders');
ok(str_contains($out, '<code>code</code>'), 'inline code renders');
ok(str_contains($out, '<a href="https://example.com">link</a>'), 'links render');
ok(str_contains($out, '<ul><li>one</li><li>two</li></ul>'), 'unordered list renders');
ok(str_contains($out, '<ol><li>first</li><li>second</li></ol>'), 'ordered list renders');
ok(str_contains($out, '<blockquote>') && str_contains($out, 'TODO'), 'blockquote (used for TODO/VERIFY callouts) renders');
ok(str_contains($out, '<table>') && str_contains($out, '<td>1</td>'), 'pipe table renders');
ok(!str_contains($out, '<script'), 'raw text is HTML-escaped, never passed through as markup (no script injection)');

// User-supplied-looking text can never break out into real markup even if
// it contains angle brackets — this content is staff-authored, not
// end-user input, but the renderer should still be safe by construction.
$hostile = Markdown::render("<script>alert(1)</script>\n\nSome *text* with <b>raw html</b>.")['html'];
ok(!str_contains($hostile, '<script>alert'), 'a literal <script> tag in source text is escaped, not executed');
ok(str_contains($hostile, '&lt;b&gt;raw html&lt;/b&gt;'), 'other raw-looking HTML in source text is escaped too');

// --- Cross-document link rewriting (StaffDocs::rewriteCrossDocLinks) -------
// Content is authored with Git-relative Markdown links between documents;
// publishing rewrites them into this app's #staff-docs-<slug> hash routes.

$rewrite = new ReflectionMethod(StaffDocs::class, 'rewriteCrossDocLinks');
$rewrite->setAccessible(true);
$call = fn (string $html): string => $rewrite->invoke(null, $html);

ok(
    str_contains($call('<a href="alcohol-service.md">Alcohol Service</a>'), 'href="#staff-docs-alcohol-service"'),
    'a bare top-level Markdown link rewrites to its #staff-docs-<slug> route'
);
ok(
    str_contains($call('<a href="../staff/venue-safety.md">Venue Safety</a>'), 'href="#staff-docs-venue-safety"'),
    'a relative ../staff/ Markdown link rewrites the same way'
);
ok(
    str_contains($call('<a href="sop/bartender.md">Bartender SOP</a>'), 'href="#staff-docs-sop-bartender"'),
    'a link into sop/ gets the "sop-" slug prefix'
);
ok(
    str_contains($call('<a href="https://example.com/policy.md">External</a>'), 'href="https://example.com/policy.md"'),
    'an absolute https:// link ending in .md is left untouched, not mistaken for a cross-doc reference'
);

echo "\n=== Results: $passed passed, $failed failed ===\n\n";
exit($failed > 0 ? 1 : 0);
