# PANIC PROMOTE — IMPLEMENTATION MAP (Phase 1 output)

> Produced by Agent 1 — Repo Cartographer. Read-only inspection of the Panic Backstage
> repo at `/Users/cdr/Projects/backstage` (planning docs PROMOTE-PLAN.md and this map live in the repo root). No repo files were modified.
> Later phases (Agents 2–6) should treat this document as the source of truth for
> repo conventions.

## A. Architecture Summary

- **Backend**: plain PHP + PDO/MySQL, no ORM, no Composer runtime deps. API-first, JSON only.
- **Routing**: constrained resolver in `src/Kernel.php` — segment matching + `match()` dispatch to endpoint classes; Kernel instantiates `new $class($db, $auth, $params, $root)`.
- **Endpoints**: classes extend `Panic\BaseEndpoint`; a single `handle(Request): Response` dispatches on HTTP method.
- **Auth**: JWT (HS256) Bearer tokens via `Auth::issueAccessToken()`; role-based event capabilities checked through `BaseEndpoint::requireEventCapability()`.
- **Database**: MySQL/MariaDB; migrations are numbered plain-SQL files in `database/migrations/`; `database/schema.sql` is the synchronized full schema; JSON stored as `LONGTEXT ... CHECK (json_valid(...))`.
- **Frontend**: static HTML shell (`public/index.html`), vanilla JS Web Components extending `PanicElement` (`core.js`), hash router in `app.js`, `api()` fetch helper with 401 auto-refresh, LARC/PAN `publish()`/`subscribe()` over CustomEvents.
- **CSS**: single `public/assets/app.css`, class-based (`.panel`, `.section-head`, `.badge`, `.modal-backdrop`, `.modal-card`), theme via CSS custom properties (`--red`, `--line`).
- **Tests**: procedural smoke script `scripts/endpoint-smoke.php` (magic-link auth → Bearer calls → assertions), plus `tests/*` and `run-tests.sh`.

---

## B. Routing — Kernel.php pattern

**File**: `src/Kernel.php`. `Kernel::resolve(string $path)` strips `/api/` and base path, splits segments, extracts ints via `intOrNull()`, returns `[ClassName, $params]`.

Existing Events block (lines ~180–207):

```php
if ($segments[0] === 'events') {
    if (($segments[1] ?? '') === 'from-template') {
        return [Events::class, ['fromTemplateId' => $this->intOrNull($segments[2] ?? null)]];
    }
    $eventId = $this->intOrNull($segments[1] ?? null);
    $child   = $segments[2] ?? null;
    $childId = $this->intOrNull($segments[3] ?? null);
    return match ($child) {
        'tasks'  => [Events\Tasks::class,  ['eventId' => $eventId, 'taskId'  => $childId]],
        'assets' => [Events\Assets::class, ['eventId' => $eventId, 'assetId' => $childId]],
        default  => [Events::class,        ['eventId' => $eventId]],
    };
}
```

Promote block to add (note: URL shape is `/api/promote/campaigns/{id}/...` and `/api/promote/events/{eventId}/campaign`, so match `segments[1]` for `campaigns` vs `events` before extracting ids):

```php
if ($segments[0] === 'promote') {
    if (($segments[1] ?? '') === 'events') {
        $eventId = $this->intOrNull($segments[2] ?? null);
        // /api/promote/events/{eventId}            (GET overview lookup)
        // /api/promote/events/{eventId}/campaign   (POST create campaign)
        return [Promote\CampaignForEvent::class, ['eventId' => $eventId]];
    }
    if (($segments[1] ?? '') === 'campaigns') {
        $campaignId = $this->intOrNull($segments[2] ?? null);
        $child      = $segments[3] ?? null;
        $childId    = $this->intOrNull($segments[4] ?? null);
        // posts have a deeper sub-path: .../posts/{postId}/variants[/generate|/{variantId}]
        return match ($child) {
            'posts'        => [Promote\Posts::class,           ['campaignId' => $campaignId, 'postId' => $childId,
                                                               'sub' => $segments[5] ?? null, 'subId' => $this->intOrNull($segments[6] ?? null)]],
            'broadcasts'   => [Promote\Broadcasts::class,      ['campaignId' => $campaignId, 'broadcastId' => $childId]],
            'health'       => [Promote\PromotionHealth::class, ['campaignId' => $campaignId]],
            'analytics'    => [Promote\Analytics::class,       ['campaignId' => $campaignId]],
            'destinations' => [Promote\Destinations::class,    ['campaignId' => $campaignId]],
            default        => [Promote::class,                 ['campaignId' => $campaignId]],
        };
    }
}
```

(Agent 2 should adapt the variants sub-path handling to however Events subresources handle 3rd-level paths — keep it consistent with the repo's actual depth conventions.)

---

## C. Endpoint class template

Base: `Panic\BaseEndpoint`. Reference subresource: `src/Events/Assets.php`.

Constructor (inherited):

```php
public function __construct(
    protected readonly Database $db,
    protected readonly Auth $auth,
    protected readonly array $params = [],
    protected readonly string $root = ''
) {}
```

Skeleton:

```php
<?php
declare(strict_types=1);

namespace Panic;

final class Promote extends BaseEndpoint
{
    public function handle(Request $request): Response
    {
        $campaignId = $this->params['campaignId'] ?? null;
        return match ($request->method()) {
            'GET'    => $campaignId ? $this->show((int) $campaignId) : $this->index(),
            'POST'   => $this->create($request),
            'PATCH'  => $this->update($request, (int) $campaignId),
            'DELETE' => $this->delete((int) $campaignId),
            default  => Response::methodNotAllowed(),
        };
    }
    // private index()/show()/create()/update()/delete() ...
}
```

Inherited helpers to use:

- `$this->userId(): ?int` — current user id (`$this->auth->user()['id']`)
- `$this->ok(array $payload): Response` — 200 JSON
- `$this->notFound(string)` / `$this->forbidden(string)` — 404/403 JSON
- `$this->requireEventCapability(int $eventId, string $cap): ?Response` — null if allowed
- `$this->hasEventCapability(int $eventId, string $cap): bool`
- `$this->eventAccess(int $eventId): ?array` — `['role' => ..., 'capabilities' => [...]]`
- `$this->isVenueAdmin(): bool`
- `Request::body(?string $key, $default)` — JSON body access
- `Response::json(array, int $status)`, `Response::noContent()`, `Response::methodNotAllowed()`

---

## D. Auth / capability recipe

Roles → event capabilities (defined in `BaseEndpoint.php` lines ~8–47):

- `venue_admin`, `event_owner`: all capabilities
- `promoter`: `read_event`, `manage_lineup`, `manage_tasks`, `manage_schedule`, `manage_open_items`, `manage_guest_list`, `manage_staffing`, `view_public_page`, `view_contracts`
- `band`/`artist`/`designer`: asset upload/manage, assigned tasks
- `staff`: read + manage tasks/schedule/open-items/guest-list/staffing
- `viewer`: `read_event` only

**Recipe for Promote** (no new capability needed):

- View campaign/posts/health/analytics → require `read_event` on the campaign's event.
- Create/update/delete campaigns, posts, variants, broadcasts → require `edit_event`.
- Always load the campaign row first, get `event_id`, then capability-check:

```php
$campaign = $this->db->one('SELECT * FROM promote_campaigns WHERE id = ?', [$campaignId]);
if (!$campaign) return $this->notFound('Campaign not found');
if ($denied = $this->requireEventCapability((int) $campaign['event_id'], 'edit_event')) return $denied;
```

---

## E. Database

- **Next migration number is `004`** → create `database/migrations/004_panic_promote.sql` (PROMOTE-PLAN.md's `024` does not match the repo; only 001–003 exist).
- Migration conventions: pure SQL, explanatory comment header, `CREATE TABLE IF NOT EXISTS`, `ADD COLUMN IF NOT EXISTS` for safe reruns, one logical change-set per file.
- Apply via `scripts/migrate.php`, then update `database/schema.sql` to match and commit both together.
- **JSON columns**: repo convention is
  `longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(col))`
  — not the `JSON` type from PROMOTE-PLAN.md.
- Tables: `promote_campaigns`, `promote_posts`, `promote_post_variants`, `promote_destinations`, `promote_broadcasts`, `promote_broadcast_results` per PROMOTE-PLAN.md shapes, but with InnoDB/utf8mb4_unicode_ci, named FK constraints (`promote_posts_ibfk_1` style), explicit `KEY` indexes on FK columns, and the JSON convention above. Seed the 11 default destinations with `ON DUPLICATE KEY UPDATE label = VALUES(label)`.

Relevant existing tables (from `database/schema.sql`):

- **events** (~line 488): `id, venue_id, title, slug, event_type, status, description_public, date, doors_time, show_time, age_restriction, ticket_url, capacity, room, public_visibility, owner_user_id, …`
- **event_assets** (~line 240): `id, event_id, asset_type enum(flyer, poster, band_photo, logo, social_square, social_story, press_photo, other), title, file_path, approval_status, uploaded_by_user_id, …`
- **event_tasks** (~line 448): `id, event_id, title, description, status enum(todo,in_progress,blocked,done,canceled), assigned_user_id, due_date, priority, …`
- **users**: `id, name, email, role(venue_admin,event_owner,promoter,band,artist,designer,staff,viewer), …`
- **event_activity_log** (~line 224): `id, event_id, user_id, action, details_json, created_at`
  — written via helper `log_activity($db, $eventId, $userId, $action, $detailsArray)` (in `Support.php`). Use this when broadcasts are created.

---

## F. Frontend

### Base component (`core.js` ~line 330)

```javascript
class PanicElement extends HTMLElement {
  connectedCallback()  { this.abort = new AbortController(); this.connect?.(); }
  disconnectedCallback() { this.abort?.abort(); }
  setLoading(label = 'Loading') { this.innerHTML = `<pb-loading-state label="${esc(label)}"></pb-loading-state>`; }
  showError(error) { this.innerHTML = `<div class="panel padded"><h2>Something went wrong</h2><p class="error-text">${esc(error.message || error)}</p></div>`; }
}
```

Components are registered `customElements.define('pb-…', Class)` — existing names: `pb-dashboard`, `pb-event-workspace`, etc. Promote components use `pb-promote-*`.

### api() helper (`core.js` ~line 78)

`api(path, options = {})` — fetch wrapper: JSON Content-Type (unless FormData), Bearer token, **auto-refresh on 401** (redirects to `login.html` on failure), parses JSON, publishes `api.error` and throws on non-OK. Usage:

```javascript
const data = await api('/promote/campaigns');
await api(`/promote/campaigns/${id}`, { method: 'PATCH', body: JSON.stringify({ status: 'active' }) });
```

### publish/subscribe (`core.js` ~lines 62–75)

```javascript
publish(topic, payload = {})          // dispatches CustomEvent + 'pan:publish'
subscribe(topic, handler, signal)     // listens to topic + 'pan:message'; pass this.abort.signal
```

Topics for Promote (per PROMOTE-PLAN.md): `promote.campaign.loaded`, `promote.campaign.changed`, `promote.post.created/updated/deleted`, `promote.variants.generated`, `promote.broadcast.open`, `promote.broadcast.created`, `promote.health.changed`, plus existing `toast.show` (`{ message, tone: 'info'|'success'|'error' }`) and `api.error`.

### Other helpers (`core.js`)

`esc()`, `assetUrl()`, `appUrl()`, `apiUrl()`, `$()`/`$$()`, `titleCase()`, `shortDate()`, `longDate()`, `isoDate()`, `money()`, `formData(form)`.

### Router + nav (`app.js`)

`AppShell.route()` (~lines 275–309) reads `location.hash`, publishes `app.route.changed`, toggles `[data-nav]` active state, then mounts via:

```javascript
mount(outlet, tagName, props = {}) {
  const element = document.createElement(tagName);
  Object.assign(element, props);
  outlet.replaceChildren(element);
}
```

To add Promote:

1. Sidebar nav in `AppShell.renderShell()` (~lines 68–75):
   `<a data-nav="promote" href="#promote" title="Panic Promote"><i class="fa-solid fa-megaphone"></i>Panic Promote</a>`
   (mobile footer nav at ~lines 114–119 too).
2. Routes in `route()`:
   ```javascript
   if (route === 'promote') return this.mount(outlet, 'pb-promote-campaign-list');
   if (route.startsWith('promote-event-')) {
     const eventId = Number(route.slice('promote-event-'.length));
     return this.mount(outlet, 'pb-promote-campaign-overview', { eventId });
   }
   ```
3. Include `<script src="assets/promote.js">` in `public/index.html` alongside the other module scripts.

### Modal pattern (reference: `contacts.js` ~lines 161–207)

Create a `div.modal-backdrop` containing `div.modal-card` (`.wide` for large forms with `.section-head.padded` header), append to `document.body`. Close on: `[data-close]` clicks, backdrop click (`event.target === dialog`), and Escape keydown. Form submit → disable button → `api()` → `publish('toast.show', …)` + domain topic → `dialog.remove()`; on error set `[data-error]` text and re-enable.

---

## G. CSS — reuse from `app.css`

- Layout: `.panel` (~361), `.panel-body` (~362), `.padded` (~363), `.section-head` (~353), `.section-head-actions` (~837)
- Modal: `.modal-backdrop` (~1653), `.modal-card` (~1664), `.modal-card.wide` (~1671)
- Status: `.badge` (~451) with tone classes `.success`, `.warning`, `.error`, `.info`
- Forms: `.grid-form`, `.wide`, `.check-label`, `.form-actions`, `.error-text`
- Buttons/text: `.primary`, `.secondary`, `.small`, `.muted`
- Theme vars: `--red` (accent), `--line` (borders, #e3e4e8)
- New Promote styles should be plain CSS classes prefixed `promote-` (no preprocessor — ignore any `@extend` notation; write real CSS), appended to `app.css`.

---

## H. Tests / smoke

`scripts/endpoint-smoke.php` pattern:

- `SmokeClient::request(string $method, string $path, mixed $body = null, array $expectedStatuses = []): array`
- Auth bootstrap: magic-link login flow, token extracted from `.eml` files in `storage/mail`
- `ok(string $msg)` prints progress; failures throw → `FAIL: …` + exit 1
- Run: `php scripts/promote-smoke.php http://localhost:8000 storage/mail`

New `scripts/promote-smoke.php` should cover: create test event (via `/api/events/from-template/{id}`), create campaign, fetch detail, create/update post, generate variants, create broadcast (verify per-destination result statuses: `needs_auth` for FB/IG/TikTok, `manual_required` for Funcheap/Foopee, `queued`/`sent` for email), fetch health (assert `score` key) + analytics + lists, 404 for missing post, invite a `viewer` (via `/api/events/{id}/invites` → `/api/invite/{token}`) and assert viewer can GET campaign but gets 403 on POST.

---

## I. Gotchas / deviations from PROMOTE-PLAN.md

1. **Repo location**: everything (code + planning docs) now lives in `/Users/cdr/Projects/backstage`.
2. **Migration number**: next is **`004_panic_promote.sql`**, not 024.
3. **JSON columns**: use the `LONGTEXT … CHECK (json_valid())` convention, not `JSON` type.
4. **Capabilities**: don't invent `manage_promote`; reuse `read_event` (view) and `edit_event` (mutate).
5. **Activity log**: use `log_activity($db, $eventId, $userId, $action, $details)` from `Support.php` on broadcast creation.
6. **Component prefix**: confirmed `pb-*` matches existing components.
7. **Broadcast result mapping** (MVP, no external calls): destination `needs_auth` → result `needs_auth`; `manual_submission` → `manual_required`; `connected` → `sent` (now) / `queued` (scheduled).
8. **Promotion Health (MVP)**: deterministic checklist from: `events.public_visibility`, approved `event_assets` flyer, post counts/statuses, approved variants, broadcast results per destination group, email/reminder presence. Return `{score, complete, total, items[]}` per PROMOTE-PLAN.md shape.
9. **Toasts**: `publish('toast.show', { message, tone })`; tones `info|success|error` (see `ToastStack.add()` in core.js ~line 365).
10. **Transactions**: wrap broadcast + results inserts in `$db->pdo()->beginTransaction()/commit()/rollback()`.
11. **Access checks**: always resolve campaign → `event_id` → capability check; never trust the campaign id alone.
12. **No frameworks/build**: keep everything as plain JS/CSS files included from `index.html`.
