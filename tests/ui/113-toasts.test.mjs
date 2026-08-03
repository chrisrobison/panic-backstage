// Toast notifications (pb-toast-stack in public/assets/core.js).
//
// Error toasts are sticky — they stay up until the reader dismisses them,
// since an error is usually the message you most need to finish reading.
// Every other tone still auto-dismisses on a timer.
import { test, assert } from './harness.mjs';

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

test('an error toast stays up instead of auto-dismissing', async (page) => {
  await page.goto('#dashboard');
  await page.until(`document.querySelector('pb-toast-stack')`);
  await page.eval(publishToast('error', 'PB UI TEST — sticky error'));

  assert.ok(await page.until(`document.querySelector('.toast.error')`), 'error toast renders');
  assert.includes(await page.text('.toast.error .toast-message'), 'PB UI TEST — sticky error', 'toast shows the message');

  // Well past the 6.5s auto-dismiss window used by the other tones.
  await page.eval('new Promise((r) => setTimeout(() => r(true), 8000))');
  assert.ok(await page.exists('.toast.error'), 'error toast is still on screen after the auto-dismiss window');
});

test('the close button dismisses a sticky error toast', async (page) => {
  await page.until(`document.querySelector('.toast.error .toast-close')`);
  await page.click('.toast.error .toast-close');
  assert.ok(
    await page.until(`!document.querySelector('.toast.error')`),
    'error toast is gone after clicking its close button',
  );
});

test('non-error toasts still auto-dismiss', async (page) => {
  await page.eval(publishToast('info', 'PB UI TEST — transient info'));
  assert.ok(await page.until(`document.querySelector('.toast')`), 'info toast renders');
  assert.notOk(await page.exists('.toast.error'), 'info toast is not styled as an error');
  assert.ok(
    await page.until(`!document.querySelector('.toast')`, 12000),
    'info toast clears itself without a click',
  );
});
