// Doors / Show / End auto-fill. Tests the real autofillEventTimes() exported
// from event-workspace.js against a detached <form> — no event is touched, so
// this is fully non-destructive.
import { test, assert } from './harness.mjs';

// Run the exported autofill against an in-page detached form and return the
// resulting "show|end" values. `seed` maps field name → starting value.
function runAutofill(page, seed, changed) {
  const setup = Object.entries(seed).map(([k, v]) => `f.${k}.value=${JSON.stringify(v)};`).join('');
  return page.eval(`(async () => {
    const f = document.createElement('form');
    f.innerHTML = '<input name="doors_time"><input name="show_time"><input name="end_time">';
    ${setup}
    const m = await import(${JSON.stringify(page.base + '/assets/event-workspace.js')});
    m.autofillEventTimes(f, ${JSON.stringify(changed)});
    return f.show_time.value + '|' + f.end_time.value;
  })()`);
}

test('Doors fills empty Show (+1h) and End (+5h): 18:00 → 19:00 / 23:00', async (page) => {
  assert.equal(await runAutofill(page, { doors_time: '18:00' }, 'doors_time'), '19:00|23:00');
});

test('autofill back-fills from Show: 19:00 → Doors 18:00, End 23:00', async (page) => {
  const r = await page.eval(`(async () => {
    const f = document.createElement('form');
    f.innerHTML = '<input name="doors_time"><input name="show_time"><input name="end_time">';
    f.show_time.value = '19:00';
    const m = await import(${JSON.stringify(page.base + '/assets/event-workspace.js')});
    m.autofillEventTimes(f, 'show_time');
    return f.doors_time.value + '|' + f.end_time.value;
  })()`);
  assert.equal(r, '18:00|23:00');
});

test('autofill never overwrites an existing value', async (page) => {
  assert.equal(await runAutofill(page, { doors_time: '18:00', show_time: '20:30' }, 'doors_time'), '20:30|23:00');
});

test('End wraps past midnight: Doors 22:00 → Show 23:00, End 03:00', async (page) => {
  assert.equal(await runAutofill(page, { doors_time: '22:00' }, 'doors_time'), '23:00|03:00');
});
