// Booking Inbox (incoming-ui.png) — public/assets/inbox/*.js.
// Non-destructive: only asserts client-side render/navigation behavior
// (list renders, clicking a row loads the workspace/detail panel, saved-
// view switching, mobile collapse) without POSTing/PATCHing any lead.
import { test, assert } from './harness.mjs';

test('Booking Inbox is reachable from the "inbox-unassigned" nav route and renders the queue', async (page) => {
  await page.goto('#inbox-unassigned');
  assert.ok(
    await page.until(`document.querySelector('pb-inbox-app') && document.querySelectorAll('.ib-list-item').length > 0`),
    'pb-inbox-app mounts and the queue renders at least one row',
  );
  assert.ok(await page.exists('.ib-list-view-select'), 'saved-view switcher is present');
  assert.ok(await page.exists('.ib-list-search input'), 'search input is present');
});

test('Clicking a queue row opens the workspace with a matching header and the detail panel', async (page) => {
  await page.goto('#inbox-unassigned');
  await page.until(`document.querySelectorAll('.ib-list-item').length > 0`);
  await page.click('.ib-list-item');
  assert.ok(
    await page.until(`document.querySelector('.ib-workspace-title-row h1')?.textContent?.trim().length > 0`),
    'workspace header renders a name once a row is selected',
  );
  assert.ok(await page.exists('.ib-tabs a.active'), 'a tab is active (Conversation by default)');
  assert.ok(await page.exists('.ib-status-bar select'), 'status dropdown is present');
  assert.ok(await page.exists('.ib-action-bar [data-action="onboard"]'), 'Onboard Lead action is present');
  assert.ok(await page.exists('.ib-detail'), 'detail panel renders');
});

test('Switching the saved-view dropdown reloads the queue', async (page) => {
  await page.goto('#inbox-unassigned');
  await page.until(`document.querySelectorAll('.ib-list-item').length > 0`);
  await page.eval(`
    const sel = document.querySelector('.ib-list-view-select');
    sel.value = 'archived';
    sel.dispatchEvent(new Event('change', { bubbles: true }));
  `);
  assert.ok(
    await page.until(`document.querySelector('.ib-list-head-row h2')?.textContent?.includes('Inquir')`),
    'list header re-renders after a view change (does not error out)',
  );
});

test('The inquiry list pane is actually scrollable (not clipped to its content height)', async (page) => {
  await page.goto('#inbox-all');
  await page.until(`document.querySelectorAll('.ib-list-item').length > 0`);
  const info = await page.eval(`(() => {
    const el = document.querySelector('.ib-list-scroll');
    if (!el) return null;
    return { scrollHeight: el.scrollHeight, clientHeight: el.clientHeight, overflowY: getComputedStyle(el).overflowY };
  })()`);
  assert.ok(info, '.ib-list-scroll is present');
  assert.equal(info.overflowY, 'auto', '.ib-list-scroll has overflow-y: auto');
  // A flexbox regression (missing min-height:0 anywhere in the .ib-body →
  // .ib-list → pb-inbox-list → .ib-list-scroll chain) makes this element grow
  // to fit every row instead of being clipped to .ib-list's bounded height —
  // scrollHeight then always equals clientHeight and nothing is scrollable.
  assert.ok(info.clientHeight > 0, '.ib-list-scroll has a real (non-zero) rendered height');
  if (info.scrollHeight > info.clientHeight) {
    const moved = await page.eval(`(() => {
      const el = document.querySelector('.ib-list-scroll');
      el.scrollTop = 0;
      el.scrollTop = el.scrollHeight;
      return el.scrollTop > 0;
    })()`);
    assert.ok(moved, 'setting .ib-list-scroll.scrollTop actually scrolls it');
  }
});

test('The conversation pane is actually scrollable (not clipped to its content height)', async (page) => {
  await page.goto('#inbox-all');
  await page.until(`document.querySelectorAll('.ib-list-item').length > 0`);
  await page.click('.ib-list-item');
  await page.until(`document.querySelector('.ib-thread')`);
  const info = await page.eval(`(() => {
    const el = document.querySelector('.ib-thread');
    if (!el) return null;
    return { scrollHeight: el.scrollHeight, clientHeight: el.clientHeight, overflowY: getComputedStyle(el).overflowY };
  })()`);
  assert.ok(info, '.ib-thread is present');
  assert.equal(info.overflowY, 'auto', '.ib-thread has overflow-y: auto');
  assert.ok(info.clientHeight > 0, '.ib-thread has a real (non-zero) rendered height');
  // .ib-thread sits behind two unstyled custom-element hops
  // (pb-inbox-workspace, pb-inbox-conversation) that must each pass the
  // bounded height through (flex + min-height:0) — if either one silently
  // reverts to sizing itself by content, .ib-thread stops being clipped and
  // this ratio assertion is what would catch it.
  assert.ok(info.clientHeight < 700, '.ib-thread is bounded to its pane, not stretched to the full conversation height');
});

test('The Event Info tab shows event-specific fields distinct from the Details tab', async (page) => {
  await page.goto('#inbox-all');
  await page.until(`document.querySelectorAll('.ib-list-item').length > 0`);
  await page.click('.ib-list-item');
  await page.until(`document.querySelector('.ib-tabs a.active')`);

  await page.click('[data-tab="details"]');
  const detailsHtml = await page.eval(`document.querySelector('[data-tab-body]')?.innerHTML || ''`);

  await page.click('[data-tab="event-info"]');
  assert.ok(
    await page.until(`document.querySelector('.ib-tabs a.active')?.dataset.tab === 'event-info'`),
    'Event Info tab becomes the active tab',
  );
  const eventInfoHtml = await page.eval(`document.querySelector('[data-tab-body]')?.innerHTML || ''`);
  assert.ok(eventInfoHtml.length > 0, 'Event Info tab renders content');
  assert.ok(eventInfoHtml !== detailsHtml, 'Event Info tab shows different content than the Details tab');
});

test('Booking Inbox collapses to a single-pane, list-first layout on a narrow viewport', async (page) => {
  await page.setViewport(420, 900, true);
  try {
    // The harness shares one page/hash across every test in the run, and
    // page.goto() is just `location.hash = ...` — a no-op (no hashchange,
    // no remount) if the hash is already 'inbox-unassigned' from an earlier
    // test. The previous test in this file leaves the saved-view switched
    // to "archived" (0 leads), so bounce through another route first to
    // force a real remount with a clean, known-good view.
    await page.goto('#dashboard');
    await page.goto('#inbox-unassigned');
    assert.ok(
      await page.until(`document.querySelectorAll('.ib-list-item').length > 0`),
      'queue still renders on a narrow viewport',
    );
    assert.ok(
      await page.until(`document.querySelector('.ib-body')?.classList.contains('ib-body-list-active')`),
      'the list (not the workspace) is the first screen on mobile',
    );
    await page.click('.ib-list-item');
    assert.ok(
      await page.until(`!document.querySelector('.ib-body')?.classList.contains('ib-body-list-active')`),
      'tapping a row switches to the workspace screen',
    );
    assert.ok(await page.exists('.ib-back-to-list'), '"Back to inquiries" control is visible once a lead is open');
  } finally {
    await page.setViewport(1440, 900, false);
  }
});
