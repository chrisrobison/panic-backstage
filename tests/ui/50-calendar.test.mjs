// Calendar space colour-coding: a legend keys each Mabuhay space to a dot
// colour, every event shows that venue dot in front of its title, and the old
// per-event status badge is gone (clutter reduction — status moved to hover).
import { test, assert } from './harness.mjs';

test('calendar shows a space legend with a dot per venue', async (page) => {
  await page.goto('#calendar');
  await page.until(`document.querySelector('.calendar-grid')`);
  assert.ok(await page.visible('.calendar-legend'), 'legend is shown above the grid');
  const items = await page.count('.calendar-legend .legend-item');
  assert.atLeast(items, 2, 'legend lists at least the two rooms');
  // Every legend chip carries a colour swatch.
  assert.equal(
    await page.count('.calendar-legend .legend-item .venue-dot'),
    items,
    'each legend item has a colour dot',
  );
});

test('each calendar event has a venue dot and no status badge', async (page) => {
  await page.goto('#calendar');
  await page.until(`document.querySelector('.calendar-grid')`);
  const events = await page.count('.mini-event');
  // Resilient to an empty month: when there are events, each must carry exactly
  // one venue dot and the legacy status badge must be gone.
  assert.equal(await page.count('.mini-event .venue-dot'), events, 'every event title has a venue dot');
  assert.equal(await page.count('.mini-event .badge'), 0, 'no per-event status badge clutter remains');
});
