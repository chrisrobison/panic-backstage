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

export const NOTE_TYPES = ['general', 'meeting', 'call', 'research', 'internal', 'strategy'];

export function noteTypeLabel(type) {
  return { general: 'Note', meeting: 'Meeting', call: 'Call', research: 'Research', internal: 'Internal', strategy: 'Strategy' }[type] || titleCaseFallback(type);
}

// ── Companies / Contacts (Phase 4) ──────────────────────────────────────────

const RELATIONSHIP_STATUS_LABELS = {
  prospect: 'Prospect', active: 'Active Account', past_client: 'Past Client',
  do_not_contact: 'Do Not Contact', unknown: 'Unknown',
};
const RELATIONSHIP_STATUS_TONES = {
  prospect: '', active: 'success', past_client: 'info', do_not_contact: 'error', unknown: '',
};

export function relationshipStatusLabel(status) {
  return RELATIONSHIP_STATUS_LABELS[status] || titleCaseFallback(status);
}

export function relationshipStatusBadge(status) {
  const tone = RELATIONSHIP_STATUS_TONES[status] ?? '';
  return `<span class="badge ${tone}">${esc(relationshipStatusLabel(status))}</span>`;
}

const CONTACT_STATUS_LABELS = { active: 'Active', cold: 'Cold', left_company: 'Left Company', unknown: 'Unknown' };
const CONTACT_STATUS_TONES = { active: 'success', cold: 'info', left_company: 'error', unknown: '' };

export function contactStatusLabel(status) {
  return CONTACT_STATUS_LABELS[status] || titleCaseFallback(status);
}

export function contactStatusBadge(status) {
  const tone = CONTACT_STATUS_TONES[status] ?? '';
  return `<span class="badge ${tone}">${esc(contactStatusLabel(status))}</span>`;
}

const VENUE_FIT_TAG_LABELS = {
  large_audience: 'Large Audience', tech_and_innovation: 'Tech & Innovation',
  executive_visibility: 'Executive Visibility', nightlife_fit: 'Nightlife Fit',
  presentation_fit: 'Presentation Fit', live_entertainment_fit: 'Live Entertainment Fit',
};

export function venueFitTagLabel(tag) {
  return VENUE_FIT_TAG_LABELS[tag] || titleCaseFallback(tag);
}

/** Up to 2 uppercase initials from a display name, for a small avatar circle. */
export function initials(name) {
  const parts = String(name || '').trim().split(/\s+/).filter(Boolean);
  if (!parts.length) return '?';
  return (parts[0][0] + (parts.length > 1 ? parts[parts.length - 1][0] : '')).toUpperCase();
}

// A small fixed palette (not random) so the same name always gets the same
// color across a session and across reloads — deterministic, not decorative
// noise. Mirrors the "hash a stable key into a fixed palette" approach used
// by other per-module avatar classes (.tk-avatar, .lm-avatar) in app.css.
const AVATAR_PALETTE = ['#2563eb', '#0c7a3c', '#a06400', '#7c3aed', '#c2185b', '#0891b2', '#b91c1c', '#4d7c0f'];

export function avatarColor(key) {
  const s = String(key || '');
  let hash = 0;
  for (let i = 0; i < s.length; i++) hash = (hash * 31 + s.charCodeAt(i)) >>> 0;
  return AVATAR_PALETTE[hash % AVATAR_PALETTE.length];
}

/**
 * A short human label for an opportunity_activities row, built entirely
 * from the real `action`/`details` the backend stored — never fabricated.
 * Shared by the Company activity feed (Phase 4) and, later, the Opportunity
 * detail activity feed (Phase 5) so the two don't drift.
 */
export function activityActionLabel(action, details) {
  const d = details || {};
  switch (action) {
    case 'created': return `Opportunity created${d.stage ? ` (${stageLabel(d.stage)})` : ''}`;
    case 'stage_changed': return `Stage changed: ${stageLabel(d.from)} → ${stageLabel(d.to)}`;
    case 'note_added': return `Note added${d.note_type ? ` (${noteTypeLabel(d.note_type)})` : ''}`;
    case 'note_deleted': return 'Note removed';
    case 'signal_added': return `Signal added${d.signal_type ? ` (${d.signal_type.replace(/_/g, ' ')})` : ''}`;
    case 'converted': return 'Converted to event';
    case 'updated': return `Updated${Array.isArray(d.fields) && d.fields.length ? ` (${d.fields.join(', ')})` : ''}`;
    // Manual "Log Activity" entries (Phase 5) — action is `{type}_logged`.
    case 'call_logged': return 'Call logged';
    case 'meeting_logged': return 'Meeting logged';
    case 'note_logged': return 'Note logged';
    case 'proposal_logged': return 'Proposal noted';
    case 'task_logged': return 'Task activity logged';
    case 'other_logged': return 'Activity logged';
    // Phase 8 activity history additions.
    case 'owner_changed': return 'Owner changed';
    case 'probability_changed': return `Probability changed${d.to != null ? ` to ${d.to}%` : ''}`;
    case 'contact_added': return `Contact added${d.contact_name ? ` (${d.contact_name})` : ''}`;
    case 'research_completed': return `AI research completed${d.job_type ? ` (${researchModeLabel(d.job_type)})` : ''}`;
    default: return titleCaseFallback(action);
  }
}

// ── Pipeline / Opportunity detail (Phase 5) ─────────────────────────────────

const DECISION_MAKER_ROLE_LABELS = {
  champion: 'Champion', influencer: 'Influencer', decision_maker: 'Decision Maker',
  finance: 'Finance', blocker: 'Blocker', other: 'Other',
};
export function decisionMakerRoleLabel(role) {
  return DECISION_MAKER_ROLE_LABELS[role] || titleCaseFallback(role);
}
const DECISION_MAKER_ROLE_TONES = {
  champion: 'success', influencer: 'info', decision_maker: 'warning', finance: '', blocker: 'error', other: '',
};
export function decisionMakerRoleBadge(role) {
  return `<span class="badge ${DECISION_MAKER_ROLE_TONES[role] ?? ''}">${esc(decisionMakerRoleLabel(role))}</span>`;
}

const WARNING_LABELS = {
  needs_follow_up: 'Needs follow-up',
  no_next_action: 'No next action set',
  waiting_on_intro: 'Waiting on intro',
  date_conflict: 'Date conflict',
  stale: 'Stale',
  budget_unknown: 'Budget unknown',
  // Phase 8 follow-up intelligence (src/Opportunities/FollowUp.php).
  no_activity: 'No recent activity',
  proposal_stalled: 'Proposal stalled',
  conference_approaching: 'Conference approaching',
  target_date_approaching: 'Target date approaching',
};
export function warningLabel(code) {
  return WARNING_LABELS[code] || titleCaseFallback(code);
}

export const QUALIFICATION_ITEMS = [
  ['decision_makers_identified', 'Identify decision makers'],
  ['event_objective_understood', 'Understand event objectives'],
  ['guest_range_confirmed', 'Confirm guest count range'],
  ['budget_range_identified', 'Budget range identified'],
  ['venue_fit_explored', 'Explore venue + format'],
  ['target_date_confirmed', 'Confirm target date'],
  ['must_have_amenities_identified', 'Identify must-have amenities'],
  ['competitor_venues_assessed', 'Assess competitor venues'],
  ['success_metrics_established', 'Finalize success metrics'],
];

/** "3 days ago" / "just now" / "in 2 days" from an ISO date(-time) string. */
export function relativeTime(value) {
  if (!value) return '—';
  const then = new Date(value.includes('T') || value.includes(' ') ? value.replace(' ', 'T') : `${value}T12:00:00`);
  if (Number.isNaN(then.getTime())) return '—';
  const diffMs = Date.now() - then.getTime();
  const diffDays = Math.round(diffMs / 86400000);
  if (diffDays === 0) return 'Today';
  if (diffDays === 1) return 'Yesterday';
  if (diffDays > 1 && diffDays < 30) return `${diffDays} days ago`;
  if (diffDays < 0 && diffDays > -30) return `in ${-diffDays} days`;
  return shortMonthDay(value.slice(0, 10));
}

/**
 * How many of `tasks` are open (not done) AND past their due_date — Phase 8
 * (spec: "show task counts and overdue status in relevant views"). Computed
 * client-side over the already-fetched task list every detail page already
 * loads, rather than a separate backend call.
 */
export function overdueTaskCount(tasks) {
  const today = new Date().toISOString().slice(0, 10);
  return (tasks || []).filter((t) => t.status !== 'done' && t.due_date && t.due_date.slice(0, 10) < today).length;
}

/** Delay invoking `fn` until `ms` have passed since the last call — used by every live-search/filter input across the module. */
export function debounce(fn, ms = 300) {
  let timer;
  return (...args) => { clearTimeout(timer); timer = setTimeout(() => fn(...args), ms); };
}

// ── AI/web research jobs (Phase 7) ──────────────────────────────────────────

export const RESEARCH_MODE_LABELS = {
  discover_conferences: 'Find upcoming conferences',
  research_conference: 'Research this conference',
  find_target_companies: 'Find sponsors & exhibitors',
  research_side_events: 'Find side events',
  generate_outreach_angles: 'Generate outreach angles',
  research_company: 'Research company',
};

export function researchModeLabel(mode) {
  return RESEARCH_MODE_LABELS[mode] || titleCaseFallback(mode);
}

const RESEARCH_STATUS_LABELS = { pending: 'Queued', processing: 'Researching…', completed: 'Completed', failed: 'Failed' };
const RESEARCH_STATUS_TONES = { pending: '', processing: 'info', completed: 'success', failed: 'error' };

export function researchStatusBadge(status) {
  return `<span class="badge ${RESEARCH_STATUS_TONES[status] ?? ''}">${esc(RESEARCH_STATUS_LABELS[status] || titleCaseFallback(status))}</span>`;
}

export function researchStatusIsActive(status) {
  return status === 'pending' || status === 'processing';
}
