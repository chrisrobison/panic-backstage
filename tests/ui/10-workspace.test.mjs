// Event workspace shell + the editable section panels' reveal/collapse forms.
import { test, assert } from './harness.mjs';

test('event workspace mounts with tabs and core panels', async (page) => {
  if (!page.hasEvent) return page.skip(`event ${page.eventId} not found`);
  await page.openEvent();
  assert.ok(await page.exists('pb-event-workspace .workspace-tabs'), 'workspace tabs render');
  assert.atLeast(await page.count('.workspace-tabs a'), 8, 'has the expected tab set');
  assert.ok(await page.exists('#details'), 'Details panel present');
  assert.ok(await page.exists('#tasks'), 'Tasks panel present');
  assert.ok(await page.exists('#assets'), 'Assets panel present');
});

test('event workspace exposes a Promote action for the event campaign', async (page) => {
  if (!page.hasEvent) return page.skip(`event ${page.eventId} not found`);
  await page.openEvent();
  const selector = '.event-actions a[href="#promote-event-' + page.eventId + '"]';
  assert.ok(await page.exists(selector), 'Promote action links to the event campaign workspace');
});

test('event workspace opens an editable clone dialog without copying operational records', async (page) => {
  if (!page.hasEvent) return page.skip(`event ${page.eventId} not found`);
  await page.openEvent();
  if (!(await page.exists('[data-clone-event]'))) return page.skip('no create_events capability for this user');
  await page.click('[data-clone-event]');
  assert.ok(await page.until(`document.querySelector('[data-clone-event-form]')`), 'clone dialog opens');
  assert.ok(await page.eval(`document.querySelector('[data-clone-event-form] [name="title"]').value.length > 0`), 'event name is pre-filled');
  assert.ok(await page.eval(`document.querySelector('[data-clone-event-form] [name="date"]').value.length === 10`), 'a new date is editable and pre-filled');
  assert.includes(await page.eval(`document.querySelector('[data-clone-event-form]').textContent`), 'Contracts, payments, ticket sales', 'dialog explains what is not copied');
  await page.click('[data-cancel-clone]');
  assert.ok(await page.until(`!document.querySelector('[data-clone-event-form]')`), 'clone dialog closes without creating anything');
});

test('event Promote action opens campaign workspace or create prompt', async (page) => {
  if (!page.hasEvent) return page.skip(`event ${page.eventId} not found`);
  await page.openEvent();
  await page.click('.event-actions a[href="#promote-event-' + page.eventId + '"]');
  const ok = await page.until(`document.querySelector('pb-promote-campaign-overview [data-create-campaign], pb-promote-campaign-overview .promote-overview-layout')`);
  assert.ok(ok, 'Promote route renders instead of an error for events without campaigns');
  assert.notOk(await page.exists('pb-promote-campaign-overview .error-text'), 'Promote route does not show the generic API error state');
});

test('Readiness card keeps all items on a single row', async (page) => {
  if (!page.hasEvent) return page.skip(`event ${page.eventId} not found`);
  await page.openEvent();
  await page.until(`document.querySelector('.health-row .health-item')`);
  const itemCount = await page.count('.health-item');
  assert.atLeast(itemCount, 1, 'readiness row has at least one item');
  // The grid's column count now tracks the actual item count (see
  // --readiness-cols), so the row should lay out as a single grid track no
  // matter how many items the server sends. Before that fix, a hardcoded
  // 6-column template would push a 7th item onto its own row with unused
  // whitespace beside it. gridTemplateRows reporting more than one length
  // means the browser actually generated a second row.
  const rowTracks = await page.eval(
    `getComputedStyle(document.querySelector('.health-row')).gridTemplateRows.trim().split(/\\s+/).length`,
  );
  assert.equal(rowTracks, 1, 'readiness items all sit on a single grid row');
});

test('Event Details form drops ticket + contract fields (now in their own sections)', async (page) => {
  if (!page.hasEvent) return page.skip(`event ${page.eventId} not found`);
  await page.openEvent();
  await page.until(`document.querySelector('#details form')`);
  // These moved to the dedicated Ticketing / Contracts sections below.
  assert.notOk(await page.exists('#details [name="ticket_url"]'), 'no Ticket URL field in Details');
  assert.notOk(await page.exists('#details [name="ticket_system"]'), 'no Ticket system field in Details');
  assert.notOk(await page.exists('#details [name="contract_url"]'), 'no Contract link field in Details');
  // Core details still present.
  assert.ok(await page.exists('#details [name="title"]'), 'title field still present');
  assert.ok(await page.exists('#details [name="load_in_time"]'), 'Load In is available for the room occupancy start');
  assert.ok(await page.exists('#details [name="load_out_time"]'), 'Load Out is available for the room occupancy end');
});

test('a panel "+" reveals its hidden add form (Tasks)', async (page) => {
  if (!page.hasEvent) return page.skip(`event ${page.eventId} not found`);
  await page.openEvent();
  if (!(await page.exists('#tasks [data-add]'))) return page.skip('no manage_tasks capability for this user');
  // Sections now live behind real tabs — only the active tab's section is
  // visible — so switch to Tasks before asserting visibility of anything inside it.
  await page.click('.workspace-tabs a[data-tab="tasks"]');
  await page.until(`document.querySelector('#tasks form[data-add-form]')`);
  assert.notOk(await page.visible('#tasks form[data-add-form]'), 'add form hidden before clicking +');
  await page.click('#tasks [data-add]');
  assert.ok(await page.visible('#tasks form[data-add-form]'), 'add form revealed after clicking +');
});

test('Invites add form is collapsed behind a "+" toggle', async (page) => {
  if (!page.hasEvent) return page.skip(`event ${page.eventId} not found`);
  await page.openEvent();
  if (!(await page.exists('#invites'))) return page.skip('invites panel not available for this user');
  // Switch to the Invites tab first — sections outside the active tab are display:none.
  await page.click('.workspace-tabs a[data-tab="invites"]');
  await page.until(`document.querySelector('#invites form[data-add-form]')`);
  assert.notOk(await page.visible('#invites form[data-add-form]'), 'invite form hidden initially');
  await page.click('#invites [data-add]');
  assert.ok(await page.visible('#invites form[data-add-form]'), 'invite form revealed after +');
});
