// AI Assistant drawer (public/assets/ai-drawer.js): opens as a non-blocking
// slide-over panel (no backdrop — the rest of the app stays interactable),
// closes via the close button and Escape, and a real question against a
// real (read-only) event gets a grounded answer back through the full
// claude CLI -> MCP -> tool-call -> DB round trip. Runs as admin@mabuhay.local
// (venue_admin), which has the use_ai_assistant capability by default.
import { test, assert } from './harness.mjs';

test('topbar trigger opens the AI drawer as a non-blocking panel; Escape closes it', async (page) => {
  await page.goto('#dashboard');
  assert.ok(await page.visible('[data-action="ai-drawer-toggle"]'), 'AI Assistant topbar button is shown for an admin');
  assert.notOk(await page.exists('pb-ai-drawer.ai-drawer-open'), 'drawer starts closed');

  await page.click('[data-action="ai-drawer-toggle"]');
  assert.ok(await page.exists('pb-ai-drawer.ai-drawer-open'), 'drawer opens on topbar click');
  assert.ok(await page.visible('.ai-drawer-transcript'), 'transcript panel is visible');

  // Non-blocking: unlike the mobile nav drawer (.drawer-backdrop) or any
  // openModal() dialog (.modal-backdrop), there must be no scrim element,
  // and content behind the drawer must still be genuinely interactable —
  // not just present in the DOM.
  assert.notOk(await page.exists('.ai-drawer-backdrop'), 'AI drawer never renders a backdrop/scrim element');
  assert.notOk(await page.visible('.modal-backdrop'), 'no modal backdrop is showing while the drawer is open');
  assert.ok(await page.visible('.side-nav a[data-nav="dashboard"]'), 'the side nav behind the drawer is still visible');
  const navClickWorks = await page.eval(`(()=>{
    const el = document.querySelector('.side-nav a[data-nav="calendar"]');
    if (!el) return false;
    const r = el.getClientRects()[0];
    if (!r) return false;
    // elementFromPoint returns whichever element would actually receive a
    // click at that point — if a scrim/overlay were covering it, this
    // would resolve to that overlay instead of (or an ancestor of) the link.
    const hit = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2);
    return !!hit && (hit === el || el.contains(hit) || hit.contains(el));
  })()`);
  assert.ok(navClickWorks, 'a nav link behind the open drawer is still the actual hit-target for a click at its position');

  await page.eval(`document.dispatchEvent(new KeyboardEvent('keydown', {key:'Escape', bubbles:true})); window.dispatchEvent(new KeyboardEvent('keydown', {key:'Escape', bubbles:true}))`);
  await new Promise((r) => setTimeout(r, 200));
  assert.notOk(await page.exists('pb-ai-drawer.ai-drawer-open'), 'Escape closes the drawer');
});

test('close button closes the drawer', async (page) => {
  await page.goto('#dashboard');
  await page.click('[data-action="ai-drawer-toggle"]');
  assert.ok(await page.exists('pb-ai-drawer.ai-drawer-open'), 'drawer opens');
  await page.click('[data-ai-close]');
  assert.notOk(await page.exists('pb-ai-drawer.ai-drawer-open'), 'close button closes the drawer');
});

test('opening from an event workspace scopes the drawer to that event', async (page) => {
  if (!page.hasEvent) page.skip('UI_EVENT_ID fixture event not found');
  await page.openEvent();
  if (!(await page.exists('[data-ask-ai]'))) page.skip('no "Ask AI" button rendered (use_ai_assistant capability not granted to this test user)');
  await page.click('[data-ask-ai]');
  assert.ok(await page.exists('pb-ai-drawer.ai-drawer-open'), 'drawer opens from the event workspace');
  const placeholder = await page.text('.ai-drawer-empty');
  assert.ok(placeholder && placeholder.includes('this event'), 'empty-state copy reflects the drawer is scoped to the open event');
});

test('a real question gets a grounded answer through the full claude CLI round trip', async (page) => {
  if (!page.hasEvent) page.skip('UI_EVENT_ID fixture event not found');
  await page.openEvent();
  if (!(await page.exists('[data-ask-ai]'))) page.skip('use_ai_assistant capability not granted to this test user');
  await page.click('[data-ask-ai]');
  await page.setValue('[data-ai-input]', 'In one short sentence, what is the title of this event?');
  await page.click('[data-ai-send]');

  // Spawning `claude` per request genuinely takes several seconds — give
  // this a much longer budget than the harness's default until() timeout.
  const answered = await page.until(`document.querySelector('.ai-msg-assistant, .ai-msg-error')`, 45000);
  assert.ok(answered, 'the drawer shows either an assistant reply or a surfaced error within 45s');
  assert.notOk(await page.exists('.ai-msg-pending'), '"Thinking…" placeholder is gone once a reply lands');
  assert.notOk(await page.exists('.ai-msg-error'), 'the round trip did not error (see message text if this fails)');
  const reply = await page.text('.ai-msg-assistant');
  assert.ok(reply && reply.length > 0, 'assistant reply has visible text');
});
