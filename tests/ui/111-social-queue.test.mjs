// Social Queue (Panic Promote extended with the spec's Draft -> ... ->
// Archived workflow) — public/assets/promote.js. Non-destructive: only opens
// the "New Post" editor to inspect the status dropdown and cancels out,
// never actually creates/edits/approves/deletes a post. The full write-path
// lifecycle (approve -> content-changing edit reverts to changes_requested
// -> re-approve -> awaiting_manual_publish creates a Tasks-app task ->
// mark-published) is covered by a live curl-based verification against a
// throwaway post (created and cleaned up) rather than here, since that
// lifecycle actually mutates data.
import { test, assert } from './harness.mjs';

const EVENT_ID = process.env.UI_EVENT_ID || '641027';

test('Promote event page renders the Social Queue post list', async (page) => {
  await page.goto(`#promote-event-${EVENT_ID}`);
  assert.ok(
    await page.until(`document.querySelector('pb-promote-post-list')`),
    'pb-promote-post-list mounts on the promote event page',
  );
  assert.ok(await page.exists('[data-new-post]'), '"New Post" button is present');
});

test('New Post editor exposes the full Social Queue status workflow', async (page) => {
  await page.goto(`#promote-event-${EVENT_ID}`);
  await page.until(`document.querySelector('[data-new-post]')`);
  await page.click('[data-new-post]');
  assert.ok(
    await page.until(`document.querySelector('[data-post-form] select[name="status"]')`),
    'post editor modal opens with a status select',
  );
  const optionValues = await page.eval(`
    Array.from(document.querySelectorAll('[data-post-form] select[name="status"] option')).map((o) => o.value)
  `);
  for (const expected of [
    'draft', 'needs_assets', 'ready_for_review', 'changes_requested', 'approved',
    'scheduled', 'awaiting_manual_publish', 'published', 'verified', 'archived',
  ]) {
    assert.ok(optionValues.includes(expected), `status dropdown includes "${expected}"`);
  }
  // Non-destructive: close without submitting.
  await page.click('[data-post-form] [data-close]');
});
