// Global topbar search — see app.js's `[data-search]` input, which navigates
// here (`#search-<encoded query>`) on the first keystroke, then keeps this
// component alive and pushes live updates over the `events.search` bus for
// every keystroke after that (so the results list updates in place instead
// of a full remount per character — see app.js's input handler for why).
import { esc, publish, subscribe, api, eventDate, longDate, eventDateRangeLabel, statusTone, badge, emptyState, PanicElement } from './core.js';

// Below this many characters a LIKE '%x%' scan matches nearly everything and
// isn't a useful result set yet — show a prompt instead of hitting the API.
const MIN_QUERY_LENGTH = 2;

// Field labels/getters checked in the order a user would find most obvious —
// title is skipped here since a title hit is already obvious from the row
// itself; this is purely for "why did this show up" on a less obvious match.
const SNIPPET_FIELDS = [
  ['Internal notes', (e) => e.description_internal],
  ['Public description', (e) => e.description_public],
  ['Producer/Artist', (e) => e.promoter_name],
  ['Producer email', (e) => e.promoter_email],
  ['Booker', (e) => e.booker_name],
  ['Booker email', (e) => e.booker_email],
  ['Organization', (e) => e.client_org],
  ['Event ID', (e) => e.external_id],
];

// A short excerpt centered on the match, so "Matched in Internal notes" means
// something instead of just asking the user to trust it.
function matchSnippet(event, qLower) {
  for (const [label, getter] of SNIPPET_FIELDS) {
    const value = String(getter(event) || '');
    const idx = value.toLowerCase().indexOf(qLower);
    if (idx === -1) continue;
    const start = Math.max(0, idx - 30);
    const end = Math.min(value.length, idx + qLower.length + 30);
    const excerpt = (start > 0 ? '…' : '') + value.slice(start, end).trim() + (end < value.length ? '…' : '');
    return { label, excerpt };
  }
  return null;
}

class SearchResults extends PanicElement {
  connect() {
    // `query` arrives as a property set by app.js's router (mount() does
    // Object.assign(element, props) before this ever runs) — see app.js's
    // route() for the `search`/`search-<q>` route.
    this.query = this.query || '';
    subscribe('events.search', ({ query }) => {
      this.query = query || '';
      this.load();
    }, this.abort.signal);
    this.load();
  }

  async load() {
    const q = this.query.trim();
    publish('page.context', {
      title: 'Search',
      blurb: q ? `Results for “${q}”` : 'Search events by ID, title, description, or internal notes.',
    });

    if (q.length < MIN_QUERY_LENGTH) {
      this.renderPrompt(q.length > 0);
      return;
    }

    this.setLoading('Searching…');
    const requestQuery = q;
    try {
      const data = await api(`/events?q=${encodeURIComponent(q)}`);
      // A slower earlier request can resolve after a faster later one if the
      // user kept typing — drop it rather than flash stale results on screen.
      if (this.query.trim() !== requestQuery) return;
      this.render(data.events || [], q);
    } catch (error) {
      if (this.query.trim() !== requestQuery) return;
      this.innerHTML = `<article class="panel"><div class="section-head padded"><h2>Search</h2></div><p class="error-text padded">${esc(error.message || 'Search failed.')}</p></article>`;
    }
  }

  renderPrompt(tooShort) {
    const message = tooShort
      ? `Type at least ${MIN_QUERY_LENGTH} characters to search.`
      : 'Search by event ID, title, description, or internal notes — use the box at the top of the screen.';
    this.innerHTML = `<article class="panel"><div class="section-head padded"><h2>Search</h2></div><p class="muted padded">${esc(message)}</p></article>`;
  }

  render(events, q) {
    const qLower = q.toLowerCase();
    const rows = events.map((event) => {
      const snippet = matchSnippet(event, qLower);
      const issue = event.primary_blocker || (Number(event.approved_flyers) ? 'Flyer approved' : 'Flyer needs review');
      return `<tr>
        <td data-label="ID"><a href="#event-${esc(event.id)}" class="event-code">${esc(event.external_id || '—')}</a></td>
        <td data-label="Date" title="${esc(longDate(eventDate(event)))}">${esc(eventDateRangeLabel(event))}</td>
        <td data-label="Event">
          <a href="#event-${esc(event.id)}">${esc(event.title || '(untitled)')}</a>
          ${snippet ? `<div class="muted small search-match">${esc(snippet.label)}: “${esc(snippet.excerpt)}”</div>` : ''}
        </td>
        <td data-label="Status">${badge(event.status)}</td>
        <td data-label="Main Issue"><span class="status-dot ${esc(event.primary_blocker ? 'red' : statusTone(event.status))}"></span>${esc(issue)}</td>
        <td data-label="Owner">${esc(event.owner_name || 'Unassigned')}</td>
      </tr>`;
    }).join('');

    this.innerHTML = `<article class="panel">
      <div class="section-head padded"><h2>Search Results${events.length ? ` <span class="muted small">(${events.length})</span>` : ''}</h2></div>
      <div class="table-scroll">
        <table class="data-table">
          <thead><tr><th>ID</th><th>Date</th><th>Event</th><th>Status</th><th>Main Issue</th><th>Owner</th></tr></thead>
          <tbody>${rows || `<tr><td colspan="6">${emptyState(`No events match “${esc(q)}”.`)}</td></tr>`}</tbody>
        </table>
      </div>
    </article>`;
  }
}

customElements.define('pb-search-results', SearchResults);
