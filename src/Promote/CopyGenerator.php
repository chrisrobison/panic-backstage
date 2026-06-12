<?php
declare(strict_types=1);

namespace Panic\Promote;

/**
 * Deterministic copy generator for post variants.
 *
 * No external API calls. Uses event + post data to produce
 * channel-appropriate text with character-count warnings.
 */
final class CopyGenerator
{
    private const CHANNELS = [
        'instagram',
        'facebook',
        'tiktok',
        'email',
        'eventbrite',
        'luma',
        'funcheap',
        'foopee',
        'press',
    ];

    /** Generate variants for all 9 channels. */
    public function generate(array $post, array $event): array
    {
        $variants = [];
        foreach (self::CHANNELS as $channel) {
            $variants[] = $this->generateChannel($channel, $post, $event);
        }
        return $variants;
    }

    private function generateChannel(string $channel, array $post, array $event): array
    {
        $title       = (string) ($post['title'] ?? '');
        $masterText  = (string) ($post['master_text'] ?? '');
        $targetUrl   = (string) ($post['target_url'] ?? '');
        $eventTitle  = (string) ($event['title'] ?? $title);
        $eventDate   = (string) ($event['date'] ?? '');
        $doorsTime   = (string) ($event['doors_time'] ?? '');
        $showTime    = (string) ($event['show_time'] ?? '');
        $ageRestr    = (string) ($event['age_restriction'] ?? '');
        $venue       = (string) ($event['venue_name'] ?? 'Mabuhay Gardens');
        $ticketUrl   = $targetUrl ?: (string) ($event['ticket_url'] ?? '');

        $dateFormatted  = $eventDate ? date('l, F j, Y', strtotime($eventDate)) : '';
        $dateShort      = $eventDate ? date('M j', strtotime($eventDate)) : '';
        $ageText        = $ageRestr ? " · $ageRestr" : '';
        $doorsText      = $doorsTime ? ' Doors ' . date('g:ia', strtotime((string) $doorsTime)) : '';
        $showText       = $showTime  ? ' · Show ' . date('g:ia', strtotime((string) $showTime)) : '';
        $ticketLine     = $ticketUrl ? "\nTickets: $ticketUrl" : '';
        $shortDesc      = $masterText !== '' ? $masterText : $eventTitle;

        return match ($channel) {
            'instagram' => [
                'channel'  => 'instagram',
                'title'    => null,
                'body'     => $this->trimTo(
                    "📣 $eventTitle\n\n" .
                    "$shortDesc\n\n" .
                    "$dateFormatted @ $venue$doorsText$showText$ageText\n\n" .
                    ($ticketUrl ? "Link in bio for tickets 🎟️\n\n" : '') .
                    "#MabuhayGardens #LiveMusic #SFMusic #BayAreaMusic",
                    2200
                ),
                'warnings' => [
                    'Instagram captions do not make links clickable — direct followers to link in bio.',
                    'Character limit: 2,200',
                ],
            ],

            'facebook' => [
                'channel'  => 'facebook',
                'title'    => null,
                'body'     => $this->trimTo(
                    "🎵 $eventTitle\n\n" .
                    "$shortDesc\n\n" .
                    "📅 $dateFormatted\n" .
                    "📍 $venue$doorsText$showText\n" .
                    ($ageText ? "🔞 $ageRestr\n" : '') .
                    $ticketLine,
                    63206
                ),
                'warnings' => [
                    'Facebook posts over 477 characters are truncated — keep key info above the fold.',
                ],
            ],

            'tiktok' => [
                'channel'  => 'tiktok',
                'title'    => null,
                'body'     => $this->trimTo(
                    "$eventTitle 🎶 $dateShort @ $venue" .
                    ($ticketUrl ? " 🎟️ Link in bio" : '') .
                    " #LiveMusic #MabuhayGardens #SFShows",
                    2200
                ),
                'warnings' => [
                    'TikTok performs best with short punchy captions under 150 characters.',
                    'Pair with vertical video (9:16) for best reach.',
                    'Links in captions are not clickable — use link in bio.',
                ],
            ],

            'email' => [
                'channel'  => 'email',
                'title'    => "Upcoming: $eventTitle — $dateShort",
                'body'     => $this->trimTo(
                    "Hi,\n\n" .
                    "We're excited to announce: $eventTitle\n\n" .
                    "$shortDesc\n\n" .
                    "When: $dateFormatted$doorsText$showText\n" .
                    "Where: $venue\n" .
                    ($ageText ? "Age: $ageRestr\n" : '') .
                    $ticketLine . "\n\n" .
                    "See you there!\n" .
                    "— The Mabuhay Gardens Team",
                    0  // no hard limit for email
                ),
                'warnings' => [
                    'Review subject line for deliverability — avoid spam trigger words.',
                    'Personalize the greeting if your email platform supports merge fields.',
                ],
            ],

            'eventbrite' => [
                'channel'  => 'eventbrite',
                'title'    => $eventTitle,
                'body'     => $this->trimTo(
                    "$shortDesc\n\n" .
                    "Date: $dateFormatted\n" .
                    "Venue: $venue$doorsText$showText\n" .
                    ($ageText ? "Age restriction: $ageRestr\n" : '') .
                    $ticketLine,
                    0
                ),
                'warnings' => [
                    'Eventbrite listing requires manual submission — check destination status.',
                    'Ensure the Eventbrite URL matches your ticket link.',
                ],
            ],

            'luma' => [
                'channel'  => 'luma',
                'title'    => $eventTitle,
                'body'     => $this->trimTo(
                    "$shortDesc\n\n" .
                    "$dateFormatted @ $venue$doorsText$showText\n" .
                    ($ageText ? "$ageRestr\n" : '') .
                    $ticketLine,
                    0
                ),
                'warnings' => [
                    'Luma listing requires manual submission — check destination status.',
                ],
            ],

            'funcheap' => [
                'channel'  => 'funcheap',
                'title'    => $eventTitle,
                'body'     => $this->trimTo(
                    "$eventTitle\n" .
                    "$dateFormatted\n" .
                    "$venue$doorsText$showText\n" .
                    ($ageText ? "$ageRestr\n" : '') .
                    $ticketLine . "\n\n" .
                    $shortDesc,
                    500
                ),
                'warnings' => [
                    'Funcheap requires manual submission via their web form.',
                    'Keep description under 500 characters for best results.',
                ],
            ],

            'foopee' => [
                'channel'  => 'foopee',
                'title'    => $eventTitle,
                'body'     => $this->trimTo(
                    "$eventTitle\n" .
                    "$dateFormatted · $venue$doorsText$showText\n" .
                    ($ageText ? "$ageRestr · " : '') .
                    "Bay Area shows\n" .
                    $ticketLine . "\n\n" .
                    $shortDesc,
                    500
                ),
                'warnings' => [
                    'Foopee requires manual submission via their web form.',
                    'Keep description concise for best calendar listing appearance.',
                ],
            ],

            'press' => [
                'channel'  => 'press',
                'title'    => "Press inquiry: $eventTitle — $dateShort at $venue",
                'body'     => $this->trimTo(
                    "FOR IMMEDIATE RELEASE\n\n" .
                    "$eventTitle\n" .
                    "$dateFormatted at $venue$doorsText$showText\n" .
                    ($ageText ? "$ageRestr\n" : '') .
                    "\n" .
                    "$shortDesc\n" .
                    $ticketLine . "\n\n" .
                    "For press inquiries, please reply to this email.",
                    0
                ),
                'warnings' => [
                    'Add a press contact email before sending.',
                    'Include a hi-res image attachment when distributing.',
                    'Personalize for each press outlet when possible.',
                ],
            ],

            default => [
                'channel'  => $channel,
                'title'    => null,
                'body'     => $shortDesc,
                'warnings' => [],
            ],
        };
    }

    private function trimTo(string $text, int $maxLength): string
    {
        if ($maxLength <= 0 || mb_strlen($text) <= $maxLength) {
            return $text;
        }
        return mb_substr($text, 0, $maxLength - 3) . '...';
    }
}
