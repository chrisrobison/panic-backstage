// <pb-opportunities-discover> — Opportunities > Discover (opportunity-1.png).
// The Phase 2 dashboard: 5 KPI cards + Best Opportunities / Upcoming
// Conferences / Venue Availability Match / AI Suggestions / Recent Notes
// panels, all sourced from one GET /api/opportunities/dashboard call (see
// src/Opportunities.php::dashboard() — deliberately one aggregate-heavy
// endpoint, not N small ones). Every number rendered here comes straight
// from that response; nothing is fabricated client-side.
import { esc, api, money, emptyState, publish, PanicElement, $, $$ } from '../core.js';
import { stageLabel, scoreTone, shortDayLabel, shortMonthDay, dateRangeLabel, noteTypeLabel } from './shared.js';

const WINDOW_OPTIONS = [
  [7, 'Next 7 Days'],
  [30, 'Next 30 Days'],
  [60, 'Next 60 Days'],
  [90, 'Next 90 Days'],
  [180, 'Next 6 Months'],
];

class OpportunitiesDiscoverPage extends PanicElement {
  async connect() {
    publish('page.context', { title: 'Opportunities', blurb: 'Conference-driven corporate sales pipeline.' });
    this.windowDays = 30;
    this.data = null;
    await this.load();
  }

  async load() {
    this.setLoading('Loading Opportunities dashboard');
    try {
      this.data = await api(`/opportunities/dashboard?window_days=${encodeURIComponent(this.windowDays)}`);
      this.render();
    } catch (error) {
      this.showError(error);
    }
  }

  render() {
    const d = this.data || {};

    this.innerHTML = `
      <div class="page-head">
        <div>
          <h1>Opportunities</h1>
          <p class="subtle">Conference-driven corporate sales pipeline</p>
        </div>
        <div class="inline-actions">
          <label class="select-inline">
            <span>Window</span>
            <select data-window>${WINDOW_OPTIONS.map(([v, l]) => `<option value="${v}"${v === this.windowDays ? ' selected' : ''}>${esc(l)}</option>`).join('')}</select>
          </label>
          <a class="button primary" href="#opportunities-pipeline">+ New Opportunity</a>
        </div>
      </div>
      ${this.kpisHtml(d.kpis || {})}
      <div data-ai-research-slot></div>
      <section class="dashboard-grid">
        ${this.bestOpportunitiesHtml(d.best_opportunities || [])}
        ${this.upcomingConferencesHtml(d.upcoming_conferences || [])}
      </section>
      <section class="opp-panel-row-3">
        ${this.availabilityHtml(d.availability_matches || [])}
        ${this.suggestionsHtml(d.suggestions || [])}
        ${this.recentNotesHtml(d.recent_notes || [])}
      </section>`;

    this.bind();
    this.mountAiResearch();
  }

  // Created via document.createElement (never inline in the innerHTML
  // template above) so scopeType/scopeId are real JS properties already set
  // before its connectedCallback fires — see ai-research-panel.js's docblock.
  mountAiResearch() {
    const slot = $('[data-ai-research-slot]', this);
    if (!slot) return;
    const panel = document.createElement('pb-opportunities-ai-research');
    panel.scopeType = 'discover';
    panel.scopeId = null;
    panel.scopeName = null;
    panel.addEventListener('research-imported', () => this.load());
    slot.replaceWith(panel);
  }

  // ── KPI cards ────────────────────────────────────────────────────────────

  kpisHtml(kpis) {
    const openOpp = kpis.open_opportunities || {};
    const revenue = kpis.projected_revenue || {};
    const confs = kpis.upcoming_conferences || {};
    const empty = kpis.empty_nights || {};
    const followups = kpis.followups_due || {};

    const cards = [
      this.kpi('fa-briefcase', 'Open Opportunities', openOpp.value ?? 0,
        `${this.delta(openOpp.new_last_30_days)} vs last 30 days`),
      this.kpi('fa-sack-dollar', 'Projected Revenue', money(revenue.value || 0),
        `${this.delta(revenue.new_last_30_days, true)} vs last 30 days`),
      this.kpi('fa-calendar-days', 'Upcoming Conferences', confs.value ?? 0,
        dateRangeLabel(confs.range_start, confs.range_end)),
      this.kpi('fa-moon', 'Empty Nights to Fill', empty.value ?? 0,
        `${money(empty.potential_value || 0)} linked potential`),
      this.kpi('fa-bell', 'Follow-ups Due', followups.value ?? 0,
        followups.overdue ? `${followups.overdue} overdue` : 'None overdue', followups.overdue ? 'warn' : ''),
    ];
    return `<section class="opp-kpis">${cards.join('')}</section>`;
  }

  delta(value, isMoney = false) {
    const n = Number(value || 0);
    if (!n) return 'No change';
    const formatted = isMoney ? money(n) : n.toLocaleString();
    return `↑ ${formatted}`;
  }

  kpi(icon, label, value, sub, tone = '') {
    return `<article class="kpi-card${tone ? ` kpi-${tone}` : ''}">
      <span class="kpi-icon"><i class="fa-solid ${icon}" aria-hidden="true"></i></span>
      <div><span class="kpi-label">${esc(label)}</span><strong class="kpi-value">${esc(String(value))}</strong><small class="kpi-sub">${esc(sub)}</small></div>
    </article>`;
  }

  // ── Best Opportunities This Week ────────────────────────────────────────

  bestOpportunitiesHtml(rows) {
    const body = rows.length
      ? rows.map((o) => `<tr data-opp-id="${esc(o.id)}" tabindex="0" role="button" aria-label="Open opportunity ${esc(o.name)}">
          <td><a href="#opportunities-company-${esc(o.company_id ?? '')}" data-stop>${esc(o.company_name || '—')}</a></td>
          <td>${o.conference_name ? esc(o.conference_name) : '<span class="muted">—</span>'}</td>
          <td class="muted">—</td>
          <td>${o.estimated_value != null ? money(o.estimated_value) : '—'}</td>
          <td>${o.probability != null ? `<span class="${scoreTone(o.probability)}">${esc(o.probability)}%</span>` : '—'}</td>
          <td>${o.next_action ? esc(o.next_action) : '<span class="muted">—</span>'}</td>
        </tr>`).join('')
      : `<tr><td colspan="6">${emptyState('No open opportunities yet.')}</td></tr>`;

    return `<article class="panel">
      <div class="section-head padded"><h2>Best Opportunities</h2><a class="button secondary small" href="#opportunities-pipeline">View pipeline</a></div>
      <div class="table-scroll"><table class="data-table">
        <thead><tr><th>Company</th><th>Conference</th><th>Likely Buyer</th><th>Est. Value</th><th>Probability</th><th>Next Action</th></tr></thead>
        <tbody>${body}</tbody>
      </table></div>
    </article>`;
  }

  // ── Upcoming Conferences ────────────────────────────────────────────────

  upcomingConferencesHtml(rows) {
    const body = rows.length
      ? rows.map((c) => `<tr data-conf-id="${esc(c.id)}" tabindex="0" role="button" aria-label="Open conference ${esc(c.name)}">
          <td>${esc(c.name)}</td>
          <td>${esc(dateRangeLabel(c.starts_at, c.ends_at))}</td>
          <td>${c.estimated_attendance != null ? Number(c.estimated_attendance).toLocaleString() + '+' : '—'}</td>
          <td>${c.estimated_sponsors != null ? Number(c.estimated_sponsors).toLocaleString() + '+' : '—'}</td>
          <td>${c.opportunity_score != null ? `<span class="badge ${scoreTone(c.opportunity_score) === 'opp-score-high' ? 'success' : scoreTone(c.opportunity_score) === 'opp-score-mid' ? 'warning' : 'error'}">${esc(c.opportunity_score)}</span>` : '<span class="muted">Unscored</span>'}</td>
        </tr>`).join('')
      : `<tr><td colspan="5">${emptyState('No upcoming conferences in this window.')}</td></tr>`;

    return `<article class="panel">
      <div class="section-head padded"><h2>Upcoming Conferences</h2><a class="button secondary small" href="#opportunities-conferences">View all</a></div>
      <div class="table-scroll"><table class="data-table">
        <thead><tr><th>Conference</th><th>Dates</th><th>Est. Attendees</th><th>Sponsors/Exhibitors</th><th>Opp. Score</th></tr></thead>
        <tbody>${body}</tbody>
      </table></div>
    </article>`;
  }

  // ── Venue Availability Match ────────────────────────────────────────────

  availabilityHtml(rows) {
    const body = rows.length
      ? rows.slice(0, 8).map((m) => {
        const conf = m.conference || {};
        const distance = conf.distance_from_venue_miles != null ? `${Number(conf.distance_from_venue_miles).toFixed(1)} mi` : 'Unknown';
        return `<tr data-conf-id="${esc(conf.id)}" tabindex="0" role="button" aria-label="Open conference ${esc(conf.name)}">
          <td>${esc(shortMonthDay(m.date))}</td>
          <td class="muted">${esc(shortDayLabel(m.date))}</td>
          <td>${esc(conf.name || '—')}</td>
          <td>${esc(distance)}</td>
        </tr>`;
      }).join('')
      : `<tr><td colspan="4">${emptyState('No open venue nights match an upcoming conference in this window.')}</td></tr>`;

    return `<article class="panel">
      <div class="section-head padded"><h2>Venue Availability Match</h2></div>
      <div class="table-scroll"><table class="data-table">
        <thead><tr><th>Open Date</th><th>Day</th><th>Conference</th><th>Distance</th></tr></thead>
        <tbody>${body}</tbody>
      </table></div>
    </article>`;
  }

  // ── Suggestions (deterministic, data-derived — no AI yet) ───────────────

  suggestionsHtml(suggestions) {
    const items = suggestions.length
      ? suggestions.map((s) => `<li class="opp-suggestion opp-suggestion-${esc(s.tone || 'medium')}"><i class="fa-solid fa-lightbulb" aria-hidden="true"></i>${esc(s.text)}</li>`).join('')
      : `<li class="muted">Nothing needs attention right now.</li>`;
    return `<article class="panel">
      <div class="section-head padded"><h2>Suggestions</h2></div>
      <ul class="opp-suggestion-list padded">${items}</ul>
    </article>`;
  }

  // ── Recent Research & Notes ─────────────────────────────────────────────

  recentNotesHtml(notes) {
    const items = notes.length
      ? notes.map((n) => `<li class="opp-note-item">
          <div class="opp-note-head">
            <strong>${esc(n.context || 'General note')}</strong>
            <span class="badge">${esc(noteTypeLabel(n.note_type))}</span>
          </div>
          <p>${esc(this.truncate(n.body, 140))}</p>
          <small class="muted">${esc(n.created_by_name || 'Unknown')} · ${esc(shortMonthDay(n.created_at ? n.created_at.slice(0, 10) : null))}</small>
        </li>`).join('')
      : `<li class="muted">No notes yet.</li>`;
    return `<article class="panel">
      <div class="section-head padded"><h2>Recent Research &amp; Notes</h2><a class="button secondary small" href="#opportunities-notes">View all</a></div>
      <ul class="opp-note-list padded">${items}</ul>
    </article>`;
  }

  truncate(text, max) {
    const s = String(text || '');
    return s.length > max ? `${s.slice(0, max - 1)}…` : s;
  }

  // ── Wiring ───────────────────────────────────────────────────────────────

  bind() {
    $('[data-window]', this)?.addEventListener('change', (e) => {
      this.windowDays = Number(e.target.value) || 30;
      this.load();
    });

    $$('[data-opp-id]', this).forEach((row) => {
      const go = () => { location.hash = `#opportunities-${row.dataset.oppId}`; };
      row.addEventListener('click', (e) => { if (!e.target.closest('[data-stop]')) go(); });
      row.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') go(); });
    });
    $$('[data-conf-id]', this).forEach((row) => {
      const id = row.dataset.confId;
      if (!id) return;
      const go = () => { location.hash = `#opportunities-conference-${id}`; };
      row.addEventListener('click', go);
      row.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') go(); });
    });
  }
}
customElements.define('pb-opportunities-discover', OpportunitiesDiscoverPage);
