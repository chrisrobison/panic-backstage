// Shared constants/helpers for the Booking Inbox (incoming-ui.png). Mirrors
// task-shared.js's role in the Tasks app — small, framework-free, imported
// by every ib-* component so status labels/tones/countdown formatting stay
// in exactly one place.

export const STATUS_LABELS = {
  new: 'New', classified: 'Classified', assigned: 'Assigned', claimed: 'Claimed',
  acknowledged: 'Acknowledged', qualifying: 'Qualifying', awaiting_customer: 'Awaiting Customer',
  availability_sent: 'Availability Sent', tour_scheduled: 'Tour Scheduled', proposal_sent: 'Proposal Sent',
  negotiating: 'Negotiating', on_hold: 'On Hold', onboarded: 'Onboarded', contract_sent: 'Contract Sent',
  deposit_pending: 'Deposit Pending', booked: 'Booked', lost: 'Lost', declined: 'Declined',
  spam: 'Spam', duplicate: 'Duplicate', archived: 'Archived',
  // Legacy Leads-pipeline values a Booking Inbox row might still carry:
  triage: 'Triage', evaluating: 'Evaluating', needs_review: 'Needs Review',
  approved: 'Approved', converted: 'Onboarded', canceled: 'Canceled',
};

export const ALL_STATUSES = Object.keys(STATUS_LABELS).filter((s) => !['triage', 'evaluating', 'needs_review', 'approved', 'converted', 'canceled'].includes(s));

export const REASON_REQUIRED_STATUSES = ['declined', 'lost', 'spam', 'duplicate', 'archived', 'canceled'];

export function statusLabel(status) {
  return STATUS_LABELS[status] || status;
}

export const SAVED_VIEWS = [
  ['mine', 'Assigned to me'],
  ['unassigned', 'Unassigned'],
  ['all', 'All Inquiries'],
  ['awaiting_first_response', 'Awaiting first response'],
  ['claims_expiring', 'Claims expiring soon'],
  ['follow_up', 'Awaiting customer'],
  ['follow_up_overdue', 'Follow-up overdue'],
  ['high_value', 'High-value inquiries'],
  ['recently_onboarded', 'Recently onboarded'],
  ['declined', 'Declined'],
  ['archived', 'Archived'],
];

export function viewLabel(view) {
  const found = SAVED_VIEWS.find(([id]) => id === view);
  return found ? found[1] : 'All Inquiries';
}

export function initials(name) {
  if (!name) return '?';
  const parts = String(name).trim().split(/\s+/).filter(Boolean);
  if (!parts.length) return '?';
  return (parts[0][0] + (parts[1]?.[0] || '')).toUpperCase();
}

/** Deterministic avatar color from a name, so the same person always gets the same color. */
export function avatarColor(name) {
  const palette = ['#2563eb', '#7c3aed', '#db2777', '#ea580c', '#16a34a', '#0891b2', '#ca8a04', '#dc2626'];
  let hash = 0;
  for (const ch of String(name || '')) hash = (hash * 31 + ch.charCodeAt(0)) >>> 0;
  return palette[hash % palette.length];
}

/** "2h ago" / "3d ago" / a short date once it's more than a week old. */
export function relativeTime(value) {
  if (!value) return '';
  const then = parseUtc(value);
  if (!then) return '';
  const diffMs = Date.now() - then.getTime();
  const mins = Math.floor(diffMs / 60000);
  if (mins < 1) return 'just now';
  if (mins < 60) return `${mins}m ago`;
  const hours = Math.floor(mins / 60);
  if (hours < 24) return `${hours}h ago`;
  const days = Math.floor(hours / 24);
  if (days < 7) return `${days}d ago`;
  return then.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
}

export function timeOfDay(value) {
  const d = parseUtc(value);
  return d ? d.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' }) : '';
}

/** DB datetimes are stored/returned as naive UTC strings (see Database.php) — always parse them as UTC explicitly. */
export function parseUtc(value) {
  if (!value) return null;
  const iso = String(value).replace(' ', 'T') + (String(value).endsWith('Z') ? '' : 'Z');
  const d = new Date(iso);
  return Number.isNaN(d.getTime()) ? null : d;
}

/** SLA countdown: "Claim expires in 37 minutes" / "First response overdue" / null once far away. */
export function slaCountdown(dueAt, label = 'Claim expires') {
  const due = parseUtc(dueAt);
  if (!due) return null;
  const diffMin = Math.round((due.getTime() - Date.now()) / 60000);
  if (diffMin <= 0) return { text: `${label.replace('expires', 'overdue')}`, overdue: true };
  if (diffMin < 120) return { text: `${label} in ${diffMin} minute${diffMin === 1 ? '' : 's'}`, overdue: false, warning: diffMin < 30 };
  const hours = Math.round(diffMin / 60);
  return { text: `${label} in ${hours} hour${hours === 1 ? '' : 's'}`, overdue: false, warning: false };
}

export function categoryClass(category) {
  return `cat-${(category || 'none').toLowerCase().replace(/[^a-z0-9_]/g, '_')}`;
}

export function scoreTone(score) {
  if (score === null || score === undefined) return 'low';
  if (score >= 70) return 'high';
  if (score >= 40) return 'medium';
  return 'low';
}

/** Terminal-ish lead statuses where there's no forward-moving deal action left to promote. */
const TERMINAL_LEAD_STATUSES = ['onboarded', 'converted', 'booked', 'lost', 'declined', 'spam', 'duplicate', 'archived', 'canceled'];

/**
 * Ranks the Booking Inbox workspace's bottom action bar into three tiers so
 * the 1-2 next-legal actions dominate instead of a flat 9-button wall:
 *
 *   primary   — filled/primary-styled, 1-2 buttons, the obvious next step
 *   secondary — outline-styled supporting actions, capped around 2-3
 *   overflow  — everything else, tucked behind a "More" menu (assign/
 *               reassign/decline/archive/other status changes)
 *
 * Pure function of (lead, capabilities) — no DOM/API access — so the
 * ranking rules can be read/adjusted in one place without touching
 * inbox-workspace.js's render/bind wiring. Release-claim and approve/deny
 * already live in the workspace header and are deliberately not repeated
 * here. See docs/booking-inbox.md's action-bar ranking rules.
 *
 * @return {{primary: Array<object>, secondary: Array<object>, overflow: Array<object>}}
 */
export function computeActionBar(lead, capabilities = {}) {
  const overflow = [];
  // Only meaningful once this inquiry has been onboarded into a real event
  // (lead.converted_event_id set) — before that there's no event and no
  // deposit_amount to pre-fill a link/QR with. Lives in overflow rather than
  // primary/secondary since it's a supporting action, not the next legal
  // step, and stays available in both the pre-terminal and terminal-status
  // branches below since both return this same `overflow` array.
  if (lead?.converted_event_id) {
    overflow.push({ id: 'deposit-link', label: 'Deposit Link / QR', icon: 'fa-solid fa-qrcode' });
  }
  if (capabilities.assign) overflow.push({ id: 'assign', label: 'Assign', icon: 'fa-solid fa-user-check' });
  if (capabilities.reassign) overflow.push({ id: 'reassign', label: 'Reassign', icon: 'fa-solid fa-right-left' });
  overflow.push(
    { id: 'decline', label: 'Decline', icon: 'fa-regular fa-circle-xmark' },
    { id: 'archive', label: 'Archive', icon: 'fa-solid fa-box-archive' },
    { id: 'more', label: 'Other status…', icon: 'fa-solid fa-ellipsis' },
  );

  if (capabilities.manage === false) {
    return { primary: [], secondary: [], overflow: [] };
  }

  const task = { id: 'task', label: 'Add Task', icon: 'fa-solid fa-list-check' };
  const status = lead?.status;

  if (TERMINAL_LEAD_STATUSES.includes(status)) {
    // Nothing left to move forward on the deal itself once terminal — Add
    // Task is still useful (e.g. a follow-up on an onboarded event); the
    // rest stays reachable via overflow for reopen-adjacent corrections.
    return { primary: [], secondary: capabilities.tasks ? [task] : [], overflow };
  }

  const availability = { id: 'availability', label: 'Send Availability', icon: 'fa-regular fa-calendar-check' };
  const proposal = { id: 'proposal', label: 'Send Proposal', icon: 'fa-regular fa-file-lines' };
  const tour = { id: 'tour', label: 'Schedule Tour', icon: 'fa-solid fa-people-group' };
  const onboard = { id: 'onboard', label: 'Onboard Lead', icon: 'fa-solid fa-user-plus', tone: 'primary-green' };

  let primary;
  let secondary;
  if (status === 'availability_sent') {
    primary = [proposal];
    secondary = [tour];
  } else if (status === 'tour_scheduled') {
    primary = [proposal];
    secondary = [availability];
  } else if (status === 'proposal_sent' || status === 'negotiating') {
    primary = [onboard];
    secondary = [tour];
  } else {
    // new / classified / assigned / claimed / acknowledged / qualifying /
    // awaiting_customer / on_hold — earliest stages default to Onboard as
    // the single next legal step, with Send Availability as the runner-up.
    primary = [onboard];
    secondary = [availability];
  }
  if (capabilities.tasks) secondary.push(task);

  return { primary, secondary, overflow };
}
