// public/assets/opportunities/shared.js — constants and small render helpers
// shared across every Opportunities page. Kept deliberately thin (mirrors
// tasks/task-shared.js and leads.js's "shared helpers, not a framework"
// convention) — grows as Phase 3+ pages land, not ahead of them.
import { esc } from '../core.js';

export const STAGES = [
  'new_signal', 'researching', 'contacted', 'qualified',
  'proposal_sent', 'verbal_yes', 'won', 'lost', 'nurture',
];

const STAGE_LABELS = {
  new_signal: 'New Signal',
  researching: 'Researching',
  contacted: 'Contacted',
  qualified: 'Qualified',
  proposal_sent: 'Proposal Sent',
  verbal_yes: 'Verbal Yes',
  won: 'Won',
  lost: 'Lost',
  nurture: 'Nurture',
};

// Reuses the generic .badge + .success/.info/.warning/.error tone classes
// (app.css — already used by Promote/ticketing, not event-status-specific).
const STAGE_TONES = {
  new_signal: '', researching: 'info', contacted: 'info', qualified: 'warning',
  proposal_sent: 'warning', verbal_yes: 'success', won: 'success', lost: 'error', nurture: '',
};

export function stageLabel(stage) {
  return STAGE_LABELS[stage] || titleCaseFallback(stage);
}

export function stageBadge(stage) {
  const tone = STAGE_TONES[stage] ?? '';
  return `<span class="badge ${tone}">${esc(stageLabel(stage))}</span>`;
}

function titleCaseFallback(value) {
  return String(value || '').replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

/** 0-100 -> a tone class for inline probability/score display. */
export function scoreTone(value) {
  const n = Number(value);
  if (!Number.isFinite(n)) return '';
  if (n >= 70) return 'opp-score-high';
  if (n >= 40) return 'opp-score-mid';
  return 'opp-score-low';
}

/** "2026-09-16" -> a `Date` at noon local time, matching core.js's eventDate() convention (avoids UTC-midnight day-shift). */
export function parseDate(value) {
  if (!value) return null;
  const d = new Date(`${value}T12:00:00`);
  return Number.isNaN(d.getTime()) ? null : d;
}

export function shortDayLabel(value) {
  const d = parseDate(value);
  return d ? d.toLocaleDateString(undefined, { weekday: 'short' }) : '';
}

export function shortMonthDay(value) {
  const d = parseDate(value);
  return d ? d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' }) : '—';
}

/** "Aug 22 – Oct 19, 2026" style range, or a single date, or "—" for no data. */
export function dateRangeLabel(startValue, endValue) {
  const start = parseDate(startValue);
  if (!start) return '—';
  const startLabel = start.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
  if (!endValue || endValue === startValue) {
    return `${startLabel}, ${start.getFullYear()}`;
  }
  const end = parseDate(endValue);
  if (!end) return `${startLabel}, ${start.getFullYear()}`;
  const endLabel = end.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
  return `${startLabel} – ${endLabel}, ${end.getFullYear()}`;
}

export function noteTypeLabel(type) {
  return { general: 'Note', meeting: 'Meeting', call: 'Call', research: 'Research', internal: 'Internal' }[type] || titleCaseFallback(type);
}
