// public/assets/opportunities/opportunities-shell.js — the single entry
// point app.js imports for the whole Opportunities module. Imports the real
// pages as they land phase by phase (Discover: Phase 2; Conference
// list/detail: Phase 3; Company list/detail: Phase 4; Pipeline board +
// Opportunity detail: Phase 5; Notes workspace: Phase 6). Every planned
// screen is now a real page — the honest "not built yet"
// `<pb-opportunities-placeholder>` this file used to define (same purpose
// as processes/automation-placeholder.js) has been removed now that nothing
// routes to it any more; AI research (Phase 7) and tasks/activities/
// scoring/availability polish (Phase 8) still have their own "planned"
// affordances scoped to the pages that need them, not a whole-page
// placeholder.
//
// ai-research-panel.js (Phase 7) defines <pb-opportunities-ai-research>, a
// shared widget embedded into Discover/Conference-detail/Company-detail
// (each creates one via document.createElement + prop assignment — see
// that file's own docblock) rather than being its own routed page.
import './ai-research-panel.js';
import './discover-page.js';
import './conferences-list.js';
import './conference-detail.js';
import './companies-list.js';
import './company-detail.js';
import './pipeline-board.js';
import './opportunity-detail.js';
import './notes-workspace.js';
