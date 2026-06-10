// ── Events barrel ────────────────────────────────────────────────────────────
// events.js was split into focused modules. This barrel preserves the original
// import surface: importing it loads (and registers the custom elements of) all
// three event modules, and re-exports the one function other modules consume.
//
//   event-views.js      top-level routed views + quick-create modal
//   event-workspace.js  event workspace shell, bus cards, details form
//   event-panels.js     the editable workspace section panels
import './event-views.js';
import './event-workspace.js';
import './event-panels.js';

export { openEventQuickCreate } from './event-views.js';
