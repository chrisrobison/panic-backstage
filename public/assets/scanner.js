/* Panic Backstage — door scanner page logic.
 *
 * Auth model: the scanner-link secret travels in the page URL (?token=...).
 * We never store it anywhere but memory; redemption posts the scanner token
 * (+ optional PIN) and the scanned ticket token to /api/scan/redeem, which is
 * authenticated by the scanner token (NOT a JWT).
 *
 * Decoding: html5-qrcode (CDN). On a successful decode we POST and render a
 * big ADMIT / ALREADY-USED / INVALID state, debounced so one physical ticket
 * isn't redeemed twice by consecutive frames.
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
    app: document.getElementById('app')
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
        if (res.body && res.body.event_id && !els.eventLabel.textContent) {
          els.eventLabel.textContent = 'Event #' + res.body.event_id;
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
    var now = Date.now();
    if (text === lastPayload && now - lastAt < 3000) return;
    lastPayload = text;
    lastAt = now;
    redeem(text);
  }

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

  // html5-qrcode is loaded with defer; start once it (and the DOM) are ready.
  if (document.readyState === 'complete') {
    startCamera();
  } else {
    window.addEventListener('load', startCamera);
  }
})();
