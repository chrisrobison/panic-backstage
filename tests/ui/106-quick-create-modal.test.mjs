// Quick-create modal (openEventQuickCreate in public/assets/event-views.js).
//
// This is the primary path for grabbing a date from the calendar, and until
// issue #25 it collected no contact fields and stamped Doors 19:00 / Show
// 20:00 on every Hold it created — times nobody had entered, and the likely
// source of the incoherent times in issue #26.
//
// Nothing else in the suite opens this modal, so without these cases a
// runtime error in its template literal would ship unnoticed: the app would
// still boot, and every other test would still pass.
//
// Non-destructive — opens the modal, inspects it, and closes it without
// submitting.
import { test, assert } from './harness.mjs';

// The modal renders asynchronously (it fetches /templates first), so every
// case waits for the form rather than assuming it is already there.
const openModal = `
  (async () => {
    document.querySelector('[data-event-quick-create-modal] [data-close]')?.click();
    const views = await import('./assets/event-views.js');
    views.openEventQuickCreate({ date: '2027-06-15' });
    return true;
  })()
`;

const closeModal = `document.querySelector('[data-event-quick-create-modal] [data-close]')?.click() ?? true`;

const field = (name) => `document.querySelector('[data-form="quick-create"] [name="${name}"]')`;

test('quick-create modal opens and renders its form', async (page) => {
  await page.goto('#dashboard');
  await page.eval(openModal);
  assert.ok(
    await page.until(`document.querySelector('[data-form="quick-create"]')`),
    'the quick-create form renders (catches a runtime error in its template)',
  );
  assert.ok(await page.exists('[data-event-quick-create-modal] .modal-card'), 'it renders as a modal');
});

test('quick-create no longer pre-fills invented Doors/Show times', async (page) => {
  // The bug in one assertion: these used to arrive as 19:00 and 20:00.
  assert.equal(await page.eval(`${field('doors_time')}.value`), '', 'Doors starts empty');
  assert.equal(await page.eval(`${field('show_time')}.value`), '', 'Show starts empty');
  assert.ok(await page.eval(`${field('doors_time')}.required`), 'Doors is required');
  assert.ok(await page.eval(`${field('show_time')}.required`), 'Show is required');
});

test('quick-create collects contacts and pre-fills the booker', async (page) => {
  assert.ok(await page.exists('[data-form="quick-create"] .quick-create-contacts'), 'the contacts fieldset renders');
  assert.includes(
    await page.eval(`document.querySelector('[data-form="quick-create"] .quick-create-contacts').textContent`),
    'Contract Name / Point of Contact',
    'the contract contact is clearly distinguished from the booker',
  );

  // Booker is whoever is placing the hold — prefilled from the session so
  // staff stop typing their own name into the artist field.
  const bookerName = await page.eval(`${field('booker_name')}.value`);
  const sessionName = await page.eval(`(async () => (await import('./assets/core.js')).getAppUser()?.name || '')()`);
  assert.ok(sessionName, 'the test session has a user name to compare against');
  assert.equal(bookerName, sessionName, 'Booker is pre-filled with the signed-in user');

  const bookerEmail = await page.eval(`${field('booker_email')}.value`);
  assert.includes(bookerEmail, '@', 'Booker email is pre-filled too');

  // Contract contact is collected but must not block a fast hold.
  assert.ok(await page.exists(`[data-form="quick-create"] [name="promoter_name"]`), 'contract contact name is collected');
  assert.notOk(await page.eval(`${field('promoter_name')}.required`), 'contract contact stays optional at Hold');
  assert.notOk(await page.eval(`${field('booker_name')}.required`), 'booker stays optional at Hold');

  await page.eval(closeModal);
  assert.ok(await page.until(`!document.querySelector('[data-event-quick-create-modal]')`), 'modal closes cleanly');
});
