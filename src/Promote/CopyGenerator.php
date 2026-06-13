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
        'sf_chronicle',
        'sf_station',
        'dothebay',
        'songkick',
        'jambase',
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
                    'Ensure your Eventbrite Organizer is configured in Promote Settings before broadcasting.',
                    'Eventbrite publishes the listing immediately — review the event page after broadcast.',
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
                    'Luma publishes the event immediately on create — review the listing after broadcast.',
                    'Cover image must be hosted on the Luma CDN; upload one manually in the Luma dashboard after creation.',
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

            'sf_chronicle' => [
                'channel'  => 'sf_chronicle',
                'title'    => "FOR IMMEDIATE RELEASE: $eventTitle — $dateShort at $venue",
                'body'     => $this->trimTo(
                    "FOR IMMEDIATE RELEASE\n\n" .
                    "$eventTitle\n" .
                    "$dateFormatted at $venue$doorsText$showText\n" .
                    ($ageText ? "$ageRestr\n" : '') .
                    "\n" .
                    "$shortDesc\n" .
                    $ticketLine . "\n\n" .
                    "Media contact: press@mabuhaygardens.com",
                    0
                ),
                'warnings' => [
                    'SF Chronicle Datebook accepts email pitches — personalize the opening paragraph.',
                    'Attach a hi-res 1200×675 flyer image to the email.',
                    'Send at least 2–3 weeks before the show date for calendar consideration.',
                ],
            ],

            'sf_station' => [
                'channel'  => 'sf_station',
                'title'    => $eventTitle,
                'body'     => $this->trimTo(
                    "$eventTitle\n" .
                    "$dateFormatted · $venue\n" .
                    "$doorsText$showText\n" .
                    ($ageText ? "$ageRestr\n" : '') .
                    "\n" .
                    $shortDesc .
                    $ticketLine,
                    400
                ),
                'warnings' => [
                    'SF Station requires manual submission via sfstation.com/submit.',
                    'Keep the description under 400 characters.',
                    'A flyer image (JPG/PNG) improves listing visibility.',
                ],
            ],

            'dothebay' => [
                'channel'  => 'dothebay',
                'title'    => $eventTitle,
                'body'     => $this->trimTo(
                    "$eventTitle\n" .
                    "$dateFormatted · $venue$doorsText$showText\n" .
                    ($ageText ? "$ageRestr · " : '') .
                    "Bay Area live music\n\n" .
                    $shortDesc .
                    $ticketLine,
                    500
                ),
                'warnings' => [
                    'DoTheBay requires manual submission at dothebay.com/submit-event.',
                    'Select the "Music" category for best placement.',
                ],
            ],

            'songkick' => [
                'channel'  => 'songkick',
                'title'    => $eventTitle,
                'body'     => $this->trimTo(
                    "$eventTitle\n" .
                    "$dateFormatted\n" .
                    "$venue — San Francisco, CA$doorsText$showText\n" .
                    ($ageText ? "$ageRestr\n" : '') .
                    "\n" .
                    $shortDesc .
                    $ticketLine,
                    500
                ),
                'warnings' => [
                    'SongKick listings are artist-managed — ensure the artist has claimed their page.',
                    'Submit through the artist\'s SongKick manager dashboard.',
                ],
            ],

            'jambase' => [
                'channel'  => 'jambase',
                'title'    => $eventTitle,
                'body'     => $this->trimTo(
                    "$eventTitle\n" .
                    "$dateFormatted · $venue · San Francisco, CA\n" .
                    "$doorsText$showText\n" .
                    ($ageText ? "$ageRestr\n" : '') .
                    "\n" .
                    $shortDesc .
                    $ticketLine,
                    500
                ),
                'warnings' => [
                    'JamBase listings require a registered artist or venue account.',
                    'Submit at jambase.com/submit or through the artist manager portal.',
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
