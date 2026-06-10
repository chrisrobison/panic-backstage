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
