// <pb-inbox-detail-panel> — the right-side info panel: contact/org,
// event overview, AI classification + confidence, routing explanation,
// related/duplicate inquiries. Read-only render of `.data = { lead,
// classification, routingExplanation, duplicates }`; the one interactive
// bit (editing contact fields) posts straight to the Leads PATCH endpoint
// and asks the parent to refresh via a bubbling event, same as everywhere
// else in this app.
import { esc, api, publish, PanicElement } from '../core.js';

class InboxDetailPanel extends PanicElement {
  set data(value) {
    this._data = value || {};
    this.render();
  }
  get data() { return this._data || {}; }

  connect() { this.render(); }

  render() {
    const { lead, classification, routingExplanation, duplicates = [] } = this.data;
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

      ${classification ? `
      <div class="ib-detail-section">
        <div class="ib-detail-section-head"><h3>AI Classification</h3></div>
        <div class="ib-ai-panel">
          ${extracted ? Object.entries(extracted).filter(([, v]) => v !== null && v !== '').slice(0, 6).map(([k, v]) => row(labelize(k), v, fieldConfidence?.[k])).join('') : ''}
          <div class="ib-ai-confidence">
            Overall confidence: ${classification.overall_confidence != null ? Math.round(classification.overall_confidence * 100) + '%' : '—'}
            ${classification.spam_probability != null ? ` · Spam probability: ${Math.round(classification.spam_probability * 100)}%` : ''}
          </div>
          ${classification.recommended_action ? `<div class="ib-ai-confidence"><strong>Suggested:</strong> ${esc(classification.recommended_action)}</div>` : ''}
        </div>
      </div>` : ''}

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
