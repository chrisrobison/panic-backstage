// Calendar Grid mode is a continuous scroll across months (macOS Calendar
// style) rather than one month replaced on every Prev/Next click — see
// EventCalendar in event-views.js. It starts showing just the current month;
// scrolling to the bottom or top of what's loaded fetches the adjacent month
// and appends/prepends it. Read-only: never creates/deletes data, so nothing
// to clean up.
import { test, assert } from './harness.mjs';

function currentMonthKey() {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
}

function shiftKey(key, delta) {
  const [y, m] = key.split('-').map(Number);
  const d = new Date(y, m - 1 + delta, 1);
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
}

async function blockKeys(page) {
  return page.eval(`Array.from(document.querySelectorAll('.calendar-month-block')).map(b => b.dataset.monthKey)`);
}

// Scrolls the actual scroll host (whichever the app uses — #app on desktop,
// the window otherwise) all the way to one edge and waits for a new block.
async function scrollToEdge(page, edge, previousCount) {
  await page.eval(`(() => {
    const host = document.querySelector('#app.workspace') || document.scrollingElement;
    const top = ${edge === 'top' ? '0' : 'host.scrollHeight'};
    host.scrollTo(0, top);
  })()`);
  await page.until(`document.querySelectorAll('.calendar-month-block').length > ${previousCount}`, 10000);
}

test('Calendar Grid mode starts on just the current month', async (page) => {
  await page.goto('#calendar');
  await page.until(`document.querySelector('.calendar-month-block')`);
  await page.until(`document.querySelector('[data-view="grid"]').classList.contains('active')`);
  const keys = await blockKeys(page);
  assert.equal(keys[0], currentMonthKey(), 'the first (current) month block is the real current month');
});

test('Scrolling to the bottom appends the next month; scrolling to the top prepends the previous one', async (page) => {
  await page.goto('#calendar');
  await page.until(`document.querySelector('.calendar-month-block')`);
  // Let any eager fill-the-viewport loading settle before taking a baseline —
  // grid mode keeps loading on mount until there's enough content to scroll.
  await page.until(`(() => {
    const host = document.querySelector('#app.workspace') || document.scrollingElement;
    return host.scrollHeight > host.clientHeight;
  })()`, 10000);

  let keys = await blockKeys(page);
  assert.ok(keys.length >= 1, 'at least the current month is loaded');
  const lastBeforeScroll = keys[keys.length - 1];

  await scrollToEdge(page, 'bottom', keys.length);
  keys = await blockKeys(page);
  assert.equal(keys[keys.length - 1], shiftKey(lastBeforeScroll, 1), 'the month after the last one loaded is appended at the bottom');
  assert.equal(new Set(keys).size, keys.length, 'no month appears twice after appending');

  const firstBeforeScroll = keys[0];
  await scrollToEdge(page, 'top', keys.length);
  keys = await blockKeys(page);
  assert.equal(keys[0], shiftKey(firstBeforeScroll, -1), 'the month before the first one loaded is prepended at the top');
  assert.equal(new Set(keys).size, keys.length, 'no month appears twice after prepending');

  // Blocks stay in strict chronological order top-to-bottom throughout.
  for (let i = 1; i < keys.length; i++) {
    assert.equal(keys[i], shiftKey(keys[i - 1], 1), `block ${i} is exactly one month after block ${i - 1}`);
  }
});

test('The toolbar month label tracks whichever month is scrolled to (scrollspy)', async (page) => {
  await page.goto('#calendar');
  await page.until(`document.querySelector('.calendar-month-block')`);
  const initialLabel = await page.text('[data-month-label]');

  // Force a couple of months to load below, then scroll down to the last one.
  for (let i = 0; i < 2; i++) {
    const before = (await blockKeys(page)).length;
    await scrollToEdge(page, 'bottom', before);
  }
  const keys = await blockKeys(page);
  const lastBlockSelector = `[data-month-key="${keys[keys.length - 1]}"]`;
  // scrollIntoView moving the real scroll position fires a genuine 'scroll'
  // event on the host — no need to synthesize one.
  await page.eval(`document.querySelector('${lastBlockSelector}').scrollIntoView({ block: 'start' })`);
  await page.until(`document.querySelector('[data-month-label]').textContent !== ${JSON.stringify(initialLabel)}`, 8000);

  const newLabel = await page.text('[data-month-label]');
  assert.ok(newLabel && newLabel !== initialLabel, 'month label updates away from the initial month once scrolled elsewhere');
});

test('Today button returns to the current month from anywhere in the stack', async (page) => {
  await page.goto('#calendar');
  await page.until(`document.querySelector('.calendar-month-block')`);
  const before = (await blockKeys(page)).length;
  await scrollToEdge(page, 'bottom', before);
  await page.click('[data-today]');
  await page.until(`document.querySelector('[data-month-key="${currentMonthKey()}"]')`, 8000);
  assert.ok(await page.exists(`[data-month-key="${currentMonthKey()}"]`), 'current month is back in the DOM after clicking Today');
});
