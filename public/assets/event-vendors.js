// ── Event Vendors panel ───────────────────────────────────────────────────────
// Tracks vendors, COI status, and cost figures for an event.
import { esc, titleCase, api, emptyState, money, publish, can, PanicElement, addToggle, bindAddToggle, $, $$ } from './core.js';

const SERVICE_CATEGORIES = [
  'catering', 'bar_service', 'photography', 'videography', 'florist',
  'entertainment', 'security', 'sound_production', 'lighting', 'rentals',
  'transportation', 'cleaning', 'staffing', 'decorator', 'dj', 'other',
];

const COI_STATUSES  = ['not_required', 'pending', 'received', 'expired'];
const PAY_STATUSES  = ['unpaid', 'invoiced', 'paid', 'refunded'];

/** Map a COI status to a badge tone class. */
function coiTone(status) {
  return { not_required: 'neutral', pending: 'warn', received: 'ok', expired: 'danger' }[status] || 'neutral';
}

/** Map a payment status to a badge tone class. */
function payTone(status) {
  return { unpaid: 'neutral', invoiced: 'warn', paid: 'ok', refunded: 'orange' }[status] || 'neutral';
}

/** Inline status badge without relying on the generic badge() helper. */
function statusBadge(value, tone) {
  return `<span class="badge ${esc(tone)}">${esc(titleCase(value.replace(/_/g, ' ')))}</span>`;
}

/** Category cell display. */
function categoryLabel(cat) {
  return titleCase((cat || '').replace(/_/g, ' '));
}

/** Build the <select> for service_category with an optional selected value. */
function categorySelect(selected) {
  const opts = SERVICE_CATEGORIES.map((c) =>
    `<option value="${esc(c)}"${c === selected ? ' selected' : ''}>${esc(categoryLabel(c))}</option>`
  ).join('');
  return `<select name="service_category">${opts}</select>`;
}

/** Build the add-vendor inline form shown at the top of the panel. */
function addVendorForm(eventId) {
  return `<form class="add-form grid-form padded" data-add-form hidden data-api="/events/${esc(String(eventId))}/vendors" data-method="POST">
    <div class="form-row">
      <label>Vendor Name* <input name="vendor_name" required placeholder="Acme Catering"></label>
      <label>Category* ${categorySelect('')}</label>
      <label>Contact Name <input name="contact_name" placeholder="Jane Smith"></label>
      <label>Contact Email <input type="email" name="contact_email" placeholder="jane@example.com"></label>
      <label>Quote Amount <input type="number" step="0.01" min="0" name="quote_amount" placeholder="0.00"></label>
      <label class="wide">Notes <textarea name="notes" placeholder="Any special notes…" rows="2"></textarea></label>
    </div>
    <div class="form-actions">
      <button type="submit">Add Vendor</button>
      <button type="button" class="secondary small" data-cancel-add>Cancel</button>
    </div>
  </form>`;
}

/** Build the inline edit form for an existing vendor row. */
function editVendorForm(v, eventId) {
  const coiOpts = COI_STATUSES.map((s) =>
    `<option value="${esc(s)}"${s === v.coi_status ? ' selected' : ''}>${esc(titleCase(s.replace(/_/g, ' ')))}</option>`
  ).join('');
  const payOpts = PAY_STATUSES.map((s) =>
    `<option value="${esc(s)}"${s === v.payment_status ? ' selected' : ''}>${esc(titleCase(s.replace(/_/g, ' ')))}</option>`
  ).join('');

  return `<form class="row-form record-form" data-api="/events/${esc(String(eventId))}/vendors/${esc(String(v.id))}" data-method="PATCH" data-edit-form>
    <div class="form-row">
      <label>Vendor Name <input name="vendor_name" value="${esc(v.vendor_name)}"></label>
      <label>Category ${categorySelect(v.service_category)}</label>
      <label>Contact Name <input name="contact_name" value="${esc(v.contact_name || '')}"></label>
      <label>Contact Email <input type="email" name="contact_email" value="${esc(v.contact_email || '')}"></label>
      <label>Quote <input type="number" step="0.01" min="0" name="quote_amount" value="${esc(v.quote_amount ?? '')}"></label>
      <label>Approved <input type="number" step="0.01" min="0" name="approved_amount" value="${esc(v.approved_amount ?? '')}"></label>
      <label>Actual <input type="number" step="0.01" min="0" name="actual_amount" value="${esc(v.actual_amount ?? '')}"></label>
      <label>COI Status <select name="coi_status">${coiOpts}</select></label>
      <label>Payment <select name="payment_status">${payOpts}</select></label>
      <label class="wide">Notes <textarea name="notes" rows="2">${esc(v.notes || '')}</textarea></label>
    </div>
    <div class="form-actions">
      <button type="submit">Save</button>
      <button type="button" class="secondary small" data-cancel>Cancel</button>
      <button type="button" class="small danger" data-delete="${esc(String(v.id))}">Delete</button>
    </div>
  </form>`;
}

/** Render one vendor as a pair of table rows: a read row + a hidden edit row. */
function vendorRow(v, canEdit, eventId) {
  const confirmed = v.confirmed_at
    ? '<span style="color:var(--color-ok,#2a9d3f)">&#10003; Confirmed</span>'
    : '<span style="color:var(--color-muted,#888)">Pending</span>';

  const dataRow = `<tr data-record data-vendor-id="${esc(String(v.id))}">
    <td data-label="Vendor">${esc(v.vendor_name)}</td>
    <td data-label="Category">${esc(categoryLabel(v.service_category))}</td>
    <td data-label="Contact">${v.contact_name ? esc(v.contact_name) : '<span class="record-empty">—</span>'}${v.contact_email ? `<br><a href="mailto:${esc(v.contact_email)}">${esc(v.contact_email)}</a>` : ''}${v.contact_phone ? `<br>${esc(v.contact_phone)}` : ''}</td>
    <td data-label="Quote">${v.quote_amount != null ? esc(money(v.quote_amount)) : '<span class="record-empty">—</span>'}</td>
    <td data-label="Approved">${v.approved_amount != null ? esc(money(v.approved_amount)) : '<span class="record-empty">—</span>'}</td>
    <td data-label="Actual">${v.actual_amount != null ? esc(money(v.actual_amount)) : '<span class="record-empty">—</span>'}</td>
    <td data-label="COI">${statusBadge(v.coi_status || 'not_required', coiTone(v.coi_status || 'not_required'))}</td>
    <td data-label="Payment">${statusBadge(v.payment_status || 'unpaid', payTone(v.payment_status || 'unpaid'))}</td>
    <td data-label="Confirmed">${confirmed}</td>
    <td class="actions-cell">${canEdit ? `<button type="button" class="record-edit small" data-edit aria-label="Edit vendor" title="Edit"><i class="fa-solid fa-pen" aria-hidden="true"></i></button>` : ''}</td>
  </tr>`;

  const editRow = canEdit
    ? `<tr class="vendor-edit-row" style="display:none"><td colspan="10">${editVendorForm(v, eventId)}</td></tr>`
    : '';

  return dataRow + editRow;
}

/** Sum a numeric field across all vendors, treating null/undefined as 0. */
function sumField(vendors, field) {
  return vendors.reduce((acc, v) => acc + (parseFloat(v[field]) || 0), 0);
}

class EventVendors extends PanicElement {
  set data(value) {
    this._data = value;
    if (this.abort) this.loadVendors();
  }

  get data() {
    return this._data;
  }

  connect() {
    if (this._data) this.loadVendors();
  }

  get eventId() {
    return this._data?.event?.id;
  }

  get canEdit() {
    return can(this._data, 'manage_vendors');
  }

  async loadVendors() {
    if (!this.eventId) return;
    this._ac?.abort();
    this._ac = new AbortController();
    try {
      const result = await api(`/events/${this.eventId}/vendors`);
      this._vendors = result.vendors || [];
      this.render();
    } catch (err) {
      this.innerHTML = `<section class="panel"><div class="padded" style="color:var(--color-danger)">${esc(err.message || 'Failed to load vendors.')}</div></section>`;
    }
  }

  render() {
    const vendors  = this._vendors || [];
    const canEdit  = this.canEdit;
    const eventId  = this.eventId;

    const thead = `<thead><tr>
      <th>Vendor</th>
      <th>Category</th>
      <th>Contact</th>
      <th>Quote</th>
      <th>Approved</th>
      <th>Actual</th>
      <th>COI</th>
      <th>Payment</th>
      <th>Confirmed</th>
      <th colspan="${canEdit ? 2 : 1}"></th>
    </tr></thead>`;

    const tbody = vendors.length
      ? `<tbody>${vendors.map((v) => vendorRow(v, canEdit, eventId)).join('')}</tbody>`
      : '';

    const totalsQuote    = sumField(vendors, 'quote_amount');
    const totalsApproved = sumField(vendors, 'approved_amount');
    const totalsActual   = sumField(vendors, 'actual_amount');
    const totalsRow = vendors.length ? `<tfoot><tr>
      <td colspan="3" style="text-align:right;font-weight:600">Totals</td>
      <td><strong>${esc(money(totalsQuote))}</strong></td>
      <td><strong>${esc(money(totalsApproved))}</strong></td>
      <td><strong>${esc(money(totalsActual))}</strong></td>
      <td colspan="${canEdit ? 4 : 3}"></td>
    </tr></tfoot>` : '';

    const table = vendors.length
      ? `<div class="table-wrap"><table class="data-table">${thead}${tbody}${totalsRow}</table></div>`
      : emptyState('No vendors', 'Add vendors to track costs and COI requirements.');

    this.innerHTML = `<section class="panel" id="vendors">
      <div class="section-head padded">
        <h2>Vendors</h2>
        <div class="section-head-actions">
          ${canEdit ? addToggle('Add Vendor', true) : ''}
        </div>
      </div>
      ${canEdit ? addVendorForm(eventId) : ''}
      <div class="record-body">
        ${table}
      </div>
    </section>`;

    this._bindEvents();
  }

  _bindEvents() {
    const canEdit = this.canEdit;
    const eventId = this.eventId;

    // "Add Vendor" toggle
    bindAddToggle(this);

    // Add form submission
    const addForm = $('[data-add-form]', this);
    if (addForm) {
      addForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const body = Object.fromEntries(new FormData(addForm).entries());
        // Remove empty optional fields
        for (const key of Object.keys(body)) {
          if (body[key] === '') delete body[key];
        }
        try {
          await api(`/events/${eventId}/vendors`, { method: 'POST', body: JSON.stringify(body) });
          await this.loadVendors();
          publish('toast.show', { message: 'Vendor added.' });
        } catch (err) {
          publish('toast.show', { message: err.message || 'Failed to add vendor.', tone: 'error' });
        }
      });
    }

    if (!canEdit) return;

    // Edit button: hide the read row and reveal the edit row that follows it
    $$('[data-edit]', this).forEach((btn) => {
      btn.addEventListener('click', () => {
        const tr = btn.closest('tr[data-record]');
        if (!tr) return;
        const editRow = tr.nextElementSibling;
        if (editRow) {
          editRow.style.display = '';
          tr.style.display = 'none';
          $('input:not([disabled]), select', editRow)?.focus();
        }
      });
    });

    // Cancel edit
    $$('[data-cancel]', this).forEach((btn) => {
      btn.addEventListener('click', () => {
        const editRow = btn.closest('tr');
        if (!editRow) return;
        const dataRow = editRow.previousElementSibling;
        editRow.style.display = 'none';
        if (dataRow) dataRow.style.display = '';
      });
    });

    // Edit form submission
    $$('form[data-edit-form]', this).forEach((form) => {
      form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const body = Object.fromEntries(new FormData(form).entries());
        try {
          await api(form.dataset.api, { method: 'PATCH', body: JSON.stringify(body) });
          await this.loadVendors();
          publish('toast.show', { message: 'Vendor updated.' });
        } catch (err) {
          publish('toast.show', { message: err.message || 'Failed to save vendor.', tone: 'error' });
        }
      });
    });

    // Delete buttons
    $$('[data-delete]', this).forEach((btn) => {
      btn.addEventListener('click', async () => {
        if (!confirm('Delete this vendor? This cannot be undone.')) return;
        const vendorId = btn.dataset.delete;
        try {
          await api(`/events/${eventId}/vendors/${vendorId}`, { method: 'DELETE' });
          await this.loadVendors();
          publish('toast.show', { message: 'Vendor removed.' });
        } catch (err) {
          publish('toast.show', { message: err.message || 'Failed to delete vendor.', tone: 'error' });
        }
      });
    });
  }
}

customElements.define('pb-event-vendors', EventVendors);
