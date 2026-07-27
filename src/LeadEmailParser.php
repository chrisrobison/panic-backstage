<?php
declare(strict_types=1);

namespace Panic;

use Panic\Ai\ClaudeCli;

/**
 * Parses an inbound booking-request email (raw RFC 5322) into a normalized
 * lead field set.
 *
 * Two flavours of message are handled:
 *
 *   1. Structured "Jotform" notifications — label/value blocks like
 *        Who's Calling:  Brody Bass (drunkmonkpresents@gmail.com)
 *        The Vibe:       Public Show / Performance ...
 *        The Date:
 *        Expected Crowd: ... heads
 *        The Vision:     <freeform pitch>
 *      These are parsed deterministically (free, exact).
 *
 *   2. Freeform prose — a human writing a paragraph. These are extracted with
 *      Claude — via the local `claude` CLI's own OAuth/subscription session
 *      (Panic\Ai\ClaudeCli), not a billed API key — when that CLI is
 *      available, falling back to regex heuristics otherwise.
 *
 * The two strategies are combined: deterministic label values win where present,
 * and the LLM (or heuristics) fills the gaps and enriches freeform sections.
 *
 * The class performs no I/O against the database — it only turns bytes into an
 * array. scripts/ingest-booking-email.php wires it to the leads table.
 */
final class LeadEmailParser
{
    /** event_type values the Leads UI understands (see public/assets/leads.js). */
    public const EVENT_TYPES = ['concert', 'private_event', 'festival', 'comedy_show', 'other'];

    /** Jotform / generic intake labels → canonical key. Keys are lowercased, de-punctuated. */
    private const JOTFORM_LABELS = [
        "who's calling"  => 'who',
        'whos calling'   => 'who',
        'the vibe'       => 'vibe',
        'event type'     => 'vibe',
        'new booking alert' => 'headline',
        'booking alert'  => 'headline',
        'the date'       => 'date',
        'date'           => 'date',
        'expected crowd' => 'crowd',
        'attendance'     => 'crowd',
        'the vision'     => 'vision',
        'details'        => 'vision',
        'message'        => 'vision',
        'name'           => 'name',
        'email'          => 'email',
        'phone'          => 'phone',
    ];

    private bool $enabled;
    private string $model;
    private string $today;

    /**
     * @param bool|null $enabled Null (the default) auto-detects via
     *   ClaudeCli::isAvailable() — pass an explicit true/false to override
     *   that (tests do this to stay hermetic regardless of whether a real
     *   `claude` binary happens to be present on the box running them).
     */
    public function __construct(?bool $enabled = null, string $model = 'opus', ?string $today = null)
    {
        $this->enabled = $enabled ?? ClaudeCli::isAvailable();
        $this->model   = $model;
        $this->today   = $today ?: date('Y-m-d');
    }

    // ── Public entry point ──────────────────────────────────────────────────

    /**
     * @return array{lead: array<string,mixed>, meta: array<string,mixed>}
     */
    public function parse(string $raw): array
    {
        $msg     = $this->parseMime($raw);
        $headers = $msg['headers'];

        $bodyText = $msg['text'] !== '' ? $msg['text'] : $this->htmlToText($msg['html']);
        $bodyText = $this->normalize($bodyText);

        // Header-derived contact facts.
        [$fromName, $fromEmail] = $this->parseAddress($headers['from'] ?? '');
        [$replyName, $replyEmail] = $this->parseAddress($headers['reply-to'] ?? '');
        $subject   = $this->decodeHeader($headers['subject'] ?? '');
        $messageId = trim($headers['message-id'] ?? '', " \t<>");
        $receivedAt = $this->parseDate($headers['date'] ?? '');

        // Threading headers — used by Panic\Leads\ThreadMatcher (invoked from
        // scripts/ingest-booking-email.php) to fold an obvious reply into its
        // existing lead's conversation instead of spawning a new lead for
        // every back-and-forth. In-Reply-To is normally a single id;
        // References accumulates the whole prior chain. Either can carry a
        // Message-ID this system minted itself (Mailer::generateMessageId(),
        // stored on lead_messages.external_message_id) or one from a prior
        // inbound email (lead_intake_emails.message_id) — either is a valid
        // thread match.
        $inReplyToIds = $this->extractMessageIds($headers['in-reply-to'] ?? '');
        $referenceIds = array_values(array_unique(array_merge(
            $inReplyToIds,
            $this->extractMessageIds($headers['references'] ?? '')
        )));

        // Deterministic Jotform label parse.
        $labels   = $this->extractLabels($bodyText);
        $jotform  = $labels !== [];
        $det      = $this->fromLabels($labels);

        // The requester's real address: Jotform puts it in Reply-To; freeform
        // mail has it in From. Prefer a body-extracted address, then Reply-To,
        // then From — skipping the forwarder / noreply addresses.
        $contactEmail = $det['contact_email']
            ?? $this->preferredEmail($replyEmail, $fromEmail, $bodyText);
        $contactName  = $det['contact_name'] ?? ($replyName ?: $fromName);

        $lead = [
            'contact_name'         => $contactName ?: null,
            'contact_email'        => $contactEmail ?: null,
            'contact_org'          => null,
            'contact_phone'        => $det['contact_phone'] ?? null,
            'event_name'           => $det['event_name'] ?? ($subject ?: null),
            'event_type'           => $det['event_type'] ?? null,
            'band_name'            => null,
            'desired_date'         => $det['desired_date'] ?? null,
            'desired_date_alt'     => null,
            'projected_attendance' => $det['projected_attendance'] ?? null,
            'is_private'           => null,
            'alcohol_plan'         => null,
            'summary'              => null,
        ];

        $method = $jotform ? 'jotform' : 'heuristic';

        // Enrich with the LLM (or heuristics) over the freeform portion.
        $freeform = $det['vision'] ?? $bodyText;
        $enriched = $this->enrich($freeform, $subject, $contactName, $contactEmail);
        if ($enriched !== null) {
            $method = $jotform ? 'jotform+llm' : 'llm';
            // Deterministic label values take precedence; LLM fills the gaps.
            foreach ($lead as $k => $v) {
                if (($v === null || $v === '') && isset($enriched[$k]) && $enriched[$k] !== '') {
                    $lead[$k] = $enriched[$k];
                }
            }
        } else {
            $this->applyHeuristics($lead, $bodyText);
        }

        // Normalize / validate.
        $lead['event_type'] = $this->normalizeEventType($lead['event_type']);
        $lead['desired_date'] = $this->coerceDate($lead['desired_date']);
        $lead['desired_date_alt'] = $this->coerceDate($lead['desired_date_alt']);
        $lead['projected_attendance'] = $this->coerceInt($lead['projected_attendance']);
        $lead['is_private'] = $lead['is_private'] === null ? 0 : ($lead['is_private'] ? 1 : 0);

        // Build the notes block: a short summary + the full original message so
        // staff always have the verbatim request when triaging.
        $notes = $this->buildNotes($lead, $bodyText, $det['vision'] ?? null);

        return [
            'lead' => [
                'status'               => 'new',
                'source'               => 'email',
                'contact_name'         => $lead['contact_name'],
                'contact_email'        => $lead['contact_email'],
                'contact_org'          => $lead['contact_org'],
                'contact_phone'        => $lead['contact_phone'],
                'event_name'           => $lead['event_name'],
                'event_type'           => $lead['event_type'],
                'band_name'            => $lead['band_name'],
                'desired_date'         => $lead['desired_date'],
                'desired_date_alt'     => $lead['desired_date_alt'],
                'projected_attendance' => $lead['projected_attendance'],
                'is_private'           => $lead['is_private'],
                'alcohol_plan'         => $lead['alcohol_plan'],
                'notes'                => $notes,
                'risk_level'           => 'unknown',
            ],
            'meta' => [
                'parse_method'  => $method,
                'message_id'    => $messageId !== '' ? $messageId : null,
                'from_name'     => $fromName ?: null,
                'from_email'    => $fromEmail ?: null,
                'reply_to'      => $replyEmail ?: null,
                'to_recipients' => $this->decodeHeader($headers['to'] ?? '') ?: null,
                'subject'       => $subject ?: null,
                'received_at'   => $receivedAt,
                'summary'       => $lead['summary'],
                // Normalized body — added for the Booking Inbox conversation
                // feed (lead_messages, see scripts/ingest-booking-email.php),
                // which wants the human-readable text/HTML the parser already
                // derived rather than re-deriving it from the raw MIME again.
                'body_text'     => $bodyText,
                'body_html'     => $msg['html'] !== '' ? $msg['html'] : null,
                // Threading (see comment above where these are extracted).
                'in_reply_to'   => $inReplyToIds[0] ?? null,
                'reference_ids' => $referenceIds,
            ],
        ];
    }

    // ── Threading helpers ──────────────────────────────────────────────────

    /**
     * Pull Message-IDs out of an In-Reply-To/References header value.
     * Normally angle-bracketed and (for References) whitespace-separated;
     * a handful of broken senders omit the brackets, so that's the fallback.
     *
     * @return list<string> ids without angle brackets, in header order.
     */
    private function extractMessageIds(string $header): array
    {
        $header = trim($header);
        if ($header === '') {
            return [];
        }
        if (preg_match_all('/<([^<>\s]+)>/', $header, $m)) {
            return array_values(array_unique($m[1]));
        }
        return array_values(array_unique(array_filter(preg_split('/\s+/', $header) ?: [])));
    }

    /** Leading reply/forward prefix — Re:, Fwd:, Fw:, Aw:, Antw:, Sv: (English plus a few common localizations), optionally suffixed "[2]". */
    private const REPLY_PREFIX = '/^\s*(re|fwd?|aw|antw|sv)\s*(\[\d+\])?\s*:\s*/i';

    /**
     * Normalize a subject line for thread matching: strip repeated reply/
     * forward prefixes (see REPLY_PREFIX) and case-fold. Shared with
     * Panic\Leads\ThreadMatcher's subject+sender fallback so both sides of
     * the comparison are normalized identically.
     */
    public static function normalizeSubject(?string $subject): string
    {
        return mb_strtolower(self::canonicalSubject($subject));
    }

    /**
     * Return a display-ready conversation subject while preserving its case.
     */
    public static function canonicalSubject(?string $subject): string
    {
        $s = trim((string) $subject);
        if ($s === '') {
            return '';
        }
        do {
            $prev = $s;
            $s = trim((string) preg_replace(self::REPLY_PREFIX, '', $s));
        } while ($s !== $prev);
        $s = (string) preg_replace('/\s+/', ' ', $s);
        return $s;
    }

    /**
     * True when the subject itself carries a reply/forward prefix (Re:,
     * Fwd:, etc.) — used by Leads\Acknowledgment to recognize "this is
     * plainly a continuation of some conversation" even when
     * Panic\Leads\ThreadMatcher couldn't resolve it to a specific existing
     * lead (a reply to a pre-threading-era message, a different channel, or
     * a dropped References chain), so the neutral first-contact
     * acknowledgment isn't sent for it.
     */
    public static function hasReplyPrefix(?string $subject): bool
    {
        return (bool) preg_match(self::REPLY_PREFIX, trim((string) $subject));
    }

    // ── MIME parsing ──────────────────────────────────────────────────────────

    /**
     * @return array{headers: array<string,string>, text: string, html: string}
     */
    public function parseMime(string $raw): array
    {
        $raw = str_replace("\r\n", "\n", $raw);
        $split = preg_split("/\n\n/", $raw, 2);
        $headerBlock = $split[0] ?? '';
        $body        = $split[1] ?? '';

        $headers = $this->parseHeaders($headerBlock);
        $ctype   = $headers['content-type'] ?? 'text/plain';
        $cte     = strtolower(trim($headers['content-transfer-encoding'] ?? '7bit'));

        $text = '';
        $html = '';

        if (preg_match('/boundary="?([^";]+)"?/i', $ctype, $m)) {
            $boundary = $m[1];
            foreach ($this->splitParts($body, $boundary) as $part) {
                $this->collectPart($part, $text, $html);
            }
        } else {
            $charset = $this->charsetOf($ctype);
            $decoded = $this->decodeBody($body, $cte, $charset);
            if (stripos($ctype, 'text/html') !== false) {
                $html = $decoded;
            } else {
                $text = $decoded;
            }
        }

        return ['headers' => $headers, 'text' => $text, 'html' => $html];
    }

    /** Split a multipart body into its constituent parts (handles nesting flatly). */
    private function splitParts(string $body, string $boundary): array
    {
        $marker = '--' . $boundary;
        $chunks = explode($marker, $body);
        $parts  = [];
        foreach ($chunks as $chunk) {
            $chunk = ltrim($chunk, "\n");
            if ($chunk === '' || str_starts_with($chunk, '--')) {
                continue; // preamble or closing "--boundary--"
            }
            $parts[] = $chunk;
        }
        return $parts;
    }

    private function collectPart(string $part, string &$text, string &$html): void
    {
        $split = preg_split("/\n\n/", $part, 2);
        $headerBlock = $split[0] ?? '';
        $body        = $split[1] ?? '';
        $headers     = $this->parseHeaders($headerBlock);
        $ctype       = $headers['content-type'] ?? 'text/plain';
        $cte         = strtolower(trim($headers['content-transfer-encoding'] ?? '7bit'));

        // Nested multipart — recurse.
        if (preg_match('/boundary="?([^";]+)"?/i', $ctype, $m)) {
            foreach ($this->splitParts($body, $m[1]) as $sub) {
                $this->collectPart($sub, $text, $html);
            }
            return;
        }

        $decoded = $this->decodeBody($body, $cte, $this->charsetOf($ctype));
        if (stripos($ctype, 'text/html') !== false && $html === '') {
            $html = $decoded;
        } elseif (stripos($ctype, 'text/plain') !== false && $text === '') {
            $text = $decoded;
        }
    }

    private function decodeBody(string $body, string $cte, string $charset): string
    {
        $decoded = match ($cte) {
            'quoted-printable' => quoted_printable_decode($body),
            'base64'           => (string) base64_decode($body, true),
            default            => $body,
        };
        return $this->toUtf8($decoded, $charset);
    }

    private function parseHeaders(string $block): array
    {
        // Unfold continuation lines (RFC 5322 folding).
        $block = preg_replace("/\n[ \t]+/", ' ', $block);
        $headers = [];
        foreach (explode("\n", (string) $block) as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $key = strtolower(trim($name));
            // Keep the first occurrence for most headers; concatenate Delivered-To.
            if (!isset($headers[$key])) {
                $headers[$key] = trim($value);
            } elseif ($key === 'delivered-to' || $key === 'received') {
                $headers[$key] .= "\n" . trim($value);
            }
        }
        return $headers;
    }

    private function charsetOf(string $ctype): string
    {
        if (preg_match('/charset="?([^";]+)"?/i', $ctype, $m)) {
            return trim($m[1]);
        }
        return 'utf-8';
    }

    private function toUtf8(string $s, string $charset): string
    {
        $charset = strtoupper(trim($charset));
        if ($charset === '' || $charset === 'UTF-8' || $charset === 'US-ASCII') {
            return $s;
        }
        $converted = @iconv($charset, 'UTF-8//TRANSLIT', $s);
        if ($converted !== false) {
            return $converted;
        }
        $converted = @mb_convert_encoding($s, 'UTF-8', $charset);
        return $converted !== false ? $converted : $s;
    }

    private function decodeHeader(string $value): string
    {
        if ($value === '') {
            return '';
        }
        // RFC 2047 encoded-words.
        $decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
        if ($decoded !== false && $decoded !== '') {
            return trim($decoded);
        }
        $decoded = @mb_decode_mimeheader($value);
        return $decoded !== '' ? trim($decoded) : trim($value);
    }

    // ── HTML / text normalization ───────────────────────────────────────────

    public function htmlToText(string $html): string
    {
        if ($html === '') {
            return '';
        }
        $html = preg_replace('#<(script|style)[^>]*>.*?</\1>#is', '', $html);
        $html = preg_replace('#<br\s*/?>#i', "\n", (string) $html);
        $html = preg_replace('#</(p|div|tr|h[1-6]|li)>#i', "\n", (string) $html);
        $text = strip_tags((string) $html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return $text;
    }

    private function normalize(string $text): string
    {
        // Curly quotes/dashes → ascii-ish so label & regex matching is stable.
        $map = [
            "\xE2\x80\x99" => "'", "\xE2\x80\x98" => "'",
            "\xE2\x80\x9C" => '"', "\xE2\x80\x9D" => '"',
            "\xE2\x80\x94" => '-', "\xE2\x80\x93" => '-',
            "\xE2\x86\x92" => '->', "\xC2\xA0" => ' ',
        ];
        $text = strtr($text, $map);
        $text = preg_replace("/[ \t]+/", ' ', $text);
        $text = preg_replace("/\n{3,}/", "\n\n", (string) $text);
        return trim((string) $text);
    }

    // ── Jotform / labelled block extraction ───────────────────────────────────

    /** @return array<string,string> canonical-key => value */
    private function extractLabels(string $text): array
    {
        $lines  = explode("\n", $text);
        $result = [];
        $current = null;
        $buffer = [];

        $flush = function () use (&$result, &$current, &$buffer) {
            if ($current !== null) {
                $val = trim(implode("\n", $buffer));
                if ($val !== '' && !isset($result[$current])) {
                    $result[$current] = $val;
                } elseif ($val !== '') {
                    $result[$current] .= "\n" . $val;
                }
            }
            $buffer = [];
        };

        foreach ($lines as $line) {
            $key = $this->matchLabel($line, $rest);
            if ($key !== null) {
                $flush();
                $current = $key;
                $buffer = $rest !== '' ? [$rest] : [];
            } elseif ($current !== null) {
                $buffer[] = $line;
            }
        }
        $flush();

        // Require at least two recognized labels to treat it as structured.
        return count($result) >= 2 ? $result : [];
    }

    /** If $line begins with a known label, return its canonical key and set $rest to any inline value. */
    private function matchLabel(string $line, ?string &$rest): ?string
    {
        $rest = '';
        if (!preg_match('/^\s*([A-Za-z][A-Za-z\' ]{1,28}):\s*(.*)$/', $line, $m)) {
            return null;
        }
        $label = strtolower(trim($m[1]));
        $label = str_replace(['’', '‘'], "'", $label);
        if (isset(self::JOTFORM_LABELS[$label])) {
            $rest = trim($m[2]);
            return self::JOTFORM_LABELS[$label];
        }
        return null;
    }

    /** @param array<string,string> $labels @return array<string,mixed> */
    private function fromLabels(array $labels): array
    {
        $out = [];

        if (isset($labels['who'])) {
            // "Brody  Bass (drunkmonkpresents@gmail.com)"
            if (preg_match('/<?([^\s<>()]+@[^\s<>()]+)>?/', $labels['who'], $m)) {
                $out['contact_email'] = strtolower($m[1]);
            }
            $name = trim(preg_replace('/[\(<].*$/', '', $labels['who']));
            $name = preg_replace('/\s+/', ' ', (string) $name);
            if ($name !== '') {
                $out['contact_name'] = $name;
            }
        }
        if (isset($labels['name']) && !isset($out['contact_name'])) {
            $out['contact_name'] = trim($labels['name']);
        }
        if (isset($labels['email']) && !isset($out['contact_email'])
            && preg_match('/[^\s<>()]+@[^\s<>()]+/', $labels['email'], $m)) {
            $out['contact_email'] = strtolower($m[0]);
        }
        if (isset($labels['phone'])) {
            $out['contact_phone'] = $this->extractPhone($labels['phone']);
        }
        if (isset($labels['vibe'])) {
            $out['event_type'] = $this->normalizeEventType($labels['vibe']);
        }
        // Event title: the "NEW Booking ALERT: <title>" headline is the name the
        // requester actually gave their event, so it wins. Many submissions
        // leave it blank, in which case fall back to the vibe/category label
        // (e.g. "Public Show / Performance") rather than the noisy subject line.
        $headline = isset($labels['headline']) ? trim($labels['headline']) : '';
        if ($headline !== '') {
            $out['event_name'] = $headline;
        } elseif (isset($labels['vibe']) && trim($labels['vibe']) !== '') {
            $out['event_name'] = trim($labels['vibe']);
        }
        if (isset($labels['date'])) {
            $out['desired_date'] = $this->coerceDate($labels['date']);
        }
        if (isset($labels['crowd'])) {
            $out['projected_attendance'] = $this->coerceInt($labels['crowd']);
        }
        if (isset($labels['vision'])) {
            $out['vision'] = trim($labels['vision']);
        }
        return array_filter($out, static fn($v) => $v !== null && $v !== '');
    }

    // ── LLM enrichment (Anthropic Messages API, raw HTTP) ─────────────────────

    /** @return array<string,mixed>|null null when the CLI is unavailable or the call failed. */
    private function enrich(string $body, string $subject, ?string $name, ?string $email): ?array
    {
        if (!$this->enabled || trim($body) === '') {
            return null;
        }

        // No JSON-Schema enforcement here — the CLI has no equivalent of
        // the Messages API's `output_config.format.json_schema` (see
        // Ai\ClaudeCli's "No API-key path" docblock note), so the shape is
        // spelled out in plain instructions and ClaudeCli::promptJson()
        // parses the reply leniently. "null" (spelled out below) still
        // means what it always meant — array_filter() below strips both
        // that and any empty string so deterministic label values aren't
        // overridden by an absent LLM field either way.
        $system = "You extract structured booking-inquiry data from emails sent to a "
            . "music & events venue (The Mab / FAME) at bookings@themab.org. Today's date is "
            . "{$this->today}.\n\n"
            . "Respond with ONLY a single JSON object — no prose, no markdown code fences, no "
            . "explanation before or after it. It must have exactly these keys, using JSON null "
            . "when a field is not stated:\n"
            . "- contact_name (string or null)\n"
            . "- contact_org (string or null)\n"
            . "- contact_phone (string or null)\n"
            . "- event_name (string or null)\n"
            . "- event_type (string or null) — one of: concert, private_event, festival, comedy_show, other\n"
            . "- band_name (string or null) — performing artist(s)/band(s), comma-separated if multiple\n"
            . "- desired_date (string or null) — YYYY-MM-DD, only if a concrete date is given\n"
            . "- desired_date_alt (string or null) — same format\n"
            . "- projected_attendance (integer or null) — headcount only\n"
            . "- is_private (boolean or null) — true only for closed/non-public events (corporate, "
            . "wedding, private party, hackathon, buyout); false for public shows\n"
            . "- alcohol_plan (string or null) — any stated alcohol arrangement (e.g. 'dry event, "
            . "no alcohol', 'cash bar')\n"
            . "- summary (string or null) — one sentence (<160 chars) describing the request\n\n"
            . "If only a month, range, or season is given for a date, leave it null (the prose is "
            . "kept in notes elsewhere) rather than guessing a day.";

        $hint = '';
        if ($name)  { $hint .= "Sender name (from headers): {$name}\n"; }
        if ($email) { $hint .= "Sender email (from headers): {$email}\n"; }
        $user = ($subject !== '' ? "Subject: {$subject}\n" : '')
            . ($hint !== '' ? $hint . "\n" : '')
            . "Email body:\n\"\"\"\n" . $body . "\n\"\"\"";

        $json = ClaudeCli::promptJson($system, $user, $this->model);
        if ($json === null) {
            return null;
        }

        // Strip empty strings/null so they don't override deterministic values.
        return array_filter($json, static fn($v) => $v !== '' && $v !== null);
    }

    // ── Heuristic fallback (no LLM) ───────────────────────────────────────────

    /** @param array<string,mixed> $lead */
    private function applyHeuristics(array &$lead, string $body): void
    {
        if (empty($lead['contact_phone'])) {
            $lead['contact_phone'] = $this->extractPhone($body);
        }
        if ($lead['projected_attendance'] === null
            && preg_match('/\b(?:about|approx(?:imately)?|around|up to|~)?\s*(\d{2,5})\s*(?:\+\s*)?(?:people|guests|attendees|heads|builders|pax|capacity)\b/i', $body, $m)) {
            $lead['projected_attendance'] = (int) $m[1];
        }
        if ($lead['is_private'] === null
            && preg_match('/\b(private|corporate|wedding|hackathon|buyout|closed[- ]door)\b/i', $body)) {
            $lead['is_private'] = 1;
        }
        if (empty($lead['alcohol_plan'])
            && preg_match('/\b(dry event|no alcohol|cash bar|open bar|hosted bar|byob)\b/i', $body, $m)) {
            $lead['alcohol_plan'] = $m[1];
        }
        if (empty($lead['summary'])) {
            // Use the first substantive sentence — skip greetings / form banners.
            foreach (preg_split('/\n+/', $body) as $line) {
                $line = trim($line);
                if ($line === '' || mb_strlen($line) < 15) {
                    continue;
                }
                if (preg_match('/^(hi|hello|hey|dear|greetings|new booking)\b/i', $line)) {
                    continue;
                }
                $lead['summary'] = mb_substr($line, 0, 160);
                break;
            }
        }
    }

    // ── Field normalizers ─────────────────────────────────────────────────────

    private function extractPhone(string $s): ?string
    {
        if (preg_match('/(\+?\d[\d\-\.\s\(\)]{7,}\d)/', $s, $m)) {
            $digits = preg_replace('/[^\d+]/', '', $m[1]);
            return strlen((string) $digits) >= 9 ? trim($m[1]) : null;
        }
        return null;
    }

    private function normalizeEventType(?string $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (in_array($raw, self::EVENT_TYPES, true)) {
            return $raw;
        }
        $r = strtolower($raw);
        return match (true) {
            str_contains($r, 'festival')                               => 'festival',
            str_contains($r, 'private') || str_contains($r, 'corporate')
                || str_contains($r, 'wedding') || str_contains($r, 'hackathon') => 'private_event',
            // Music/performance wins over the bare word "comedy" because the
            // intake form's umbrella option lists "Live Music, Comedy, Theater"
            // together — treat that catch-all as a concert/show.
            str_contains($r, 'music') || str_contains($r, 'show')
                || str_contains($r, 'concert') || str_contains($r, 'performance')
                || str_contains($r, 'live') || str_contains($r, 'band')   => 'concert',
            str_contains($r, 'comedy')                                 => 'comedy_show',
            default                                                    => 'other',
        };
    }

    private function coerceDate(mixed $v): ?string
    {
        if (!is_string($v) || trim($v) === '') {
            return null;
        }
        $v = trim($v);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            return $v;
        }
        // US-style numeric dates the intake forms emit — MM-DD-YYYY or
        // MM/DD/YYYY (single-digit month/day allowed). strtotime mishandles the
        // dashed variant, so parse it explicitly. If the first field can't be a
        // month (>12) but the second can, treat it as day-first instead.
        if (preg_match('#^(\d{1,2})[/-](\d{1,2})[/-](\d{4})$#', $v, $m)) {
            [$first, $second, $year] = [(int) $m[1], (int) $m[2], (int) $m[3]];
            [$month, $day] = ($first > 12 && $second <= 12) ? [$second, $first] : [$first, $second];
            return checkdate($month, $day, $year)
                ? sprintf('%04d-%02d-%02d', $year, $month, $day)
                : null;
        }
        $ts = strtotime($v);
        // Reject vague strings strtotime would happily (mis)interpret.
        if ($ts === false || !preg_match('/\d/', $v)) {
            return null;
        }
        return date('Y-m-d', $ts);
    }

    private function coerceInt(mixed $v): ?int
    {
        if (is_int($v)) {
            return $v > 0 ? $v : null;
        }
        if (is_string($v) && preg_match('/\d{1,6}/', $v, $m)) {
            $n = (int) $m[0];
            return $n > 0 ? $n : null;
        }
        return null;
    }

    private function parseDate(string $header): ?string
    {
        if ($header === '') {
            return null;
        }
        $ts = strtotime($header);
        return $ts !== false ? date('Y-m-d H:i:s', $ts) : null;
    }

    /** @return array{0:string,1:string} [name, email] */
    private function parseAddress(string $value): array
    {
        $value = $this->decodeHeader($value);
        if ($value === '') {
            return ['', ''];
        }
        if (preg_match('/^\s*"?([^"<]*?)"?\s*<([^>]+)>/', $value, $m)) {
            return [trim($m[1]), strtolower(trim($m[2]))];
        }
        if (preg_match('/[^\s<>]+@[^\s<>]+/', $value, $m)) {
            return ['', strtolower($m[0])];
        }
        return [trim($value), ''];
    }

    /** Choose the most likely human requester address, skipping forwarders/noreply. */
    private function preferredEmail(string $replyTo, string $from, string $body): string
    {
        $isJunk = static fn(string $e): bool =>
            $e === '' ||
            (bool) preg_match('/(noreply|no-reply|caf_|notification|mailer-daemon)/i', $e);

        foreach ([$replyTo, $from] as $cand) {
            if (!$isJunk($cand)) {
                return $cand;
            }
        }
        // Last resort: first address in the body that isn't the venue itself.
        if (preg_match_all('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', $body, $m)) {
            foreach ($m[0] as $e) {
                if (stripos($e, 'themab.org') === false && !$isJunk($e)) {
                    return strtolower($e);
                }
            }
        }
        return $replyTo ?: $from;
    }

    private function buildNotes(array $lead, string $body, ?string $vision): string
    {
        $parts = [];
        if (!empty($lead['summary'])) {
            $parts[] = $lead['summary'];
            $parts[] = '';
        }
        $parts[] = '--- Original request ---';
        $parts[] = trim($body) !== '' ? trim($body) : trim((string) $vision);
        return trim(implode("\n", $parts));
    }
}
