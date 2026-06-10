# UI tests

Zero-dependency, browser-driven UI smoke tests for the SPA. They drive the
system Chromium/Chrome over the DevTools Protocol using only Node's built-in
`fetch` + `WebSocket` (Node 21+/22+) — no npm, no build step, matching the rest
of the codebase.

```bash
node tests/ui/run.mjs
```

The runner:

1. Starts a local PHP dev server (unless one is already serving the base URL).
2. Logs in non-destructively via a one-shot magic-link token
   (`scripts/login-link.php` — no password is set or changed).
3. Launches headless Chromium and seeds the JWTs into `localStorage`.
4. Imports every `tests/ui/*.test.mjs`, runs each case against the live DOM,
   and prints `✓ / ✗ / •` with a summary. Exit code is non-zero if any fail.

## Environment

| Var | Default | Meaning |
|-----|---------|---------|
| `UI_EMAIL` | `admin@mabuhay.local` | account to log in as (must exist) |
| `UI_EVENT_ID` | `641027` | event used by workspace/ticketing tests |
| `UI_PORT` | `8099` | port for the dev server we start |
| `UI_CDP_PORT` | `9344` | Chromium remote-debugging port |
| `UI_BASE` | _auto_ | full base URL; overrides the port/base-path autodetect |

Tests that need a specific event call `page.skip(...)` when `UI_EVENT_ID`
doesn't exist or lacks the relevant feature, so the suite degrades gracefully
on an unfamiliar database.

## Writing a test

```js
import { test, assert } from './harness.mjs';

test('what it checks', async (page) => {
  await page.openEvent();                  // navigate to UI_EVENT_ID's workspace
  assert.ok(await page.exists('#tasks'), 'tasks panel present');
  await page.click('#tasks [data-add]');
  assert.ok(await page.visible('#tasks form[data-add-form]'), 'add form revealed');
});
```

`page` helpers: `goto(hash)`, `openEvent(id?)`, `exists/visible/text/attr/count(sel)`,
`click(sel)`, `setValue(sel,val)`, `selectRadio(sel)`, `until(expr)`, `eval(expr)`,
and `skip(reason)`. Selectors are embedded safely, so attribute selectors with
quotes (e.g. `form[data-form="tier"]`) work as-is.

**Keep tests non-destructive.** Prefer asserting client-side behaviour (form
reveals, reactive toggles, computed values) over interactions that POST/PATCH —
the runner uses the local dev database, but tests should not depend on or mutate
its contents. To exercise pure logic, export it and call it from a detached DOM
node (see `30-event-times.test.mjs`).

## Shared kit

`browser.mjs` holds the reusable browser/CDP/login/server machinery. It's shared
with `scripts/screenshots.mjs`, so both the screenshot generator and the test
runner use one implementation.
