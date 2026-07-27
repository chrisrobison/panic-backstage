// <pb-inbox-detail-panel> — the right-side info panel: contact/org,
// event overview, AI classification + confidence, routing explanation,
// related/duplicate inquiries, plus audited human classification corrections.
import { esc, api, openModal, publish, PanicElement, $ } from '../core.js';

class InboxDetailPanel extends PanicElement {
  set data(value) {
    this._data = value || {};
    this.render();
  }
  get data() { return this._data || {}; }

  connect() { this.render(); }

  render() {
    const { lead, classification, routingExplanation, duplicates = [], canManage = false } = this.data;
    if (!lead) { this.innerHTML = ''; return; }

    const extracted = classification ? safeJson(classification.extracted_json) : null;
    const fieldConfidence = classification ? safeJson(classification.field_confidence_json) : null;

    this.innerHTML = `
      <div class="ib-detail-section">
        <div class="ib-detail-section-head"><h3>Contact Information</h3></div>
        ${row('Name', lead.contact_name)}
        ${row('Email', lead.contact_email)}
        ${row('Phone', lead.contact_phone)}
        ${row('Company', lead.contact_org)}
        ${row('Source', lead.source)}
      </div>

      <div class="ib-detail-section">
        <div class="ib-detail-section-head"><h3>Event Overview</h3></div>
        ${row('Event Type', lead.event_type)}
        ${row('Category', lead.event_category)}
        ${row('Genre', lead.music_genre)}
        ${row('Date', lead.desired_date)}
        ${row('Guests', lead.projected_attendance)}
        ${row('Budget', lead.budget ? `$${Number(lead.budget).toLocaleString()}` : null)}
        ${row('Age restriction', lead.age_restriction)}
      </div>

      <div class="ib-detail-section">
        <div class="ib-detail-section-head"><h3>Classification</h3>${canManage ? '<button type="button" class="small secondary" data-edit-classification title="Correct classification" aria-label="Correct classification"><i class="fa-solid fa-pen" aria-hidden="true"></i></button>' : ''}</div>
        ${classification ? `
        <div class="ib-ai-panel">
          ${extracted ? Object.entries(extracted).filter(([, v]) => v !== null && v !== '').slice(0, 6).map(([k, v]) => row(labelize(k), v, fieldConfidence?.[k])).join('') : ''}
          <div class="ib-ai-confidence">
            ${classification.source === 'human_correction' ? 'Corrected by staff' : `Overall confidence: ${classification.overall_confidence != null ? Math.round(classification.overall_confidence * 100) + '%' : '—'}`}
            ${classification.spam_probability != null ? ` · Spam probability: ${Math.round(classification.spam_probability * 100)}%` : ''}
          </div>
          ${classification.recommended_action ? `<div class="ib-ai-confidence"><strong>Suggested:</strong> ${esc(classification.recommended_action)}</div>` : ''}
        </div>` : '<div class="ib-ai-confidence">Not classified</div>'}
      </div>

      ${routingExplanation ? `
      <div class="ib-detail-section">
        <div class="ib-detail-section-head"><h3>Routing Explanation</h3></div>
        <div class="ib-routing-explanation">${esc(routingExplanation)}</div>
      </div>` : ''}

      ${duplicates.length ? `
      <div class="ib-detail-section">
        <div class="ib-detail-section-head"><h3>Related / Duplicate Inquiries</h3></div>
        ${duplicates.map((d) => `<div class="ib-detail-row"><span class="k">#${esc(String(d.id))}</span><span class="v">${esc(d.contact_name || '')} — ${esc(d.status)}</span></div>`).join('')}
      </div>` : ''}
    `;

    $('[data-edit-classification]', this)?.addEventListener('click', () => this.editClassification(extracted || {}));
  }

  editClassification(extracted) {
    const { lead } = this.data;
    const values = {
      event_type: extracted.event_type ?? lead.event_type ?? '',
      event_category: extracted.event_category ?? lead.event_category ?? '',
      music_genre: extracted.music_genre ?? lead.music_genre ?? '',
      age_restriction: extracted.age_restriction ?? lead.age_restriction ?? '',
      attendance: extracted.attendance ?? lead.projected_attendance ?? '',
      budget: extracted.budget ?? lead.budget ?? '',
      urgency: extracted.urgency ?? '',
    };
    const { dialog, close } = openModal({
      title: 'Correct Classification',
      bodyHtml: `<form class="grid-form padded" data-classification-form>
        <label>Event type<input name="event_type" value="${esc(values.event_type)}"></label>
        <label>Category<input name="event_category" value="${esc(values.event_category)}"></label>
        <label>Genre<input name="music_genre" value="${esc(values.music_genre)}"></label>
        <label>Age restriction<input name="age_restriction" value="${esc(values.age_restriction)}"></label>
        <label>Attendance<input type="number" min="1" name="attendance" value="${esc(values.attendance)}"></label>
        <label>Budget<input type="number" min="0" step="0.01" name="budget" value="${esc(values.budget)}"></label>
        <label class="wide">Urgency<select name="urgency"><option value=""></option>${['low', 'medium', 'high'].map((value) => `<option value="${value}" ${values.urgency === value ? 'selected' : ''}>${value}</option>`).join('')}</select></label>
        <div class="wide"><button type="submit">Save Correction</button></div>
      </form>`,
    });
    $('[data-classification-form]', dialog)?.addEventListener('submit', async (event) => {
      event.preventDefault();
      const fields = Object.fromEntries(new FormData(event.currentTarget).entries());
      for (const key of ['attendance', 'budget']) {
        fields[key] = fields[key] === '' ? null : Number(fields[key]);
      }
      try {
        await api(`/leads/${lead.id}/classification`, {
          method: 'PATCH',
          body: JSON.stringify(fields),
        });
        close();
        publish('toast.show', { message: 'Classification corrected.' });
        this.dispatchEvent(new CustomEvent('inbox-lead-changed', { bubbles: true, detail: { leadId: lead.id } }));
      } catch (err) {
        publish('toast.show', { message: err.message, tone: 'error' });
      }
    });
  }
}

function row(label, value, confidence) {
  if (value === null || value === undefined || value === '') return '';
  const conf = typeof confidence === 'number' ? ` <span class="ib-ai-confidence" style="display:inline">(${Math.round(confidence * 100)}%)</span>` : '';
  return `<div class="ib-detail-row"><span class="k">${esc(label)}</span><span class="v">${esc(String(value))}${conf}</span></div>`;
}

function labelize(key) {
  return String(key).replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

function safeJson(value) {
  if (!value) return null;
  if (typeof value === 'object') return value;
  try { return JSON.parse(value); } catch { return null; }
}

customElements.define('pb-inbox-detail-panel', InboxDetailPanel);
