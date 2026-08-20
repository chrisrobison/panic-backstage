/* Panic Backstage — door scanner page logic.
 *
 * Auth model: the scanner-link secret travels in the page URL (?token=...).
 * We never store it anywhere but memory; every call posts the scanner token
 * (+ optional PIN) to a /api/scan/* endpoint authenticated by that token
 * (NOT a JWT).
 *
 * Three modes, each covering one of the three people who show up at a door:
 *   Scan — they have a QR. Decode it (html5-qrcode, CDN) and POST
 *     /api/scan/redeem, rendering a big ADMIT / ALREADY-USED / INVALID state,
 *     debounced so one physical ticket isn't redeemed twice by consecutive
 *     frames.
 *   Sell — they have money and no ticket. POST /api/scan/sell, which logs the
 *     money AND admits the buyer in one step (no QR to scan back).
 *   Look up — they bought a ticket but can't produce it. POST
 *     /api/scan/tickets for the guest list, check ID against the name, then
 *     POST /api/scan/admit for that ticket id.
 * The last two are opt-in per scanner link (can_sell / can_lookup); the tabs
 * only exist when /api/scan/context says the link has them.
 */
(function () {
  'use strict';

  // ── resolve API base from the page location (handles APP_BASE_PATH installs) ──
  function apiBase() {
    var path = window.location.pathname;
    var idx = path.lastIndexOf('/scanner.html');
    var prefix = idx >= 0 ? path.slice(0, idx) : path.replace(/\/[^/]*$/, '');
    return prefix.replace(/\/$/, '') + '/api';
  }
  var API = apiBase();

  var params = new URLSearchParams(window.location.search);
  var SCANNER_TOKEN = (params.get('token') || params.get('t') || '').trim();

  var els = {
    result: document.getElementById('result'),
    pinWrap: document.getElementById('pin-wrap'),
    pin: document.getElementById('pin'),
    manual: document.getElementById('manual'),
    manualGo: document.getElementById('manual-go'),
    hint: document.getElementById('hint'),
    eventLabel: document.getElementById('event-label'),
    app: document.getElementById('app'),
    // mode switch + sell panel
    modes: document.getElementById('modes'),
    modeScan: document.getElementById('mode-scan'),
    modeSell: document.getElementById('mode-sell'),
    modeLook: document.getElementById('mode-look'),
    scanView: document.getElementById('scan-view'),
    scanControls: document.getElementById('scan-controls'),
    sellView: document.getElementById('sell-view'),
    // look-up mode
    lookView: document.getElementById('look-view'),
    lookQ: document.getElementById('look-q'),
    lookRefresh: document.getElementById('look-refresh'),
    guests: document.getElementById('guests'),
    lookHint: document.getElementById('look-hint'),
    tiers: document.getElementById('tiers'),
    qtyN: document.getElementById('qty-n'),
    qtyUp: document.getElementById('qty-up'),
    qtyDown: document.getElementById('qty-down'),
    pays: document.getElementById('pays'),
    sellName: document.getElementById('sell-name'),
    sellEmail: document.getElementById('sell-email'),
    sellTotal: document.getElementById('sell-total'),
    sellGo: document.getElementById('sell-go'),
    sellHint: document.getElementById('sell-hint'),
    // self-serve card purchase
    buyBar: document.getElementById('buy-bar'),
    buyBtn: document.getElementById('buy-btn'),
    buyOverlay: document.getElementById('buy-overlay'),
    buyQr: document.getElementById('buy-qr'),
    buyEvent: document.getElementById('buy-event'),
    buyClose: document.getElementById('buy-close')
  };

  if (!SCANNER_TOKEN) {
    fatal('Missing scanner token', 'Open this page using the full scanner link generated for the event.');
    return;
  }

  // Always offer a PIN field (it is simply ignored server-side when the link
  // has none). Keeps the page usable when the link is PIN-protected.
  els.pinWrap.classList.add('show');

  // ── result rendering ─────────────────────────────────────────────────────────
  var CLASSES = ['idle', 'admit', 'used', 'invalid'];
  function setResult(kind, stateText, holder, meta) {
    CLASSES.forEach(function (c) { els.result.classList.remove(c); });
    els.result.classList.add(kind);
    els.result.innerHTML = '';
    var st = document.createElement('div');
    st.className = 'state';
    st.textContent = stateText;
    els.result.appendChild(st);
    if (holder) {
      var h = document.createElement('div');
      h.className = 'holder';
      h.textContent = holder;
      els.result.appendChild(h);
    }
    if (meta) {
      var m = document.createElement('div');
      m.className = 'meta';
      m.textContent = meta;
      els.result.appendChild(m);
    }
  }

  function render(data, httpOk) {
    if (!httpOk) {
      setResult('invalid', 'Scanner Error', null, (data && data.error) || 'Could not reach the server.');
      return;
    }
    var result = data.result;
    var holder = data.holder_name || null;
    var tierBits = [];
    if (data.tier) tierBits.push(data.tier);
    if (data.ticket_code) tierBits.push(data.ticket_code);
    var meta = tierBits.join(' · ') || null;

    switch (result) {
      case 'admitted':
        beep(true);
        setResult('admit', 'Admit', holder || 'Ticket valid', meta);
        break;
      case 'already_redeemed':
        beep(false);
        setResult('used', 'Already Used', holder, meta || 'This ticket was already scanned.');
        break;
      case 'void':
        beep(false);
        setResult('used', 'Voided', holder, meta || 'This ticket has been voided.');
        break;
      case 'wrong_event':
        beep(false);
        setResult('invalid', 'Wrong Event', holder, 'This ticket is for a different event.');
        break;
      case 'not_found':
      default:
        beep(false);
        setResult('invalid', 'Invalid', null, 'Ticket not recognized.');
        break;
    }
  }

  // ── redeem call ──────────────────────────────────────────────────────────────
  var inFlight = false;
  function redeem(ticketToken) {
    if (inFlight || !ticketToken) return;
    inFlight = true;
    setResult('idle', 'Checking…', null, null);

    fetch(API + '/scan/redeem', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        scanner_token: SCANNER_TOKEN,
        pin: (els.pin.value || '').trim(),
        ticket_token: ticketToken
      })
    })
      .then(function (r) {
        return r.json().catch(function () { return {}; }).then(function (j) {
          return { ok: r.ok, status: r.status, body: j };
        });
      })
      .then(function (res) {
        if (res.status === 401) {
          // Scanner-link / PIN problem — surface it distinctly.
          setResult('invalid', 'Locked', null, (res.body && res.body.error) || 'Scanner link rejected.');
          if (res.body && /pin/i.test(res.body.error || '')) els.pin.focus();
          return;
        }
        if (res.body && (res.body.event_code || res.body.event_id) && !els.eventLabel.textContent) {
          els.eventLabel.textContent = res.body.event_code || ('Event #' + res.body.event_id);
        }
        render(res.body, res.ok);
      })
      .catch(function () {
        render({ error: 'Network error.' }, false);
      })
      .finally(function () {
        // brief cooldown so the same QR frame-stream can't double-submit
        setTimeout(function () { inFlight = false; }, 1200);
      });
  }

  // ── decode debounce (ignore the same payload for a moment) ────────────────────
  var lastPayload = '';
  var lastAt = 0;
  function onDecode(text) {
    // The camera keeps decoding while the sell panel is up (the video element is
    // merely hidden), so ignore frames unless we're actually in scan mode —
    // otherwise a ticket lying on the table gets silently redeemed mid-sale.
    if (mode !== 'scan') return;
    var now = Date.now();
    if (text === lastPayload && now - lastAt < 3000) return;
    lastPayload = text;
    lastAt = now;
    redeem(text);
  }

  // ── mode switching ───────────────────────────────────────────────────────────
  var mode = 'scan';
  function setMode(next) {
    // Fall back to scanning if the link was never granted the requested mode —
    // scan is the one capability every scanner link has by definition.
    if (next === 'sell' && !canSell) next = 'scan';
    if (next === 'look' && !canLookup) next = 'scan';
    mode = next;

    var tabs = [[els.modeScan, 'scan'], [els.modeSell, 'sell'], [els.modeLook, 'look']];
    tabs.forEach(function (pair) {
      var on = pair[1] === mode;
      pair[0].classList.toggle('on', on);
      pair[0].setAttribute('aria-selected', String(on));
    });

    els.scanView.hidden = mode !== 'scan';
    els.scanControls.hidden = mode !== 'scan';
    els.sellView.hidden = mode !== 'sell';
    els.lookView.hidden = mode !== 'look';

    // The result box is shared: scan verdicts, sale confirmations, and
    // no-QR admissions all render into it.
    if (mode === 'sell') setResult('idle', 'Ring up a walk-up sale', null, null);
    else if (mode === 'look') setResult('idle', 'Find a ticket by name', null, null);
    else setResult('idle', 'Point the camera at a ticket QR', null, null);

    // Entering look-up pulls a fresh list — someone may have been admitted on
    // another device since this one last looked.
    if (mode === 'look') loadGuests();
  }
  els.modeScan.addEventListener('click', function () { setMode('scan'); });
  els.modeSell.addEventListener('click', function () { setMode('sell'); });
  els.modeLook.addEventListener('click', function () { setMode('look'); });

  // ── sell state ───────────────────────────────────────────────────────────────
  var canSell = false;
  var tiers = [];
  var selectedTier = null;
  var qty = 1;
  var payMethod = 'cash';

  function money(cents, currency) {
    var amount = (cents / 100).toFixed(2);
    if ((currency || 'USD') === 'USD') return '$' + amount;
    return amount + ' ' + currency;
  }

  function renderTiers() {
    els.tiers.innerHTML = '';
    if (!tiers.length) {
      var p = document.createElement('p');
      p.className = 'hint';
      p.textContent = 'No ticket types are on sale for this event.';
      els.tiers.appendChild(p);
      return;
    }
    tiers.forEach(function (t) {
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'tier' + (selectedTier && selectedTier.id === t.id ? ' on' : '');
      if (t.available <= 0) b.disabled = true;

      var left = document.createElement('span');
      var nm = document.createElement('span');
      nm.className = 'nm';
      nm.textContent = t.name;
      var av = document.createElement('span');
      av.className = 'av';
      av.textContent = t.available > 0 ? t.available + ' left' : 'Sold out';
      left.appendChild(nm);
      left.appendChild(document.createElement('br'));
      left.appendChild(av);

      var pr = document.createElement('span');
      pr.className = 'pr';
      pr.textContent = money(t.price_cents, t.currency);

      b.appendChild(left);
      b.appendChild(pr);
      b.addEventListener('click', function () { selectTier(t); });
      els.tiers.appendChild(b);
    });
  }

  function selectTier(t) {
    selectedTier = t;
    // Never let the picker offer more than the tier actually has left.
    if (qty > t.available) qty = Math.max(1, t.available);
    renderTiers();
    updateTotal();
  }

  function updateTotal() {
    els.qtyN.textContent = String(qty);
    var cents = selectedTier ? selectedTier.price_cents * qty : 0;
    els.sellTotal.textContent = money(cents, selectedTier ? selectedTier.currency : 'USD');
    els.sellGo.disabled = !selectedTier || qty < 1 || selectedTier.available < qty;
    els.sellGo.textContent = selectedTier
      ? 'Complete Sale · ' + money(cents, selectedTier.currency)
      : 'Complete Sale';
  }

  els.qtyUp.addEventListener('click', function () {
    var max = selectedTier ? Math.min(20, selectedTier.available) : 20;
    if (qty < max) { qty++; updateTotal(); }
  });
  els.qtyDown.addEventListener('click', function () {
    if (qty > 1) { qty--; updateTotal(); }
  });

  els.pays.addEventListener('click', function (e) {
    var btn = e.target.closest('button[data-pay]');
    if (!btn) return;
    payMethod = btn.dataset.pay;
    Array.prototype.forEach.call(els.pays.querySelectorAll('button'), function (b) {
      b.classList.toggle('on', b === btn);
    });
  });

  // ── look up a purchased ticket, admit without a QR ───────────────────────────
  // For the guest who really did buy a ticket but cannot show it: dead phone,
  // buried email, ticket left on the kitchen table. Staff find them by name,
  // check their ID against it, and admit — the same issued -> redeemed
  // transition a scan performs, audited as a manual admission.
  var canLookup = false;
  var guests = [];
  var guestsLoaded = false;

  function loadGuests() {
    if (!canLookup) return Promise.resolve();
    els.lookHint.textContent = guestsLoaded ? 'Refreshing…' : 'Loading ticket list…';

    return fetch(API + '/scan/tickets', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        scanner_token: SCANNER_TOKEN,
        pin: (els.pin.value || '').trim()
      })
    })
      .then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { return { ok: r.ok, body: j }; }); })
      .then(function (res) {
        var b = res.body || {};
        if (!res.ok || b.result !== 'ok') {
          els.lookHint.textContent = b.error || b.message || 'Could not load the ticket list.';
          return;
        }
        guests = b.tickets || [];
        guestsLoaded = true;
        renderGuests();
      })
      .catch(function () {
        els.lookHint.textContent = 'Network error — could not load the ticket list.';
      });
  }

  // Filtering is client-side: the whole list is already here, and a door with
  // flaky wifi should not need a round trip per keystroke.
  function filteredGuests() {
    var q = (els.lookQ.value || '').trim().toLowerCase();
    if (!q) return guests;
    return guests.filter(function (g) {
      return (g.holder_name || '').toLowerCase().indexOf(q) >= 0 ||
             (g.holder_email || '').toLowerCase().indexOf(q) >= 0 ||
             (g.code || '').toLowerCase().indexOf(q) >= 0 ||
             (g.printed_number || '').toLowerCase().indexOf(q) >= 0;
    });
  }

  function renderGuests() {
    var list = filteredGuests();
    els.guests.innerHTML = '';

    if (!list.length) {
      els.lookHint.textContent = guests.length
        ? 'No ticket matches that search.'
        : 'No tickets have been sold for this event yet.';
      return;
    }
    els.lookHint.textContent = 'Check their ID against the name before admitting.';

    list.forEach(function (g) {
      var row = document.createElement('div');
      row.className = 'guest' + (g.status === 'redeemed' ? ' done' : '') + (g.status === 'void' ? ' voided' : '');
      row.setAttribute('data-ticket', g.id);

      var who = document.createElement('div');
      who.className = 'who';
      var nm = document.createElement('div');
      nm.className = 'nm';
      nm.textContent = g.holder_name || '(no name on ticket)';
      var sub = document.createElement('div');
      sub.className = 'sub';
      var bits = [g.tier];
      if (g.is_comp) bits.push('comp');
      if (g.holder_email) bits.push(g.holder_email);
      bits.push(g.printed_number ? ('#' + g.printed_number) : g.code);
      sub.textContent = bits.join(' · ');
      who.appendChild(nm);
      who.appendChild(sub);

      var act = document.createElement('div');
      act.className = 'act';
      if (g.status === 'issued') {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'primary';
        btn.textContent = 'Admit';
        btn.addEventListener('click', function () { admitGuest(g, btn); });
        act.appendChild(btn);
      } else {
        act.textContent = g.status === 'void' ? 'Void' : 'Used';
      }

      row.appendChild(who);
      row.appendChild(act);
      els.guests.appendChild(row);
    });
  }

  els.lookQ.addEventListener('input', renderGuests);
  els.lookRefresh.addEventListener('click', function () { loadGuests(); });

  function admitGuest(g, btn) {
    if (inFlight) return;
    // Admitting on a name rather than a token is a judgement call, and it is
    // not undoable from this page — make staff confirm the person in front of
    // them is the person on the ticket.
    var who = g.holder_name || g.code;
    if (!window.confirm('Admit ' + who + '?\n\nCheck their ID first — this marks the ticket used.')) return;

    inFlight = true;
    btn.disabled = true;
    setResult('idle', 'Admitting…', null, null);

    fetch(API + '/scan/admit', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        scanner_token: SCANNER_TOKEN,
        pin: (els.pin.value || '').trim(),
        ticket_id: g.id
      })
    })
      .then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { return { ok: r.ok, status: r.status, body: j }; }); })
      .then(function (res) {
        var b = res.body || {};
        if (res.status === 401) {
          setResult('invalid', 'Locked', null, b.error || 'Scanner link rejected.');
          return;
        }
        if (!res.ok) {
          setResult('invalid', 'Failed', null, b.error || 'Could not admit that ticket.');
          return;
        }
        // The admit response mirrors redeem()'s shape, so the shared renderer
        // handles every outcome — including the "someone else already scanned
        // them in" race — with no special-casing here.
        render(b, true);
        // Reflect the new state locally, then resync from the server so a
        // second device's admissions show up too.
        if (b.result === 'admitted' || b.result === 'already_redeemed') g.status = 'redeemed';
        if (b.result === 'void') g.status = 'void';
        renderGuests();
        loadGuests();
      })
      .catch(function () {
        setResult('invalid', 'Failed', null, 'Network error — check before admitting again.');
      })
      .finally(function () {
        setTimeout(function () { inFlight = false; }, 600);
        btn.disabled = false;
      });
  }

  // ── self-serve card purchase ─────────────────────────────────────────────────
  // The buyer scans this off the door device's screen and checks out on their
  // own phone through the ordinary public ticket page — so a venue with no card
  // reader can still take a card, and the money lands through the normal
  // payment provider rather than anything the door has to reconcile. Their
  // ticket arrives by email and gets scanned in the usual way.
  //
  // Deliberately NOT gated on can_sell: this only ever shows a link to a page
  // that is already public, and creates nothing.
  var buyUrl = null;

  function assetBase() {
    return API.replace(/\/api$/, '');
  }

  function openBuyOverlay() {
    if (!buyUrl) return;
    // Sized generously: it has to decode across a bar counter, sometimes on a
    // dim phone screen.
    els.buyQr.src = assetBase() + '/assets/qr.png?text=' + encodeURIComponent(buyUrl) + '&size=800';
    els.buyOverlay.classList.add('show');
    // Nothing should be scanning or ringing up while the screen faces the buyer.
    var wasMode = mode;
    els.buyOverlay.dataset.returnMode = wasMode;
    mode = 'buy';
  }

  function closeBuyOverlay() {
    els.buyOverlay.classList.remove('show');
    mode = els.buyOverlay.dataset.returnMode || 'scan';
  }

  els.buyBtn.addEventListener('click', openBuyOverlay);
  els.buyClose.addEventListener('click', closeBuyOverlay);
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && els.buyOverlay.classList.contains('show')) closeBuyOverlay();
  });

  // ── context: what may this link do, and what can it sell? ────────────────────
  function loadContext() {
    return fetch(API + '/scan/context', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        scanner_token: SCANNER_TOKEN,
        pin: (els.pin.value || '').trim()
      })
    })
      .then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { return { ok: r.ok, body: j }; }); })
      .then(function (res) {
        if (!res.ok) return; // PIN not entered yet, or link rejected — stay quiet
        var b = res.body || {};
        if (b.event_code || b.event_title) {
          els.eventLabel.textContent = b.event_title || b.event_code;
        }
        canSell = !!b.can_sell;
        canLookup = !!b.can_lookup;
        tiers = b.ticket_types || [];
        // The mode switch only earns its space once there is somewhere else to
        // go; each extra tab appears only if this link was granted that power.
        els.modeSell.hidden = !canSell;
        els.modeLook.hidden = !canLookup;
        els.modes.classList.toggle('show', canSell || canLookup);

        buyUrl = b.buy_url || null;
        els.buyBar.classList.toggle('show', !!buyUrl);
        els.buyEvent.textContent = b.event_title || b.event_code || '';
        if (canSell) {
          if (selectedTier) {
            // Keep the operator's pick across refreshes, with fresh availability.
            var match = tiers.filter(function (t) { return t.id === selectedTier.id; })[0];
            selectedTier = match || null;
          }
          if (!selectedTier && tiers.length === 1) selectedTier = tiers[0];
          renderTiers();
          updateTotal();
        }
        // A PIN-protected link only gets here once the PIN is right, so this is
        // the first moment we're allowed to pull the list.
        if (canLookup && mode === 'look' && !guestsLoaded) loadGuests();
      })
      .catch(function () { /* context is best-effort; scanning still works */ });
  }

  // A PIN-protected link can't answer /context until the PIN is typed, so retry
  // (debounced) as the operator enters it.
  var pinTimer = null;
  els.pin.addEventListener('input', function () {
    clearTimeout(pinTimer);
    pinTimer = setTimeout(loadContext, 500);
  });

  // ── complete a sale ──────────────────────────────────────────────────────────
  function completeSale() {
    if (!selectedTier || inFlight) return;
    inFlight = true;
    els.sellGo.disabled = true;
    setResult('idle', 'Recording sale…', null, null);

    fetch(API + '/scan/sell', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        scanner_token: SCANNER_TOKEN,
        pin: (els.pin.value || '').trim(),
        ticket_type_id: selectedTier.id,
        quantity: qty,
        payment_method: payMethod,
        holder_name: (els.sellName.value || '').trim(),
        holder_email: (els.sellEmail.value || '').trim()
      })
    })
      .then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { return { ok: r.ok, status: r.status, body: j }; }); })
      .then(function (res) {
        var b = res.body || {};
        if (res.status === 401) {
          setResult('invalid', 'Locked', null, b.error || 'Scanner link rejected.');
          return;
        }
        if (!res.ok) {
          setResult('invalid', 'Sale Failed', null, b.error || 'Could not record the sale.');
          return;
        }
        if (b.result === 'sold') {
          beep(true);
          var bits = [b.tier, money(b.amount_cents, b.currency) + ' ' + b.payment_method];
          if (b.quantity > 1) bits.unshift(b.quantity + '×');
          if (b.emailed) bits.push('receipt sent');
          setResult('admit', 'Sold · Admit', b.holder_name || (b.quantity > 1 ? b.quantity + ' tickets' : 'Ticket sold'), bits.join(' · '));
          // Reset for the next person in line; keep tier + payment method, which
          // are almost always the same for the next sale.
          qty = 1;
          els.sellName.value = '';
          els.sellEmail.value = '';
          loadContext();
        } else {
          beep(false);
          setResult('invalid', b.result === 'sold_out' ? 'Sold Out' : 'Not Allowed', null,
            b.message || 'The sale could not be completed.');
          loadContext();
        }
      })
      .catch(function () {
        setResult('invalid', 'Sale Failed', null, 'Network error — check before re-charging.');
      })
      .finally(function () {
        setTimeout(function () { inFlight = false; updateTotal(); }, 600);
      });
  }
  els.sellGo.addEventListener('click', completeSale);

  // ── manual entry ─────────────────────────────────────────────────────────────
  els.manualGo.addEventListener('click', function () {
    var v = (els.manual.value || '').trim();
    if (v) { redeem(v); els.manual.value = ''; }
  });
  els.manual.addEventListener('keydown', function (e) {
    if (e.key === 'Enter') { e.preventDefault(); els.manualGo.click(); }
  });

  // ── camera start (html5-qrcode) ──────────────────────────────────────────────
  function startCamera() {
    if (typeof Html5Qrcode === 'undefined') {
      els.hint.textContent = 'QR library failed to load. Use manual entry above.';
      return;
    }
    var scanner = new Html5Qrcode('reader', { verbose: false });
    var config = {
      fps: 10,
      qrbox: function (vw, vh) {
        var m = Math.floor(Math.min(vw, vh) * 0.8);
        return { width: m, height: m };
      },
      aspectRatio: 1.0
    };

    scanner.start(
      { facingMode: 'environment' },
      config,
      function (decodedText) { onDecode(decodedText); },
      function () { /* per-frame decode failures are normal; ignore */ }
    ).then(function () {
      els.hint.textContent = 'Scanning… hold a ticket QR in the frame.';
    }).catch(function (err) {
      els.hint.textContent = 'Camera unavailable (' + (err && err.message ? err.message : err) +
        '). Use manual entry above.';
    });
  }

  // ── tiny audio feedback (no assets) ───────────────────────────────────────────
  var actx = null;
  function beep(good) {
    try {
      actx = actx || new (window.AudioContext || window.webkitAudioContext)();
      var o = actx.createOscillator();
      var g = actx.createGain();
      o.connect(g); g.connect(actx.destination);
      o.frequency.value = good ? 880 : 220;
      g.gain.value = 0.05;
      o.start();
      o.stop(actx.currentTime + (good ? 0.12 : 0.28));
    } catch (e) { /* audio is best-effort */ }
  }

  function fatal(title, msg) {
    els.app.innerHTML = '';
    var box = document.createElement('div');
    box.className = 'fatal';
    var h = document.createElement('h2'); h.textContent = title;
    var p = document.createElement('p'); p.textContent = msg; p.style.color = '#9aa3b2';
    box.appendChild(h); box.appendChild(p);
    els.app.appendChild(box);
  }

  // Ask what this link may do as soon as the page opens — it decides whether the
  // Sell tab exists at all, and fills in the event label without a first scan.
  loadContext();

  // html5-qrcode is loaded with defer; start once it (and the DOM) are ready.
  if (document.readyState === 'complete') {
    startCamera();
  } else {
    window.addEventListener('load', startCamera);
  }
})();
