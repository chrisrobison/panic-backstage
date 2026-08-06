// Toast notifications (pb-toast-stack in public/assets/core.js).
//
// Error toasts are sticky — they stay up until the reader dismisses them,
// since an error is usually the message you most need to finish reading.
// Every other tone still auto-dismisses on a timer.
//
// The toast stack is global and long-lived: app.js renders it once into the
// shell, and it stays mounted for the whole suite. It also subscribes to
// 'api.error', so any failed request from any earlier test file leaves a
// sticky error toast sitting in the DOM forever. These tests therefore reset
// the stack on entry and scope every assertion to their own message, rather
// than to a bare '.toast' / '.toast.error' first match that would pick up
// somebody else's toast.
import { test, assert } from './harness.mjs';

const STICKY = 'PB UI TEST — sticky error';
const DISMISS = 'PB UI TEST — dismiss me';
const TRANSIENT = 'PB UI TEST — transient info';

// Publishes a toast through the real event bus the app uses. core.js is
// loaded as a module, so reach it through a dynamic import of the same URL
// the app imported (the module is already evaluated — this is a cache hit,
// not a second copy of the component registry).
const publishToast = (tone, message) => `
  (async () => {
    const core = await import('./assets/core.js');
    core.publish('toast.show', { tone: ${JSON.stringify(tone)}, message: ${JSON.stringify(message)} });
    return true;
  })()
`;

// Empty the shared stack so leftovers from earlier tests can't be mistaken
// for ours. Pending auto-dismiss timers for the cleared toasts are harmless:
// dismiss() filters by id and bails when nothing matches.
const resetStack = `
  (() => {
    const stack = document.querySelector('pb-toast-stack');
    if (!stack || !Array.isArray(stack.items)) return false;
    stack.items = [];
    stack.render();
    return true;
  })()
`;

// How many toasts currently carry `message`, optionally narrowed to a tone
// class (e.g. '.error'). Matching on the message is what keeps these tests
// independent of whatever else the app has toasted.
const countToasts = (message, toneClass = '') => `([...document.querySelectorAll('.toast${toneClass}')]
    .filter((el) => (el.querySelector('.toast-message')?.textContent || '').includes(${JSON.stringify(message)}))
    .length)`;

// Click the close button belonging to our toast specifically — the stack may
// hold unrelated toasts, so the first .toast-close is not necessarily ours.
const closeToast = (message) => `
  (() => {
    const toast = [...document.querySelectorAll('.toast')]
      .find((el) => (el.querySelector('.toast-message')?.textContent || '').includes(${JSON.stringify(message)}));
    if (!toast) return false;
    toast.querySelector('.toast-close').click();
    return true;
  })()
`;

test('an error toast stays up instead of auto-dismissing', async (page) => {
  await page.goto('#dashboard');
  await page.until(`document.querySelector('pb-toast-stack')`);
  assert.ok(await page.eval(resetStack), 'toast stack is mounted and starts empty');
  await page.eval(publishToast('error', STICKY));

  // Counting by message covers both "it rendered" and "it shows the message".
  assert.ok(await page.until(`${countToasts(STICKY, '.error')} === 1`), 'error toast renders with its message');

  // Well past the 6.5s auto-dismiss window used by the other tones.
  await page.eval('new Promise((r) => setTimeout(() => r(true), 8000))');
  assert.equal(
    await page.eval(countToasts(STICKY, '.error')),
    1,
    'error toast is still on screen after the auto-dismiss window',
  );
});

test('the close button dismisses a sticky error toast', async (page) => {
  // Self-contained: publishes its own toast rather than inheriting the one
  // above, so a failure up there doesn't cascade into a failure down here.
  assert.ok(await page.eval(resetStack), 'toast stack is mounted and starts empty');
  await page.eval(publishToast('error', DISMISS));
  assert.ok(await page.until(`${countToasts(DISMISS, '.error')} === 1`), 'error toast renders');

  assert.ok(await page.eval(closeToast(DISMISS)), 'our toast has a close button');
  assert.ok(
    await page.until(`${countToasts(DISMISS, '.error')} === 0`),
    'error toast is gone after clicking its close button',
  );
});

test('non-error toasts still auto-dismiss', async (page) => {
  assert.ok(await page.eval(resetStack), 'toast stack is mounted and starts empty');
  await page.eval(publishToast('info', TRANSIENT));
  assert.ok(await page.until(`${countToasts(TRANSIENT)} === 1`), 'info toast renders');
  assert.equal(await page.eval(countToasts(TRANSIENT, '.error')), 0, 'info toast is not styled as an error');
  assert.ok(
    await page.until(`${countToasts(TRANSIENT)} === 0`, 12000),
    'info toast clears itself without a click',
  );
});
