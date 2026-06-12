You are working in the attached Panic Backstage repository. Build a new event marketing/promotions module called **Panic Promote**.

Panic Promote is a campaign command center for promoting live shows and events at Mabuhay Gardens. It should let staff turn an existing event into a structured promotion campaign with posts, assets, channel-specific variants, broadcast destinations, manual listing submissions, email recipient groups, promotion-health checklists, and broadcast history.

Follow the existing architecture and development style in this repo.

## Non-negotiable architecture constraints

* No React, Vue, Svelte, Lit, Angular, or frontend framework.
* No npm, no bundler, no frontend build process.
* Minimize external dependencies.
* No Composer runtime dependencies.
* PHP must be API-first and emit JSON only for this module.
* Static HTML pages and native Web Components should consume JSON APIs with `fetch()`.
* Use the existing `api()` helper pattern.
* Use the existing LARC/PAN communication style through `publish(topic, payload)` and `subscribe(topic, handler, signal)`.
* Keep components loosely coupled. Components should communicate through topic events, not by directly reaching into each other.
* Preserve the current login/auth/JWT/capability model.
* Preserve the current `Kernel.php` endpoint routing style.
* Preserve the existing `BaseEndpoint`, `Request`, `Response`, `Database`, and `Auth` patterns.
* Keep the feature boring to run: PHP built-in server + MySQL + static files.
* Do not introduce OAuth integrations yet. For real third-party platforms, create adapter/stub layers and UI statuses such as `connected`, `needs_auth`, `manual_submission`, `ready`, `failed`.
* Do not hardcode secrets.
* Do not break existing tests or existing event functionality.

## Current repo assumptions to respect

The app already has:

* `public/index.html` as the main staff shell.
* `public/assets/app.js`, `core.js`, `events.js`, `admin.js`, etc.
* `public/assets/app.css` for styling.
* `src/Kernel.php` for constrained route resolution.
* `src/BaseEndpoint.php` for shared endpoint behavior and capability checks.
* `src/Events.php` and `src/Events/*` for event subresources.
* `database/migrations/*` for migrations.
* `database/schema.sql` as the root/current schema copy.
* API endpoints under `/api`.
* Event assets under `/api/events/{id}/assets`.
* Event tasks/open items that can be reused for promotion-health tasks.
* Static Web Components registered from browser JS files.
* LARC/PAN style event topics via `publish()` and `subscribe()`.

## Product goal

Add a new **Marketing / Panic Promote** workspace where a venue admin or event owner can:

1. Open an existing event.
2. Create or view the event’s promotion campaign.
3. Create reusable marketing posts.
4. Generate or manually write channel-specific post variants.
5. Select destinations for a broadcast.
6. Track direct posts, event-platform listings, editorial submissions, email recipient groups, and manual tasks.
7. See “Promotion Health” for the campaign.
8. Track broadcast history and per-destination status.

## Core terminology

### Campaign

A campaign belongs to one event.

A campaign should reuse event fields where possible:

* event title
* date
* doors/show time
* venue
* room/location if available
* age restriction
* ticket URL
* public Panic page URL
* capacity
* public description

Do not duplicate event data unless the campaign needs override fields.

### Post

A post belongs to a campaign.

A post has:

* title
* master text
* selected asset/image
* target link
* status: `draft`, `approved`, `scheduled`, `sent`, `archived`
* optional scheduled datetime
* created/updated metadata

### Post Variant

A post variant belongs to a post and represents a platform-specific version.

Examples:

* Instagram caption
* Facebook post
* TikTok caption
* email body
* Funcheap listing copy
* Foopee listing copy
* press blurb
* Eventbrite/Luma description

Each variant has:

* channel
* title/subject if applicable
* body text
* selected asset variant
* character count/warnings
* status: `draft`, `ready`, `needs_review`, `approved`

### Destination

A destination is a place a post/listing can be sent or tracked.

Group destinations into:

1. Direct Posts

   * Facebook Page
   * Instagram
   * TikTok

2. Event Platforms

   * Eventbrite
   * Luma
   * Bandsintown

3. Editorial Submissions

   * Funcheap
   * Foopee
   * Press List

4. Email Recipients

   * General list
   * Press list
   * manually entered emails

For MVP, do not perform actual third-party API posting. Create a clean adapter interface and stub/mock implementations that record intended actions and statuses.

### Broadcast

A broadcast is an attempt to send or queue one post to one or more destinations.

A broadcast has:

* campaign id
* post id
* user id
* send mode: `now` or `scheduled`
* scheduled datetime
* status: `draft`, `queued`, `processing`, `completed`, `partial_failure`, `failed`
* created/updated metadata

### Broadcast Result

A result tracks one destination within a broadcast.

Fields:

* broadcast id
* destination key
* destination group
* status: `queued`, `sent`, `manual_required`, `needs_auth`, `failed`, `skipped`
* external URL if available
* error message if any
* response JSON / metadata JSON
* created/updated metadata

### Promotion Health

Promotion Health is a checklist/status summary for a campaign.

Example checklist items:

* Panic event page published
* approved flyer exists
* Instagram announcement post approved
* Facebook event/promo post approved
* Eventbrite listing prepared
* Luma listing prepared
* Funcheap submitted
* Foopee submitted
* press email prepared
* email blast scheduled
* day-before reminder scheduled
* band assets collected

It should return a JSON summary:

```json
{
  "score": 42,
  "complete": 5,
  "total": 12,
  "items": [
    {
      "key": "panic_page_published",
      "label": "Panic event page published",
      "status": "done",
      "severity": "success",
      "detail": "Public page is live"
    }
  ]
}
```

## UI requirements

Add a **Panic Promote / Marketing** navigation entry to the staff app.

The UI should include:

### Campaigns List

Route/hash suggestion:

```text
#promote
```

Shows marketing campaign cards or rows for upcoming events.

Each row/card should show:

* event title
* event date
* status
* tickets sold / goal if available
* days out
* promotion-health score
* primary missing item
* button/link to open campaign

### Campaign Overview

Route/hash suggestion:

```text
#promote-event-{eventId}
```

Main sections:

1. Campaign hero

   * flyer thumbnail if available
   * event title
   * venue/date/doors/show
   * age restriction
   * ticket/Panic page link
   * goal/sold/days-out metrics

2. Promotion Health panel

   * checklist with green/yellow/red statuses
   * score percentage
   * “View full checklist” or expandable detail

3. Posts section

   * list of post cards
   * statuses: Draft, Approved, Scheduled, Sent
   * buttons: Edit, Preview, Queue/Broadcast

4. Assets panel

   * existing approved event assets
   * aspect-ratio placeholders for 1:1, 4:5, 9:16, 16:9
   * MVP may not actually resize images; show placeholders and metadata

5. Analytics panel

   * stub metrics for now:

     * website clicks
     * RSVPs
     * ticket conversions
     * email opens
   * return zeros/stub values from API until real analytics exists

6. Broadcast modal

   * opens from a `Broadcast` button
   * allows selecting destinations grouped as:

     * Direct Posts
     * Event Platforms
     * Editorial Submissions
     * Email Recipients
   * show per-destination readiness:

     * Connected
     * Needs auth
     * Manual submission
     * Ready
     * Needs content
   * allow `Post now` or `Schedule for`
   * button: `Send Broadcast`
   * after submit, record broadcast + results through the API
   * publish LARC/PAN topics so the overview refreshes without full reload

### Post Editor Modal

Create/edit a post with:

* title
* master text
* target URL
* selected event asset
* status
* variant editor tabs/sections:

  * Instagram
  * Facebook
  * TikTok
  * Email
  * Eventbrite
  * Luma
  * Funcheap
  * Foopee
  * Press

For MVP, generation can be a deterministic helper, not an AI API call.

Example helper:

* Short social caption
* Long listing copy
* Email subject/body
* Press blurb

Do not call external AI APIs.

## Suggested files to add

Backend:

```text
src/Promote.php
src/Promote/
  Campaigns.php
  Posts.php
  PostVariants.php
  Broadcasts.php
  Destinations.php
  PromotionHealth.php
  Analytics.php
  CopyGenerator.php
  BroadcastAdapters.php
```

Frontend:

```text
public/assets/promote.js
```

Database:

```text
database/migrations/024_panic_promote.sql
```

Update:

```text
database/schema.sql
src/Kernel.php
public/assets/app.js
public/assets/app.css
public/index.html if needed
README.md
scripts/endpoint-smoke.php or a new scripts/promote-smoke.php
tests/*
```

Use the repo’s actual style after inspecting it. If existing naming conventions differ, follow the repo.

## API design

Add routes in `Kernel.php` using the existing constrained resolver style.

Suggested endpoints:

```text
GET    /api/promote/campaigns
POST   /api/promote/campaigns
GET    /api/promote/campaigns/{campaignId}
PATCH  /api/promote/campaigns/{campaignId}

GET    /api/promote/events/{eventId}
POST   /api/promote/events/{eventId}/campaign

GET    /api/promote/campaigns/{campaignId}/posts
POST   /api/promote/campaigns/{campaignId}/posts
GET    /api/promote/campaigns/{campaignId}/posts/{postId}
PATCH  /api/promote/campaigns/{campaignId}/posts/{postId}
DELETE /api/promote/campaigns/{campaignId}/posts/{postId}

POST   /api/promote/campaigns/{campaignId}/posts/{postId}/variants/generate
PATCH  /api/promote/campaigns/{campaignId}/posts/{postId}/variants/{variantId}

GET    /api/promote/campaigns/{campaignId}/destinations
GET    /api/promote/campaigns/{campaignId}/health
GET    /api/promote/campaigns/{campaignId}/analytics

GET    /api/promote/campaigns/{campaignId}/broadcasts
POST   /api/promote/campaigns/{campaignId}/broadcasts
GET    /api/promote/campaigns/{campaignId}/broadcasts/{broadcastId}
```

Alternative route shape is acceptable if it fits the existing Kernel better, but keep it clean and predictable.

## Database schema proposal

Create migration `024_panic_promote.sql`.

Add tables similar to:

```sql
CREATE TABLE IF NOT EXISTS promote_campaigns (
  id INT AUTO_INCREMENT PRIMARY KEY,
  event_id INT NOT NULL UNIQUE,
  title VARCHAR(255) NOT NULL,
  status ENUM('draft','active','paused','completed','archived') NOT NULL DEFAULT 'draft',
  goal_tickets INT NULL,
  notes TEXT NULL,
  created_by_user_id INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS promote_posts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  campaign_id INT NOT NULL,
  asset_id INT NULL,
  title VARCHAR(255) NOT NULL,
  master_text TEXT NULL,
  target_url VARCHAR(500) NULL,
  status ENUM('draft','approved','scheduled','sent','archived') NOT NULL DEFAULT 'draft',
  scheduled_at DATETIME NULL,
  created_by_user_id INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (campaign_id) REFERENCES promote_campaigns(id) ON DELETE CASCADE,
  FOREIGN KEY (asset_id) REFERENCES event_assets(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS promote_post_variants (
  id INT AUTO_INCREMENT PRIMARY KEY,
  post_id INT NOT NULL,
  channel VARCHAR(80) NOT NULL,
  title VARCHAR(255) NULL,
  body TEXT NULL,
  status ENUM('draft','ready','needs_review','approved') NOT NULL DEFAULT 'draft',
  warnings_json JSON NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_post_channel (post_id, channel),
  FOREIGN KEY (post_id) REFERENCES promote_posts(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS promote_destinations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  destination_key VARCHAR(80) NOT NULL UNIQUE,
  destination_group ENUM('direct_post','event_platform','editorial_submission','email') NOT NULL,
  label VARCHAR(120) NOT NULL,
  status ENUM('connected','needs_auth','manual_submission','disabled') NOT NULL DEFAULT 'manual_submission',
  config_json JSON NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS promote_broadcasts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  campaign_id INT NOT NULL,
  post_id INT NOT NULL,
  created_by_user_id INT NULL,
  send_mode ENUM('now','scheduled') NOT NULL DEFAULT 'now',
  scheduled_at DATETIME NULL,
  status ENUM('draft','queued','processing','completed','partial_failure','failed') NOT NULL DEFAULT 'queued',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (campaign_id) REFERENCES promote_campaigns(id) ON DELETE CASCADE,
  FOREIGN KEY (post_id) REFERENCES promote_posts(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS promote_broadcast_results (
  id INT AUTO_INCREMENT PRIMARY KEY,
  broadcast_id INT NOT NULL,
  destination_key VARCHAR(80) NOT NULL,
  destination_group VARCHAR(80) NOT NULL,
  status ENUM('queued','sent','manual_required','needs_auth','failed','skipped') NOT NULL DEFAULT 'queued',
  external_url VARCHAR(500) NULL,
  error_message TEXT NULL,
  response_json JSON NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (broadcast_id) REFERENCES promote_broadcasts(id) ON DELETE CASCADE
);
```

Seed default destinations:

```sql
INSERT INTO promote_destinations (destination_key, destination_group, label, status) VALUES
('facebook_page', 'direct_post', 'Facebook Page', 'needs_auth'),
('instagram', 'direct_post', 'Instagram', 'needs_auth'),
('tiktok', 'direct_post', 'TikTok', 'needs_auth'),
('eventbrite', 'event_platform', 'Eventbrite', 'needs_auth'),
('luma', 'event_platform', 'Luma', 'needs_auth'),
('bandsintown', 'event_platform', 'Bandsintown', 'manual_submission'),
('funcheap', 'editorial_submission', 'Funcheap', 'manual_submission'),
('foopee', 'editorial_submission', 'Foopee', 'manual_submission'),
('press_list', 'editorial_submission', 'Press List', 'manual_submission'),
('email_general', 'email', 'General Email List', 'connected'),
('email_press', 'email', 'Press Email List', 'connected')
ON DUPLICATE KEY UPDATE label = VALUES(label);
```

Adjust SQL if the project uses MariaDB-compatible JSON behavior.

## Backend behavior

### Campaign creation

When opening a Promote campaign for an event:

* If no campaign exists, allow creating one from the event.
* Default title should match event title.
* Default goal_tickets should use event capacity if available, otherwise null.
* Return campaign + event + assets + posts + health + destinations + analytics in a single useful payload for the overview page.

### Posts

Implement CRUD.

Validation:

* campaign must exist
* user must have access to the linked event
* title required
* status must be one of allowed enum values
* asset must belong to same event as campaign

### Variants

When generating variants, use deterministic local text generation.

Given event/post data, create/update variants for:

```text
instagram
facebook
tiktok
email
eventbrite
luma
funcheap
foopee
press
```

Example rules:

* Instagram: shorter caption, hashtags, reminder that link may be in bio.
* Facebook: slightly longer copy with ticket link.
* TikTok: short punchy caption.
* Email: subject + body.
* Eventbrite/Luma: clear listing copy.
* Funcheap/Foopee: concise calendar listing copy.
* Press: short pitch angle.

Include warnings, e.g.:

* Instagram captions do not make links clickable.
* TikTok works better with vertical video.
* Funcheap/Foopee may require manual submission.
* Press variant needs a contact email.

### Broadcasts

For MVP:

* Record the broadcast.
* For each selected destination:

  * If destination status is `needs_auth`, create result `needs_auth`.
  * If destination status is `manual_submission`, create result `manual_required`.
  * If destination status is `connected`, create result `sent` or `queued` depending on send mode.
* Do not call external APIs.
* Return broadcast and result objects.
* Log event activity using existing activity log helper if appropriate.

### Promotion Health

Create a service that computes health from:

* event public visibility
* approved flyer/assets
* number of posts
* approved variants
* broadcast results
* missing editorial submissions
* missing email
* missing scheduled reminder

This does not need to be perfect. It needs to be useful and deterministic.

## Frontend behavior

Add `public/assets/promote.js`.

Use native Web Components extending the existing base element style if available.

Suggested components:

```text
<pb-promote-page>
<pb-promote-campaign-list>
<pb-promote-campaign-overview>
<pb-promote-health-card>
<pb-promote-post-list>
<pb-promote-post-editor>
<pb-promote-broadcast-modal>
<pb-promote-assets-card>
<pb-promote-analytics-card>
```

Use topic events:

```text
promote.campaign.loaded
promote.campaign.changed
promote.post.created
promote.post.updated
promote.post.deleted
promote.variants.generated
promote.broadcast.open
promote.broadcast.created
promote.health.changed
toast.show
api.error
```

Avoid tight coupling. For example:

* Post list publishes `promote.broadcast.open` with campaign/post ids.
* Broadcast modal listens and opens itself.
* On successful broadcast, modal publishes `promote.broadcast.created`.
* Campaign overview reloads just the relevant data.

Update the shell navigation in `app.js`:

* Add `Panic Promote` or `Marketing` nav item.
* Route `#promote` to campaign list.
* Route `#promote-event-{id}` to overview.

Follow the existing hash routing style. Do not introduce a router library.

## Styling

Update `public/assets/app.css`.

Use the existing Panic Backstage visual language, but the module may have a “promote/promotions” flavor:

* cards
* status badges
* health checklist
* destination rows
* modal dialog
* asset thumbnails
* small metric tiles

Do not add a CSS framework.

Use responsive layouts compatible with existing desktop/mobile patterns.

## Agent orchestration requirement

Break this implementation into manageable tasks and coordinate them like an orchestrator.

If your environment supports spawning subagents, spawn these agents and assign them work:

### Agent 1 — Repo Cartographer

Tasks:

* Inspect existing repo patterns.
* Identify how `Kernel.php`, endpoint classes, migrations, JS components, and CSS are structured.
* Produce a short implementation map.
* Do not edit files.

### Agent 2 — Database/API Architect

Tasks:

* Create migration `024_panic_promote.sql`.
* Update `database/schema.sql`.
* Implement backend endpoint classes and service classes.
* Update `Kernel.php`.
* Ensure auth/capability checks mirror existing event access patterns.
* Use PDO prepared statements only.

### Agent 3 — Frontend Web Components

Tasks:

* Create `public/assets/promote.js`.
* Add Web Components for list, overview, posts, editor modal, broadcast modal, health card.
* Use existing `api()`, `publish()`, `subscribe()`, `esc()`, `assetUrl()`, etc.
* Update app shell navigation and routing.
* Avoid framework patterns.

### Agent 4 — UI/CSS Polish

Tasks:

* Add CSS for Promote components.
* Match existing app style.
* Keep mobile usable.
* Add empty/loading/error states.

### Agent 5 — Tests/Smoke

Tasks:

* Add or update shell tests/API tests.
* Add `scripts/promote-smoke.php` or extend existing smoke script.
* Verify:

  * unauthenticated access blocked
  * admin can create campaign
  * admin can create post
  * variants generate
  * broadcast creates results
  * viewer cannot mutate campaign
  * existing tests still pass

### Agent 6 — Documentation

Tasks:

* Update README with Panic Promote section.
* Document endpoints.
* Document local smoke test.
* Mention that platform integrations are stubs in MVP.

If subagents are not actually available, simulate the agent process by completing each phase sequentially and keeping clear notes.

## Implementation order

Work in this order:

1. Inspect repo and summarize exact patterns.
2. Design route map and data model.
3. Add migration and schema updates.
4. Add backend endpoints/services.
5. Add API smoke coverage.
6. Add frontend route and nav item.
7. Add Web Components.
8. Add modal workflows.
9. Add CSS.
10. Run tests/smoke checks.
11. Fix regressions.
12. Update README.
13. Provide final implementation summary.

## Acceptance criteria

The feature is complete when:

* Existing app still runs with PHP built-in server.
* Existing login/auth still works.
* New `Panic Promote` nav item appears.
* `#promote` shows upcoming events/campaigns.
* Opening an event campaign works.
* Campaign can be created for an event.
* Campaign overview loads event details, assets, posts, health, destinations, analytics.
* A post can be created, edited, approved, and deleted.
* Variants can be generated locally without external APIs.
* Broadcast modal can select destinations and create broadcast results.
* Manual destinations show `manual_required`.
* Needs-auth destinations show `needs_auth`.
* Connected email destinations can be marked `queued`/`sent` in MVP.
* Health score updates after posts/broadcasts.
* All backend responses are JSON.
* No external dependencies were added.
* No build process was added.
* No framework was added.
* Tests or smoke scripts cover the new API paths.

## Important coding style rules

* Prefer clear boring PHP over clever abstractions.
* Keep endpoint methods short-ish and readable.
* Validate input server-side.
* Escape all UI-rendered values in JS using existing escaping helpers.
* Keep SQL explicit.
* Use transactions where creating a broadcast and many results.
* Return useful JSON shapes.
* Avoid global frontend state except existing token/user helpers.
* Use LARC/PAN topic messages for cross-component updates.
* Do not break existing event routes.
* Do not rename existing files unless necessary.
* Do not rewrite the app.
* Add the smallest clean set of files needed.

## Final response required from coding LLM

When done, report:

1. Files changed.
2. New API endpoints.
3. New database tables.
4. How to run migration.
5. How to test locally.
6. What is stubbed vs real.
7. Any known limitations.
8. Suggested next steps for real platform integrations.
