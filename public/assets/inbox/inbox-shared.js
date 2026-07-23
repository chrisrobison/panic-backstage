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
