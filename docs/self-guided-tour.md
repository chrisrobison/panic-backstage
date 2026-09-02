# Self-guided in-app tours

A lightweight, dependency-free product-tour engine: a dimmed backdrop, a
highlight ring around one real UI element at a time, and a tooltip card with
Back/Next/Skip. Hand-rolled (no Shepherd.js/Intro.js) to match the app's
zero-build-step, no-npm-runtime-dep convention — same philosophy as
`src/QrCode.php`/`src/Pdf.php`.

This is distinct from two other things that already exist and cover
different jobs:

- **`public/assets/help.js`** (`HELP_SECTIONS`/`HELP_CONTENT`) — the static
  reference manual, read on your own, not anchored to live UI.
- **`docs/training.md`** — a 3+ hour *instructor-led* lesson plan for a live
  training session with a group.

A tour is neither of those: it's a short, replayable, self-paced walk around
the real app, pointing at real sidebar/topbar elements as you go.

## Where it lives

- `public/assets/tour.js` — the `TOURS` registry, the `<pb-tour>` Web
  Component (the engine), and `startTour(key)` / `openTourPicker()`.
- `<pb-tour></pb-tour>` is mounted once, persistently, in `pb-app-shell`'s
  `renderShell()` (`public/assets/app.js`), alongside `<pb-toast-stack>` and
  `<pb-ai-drawer>` — it survives route changes so a tour step can navigate
  the app mid-tour.
- Entry points: **Help ▸ Take a tour** in the sidebar (opens the picker —
  `app.js`'s `helpNavGroup()`), and a **Take the tour** button on the
  Dashboard's "Getting started" checklist card (`event-views.js`'s
  `onboardingCard()`), which launches the `venue-setup` tour directly.
- Styles: the `.pb-tour-*` rules at the end of `public/assets/app.css`.

## Adding a tour or a step

Each entry in `TOURS` (in `tour.js`):

```js
{
  key: 'my-tour',
  label: 'Shown in the picker',
  description: 'One sentence, shown under the label.',
  icon: 'fa-solid fa-...',
  capability: 'some_capability', // optional — hides the tour from the
                                  // picker unless getAppCapabilities()
                                  // has this flag (mirrors core.js's can())
  steps: [ /* see below */ ],
}
```

Each step:

```js
{
  route: 'admin-venue',        // optional — hash to navigate to first
  selector: '[data-nav="admin-venue"]', // optional — omit for a centered,
                                          // un-anchored card (intro/outro)
  title: 'Venue details',
  body: 'Trusted static HTML — not escaped, same as help.js\'s HELP_CONTENT.',
  help: 'admin-venue',          // optional — renders a "Learn more" link to
                                 // #help-<slug>; must be a real help.js slug
}
```

**Keep `selector` targets on stable chrome** — sidebar nav links
(`[data-nav="..."]`), nav group toggles (`[data-group-toggle="..."]`),
topbar controls (`[data-search]`, `[data-action="new-event"]`,
`[data-user-pill]`) — rather than inside feature panels, which change shape
often. If a step's `selector` can't be found within ~4s (a venue renamed/
removed the nav item via Navigation Manager, or the signed-in user's role
hides it), the engine skips that step automatically instead of showing a
broken spotlight — so it's safe to write steps assuming the *default*
seeded nav (`nav_items`), without hand-gating every single step.

Sidebar nav items live inside collapsible `.nav-group`s and, on mobile,
an off-canvas drawer — `revealAncestors()` auto-opens whichever of those
owns the current step's target, the same way `pb-app-shell`'s own `route()`
auto-opens the group owning the active nav link.

## Persistence

Completion is tracked client-side only, in `localStorage`
(`pb.toursDone`, via the same `window.PBConsent.savePref()` gate the app
already uses for other local UI prefs like `pb.navCollapsed`) — it's
per-browser, not per-account, and purely cosmetic (a "✓ Completed" badge in
the picker). Tours are always replayable regardless of that flag. A future
enhancement could persist completion server-side per user, the way the
Dashboard onboarding checklist's `onboarding_dismissed` does, if cross-device
tracking turns out to matter.

## Testing

`tests/ui/121-self-guided-tour.test.mjs` drives the real picker and engine:
opens it from the Help nav group, steps through a tour (spotlight-less
centered step → spotlighted step → back → skip), Escape-to-close, a full
run to completion (asserts the "Completed" badge appears), and the
Dashboard checklist's direct-launch shortcut.
