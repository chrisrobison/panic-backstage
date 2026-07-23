// openOnboardDialog(lead) — the "Onboard Lead" review dialog. Uses the
// shared openModal() (core.js) rather than a hand-rolled overlay, per this
// app's UI convention. Phase 6 ships the review-and-confirm flow calling
// the existing convert()-backed /onboard endpoint; Phase 8 deepens this
// with duplicate-detection surfacing, availability-conflict display, a
// task-checklist template picker, and calendar-hold options server-side —
// the dialog already has fields for all of it, they're just not yet wired
// to that richer response shape.
import { esc, api, publish, openModal, formData, $ } from '../core.js';

export function openOnboardDialog(lead, onDone) {
  const { dialog, close } = openModal({
    title: `Onboard Lead — ${lead.contact_org || lead.contact_name || 'Inquiry'}`,
    wide: true,
    bodyHtml: `
      <form class="grid-form padded" data-onboard-form>
        <p class="muted wide">Review the extracted details below, correct anything that's wrong, then confirm to create the event opportunity. This does <strong>not</strong> mark the event as booked.</p>
        <label>Event title<input type="text" name="title" value="${esc(lead.event_name || '')}" required></label>
        <label>Event type
          <select name="event_type">
            ${['live_music', 'karaoke', 'open_mic', 'promoter_night', 'dj_night', 'comedy', 'private_event', 'special_event']
              .map((t) => `<option value="${t}" ${t === lead.event_type ? 'selected' : ''}>${esc(t.replace(/_/g, ' '))}</option>`).join('')}
          </select>
        </label>
        <label>Date<input type="date" name="date" value="${esc(lead.desired_date || '')}" required></label>
        <label>Estimated guests<input type="number" name="estimated_guests" value="${esc(lead.projected_attendance || '')}"></label>
        <label class="wide">Notes carried over from the inquiry<textarea name="notes" rows="3" readonly>${esc(lead.notes || '')}</textarea></label>
        <div class="wide" data-onboard-warnings></div>
        <div class="wide"><button type="submit">Onboard Lead</button></div>
      </form>`,
    focus: '[name="title"]',
  });

  const form = $('[data-onboard-form]', dialog);
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const fd = formData(form);
    try {
      const res = await api(`/leads/${lead.id}/onboard`, { method: 'POST', body: JSON.stringify(fd) });
      publish('toast.show', { message: `Onboarded — event #${res.event_id} created.` });
      close();
      onDone?.(res);
    } catch (err) {
      const box = $('[data-onboard-warnings]', form);
      if (box) box.innerHTML = `<div class="ib-conflict-warning">${esc(err.message)}</div>`;
    }
  });
}
