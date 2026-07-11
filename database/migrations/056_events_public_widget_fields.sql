-- 056_events_public_widget_fields.sql
--
-- Backing fields for the embeddable "upcoming events" web component
-- (public/assets/mab-events-carousel.js) that themab.org drops in as a
-- drop-in replacement for its static events carousel markup, and for the
-- new GET /api/feed/events.json widget feed (src/Feed.php) that powers it.
--
-- The existing `events` table already covers date/time/venue/ticketing, but
-- the public marketing copy shown on themab.org needs three things it has
-- no home for yet:
--
--   public_subtitle          short line under the date badge — either a
--                             support-act/lineup line ("STEVE LUCKY & THE
--                             RHUMBA BUMS") or a doors/show-time line
--                             ("DOORS 6PM // SHOW 7PM"), exactly as themab.org
--                             shows it under <div class="mab-date-block">.
--
--   public_tags               comma-separated slugs used for the carousel's
--                             category filter pills (e.g. "live-music,dance").
--                             Free-form on purpose — event_type is an
--                             operational enum (live_music/dj_night/...) and
--                             doesn't line up 1:1 with the site's marketing
--                             categories (a Cat's Corner swing night is both
--                             "dance" and "live-music").
--
--   public_schedule_pricing   optional structured detail rendered inside a
--                             collapsible <details>/<summary> on the card —
--                             only the recurring Cat's Corner series uses
--                             this today. Stored as JSON
--                             ({"sections":[{"heading":"MAIN BALLROOM",
--                             "lines":["7-8 PM: ..."]}, ...]}) rather than
--                             raw HTML so the widget can render it safely
--                             (escape text, control markup) instead of
--                             injecting admin-entered HTML into a public page.

ALTER TABLE events
  ADD COLUMN IF NOT EXISTS public_subtitle VARCHAR(255) NULL DEFAULT NULL AFTER description_public,
  ADD COLUMN IF NOT EXISTS public_tags VARCHAR(255) NULL DEFAULT NULL AFTER public_subtitle,
  ADD COLUMN IF NOT EXISTS public_schedule_pricing TEXT NULL DEFAULT NULL AFTER public_tags;
