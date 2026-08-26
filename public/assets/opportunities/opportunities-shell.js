// public/assets/opportunities/opportunities-shell.js — the single entry
// point app.js imports for the whole Opportunities module. Imports the real
// pages as they land phase by phase (Discover: Phase 2; Conference
// list/detail: Phase 3; Company list/detail: Phase 4; Pipeline board +
// Opportunity detail: Phase 5) and defines <pb-opportunities-placeholder>,
// an honest "not built yet" page — same purpose and shape as
// processes/automation-placeholder.js — for every nav destination/detail
// route that doesn't have a real page yet (only the dedicated Notes
// workspace remains — Phase 6).
//
// Phase 2 acceptance criteria explicitly allows this: "clicking company/
// conference/opportunity placeholders routes correctly or to a safe
// not-yet-implemented state."
import { publish, PanicElement } from '../core.js';
import './discover-page.js';
import './conferences-list.js';
import './conference-detail.js';
import './companies-list.js';
import './company-detail.js';
import './pipeline-board.js';
import './opportunity-detail.js';

const PAGES = {
  notes: {
    title: 'Notes',
    blurb: 'Research notes workspace.',
    body: '<p>Search, filter, and manage every Opportunities note in one workspace. You can already add notes from the Discover dashboard\'s panels, every detail page, and an opportunity\'s own Notes tab — this dedicated cross-cutting workspace lands in Phase 6.</p>',
  },
};

export class OpportunitiesPlaceholderElement extends PanicElement {
  connect() {
    const page = PAGES[this.page] || PAGES.notes;
    publish('page.context', { title: `Opportunities › ${page.title}`, blurb: page.blurb });
    this.innerHTML = `
      <div class="page-head"><div><h1>${page.title}</h1><p class="subtle">${page.blurb}</p></div>
        <a class="button secondary small" href="#opportunities">Back to Discover</a></div>
      <div class="panel padded">
        <span class="pill pill-muted">Planned — not yet built</span>
        ${page.body}
      </div>`;
  }
}
customElements.define('pb-opportunities-placeholder', OpportunitiesPlaceholderElement);
