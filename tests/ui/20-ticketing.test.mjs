// Ticketing panel: the mode picker hide/show behaviour (the regression we
// fixed) and the ticket-types reveal toggle. All reactive — nothing is saved.
import { test, assert } from './harness.mjs';

async function openTicketing(page) {
  await page.openEvent();
  return page.until(`document.querySelector('#ticketing') && document.querySelector('#ticketing').children.length>0`, 8000);
}

test('external ticket URL fields only show in external mode', async (page) => {
  if (!page.hasEvent) return page.skip(`event ${page.eventId} not found`);
  if (!(await openTicketing(page))) return page.skip('ticketing panel not present (no manage_ticketing?)');
  if (!(await page.exists('#ticketing input[name="ticketing_mode"][value="external"]'))) {
    return page.skip('mode picker not editable for this user');
  }

  await page.selectRadio('#ticketing input[name="ticketing_mode"][value="external"]');
  assert.ok(await page.visible('#ticketing [data-mode-config="external"]'), 'external URL fields show when External is selected');

  await page.selectRadio('#ticketing input[name="ticketing_mode"][value="internal"]');
  assert.notOk(await page.visible('#ticketing [data-mode-config="external"]'), 'external URL fields hide when In-house is selected');
});

test('ticket-types "+" reveals the add-type form', async (page) => {
  if (!page.hasEvent) return page.skip(`event ${page.eventId} not found`);
  if (!(await openTicketing(page))) return page.skip('ticketing panel not present');
  // Ticket types only render in saved in-house mode.
  if (!(await page.exists('#ticketing form[data-form="tier"]'))) return page.skip('event is not in in-house mode');

  assert.notOk(await page.visible('#ticketing form[data-form="tier"]'), 'add-type form hidden initially');
  await page.click(`#ticketing [data-add-target='form[data-form="tier"]']`);
  assert.ok(await page.visible('#ticketing form[data-form="tier"]'), 'add-type form revealed after +');
});
