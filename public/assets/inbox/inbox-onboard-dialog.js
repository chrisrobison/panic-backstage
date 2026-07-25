// openOnboardDialog(lead) — the "Onboard Lead" review dialog. Uses the
// shared openModal() (core.js) rather than a hand-rolled overlay, per this
// app's UI convention.
//
// Fetches a preview (GET /api/leads/{id}/onboard — no side effects) up
// front so duplicate inquiries/events and a same-date calendar conflict are
// visible *before* the user commits, matching the spec's review-dialog
// steps; the actual creation (POST, same endpoint) is a separate explicit
// action. Server-side logic lives in src/Leads/Onboarding.php.
import { esc, api, publish, openModal, formData, $ } from '../core.js';

const EVENT_TYPES = ['live_music', 'karaoke', 'open_mic', 'promoter_night', 'dj_night', 'comedy', 'private_event', 'special_event'];

export async function openOnboardDialog(lead, onDone) {
  const { dialog, close } = openModal({
    title: `Onboard Lead — ${lead.contact_org || lead.contact_name || 'Inquiry'}`,
    wide: true,
    bodyHtml: `
      <form class="grid-form padded" data-onboard-form>
        <p class="muted wide">Review the extracted details below, correct anything that's wrong, then confirm to create the event opportunity. This does <strong>not</strong> mark the event as booked.</p>
        <div class="wide" data-onboard-preview><p class="muted">Checking for duplicates and calendar conflicts…</p></div>
        <label>Event title<input type="text" name="title" value="${esc(lead.event_name || '')}" required></label>
        <label>Event type
          <select name="event_type">
            ${EVENT_TYPES.map((t) => `<option value="${t}" ${t === lead.event_type ? 'selected' : ''}>${esc(t.replace(/_/g, ' '))}</option>`).join('')}
          </select>
        </label>
        <label>Date<input type="date" name="date" value="${esc(lead.desired_date || '')}" required data-date-input></label>
        <label>Estimated guests<input type="number" name="estimated_guests" value="${esc(lead.projected_attendance || '')}"></label>
        <label>Initial task checklist<select name="task_template_id" data-template-select><option value="">None</option></select></label>
        <label class="wide">Notes carried over from the inquiry<textarea name="notes" rows="3" readonly>${esc(lead.notes || '')}</textarea></label>
        <div class="wide" data-onboard-warnings></div>
        <div class="wide"><button type="submit">Onboard Lead</button></div>
      </form>`,
    focus: '[name="title"]',
  });

  const form = $('[data-onboard-form]', dialog);
  const previewBox = $('[data-onboard-preview]', form);

  async function loadPreview() {
    try {
      const date = $('[data-date-input]', form)?.value || lead.desired_date || '';
      const res = await api(`/leads/${lead.id}/onboard?date=${encodeURIComponent(date)}`);
      renderPreview(res);
      const select = $('[data-template-select]', form);
      if (select && res.templates?.length) {
        select.innerHTML = '<option value="">None</option>' + res.templates.map((t) => `<option value="${t.id}">${esc(t.name)}</option>`).join('');
      }
    } catch (err) {
      if (previewBox) previewBox.innerHTML = `<p class="muted">Couldn't load duplicate/availability checks: ${esc(err.message)}</p>`;
    }
  }

  function renderPreview(res) {
    if (!previewBox) return;
    const parts = [];
    if (!res.availability?.available) {
      parts.push(`<div class="ib-conflict-warning"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i> Potential calendar conflict: event #${esc(String(res.availability.conflict_event_id))} is already on the calendar for this date at this venue.</div>`);
    }
    for (const dup of res.duplicates || []) {
      parts.push(`<div class="ib-conflict-warning"><i class="fa-solid fa-clone" aria-hidden="true"></i> Possible duplicate ${esc(dup.kind)} #${esc(String(dup.id))}: ${esc(dup.label)}</div>`);
    }
    previewBox.innerHTML = parts.length ? parts.join('') : '<p class="muted">No duplicates or calendar conflicts found.</p>';
  }

  $('[data-date-input]', form)?.addEventListener('change', loadPreview);
  await loadPreview();

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const fd = formData(form);
    try {
      const res = await api(`/leads/${lead.id}/onboard`, { method: 'POST', body: JSON.stringify(fd) });
      publish('toast.show', { message: `Onboarded — event #${res.event_id} created${res.tasks_created ? ` with ${res.tasks_created} starter task(s)` : ''}.` });
      close();
      onDone?.(res);
    } catch (err) {
      const box = $('[data-onboard-warnings]', form);
      if (box) box.innerHTML = `<div class="ib-conflict-warning">${esc(err.message)}</div>`;
    }
  });
}
