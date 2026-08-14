// ── Recurring events ──────────────────────────────────────────────────────────
// Pure date math + a presentational <pb-recurrence-fields> element shared by
// the event creation wizard (event-wizard.js) and the Event Details page's
// recurrence panel (event-workspace.js). Neither the math nor the element
// talks to the API — they only ever produce/consume a plain `pattern` object
// and the resulting `dates` array; the caller decides what to do with them
// (store in wizard state, or POST to /events/{id}/series).
//
// Deliberately NOT a general RRULE implementation: the weekday / day-of-month
// is always derived from the anchor date rather than separately pickable, so
// "the pattern doesn't match the date" is not a state that can be reached.
// Pattern shape:
//   {
//     freq: 'weekly' | 'monthly_weekday' | 'monthly_date',
//     interval: 1-4,                 // weekly only — "every N weeks"
//     endType: 'after_count' | 'on_date',
//     occurrenceCount: number,       // after_count
//     endDate: 'YYYY-MM-DD',         // on_date
//   }

import { esc, api, openModal, badge, shortDate, publish, $, $$ } from './core.js';

const MAX_OCCURRENCES = 52;
// Rolling booking-horizon cap, alongside MAX_OCCURRENCES — mirrors the
// server-side MAX_HORIZON_DAYS in src/Events/Series.php. This is UX only
// (stops the preview from suggesting dates the server would reject); the
// server remains the authoritative check.
const MAX_HORIZON_DAYS = 90;
const WEEKDAY_NAMES = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
const ORDINAL_NAMES = { 1: 'First', 2: 'Second', 3: 'Third', 4: 'Fourth', '-1': 'Last' };

function parseISO(iso) {
  const [y, m, d] = iso.split('-').map(Number);
  return new Date(y, m - 1, d);
}

function toISO(date) {
  const y = date.getFullYear();
  const m = String(date.getMonth() + 1).padStart(2, '0');
  const d = String(date.getDate()).padStart(2, '0');
  return `${y}-${m}-${d}`;
}

/** The Nth (or, for ordinal -1, last) weekday of a given month. Null if that month has no Nth occurrence (e.g. a 5th Friday). */
function nthWeekdayOfMonth(year, month, weekday, ordinal) {
  if (ordinal === -1) {
    const last = new Date(year, month + 1, 0);
    last.setDate(last.getDate() - ((last.getDay() - weekday + 7) % 7));
    return last;
  }
  const first = new Date(year, month, 1);
  const day = 1 + ((weekday - first.getDay() + 7) % 7) + (ordinal - 1) * 7;
  const date = new Date(year, month, day);
  return date.getMonth() === month ? date : null;
}

/** 1-4, or -1 when `date` is the LAST occurrence of its weekday in its month (covers the rare 5th-occurrence case). */
function ordinalOfDateInMonth(date) {
  const lastOfWeekday = nthWeekdayOfMonth(date.getFullYear(), date.getMonth(), date.getDay(), -1);
  if (lastOfWeekday && lastOfWeekday.getDate() === date.getDate()) return -1;
  return Math.floor((date.getDate() - 1) / 7) + 1;
}

function ordinalSuffix(n) {
  const v = n % 100;
  if (v >= 11 && v <= 13) return `${n}th`;
  return n + ({ 1: 'st', 2: 'nd', 3: 'rd' }[n % 10] || 'th');
}

/** Human label for a pattern, e.g. "Every other Tuesday", "First Thursday of the month". */
function describeRecurrence(anchorISO, pattern) {
  const anchor = parseISO(anchorISO);
  const weekday = WEEKDAY_NAMES[anchor.getDay()];
  if (pattern.freq === 'weekly') {
    const n = Math.max(1, pattern.interval || 1);
    if (n === 1) return `Every ${weekday}`;
    if (n === 2) return `Every other ${weekday}`;
    return `Every ${n} weeks on ${weekday}`;
  }
  if (pattern.freq === 'monthly_weekday') {
    return `${ORDINAL_NAMES[String(ordinalOfDateInMonth(anchor))]} ${weekday} of the month`;
  }
  return `Monthly on the ${ordinalSuffix(anchor.getDate())}`;
}

/** Bounded list of ISO dates (never including the anchor itself), capped at 52. */
function generateOccurrenceDates(anchorISO, pattern) {
  const anchor = parseISO(anchorISO);
  const dates = [];
  const cap = MAX_OCCURRENCES;
  const maxCount = pattern.endType === 'after_count'
    ? Math.min(Math.max(1, parseInt(pattern.occurrenceCount, 10) || 1), cap)
    : cap;
  const endDate = pattern.endType === 'on_date' && pattern.endDate ? parseISO(pattern.endDate) : null;
  // Rolling horizon: today + MAX_HORIZON_DAYS, regardless of the anchor date
  // or end condition — mirrors the server's authoritative check.
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  const horizonCutoff = new Date(today.getFullYear(), today.getMonth(), today.getDate() + MAX_HORIZON_DAYS);

  if (pattern.freq === 'weekly') {
    const interval = Math.max(1, parseInt(pattern.interval, 10) || 1);
    let cursor = new Date(anchor);
    while (dates.length < maxCount) {
      cursor = new Date(cursor.getFullYear(), cursor.getMonth(), cursor.getDate() + interval * 7);
      if (endDate && cursor > endDate) break;
      if (cursor > horizonCutoff) break;
      dates.push(toISO(cursor));
    }
    return dates;
  }

  // monthly_weekday / monthly_date
  const weekday    = anchor.getDay();
  const ordinal    = pattern.freq === 'monthly_weekday' ? ordinalOfDateInMonth(anchor) : null;
  const dayOfMonth = anchor.getDate();
  let y = anchor.getFullYear();
  let m = anchor.getMonth();
  let guard = 0;
  while (dates.length < maxCount && guard < cap * 3) {
    guard++;
    m++;
    if (m > 11) { m = 0; y++; }
    let candidate;
    if (pattern.freq === 'monthly_weekday') {
      candidate = nthWeekdayOfMonth(y, m, weekday, ordinal);
      if (!candidate) continue; // e.g. no 5th Friday this month
    } else {
      const lastDayOfMonth = new Date(y, m + 1, 0).getDate();
      candidate = new Date(y, m, Math.min(dayOfMonth, lastDayOfMonth));
    }
    if (endDate && candidate > endDate) break;
    if (candidate > horizonCutoff) break;
    dates.push(toISO(candidate));
  }
  return dates;
}

function previewLabel(date) {
  return parseISO(date).toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' });
}

/**
 * The earliest date `pattern` could ever produce (ignoring whatever end
 * condition is currently set) — e.g. anchor+7d for weekly, ~anchor+1mo for
 * monthly. Used to bound the "On date" end-date picker so a user can't pick
 * an end date that's guaranteed to yield zero occurrences (which previously
 * made the Create button silently go back to disabled with no explanation).
 */
function firstOccurrenceDate(anchorISO, pattern) {
  const [date] = generateOccurrenceDates(anchorISO, { ...pattern, endType: 'after_count', occurrenceCount: 1 });
  return date || anchorISO;
}

// ── <pb-recurrence-fields> ────────────────────────────────────────────────────
// Presentational only: checkbox + frequency + end-condition + a live preview.
// Emits `change` with `{ pattern, dates, description }` (or `null` when the
// checkbox is off) any time a control changes. Set `.anchorDate` (a
// 'YYYY-MM-DD' string) before/whenever the underlying event date is known.
class RecurrenceFields extends HTMLElement {
  constructor() {
    super();
    this._anchorDate = '';
    this._state = {
      enabled: false,
      freq: 'weekly',
      interval: 1,
      monthlyMode: 'monthly_weekday',
      endType: 'after_count',
      occurrenceCount: 12,
      endDate: '',
    };
  }

  connectedCallback() {
    this.render();
    // One delegated listener survives re-renders since it's bound to `this`,
    // not the (replaced) inner controls. `change` (not `input`) so number/date
    // fields commit on blur rather than re-rendering (and stealing focus)
    // after every keystroke — mirrors EventDetailsForm's autosave binding.
    this.addEventListener('change', (e) => this._onChange(e));
  }

  set anchorDate(value) {
    this._anchorDate = value || '';
    this.render();
  }

  get anchorDate() {
    return this._anchorDate;
  }

  /** Current computed value, or null when the checkbox is unchecked / no anchor date yet. */
  get value() {
    if (!this._state.enabled || !this._anchorDate) return null;
    const pattern = this._pattern();
    const dates = generateOccurrenceDates(this._anchorDate, pattern);
    if (!dates.length) return null;
    return { pattern, dates, description: describeRecurrence(this._anchorDate, pattern) };
  }

  _pattern() {
    const s = this._state;
    return {
      freq: s.freq === 'monthly' ? s.monthlyMode : 'weekly',
      interval: s.interval,
      endType: s.endType,
      occurrenceCount: s.occurrenceCount,
      endDate: s.endDate,
    };
  }

  _onChange(e) {
    const el = e.target;
    if (!el.name || !this.contains(el)) return;
    // The native 'change' from an internal control is an implementation
    // detail — consumers (EventRecurrencePanel, the New Event wizard) are
    // only meant to see the synthesized 'change' dispatched below, which
    // carries the coherent computed `detail`. Without stopping it here,
    // that native event keeps bubbling after this handler returns and
    // reaches any listener bound to `this` (e.g. the panel's) a *second*
    // time with `detail: undefined` — right after the synthesized dispatch
    // (nested inside this same call) already ran and set it correctly, so
    // the bogus second firing clobbers it. Net effect: the Create button
    // computed the right value for an instant, then immediately reverted
    // to disabled, every single time — "check the box, fill out the form,
    // click create, nothing happens".
    e.stopImmediatePropagation();
    switch (el.name) {
      case 'rf_enabled':      this._state.enabled = el.checked; break;
      case 'rf_freq':         this._state.freq = el.value; break;
      case 'rf_interval':     this._state.interval = Number(el.value); break;
      case 'rf_monthly_mode': this._state.monthlyMode = el.value; break;
      case 'rf_end_type':     this._state.endType = el.value; break;
      case 'rf_occurrences':  this._state.occurrenceCount = Number(el.value); break;
      case 'rf_end_date':     this._state.endDate = el.value; break;
      default: return;
    }
    this.render();
    this.dispatchEvent(new CustomEvent('change', { detail: this.value, bubbles: true }));
  }

  render() {
    const s = this._state;
    const hasAnchor = Boolean(this._anchorDate);
    const weekday = hasAnchor ? WEEKDAY_NAMES[parseISO(this._anchorDate).getDay()] : '';
    const weeklyText  = hasAnchor ? describeRecurrence(this._anchorDate, { freq: 'weekly', interval: s.interval }) : '';
    const monthlyWeekdayText = hasAnchor ? describeRecurrence(this._anchorDate, { freq: 'monthly_weekday' }) : '';
    const monthlyDateText    = hasAnchor ? describeRecurrence(this._anchorDate, { freq: 'monthly_date' }) : '';

    if (!hasAnchor) {
      this.innerHTML = `<p class="field-hint muted small">Set a date above to configure a recurring pattern.</p>`;
      return;
    }

    const val = s.enabled ? this.value : null;
    const preview = val ? val.dates : [];
    const minEndDate = firstOccurrenceDate(this._anchorDate, this._pattern());
    const previewHtml = s.enabled
      ? (preview.length
          ? `<ul class="recurrence-preview">
               ${preview.slice(0, 5).map((d) => `<li>${esc(previewLabel(d))}</li>`).join('')}
               ${preview.length > 5 ? `<li class="muted">+${preview.length - 5} more</li>` : ''}
             </ul>`
          : `<p class="field-hint error-text small">No matching dates in that range — the earliest possible occurrence is ${esc(previewLabel(minEndDate))}. Pick a later end date, or switch to "After N occurrences".</p>`)
      : '';

    this.innerHTML = `
      <label class="check-label"><input type="checkbox" name="rf_enabled" ${s.enabled ? 'checked' : ''}> Recurring event</label>
      ${s.enabled ? `
        <div class="recurrence-body">
          <label>Repeats
            <select name="rf_freq">
              <option value="weekly"${s.freq === 'weekly' ? ' selected' : ''}>Weekly</option>
              <option value="monthly"${s.freq === 'monthly' ? ' selected' : ''}>Monthly</option>
            </select>
          </label>
          ${s.freq === 'weekly' ? `
            <label>Every
              <select name="rf_interval">
                <option value="1"${s.interval === 1 ? ' selected' : ''}>Week</option>
                <option value="2"${s.interval === 2 ? ' selected' : ''}>2 weeks (every other)</option>
                <option value="3"${s.interval === 3 ? ' selected' : ''}>3 weeks</option>
                <option value="4"${s.interval === 4 ? ' selected' : ''}>4 weeks</option>
              </select>
              <span class="field-hint muted small">on ${esc(weekday)} — ${esc(weeklyText)}</span>
            </label>
          ` : `
            <label class="check-label"><input type="radio" name="rf_monthly_mode" value="monthly_weekday" ${s.monthlyMode === 'monthly_weekday' ? 'checked' : ''}> ${esc(monthlyWeekdayText)}</label>
            <label class="check-label"><input type="radio" name="rf_monthly_mode" value="monthly_date" ${s.monthlyMode === 'monthly_date' ? 'checked' : ''}> ${esc(monthlyDateText)}</label>
          `}
          <p class="form-section-head wide">Ends</p>
          <label class="check-label">
            <input type="radio" name="rf_end_type" value="after_count" ${s.endType === 'after_count' ? 'checked' : ''}>
            After <input type="number" name="rf_occurrences" min="1" max="${MAX_OCCURRENCES}" value="${esc(s.occurrenceCount)}" ${s.endType !== 'after_count' ? 'disabled' : ''} style="width:4em"> occurrences
          </label>
          <label class="check-label">
            <input type="radio" name="rf_end_type" value="on_date" ${s.endType === 'on_date' ? 'checked' : ''}>
            On date <input type="date" name="rf_end_date" min="${esc(minEndDate)}" value="${esc(s.endDate)}" ${s.endType !== 'on_date' ? 'disabled' : ''}>
          </label>
          <p class="field-hint muted small wide">Max ${MAX_OCCURRENCES} occurrences or ${MAX_HORIZON_DAYS} days out, whichever comes first. Each occurrence becomes its own independent event.</p>
          ${previewHtml}
        </div>
      ` : ''}
    `;
  }
}

customElements.define('pb-recurrence-fields', RecurrenceFields);

// ── Room-conflict resolution ────────────────────────────────────────────────
// Shared by every entry point that can create/extend a recurring series —
// the Recurrence panel (event-workspace.js's EventRecurrencePanel), the New
// Event wizard (event-wizard.js), and the AI Assistant drawer's "Apply" on a
// propose_recurring_series proposal (ai-drawer.js). A room conflict on one or
// more generated dates no longer rejects the whole pattern outright: the
// server's POST /events/{id}/series/conflicts preflight (always 200, never
// throws for "there are conflicts" — only for a genuinely malformed request)
// reports which dates collide and which other rooms are free that day, and
// this module turns that into the "Resolve recurring-event conflicts"
// dialog. Each caller still does its own actual series create/apply POST
// afterward — this module only gets the caller from "here's the dates I want"
// to "here's the dates to actually submit, plus any room reassignments",
// same shape either way: `{ dates, roomOverrides }`.

/** Calls the conflicts preflight. Returns the (possibly empty) conflicts array; throws on a genuine validation failure (bad pattern shape, too many occurrences, beyond the 90-day horizon) — the same error the actual create/apply call would throw, just caught earlier. */
async function checkSeriesConflicts(eventId, dates) {
  const res = await api(`/events/${eventId}/series/conflicts`, {
    method: 'POST',
    body: JSON.stringify({ dates }),
  });
  return res.conflicts || [];
}

/**
 * Renders the resolution dialog — one row per date in `dates`; conflicted
 * ones (per `conflicts`) get a resolution <select> (skip, or move to one of
 * that date's free rooms), clean ones are listed with no control. Resolves
 * to `{ dates: finalDates, roomOverrides }` (skipped dates simply omitted)
 * when the user clicks Continue, or `null` if they back out — via the
 * Cancel button, Escape, the header Close button, or the backdrop — treated
 * uniformly via openModal()'s `onClose` hook so none of those paths can
 * leave the caller awaiting a Promise that never settles.
 */
function openSeriesConflictModal(dates, conflicts) {
  return new Promise((resolve) => {
    let settled = false;
    const finishOnce = (result) => {
      if (settled) return;
      settled = true;
      resolve(result);
    };

    const conflictByDate = new Map(conflicts.map((c) => [c.date, c]));
    const rows = dates.map((date) => {
      const conflict = conflictByDate.get(date);
      const dateLabel = esc(shortDate(new Date(`${date}T12:00:00`)));
      if (!conflict) {
        return `<tr data-date="${esc(date)}"><td>${dateLabel}</td><td class="muted small">No conflict</td><td></td></tr>`;
      }
      const roomOptions = conflict.available_rooms.map((r) => `<option value="${esc(r.id)}">Move to ${esc(r.name)}</option>`).join('');
      return `<tr data-date="${esc(date)}" data-conflict>
        <td>${dateLabel}</td>
        <td>Conflicts with <a href="#event-${esc(String(conflict.conflict_event_id))}" target="_blank">${esc(conflict.conflict_title)}</a> ${badge(conflict.conflict_status)}</td>
        <td><select data-resolution><option value="">Skip this date</option>${roomOptions}</select></td>
      </tr>`;
    }).join('');

    const { dialog, close } = openModal({
      title: 'Resolve recurring-event conflicts',
      wide: true,
      bodyHtml: `<div class="modal-card-body padded">
        <p>${conflicts.length} of ${dates.length} date${dates.length === 1 ? '' : 's'} in this pattern conflict with an existing booking. For each conflicted date, move it to a free room or skip it — the rest of the series is unaffected either way.</p>
        <table class="data-table recurrence-conflict-table">
          <thead><tr><th>Date</th><th>Conflict</th><th>Resolution</th></tr></thead>
          <tbody>${rows}</tbody>
        </table>
        <div class="calendar-actions">
          <button type="button" class="secondary" data-cancel-conflicts>Cancel</button>
          <button type="button" data-resolve-conflicts>Continue</button>
        </div>
      </div>`,
      onClose: () => finishOnce(null),
    });

    $('[data-cancel-conflicts]', dialog).addEventListener('click', close);
    $('[data-resolve-conflicts]', dialog).addEventListener('click', () => {
      const finalDates = [];
      const roomOverrides = {};
      $$('tr[data-date]', dialog).forEach((row) => {
        const date = row.dataset.date;
        const resolution = $('select[data-resolution]', row);
        if (!resolution) { finalDates.push(date); return; } // no conflict on this date
        if (!resolution.value) return; // skipped
        finalDates.push(date);
        roomOverrides[date] = Number(resolution.value);
      });
      if (!finalDates.length) {
        publish('toast.show', { message: 'Every conflicted date was skipped, and there were no clean dates left to create.', tone: 'error' });
        return; // keep the dialog open — nothing to resolve to yet
      }
      finishOnce({ dates: finalDates, roomOverrides });
      close();
    });
  });
}

/**
 * The one-call orchestrator most callers want: checks `dates` for room
 * conflicts and, if any exist, opens the resolution dialog. Returns
 * `{ dates, roomOverrides }` to submit — either `dates` unchanged with an
 * empty `roomOverrides` (the clean-pattern case, no dialog shown at all) or
 * whatever the user resolved in the dialog — or `null` if the user backed
 * out of a shown dialog (never null for a clean pattern; there's nothing to
 * cancel out of). Throws on the same preflight validation failures
 * checkSeriesConflicts() does. Callers that need finer control over UI state
 * around the preflight network call (e.g. a submit button's disabled/label
 * state — see EventRecurrencePanel.submitSeries()) can call
 * checkSeriesConflicts()/openSeriesConflictModal() directly instead.
 */
async function resolveSeriesConflicts(eventId, dates) {
  const conflicts = await checkSeriesConflicts(eventId, dates);
  if (!conflicts.length) return { dates, roomOverrides: {} };
  return openSeriesConflictModal(dates, conflicts);
}

export { generateOccurrenceDates, describeRecurrence, checkSeriesConflicts, openSeriesConflictModal, resolveSeriesConflicts };
