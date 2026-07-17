// Calendar encoding: the dot colour denotes an event's STATUS (keyed by a
// legend above the grid), while its vertical position in the day cell denotes
// the floor — On Broadway (upstairs) above the divider, The Mab (downstairs)
// below it. The old per-event status badge is gone (status moved to the dot +
// hover tooltip).
import { test, assert } from './harness.mjs';

test('calendar shows a status legend with coloured dots', async (page) => {
  await page.goto('#calendar');
  await page.until(`document.querySelector('.calendar-grid')`);
  assert.ok(await page.visible('.calendar-legend'), 'legend is shown above the grid');
  const items = await page.count('.calendar-legend .legend-item');
  assert.atLeast(items, 1, 'legend lists the statuses present this month');
  assert.equal(
    await page.count('.calendar-legend .legend-item .status-dot'),
    items,
    'each legend item carries a status-coloured dot',
  );
});

test('Grid|Agenda toggle has a middle List button that returns to the dashboard', async (page) => {
  await page.goto('#calendar');
  await page.until(`document.querySelector('.cal-view-toggle')`);

  // Three children, in order: Grid, List, Agenda.
  assert.equal(await page.count('.cal-view-toggle > *'), 3, 'toggle group has exactly three children');
  const children = await page.eval(
    `Array.from(document.querySelectorAll('.cal-view-toggle > *')).map(el => el.tagName + '|' + (el.dataset.view || el.getAttribute('href')))`,
  );
  assert.equal(children[0], 'BUTTON|grid', 'first child is the Grid button');
  assert.equal(children[1], 'A|#dashboard', 'middle child is the List link to #dashboard');
  assert.equal(children[2], 'BUTTON|agenda', 'last child is the Agenda button');
  assert.includes(await page.text('.cal-view-toggle a'), 'List', 'middle button is labelled List');

  // Grid/Agenda still toggle their own active state independently of List.
  assert.ok(await page.eval(`document.querySelector('[data-view="grid"]').classList.contains('active')`), 'grid starts active (desktop default)');
  await page.click('[data-view="agenda"]');
  await page.until(`document.querySelector('[data-view="agenda"]').classList.contains('active')`);
  assert.notOk(await page.eval(`document.querySelector('[data-view="grid"]').classList.contains('active')`), 'grid loses active state');

  // Clicking List navigates away to the dashboard (events-upcoming) view.
  await page.click('.cal-view-toggle a');
  await page.until(`document.querySelector('pb-events-upcoming') && document.querySelector('pb-events-upcoming').children.length>0`);
  assert.equal(await page.eval('location.hash'), '#dashboard', 'List navigates to #dashboard');
});

test('events carry a status dot and the cell is split into floor zones', async (page) => {
  await page.goto('#calendar');
  await page.until(`document.querySelector('.calendar-grid')`);
  const events = await page.count('.mini-event');
  // Each event title leads with a status dot; the legacy badge is gone.
  assert.equal(await page.count('.mini-event .status-dot'), events, 'every event title has a status dot');
  assert.equal(await page.count('.mini-event .badge'), 0, 'no per-event status badge remains');
  if (events > 0) {
    // A day with any events renders both floor zones (upstairs + downstairs),
    // so the dividing line is always present on a populated cell.
    assert.atLeast(await page.count('.calendar-day .zone-up'), 1, 'upstairs (On Broadway) zone exists');
    assert.atLeast(await page.count('.calendar-day .zone-down'), 1, 'downstairs (The Mab) zone exists');
  }
});
