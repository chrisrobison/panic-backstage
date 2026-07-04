/* Panic Backstage — cookie / preference consent banner.
   Self-contained: injects its own styles so it works on any page. Plain browser
   JS, no build step (consistent with the app).

   The app keeps you signed in with a token in this browser's local storage and
   protects requests with a CSRF token — both strictly necessary to use the app
   and exempt from consent. Remembered interface preferences (such as a collapsed
   navigation rail) are non-essential and are only stored once you accept.
   There are no advertising or analytics trackers.

   Public API:
     window.PBConsent.allowsPreferences()  -> boolean
     window.PBConsent.get()                -> "all" | "essential" | null
   A "pb:consent" event fires on document whenever the choice changes. */
(function () {
  "use strict";

  var KEY = "pb.cookieConsent";
  var POLICY_HREF = "./privacy.html#cookies";

  // Non-essential UI-preference keys this app may store. Used to purge remembered
  // preferences if the user declines (or later withdraws) preference consent.
  // "pb_sections_" is a prefix: per-event, per-user section-visibility prefs.
  var PREF_KEYS = ["pb.navGroups", "pb.navCollapsed", "pb-msg-detail-h", "pb-campaign-detail-h", "pb-mlist-detail-h"];
  var PREF_PREFIXES = ["pb_sections_"];

  function read() { try { return localStorage.getItem(KEY); } catch (e) { return null; } }

  function purgePrefs() {
    try {
      PREF_KEYS.forEach(function (k) { localStorage.removeItem(k); });
      for (var i = localStorage.length - 1; i >= 0; i--) {
        var k = localStorage.key(i);
        if (!k) continue;
        for (var j = 0; j < PREF_PREFIXES.length; j++) {
          if (k.indexOf(PREF_PREFIXES[j]) === 0) { localStorage.removeItem(k); break; }
        }
      }
    } catch (e) {}
  }

  function write(v) {
    try { localStorage.setItem(KEY, v); } catch (e) {}
    if (v !== "all") purgePrefs();
    document.dispatchEvent(new CustomEvent("pb:consent", { detail: { value: v } }));
  }

  window.PBConsent = {
    get: read,
    allowsPreferences: function () { return read() === "all"; },
    // Persist a non-essential UI preference only if the user accepted
    // preference storage. Returns true if it was written.
    savePref: function (key, value) {
      if (read() !== "all") return false;
      try { localStorage.setItem(key, value); return true; } catch (e) { return false; }
    }
  };

  function injectStyles() {
    if (document.getElementById("pb-consent-styles")) return;
    var css =
      ".pb-cookie{position:fixed;left:16px;right:16px;bottom:16px;z-index:9999;" +
      "background:#15171c;color:#fff;border:1px solid rgba(255,255,255,.12);" +
      "border-radius:14px;box-shadow:0 18px 50px -12px rgba(0,0,0,.5);" +
      "font-family:system-ui,-apple-system,'Segoe UI',sans-serif;" +
      "transition:opacity .2s ease,transform .2s ease}" +
      ".pb-cookie.pb-hide{opacity:0;transform:translateY(8px);pointer-events:none}" +
      ".pb-cookie-in{max-width:1100px;margin:0 auto;padding:18px 20px;display:flex;" +
      "gap:20px;align-items:center;justify-content:space-between;flex-wrap:wrap}" +
      ".pb-cookie-copy{max-width:70ch}" +
      ".pb-cookie-copy strong{display:block;font-size:15px;margin-bottom:4px}" +
      ".pb-cookie-copy p{margin:0;font-size:13.5px;line-height:1.5;color:rgba(255,255,255,.72)}" +
      ".pb-cookie-copy a{color:#fff;text-decoration:underline}" +
      ".pb-cookie-actions{display:flex;gap:10px;flex-shrink:0}" +
      ".pb-cookie-btn{cursor:pointer;border-radius:9px;padding:11px 18px;font-size:14px;" +
      "font-weight:600;border:1px solid transparent;font-family:inherit}" +
      ".pb-cookie-accept{background:#ef4338;color:#fff}" +
      ".pb-cookie-accept:hover{background:#c4291f}" +
      ".pb-cookie-reject{background:transparent;color:#fff;border-color:rgba(255,255,255,.28)}" +
      ".pb-cookie-reject:hover{border-color:rgba(255,255,255,.6)}" +
      "@media(max-width:640px){.pb-cookie-actions{width:100%}.pb-cookie-btn{flex:1}}";
    var s = document.createElement("style");
    s.id = "pb-consent-styles";
    s.textContent = css;
    document.head.appendChild(s);
  }

  function render() {
    if (read()) return;
    injectStyles();
    var banner = document.createElement("div");
    banner.className = "pb-cookie";
    banner.setAttribute("role", "dialog");
    banner.setAttribute("aria-label", "Cookie preferences");
    banner.innerHTML =
      '<div class="pb-cookie-in">' +
        '<div class="pb-cookie-copy">' +
          '<strong>Cookies &amp; your privacy</strong>' +
          '<p>We store a sign-in token to keep you logged in. With your consent we’ll also remember ' +
          'your interface preferences on this device. No advertising or analytics trackers. ' +
          'Read our <a href="' + POLICY_HREF + '">Privacy &amp; Cookie Policy</a>.</p>' +
        '</div>' +
        '<div class="pb-cookie-actions">' +
          '<button type="button" class="pb-cookie-btn pb-cookie-reject" data-consent="essential">Essential only</button>' +
          '<button type="button" class="pb-cookie-btn pb-cookie-accept" data-consent="all">Accept all</button>' +
        '</div>' +
      '</div>';
    banner.addEventListener("click", function (e) {
      var btn = e.target.closest("[data-consent]");
      if (!btn) return;
      write(btn.getAttribute("data-consent"));
      banner.classList.add("pb-hide");
      setTimeout(function () { if (banner.parentNode) banner.parentNode.removeChild(banner); }, 250);
    });
    document.body.appendChild(banner);
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", render);
  } else {
    render();
  }
})();
