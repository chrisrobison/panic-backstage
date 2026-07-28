// Booking Inbox (incoming-ui.png) — public/assets/inbox/*.js.
// Non-destructive: only asserts client-side render/navigation behavior
// (list renders, clicking a row loads the workspace/detail panel, saved-
// view switching, mobile collapse) without POSTing/PATCHing any lead.
import { test, assert } from './harness.mjs';

test('Booking Inbox is reachable from the "inbox-unassigned" nav route and renders the queue', async (page) => {
  await page.goto('#inbox-unassigned');
  assert.ok(
    await page.until(`document.querySelector('pb-inbox-app') && document.querySelectorAll('.ib-list-item').length > 0`),
    'pb-inbox-app mounts and the queue renders at least one row',
  );
  assert.ok(await page.exists('.ib-list-view-select'), 'saved-view switcher is present');
  assert.ok(await page.exists('.ib-list-search input'), 'search input is present');
});

test('Clicking a queue row opens the workspace with a matching header and the detail panel', async (page) => {
  await page.goto('#inbox-unassigned');
  await page.until(`document.querySelectorAll('.ib-list-item').length > 0`);
  await page.click('.ib-list-item');
  assert.ok(
    await page.until(`document.querySelector('.ib-workspace-title-row h1')?.textContent?.trim().length > 0`),
    'workspace header renders a name once a row is selected',
  );
  assert.ok(await page.exists('.ib-tabs a.active'), 'a tab is active (Conversation by default)');
  assert.ok(await page.exists('.ib-status-bar select'), 'status dropdown is present');
  assert.ok(await page.exists('.ib-action-bar [data-action="onboard"]'), 'Onboard Lead action is present');
  assert.ok(await page.exists('.ib-composer [data-subject]'), 'reply composer includes an email subject');
  assert.ok(await page.exists('.ib-composer [data-attach]'), 'reply composer includes attachment upload');
  assert.ok(await page.exists('.ib-composer [data-template]'), 'reply composer includes template insertion');
  assert.ok(await page.exists('.ib-action-bar [data-action="task"]'), 'linked task action is present');
  assert.ok(await page.exists('.ib-action-bar [data-action="reassign"]'), 'reassignment action is present');
  assert.ok(await page.exists('.ib-detail'), 'detail panel renders');
});

test('The action bar promotes Onboard as primary and tucks Decline/Archive/Reassign behind a "More" menu', async (page) => {
  await page.goto('#inbox-unassigned');
  await page.until(`document.querySelectorAll('.ib-list-item').length > 0`);
  await page.click('.ib-list-item');
  await page.until(`document.querySelector('.ib-action-bar [data-action="onboard"]')`);

  assert.ok(await page.exists('.ib-action-bar .button.primary-green[data-action="onboard"]'), 'Onboard Lead renders with primary (green) styling');
  assert.ok(
    await page.eval(`!!document.querySelector('.ib-overflow-menu[data-overflow-menu][hidden]')`),
    'the overflow menu starts closed',
  );
  assert.ok(
    await page.eval(`!!document.querySelector('.ib-overflow-menu [data-action="decline"]') && !!document.querySelector('.ib-overflow-menu [data-action="archive"]')`),
    'Decline and Archive live inside the overflow menu, not as top-level bar buttons',
  );

  await page.click('[data-action="more-menu"]');
  assert.ok(
    await page.until(`!document.querySelector('.ib-overflow-menu')?.hasAttribute('hidden')`),
    'clicking "More" opens the overflow menu',
  );
  assert.ok(await page.exists('.ib-overflow-menu [data-action="reassign"]'), 'Reassign is reachable from the open overflow menu');

  await page.eval(`document.body.click()`);
  assert.ok(
    await page.until(`document.querySelector('.ib-overflow-menu')?.hasAttribute('hidden')`),
    'clicking outside the menu closes it again',
  );
});

test('Conversation bodies render safe, clickable web links', async (page) => {
  await page.goto('#inbox-all');
  assert.ok(
    await page.until(`customElements.get('pb-inbox-conversation')`),
    'conversation component is registered',
  );
  const rendered = await page.eval(`(() => {
    const component = document.createElement('pb-inbox-conversation');
    const host = document.createElement('div');
    host.innerHTML = component.messageHtml({
      direction: 'inbound',
      channel: 'email',
      created_at: '2026-07-26 12:00:00',
      body_text: 'Visit https://example.com/show?ref=inbox&day=2. Also www.themab.org/calendar! <img src=x onerror=alert(1)>',
    });
    const links = [...host.querySelectorAll('.ib-msg-body a')];
    return {
      links: links.map((link) => ({
        href: link.getAttribute('href'),
        text: link.textContent,
        target: link.target,
        rel: link.rel,
      })),
      bodyText: host.querySelector('.ib-msg-body')?.textContent,
      injectedImages: host.querySelectorAll('img').length,
    };
  })()`);
  assert.equal(rendered.links.length, 2, 'http and www URLs are both linked');
  assert.equal(rendered.links[0].href, 'https://example.com/show?ref=inbox&day=2', 'query string remains part of the URL');
  assert.equal(rendered.links[0].text, 'https://example.com/show?ref=inbox&day=2', 'sentence punctuation is not included in the link');
  assert.equal(rendered.links[1].href, 'https://www.themab.org/calendar', 'www URLs receive a safe https scheme');
  assert.equal(rendered.links[0].target, '_blank', 'links open in a new tab');
  assert.includes(rendered.links[0].rel, 'noopener', 'links cannot access the opener window');
  assert.equal(rendered.injectedImages, 0, 'message HTML remains escaped');
  assert.includes(rendered.bodyText, '<img src=x onerror=alert(1)>', 'escaped markup remains visible as text');
});

test('HTML email bodies render in a tracking-safe sandbox', async (page) => {
  await page.goto('#inbox-all');
  await page.until(`customElements.get('pb-inbox-conversation')`);
  const rendered = await page.eval(`(() => {
    const component = document.createElement('pb-inbox-conversation');
    const host = document.createElement('div');
    host.innerHTML = component.messageHtml({
      id: 999,
      direction: 'inbound',
      channel: 'email',
      from_name: 'Safety Test',
      created_at: '2026-07-26 12:00:00',
      body_text: 'Plain fallback',
      body_html: '<style>p{font-weight:bold}</style><p onclick="alert(1)">Formatted <a href="https://example.com/show">link</a></p><script>alert(1)</script><form action="https://bad.example"><input></form><img src="https://tracker.example/pixel.gif" alt="Poster"><a href="javascript:alert(1)">unsafe</a>',
    });
    const frame = host.querySelector('.ib-email-frame');
    const email = new DOMParser().parseFromString(frame.getAttribute('srcdoc'), 'text/html');
    const links = [...email.querySelectorAll('a')];
    return {
      sandbox: frame.getAttribute('sandbox'),
      referrerPolicy: frame.getAttribute('referrerpolicy'),
      scripts: email.querySelectorAll('script').length,
      forms: email.querySelectorAll('form,input').length,
      remoteImages: [...email.querySelectorAll('img')].filter((img) => /^https?:/i.test(img.src)).length,
      placeholder: email.querySelector('.email-image-placeholder')?.textContent,
      eventHandlers: email.querySelectorAll('[onclick],[onerror],[onload]').length,
      safeHref: links[0]?.getAttribute('href'),
      safeTarget: links[0]?.target,
      unsafeHref: links[1]?.getAttribute('href'),
      hasCsp: !!email.querySelector('meta[http-equiv="Content-Security-Policy"]'),
    };
  })()`);
  assert.notOk(rendered.sandbox.includes('allow-scripts'), 'email scripts are disabled by the iframe sandbox');
  assert.notOk(rendered.sandbox.includes('allow-forms'), 'email forms are disabled by the iframe sandbox');
  assert.equal(rendered.referrerPolicy, 'no-referrer', 'email navigation does not leak the inbox URL');
  assert.equal(rendered.scripts, 0, 'script elements are removed');
  assert.equal(rendered.forms, 0, 'form controls are removed');
  assert.equal(rendered.remoteImages, 0, 'remote tracking images are blocked');
  assert.equal(rendered.placeholder, '[Image: Poster]', 'blocked images retain useful alt text');
  assert.equal(rendered.eventHandlers, 0, 'inline event handlers are removed');
  assert.equal(rendered.safeHref, 'https://example.com/show', 'safe HTML links are preserved');
  assert.equal(rendered.safeTarget, '_blank', 'HTML links open outside the sandbox');
  assert.equal(rendered.unsafeHref, null, 'javascript links are disabled');
  assert.ok(rendered.hasCsp, 'HTML email document has a restrictive content security policy');
});

test('Reason-required Booking Inbox actions use an in-app confirmation dialog', async (page) => {
  await page.goto('#inbox-unassigned');
  await page.until(`document.querySelectorAll('.ib-list-item').length > 0`);
  await page.click('.ib-list-item');
  await page.until(`document.querySelector('[data-action="decline"]')`);
  await page.click('[data-action="decline"]');
  assert.ok(await page.until(`document.querySelector('[data-status-reason-form] textarea[required]')`), 'decline opens a required reason form');
  await page.click('.modal-backdrop [data-close]');
  assert.notOk(await page.exists('.modal-backdrop'), 'closing the reason form makes no change');
});

test('Reply templates can be selected without leaving the inquiry workspace', async (page) => {
  await page.goto('#inbox-unassigned');
  await page.until(`document.querySelectorAll('.ib-list-item').length > 0`);
  await page.click('.ib-list-item');
  await page.until(`document.querySelector('.ib-composer [data-template]')`);
  await page.click('.ib-composer [data-template]');
  assert.ok(await page.until(`document.querySelector('[data-template-form] select[name="template_id"] option')`), 'template picker contains configured templates');
  await page.click('.modal-backdrop [data-close]');
});

test('Managers can open the quarantined-mail review queue', async (page) => {
  await page.goto('#inbox-unassigned');
  await page.until(`document.querySelector('[data-quarantine]')`);
  await page.click('[data-quarantine]');
  assert.ok(
    await page.until(`document.querySelector('.modal-backdrop .section-head h2')?.textContent === 'Quarantined Mail'`),
    'quarantine review opens in an in-app dialog',
  );
  await page.click('.modal-backdrop [data-close]');
});

test('Managers can open an audited classification-correction form', async (page) => {
  await page.goto('#inbox-unassigned');
  await page.until(`document.querySelectorAll('.ib-list-item').length > 0`);
  await page.click('.ib-list-item');
  await page.until(`document.querySelector('[data-edit-classification]')`);
  await page.click('[data-edit-classification]');
  assert.ok(
    await page.until(`document.querySelector('[data-classification-form] input[name="event_category"]')`),
    'classification correction fields open in an in-app dialog',
  );
  await page.click('.modal-backdrop [data-close]');
});

test('Switching the saved-view dropdown reloads the queue', async (page) => {
  await page.goto('#inbox-unassigned');
  await page.until(`document.querySelectorAll('.ib-list-item').length > 0`);
  await page.eval(`
    const sel = document.querySelector('.ib-list-view-select');
    sel.value = 'archived';
    sel.dispatchEvent(new Event('change', { bubbles: true }));
  `);
  assert.ok(
    await page.until(`document.querySelector('.ib-list-head-row h2')?.textContent?.includes('Inquir')`),
    'list header re-renders after a view change (does not error out)',
  );
});

test('The inquiry list pane is actually scrollable (not clipped to its content height)', async (page) => {
  await page.goto('#inbox-all');
  await page.until(`document.querySelectorAll('.ib-list-item').length > 0`);
  const info = await page.eval(`(() => {
    const el = document.querySelector('.ib-list-scroll');
    if (!el) return null;
    return { scrollHeight: el.scrollHeight, clientHeight: el.clientHeight, overflowY: getComputedStyle(el).overflowY };
  })()`);
  assert.ok(info, '.ib-list-scroll is present');
  assert.equal(info.overflowY, 'auto', '.ib-list-scroll has overflow-y: auto');
  // A flexbox regression (missing min-height:0 anywhere in the .ib-body →
  // .ib-list → pb-inbox-list → .ib-list-scroll chain) makes this element grow
  // to fit every row instead of being clipped to .ib-list's bounded height —
  // scrollHeight then always equals clientHeight and nothing is scrollable.
  assert.ok(info.clientHeight > 0, '.ib-list-scroll has a real (non-zero) rendered height');
  if (info.scrollHeight > info.clientHeight) {
    const moved = await page.eval(`(() => {
      const el = document.querySelector('.ib-list-scroll');
      el.scrollTop = 0;
      el.scrollTop = el.scrollHeight;
      return el.scrollTop > 0;
    })()`);
    assert.ok(moved, 'setting .ib-list-scroll.scrollTop actually scrolls it');
  }
});

test('The conversation pane is actually scrollable (not clipped to its content height)', async (page) => {
  await page.goto('#inbox-all');
  await page.until(`document.querySelectorAll('.ib-list-item').length > 0`);
  await page.click('.ib-list-item');
  await page.until(`document.querySelector('.ib-thread')`);
  const info = await page.eval(`(() => {
    const el = document.querySelector('.ib-thread');
    if (!el) return null;
    return { scrollHeight: el.scrollHeight, clientHeight: el.clientHeight, overflowY: getComputedStyle(el).overflowY };
  })()`);
  assert.ok(info, '.ib-thread is present');
  assert.equal(info.overflowY, 'auto', '.ib-thread has overflow-y: auto');
  assert.ok(info.clientHeight > 0, '.ib-thread has a real (non-zero) rendered height');
  // .ib-thread sits behind two unstyled custom-element hops
  // (pb-inbox-workspace, pb-inbox-conversation) that must each pass the
  // bounded height through (flex + min-height:0) — if either one silently
  // reverts to sizing itself by content, .ib-thread stops being clipped and
  // this ratio assertion is what would catch it.
  assert.ok(info.clientHeight < 700, '.ib-thread is bounded to its pane, not stretched to the full conversation height');
});

test('The Event Info tab shows event-specific fields distinct from the Details tab', async (page) => {
  await page.goto('#inbox-all');
  await page.until(`document.querySelectorAll('.ib-list-item').length > 0`);
  await page.click('.ib-list-item');
  await page.until(`document.querySelector('.ib-tabs a.active')`);

  await page.click('[data-tab="details"]');
  const detailsHtml = await page.eval(`document.querySelector('[data-tab-body]')?.innerHTML || ''`);

  await page.click('[data-tab="event-info"]');
  assert.ok(
    await page.until(`document.querySelector('.ib-tabs a.active')?.dataset.tab === 'event-info'`),
    'Event Info tab becomes the active tab',
  );
  const eventInfoHtml = await page.eval(`document.querySelector('[data-tab-body]')?.innerHTML || ''`);
  assert.ok(eventInfoHtml.length > 0, 'Event Info tab renders content');
  assert.ok(eventInfoHtml !== detailsHtml, 'Event Info tab shows different content than the Details tab');
});

test('Booking Inbox collapses to a single-pane, list-first layout on a narrow viewport', async (page) => {
  await page.setViewport(420, 900, true);
  try {
    // The harness shares one page/hash across every test in the run, and
    // page.goto() is just `location.hash = ...` — a no-op (no hashchange,
    // no remount) if the hash is already 'inbox-unassigned' from an earlier
    // test. The previous test in this file leaves the saved-view switched
    // to "archived" (0 leads), so bounce through another route first to
    // force a real remount with a clean, known-good view.
    await page.goto('#dashboard');
    await page.goto('#inbox-unassigned');
    assert.ok(
      await page.until(`document.querySelectorAll('.ib-list-item').length > 0`),
      'queue still renders on a narrow viewport',
    );
    assert.ok(
      await page.until(`document.querySelector('.ib-body')?.classList.contains('ib-body-list-active')`),
      'the list (not the workspace) is the first screen on mobile',
    );
    await page.click('.ib-list-item');
    assert.ok(
      await page.until(`!document.querySelector('.ib-body')?.classList.contains('ib-body-list-active')`),
      'tapping a row switches to the workspace screen',
    );
    assert.ok(await page.exists('.ib-back-to-list'), '"Back to inquiries" control is visible once a lead is open');
  } finally {
    await page.setViewport(1440, 900, false);
  }
});
