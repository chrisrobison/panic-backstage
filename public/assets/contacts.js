// ── Contacts (CRM) ───────────────────────────────────────────────────────────
// Top-level admin page for the audience/customer list seeded from the ticketing
// provider's "Fan View" export. Server-side search, sort, filter and paging
// (the table can grow large), plus add/edit/delete via a modal.
import { esc, api, PanicElement, formData, money, publish, helpLink, optedBadge, memberStatusBadge, $, $$ } from './core.js';

const fmtDate = (value) => {
  if (!value) return '—';
  const d = new Date(String(value).replace(' ', 'T'));
  return Number.isNaN(d.getTime()) ? '—' : d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
};
const fullName = (c) => `${c.first_name || ''} ${c.last_name || ''}`.trim() || '(no name)';

class ContactsPage extends PanicElement {
  async connect() {
    this.state = { q: '', opted: '', sort: 'last_name', dir: 'asc', page: 1, limit: 50 };
    publish('page.context', { title: 'Contacts', blurb: 'Your audience from ticketing — search, segment, and keep details current for event email.' });
    this.setLoading('Loading contacts');
    try {
      this.renderShell(await this.fetch());
    } catch (error) {
      this.showError(error);
    }
  }

  fetch() {
    const s = this.state;
    const qs = new URLSearchParams({ sort: s.sort, dir: s.dir, page: String(s.page), limit: String(s.limit) });
    if (s.q) qs.set('q', s.q);
    if (s.opted !== '') qs.set('opted', s.opted);
    return api('/contacts?' + qs.toString());
  }

  async reload() {
    try {
      this.applyData(await this.fetch());
    } catch (error) {
      publish('toast.show', { message: error.message || 'Could not load contacts.', tone: 'error' });
    }
  }

  renderShell(data) {
    this.innerHTML = `<section class="page-head">
        <button class="button" data-add type="button"><i class="fa-solid fa-plus" aria-hidden="true"></i> Add contact</button>
      </section>
      <section class="contacts-kpis" data-kpis></section>
      <article class="panel">
        <div class="list-controls contacts-controls">
          <label class="search contacts-search"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i><input data-q type="search" placeholder="Search name, email, or phone" aria-label="Search contacts"></label>
          <label class="select-inline">Marketing
            <select data-opted>
              <option value="">All contacts</option>
              <option value="1">Opted in</option>
              <option value="0">Not opted in</option>
            </select>
          </label>
        </div>
        <div data-table></div>
        <div class="pager" data-pager></div>
      </article>`;

    let debounce;
    $('[data-q]', this).addEventListener('input', (event) => {
      clearTimeout(debounce);
      debounce = setTimeout(() => { this.state.q = event.target.value.trim(); this.state.page = 1; this.reload(); }, 250);
    });
    $('[data-opted]', this).addEventListener('change', (event) => { this.state.opted = event.target.value; this.state.page = 1; this.reload(); });
    $('[data-add]', this).addEventListener('click', () => this.openModal(null));
    this.applyData(data);
  }

  applyData(data) {
    this.data = data;
    const { stats } = data;
    $('[data-kpis]', this).innerHTML = [
      this.kpi('Contacts', stats.total.toLocaleString(), 'fa-address-book'),
      this.kpi('Opted in', `${stats.opted_in.toLocaleString()} <small>(${stats.total ? Math.round(stats.opted_in / stats.total * 100) : 0}%)</small>`, 'fa-envelope-circle-check'),
      this.kpi('Tickets sold', stats.total_tickets.toLocaleString(), 'fa-ticket'),
      this.kpi('Lifetime spend', money(stats.total_spend), 'fa-sack-dollar'),
    ].join('');

    const rows = (data.contacts || []).map((c) => `<tr>
      <td data-label="Name"><strong>${esc(fullName(c))}</strong></td>
      <td data-label="Email">${c.email ? `<a href="mailto:${esc(c.email)}">${esc(c.email)}</a>` : '<span class="muted">—</span>'}</td>
      <td data-label="Phone">${c.phone ? esc(c.phone) : '<span class="muted">—</span>'}</td>
      <td data-label="Tickets" class="num">${esc(c.tickets_count)}</td>
      <td data-label="Spend" class="num">${money(c.usd_spend)}</td>
      <td data-label="Last seen">${esc(fmtDate(c.last_interaction))}</td>
      <td data-label="Marketing">${optedBadge(c)}</td>
      <td class="row-actions"><button class="small secondary" data-edit="${esc(c.id)}">Edit</button><button class="small danger" data-delete="${esc(c.id)}">Delete</button></td>
    </tr>`).join('');

    const head = (key, label, cls = '') => {
      const active = this.state.sort === key;
      const arrow = active ? (this.state.dir === 'asc' ? ' ▲' : ' ▼') : '';
      return `<th class="${cls}"><button class="th-sort" data-sort="${key}">${esc(label)}${arrow}</button></th>`;
    };
    $('[data-table]', this).innerHTML = data.contacts.length ? `<table class="data-table admin-table contacts-table">
      <thead><tr>
        ${head('last_name', 'Name')}
        ${head('email', 'Email')}
        <th>Phone</th>
        ${head('tickets_count', 'Tickets', 'num')}
        ${head('usd_spend', 'Spend', 'num')}
        ${head('last_interaction', 'Last seen')}
        <th>Marketing</th>
        <th></th>
      </tr></thead>
      <tbody>${rows}</tbody>
    </table>` : '<p class="empty-note padded">No contacts match your search.</p>';

    const { page, pages, total, limit } = data;
    const from = total ? (page - 1) * limit + 1 : 0;
    const to = Math.min(page * limit, total);
    $('[data-pager]', this).innerHTML = `<span class="muted">${from.toLocaleString()}–${to.toLocaleString()} of ${total.toLocaleString()}</span>
      <span class="pager-buttons">
        <button class="small secondary" data-page="prev" ${page <= 1 ? 'disabled' : ''}>‹ Prev</button>
        <span class="muted">Page ${page} of ${Math.max(pages, 1)}</span>
        <button class="small secondary" data-page="next" ${page >= pages ? 'disabled' : ''}>Next ›</button>
      </span>`;

    $$('[data-sort]', this).forEach((btn) => btn.addEventListener('click', () => {
      const key = btn.dataset.sort;
      if (this.state.sort === key) this.state.dir = this.state.dir === 'asc' ? 'desc' : 'asc';
      else { this.state.sort = key; this.state.dir = (key === 'last_name' || key === 'email') ? 'asc' : 'desc'; }
      this.state.page = 1; this.reload();
    }));
    $$('[data-page]', this).forEach((btn) => btn.addEventListener('click', () => {
      this.state.page += btn.dataset.page === 'next' ? 1 : -1;
      this.reload();
    }));
    $$('[data-edit]', this).forEach((btn) => btn.addEventListener('click', () => {
      const c = this.data.contacts.find((x) => String(x.id) === btn.dataset.edit);
      this.openModal(c);
    }));
    $$('[data-delete]', this).forEach((btn) => btn.addEventListener('click', () => {
      const c = this.data.contacts.find((x) => String(x.id) === btn.dataset.delete);
      this.remove(c);
    }));
  }

  kpi(label, value, icon) {
    return `<article class="kpi-card"><span class="kpi-icon"><i class="fa-solid ${icon}" aria-hidden="true"></i></span><div><span class="kpi-label">${esc(label)}</span><strong class="kpi-value">${value}</strong></div></article>`;
  }

  async remove(contact) {
    if (!contact) return;
    if (!confirm(`Delete ${fullName(contact)}? This cannot be undone.`)) return;
    try {
      await api(`/contacts/${contact.id}`, { method: 'DELETE' });
      publish('toast.show', { message: `Deleted ${fullName(contact)}.` });
      this.reload();
    } catch (error) {
      publish('toast.show', { message: error.message || 'Delete failed.', tone: 'error' });
    }
  }

  // Reads via GET /contacts/{id}/lists; writes reuse MailingLists' own
  // /mailing-lists/{id}/members endpoints directly rather than duplicating
  // membership-editing logic on the contacts side (see src/Contacts.php).
  async loadContactLists(dialog, contactId) {
    const body = $('[data-contact-lists-body]', dialog);
    try {
      const membershipData = await api(`/contacts/${contactId}/lists`);
      let allLists = [];
      if (membershipData.can_manage) {
        const listsData = await api('/mailing-lists');
        allLists = listsData.lists || [];
      }
      this.renderContactLists(dialog, contactId, membershipData, allLists);
    } catch (error) {
      if (body) body.innerHTML = `<p class="error-text">Could not load mailing lists: ${esc(error.message)}</p>`;
    }
  }

  renderContactLists(dialog, contactId, membershipData, allLists) {
    const body = $('[data-contact-lists-body]', dialog);
    if (!body) return;
    const memberships = membershipData.memberships || [];
    const canManage = Boolean(membershipData.can_manage);

    const rows = memberships.length ? memberships.map((m) => {
      const isSegment = m.list_type === 'segment';
      let actions = '';
      if (isSegment) {
        actions = '<span class="mlist-segment-badge"><i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i> Auto (segment)</span>';
      } else if (canManage) {
        const nextStatus = m.status === 'subscribed' ? 'unsubscribed' : 'subscribed';
        actions = `<button type="button" class="small secondary" data-toggle-list="${esc(m.list_id)}" data-next-status="${esc(nextStatus)}">${m.status === 'subscribed' ? 'Unsubscribe' : 'Resubscribe'}</button>
          <button type="button" class="small danger" data-remove-list="${esc(m.list_id)}">Remove</button>`;
      }
      return `<li>
        <span class="contact-list-name"><strong>${esc(m.list_name)}</strong> ${memberStatusBadge(m.status)}</span>
        <span class="contact-list-actions">${actions}</span>
      </li>`;
    }).join('') : '<li class="muted">Not on any mailing list.</li>';

    const joinedIds = new Set(memberships.map((m) => m.list_id));
    const available = allLists.filter((l) => l.list_type !== 'segment' && !joinedIds.has(l.id));
    const addControl = canManage && available.length ? `<div class="contact-list-add">
        <select data-add-list-select aria-label="Add to a mailing list">
          <option value="">Add to list…</option>
          ${available.map((l) => `<option value="${esc(l.id)}">${esc(l.name)}</option>`).join('')}
        </select>
        <button type="button" class="small" data-add-list disabled>Add</button>
      </div>` : '';
    const hint = !canManage ? '<p class="muted small">Contact an admin to change mailing list membership.</p>' : '';

    body.innerHTML = `<ul class="contact-lists-list">${rows}</ul>${addControl}${hint}`;

    $$('[data-toggle-list]', body).forEach((btn) => btn.addEventListener('click', async () => {
      btn.disabled = true;
      try {
        await api(`/mailing-lists/${btn.dataset.toggleList}/members/${contactId}`, {
          method: 'PATCH',
          body: JSON.stringify({ status: btn.dataset.nextStatus }),
        });
        await this.loadContactLists(dialog, contactId);
      } catch (error) {
        publish('toast.show', { message: error.message, tone: 'error' });
        btn.disabled = false;
      }
    }));
    $$('[data-remove-list]', body).forEach((btn) => btn.addEventListener('click', async () => {
      if (!confirm('Remove this contact from the list? This fully deletes their membership.')) return;
      btn.disabled = true;
      try {
        await api(`/mailing-lists/${btn.dataset.removeList}/members/${contactId}`, { method: 'DELETE' });
        await this.loadContactLists(dialog, contactId);
      } catch (error) {
        publish('toast.show', { message: error.message, tone: 'error' });
        btn.disabled = false;
      }
    }));
    const addSelect = $('[data-add-list-select]', body);
    const addBtn = $('[data-add-list]', body);
    addSelect?.addEventListener('change', () => { if (addBtn) addBtn.disabled = !addSelect.value; });
    addBtn?.addEventListener('click', async () => {
      if (!addSelect?.value) return;
      addBtn.disabled = true;
      try {
        await api(`/mailing-lists/${addSelect.value}/members`, {
          method: 'POST',
          body: JSON.stringify({ contact_ids: [contactId] }),
        });
        await this.loadContactLists(dialog, contactId);
      } catch (error) {
        publish('toast.show', { message: error.message, tone: 'error' });
        addBtn.disabled = false;
      }
    });
  }

  openModal(contact) {
    const isEdit = Boolean(contact && contact.id);
    const c = contact || {};
    const dialog = document.createElement('div');
    dialog.className = 'modal-backdrop';
    dialog.innerHTML = `<div class="modal-card">
      <div class="section-head padded"><h2>${isEdit ? 'Edit contact' : 'Add contact'}</h2><button class="small secondary" data-close type="button">Close</button></div>
      <form class="grid-form padded" data-form="contact">
        <label>First name <input name="first_name" value="${esc(c.first_name || '')}"></label>
        <label>Last name <input name="last_name" value="${esc(c.last_name || '')}"></label>
        <label class="wide">Email <input type="email" name="email" value="${esc(c.email || '')}"></label>
        <label>Phone <input name="phone" value="${esc(c.phone || '')}"></label>
        <label>Birthday <input type="date" name="birthday" value="${esc(c.birthday || '')}"></label>
        <label class="check-label"><input type="checkbox" name="marketing_opted_in" value="1" ${Number(c.marketing_opted_in) ? 'checked' : ''}> Opted in to marketing email</label>
        <label class="wide">Notes <textarea name="notes">${esc(c.notes || '')}</textarea></label>
        ${isEdit ? `<dl class="contact-meta wide"><div><dt>Tickets</dt><dd>${esc(c.tickets_count || 0)}</dd></div><div><dt>Spend</dt><dd>${money(c.usd_spend || 0)}</dd></div><div><dt>Source</dt><dd>${esc(c.source || 'manual')}</dd></div><div><dt>Last seen</dt><dd>${esc(fmtDate(c.last_interaction))}</dd></div></dl>` : ''}
        <div class="wide form-actions"><button type="submit" class="primary">${isEdit ? 'Save changes' : 'Add contact'}</button><button type="button" class="secondary" data-close>Cancel</button></div>
        <p class="error-text wide" data-error></p>
      </form>
      ${isEdit ? `<section class="contact-lists padded" data-contact-lists>
        <h3 class="mlist-h3">Mailing Lists</h3>
        <div data-contact-lists-body class="muted">Loading…</div>
      </section>` : ''}
    </div>`;
    document.body.appendChild(dialog);
    const close = () => dialog.remove();
    $$('[data-close]', dialog).forEach((btn) => btn.addEventListener('click', close));
    dialog.addEventListener('click', (event) => { if (event.target === dialog) close(); });
    document.addEventListener('keydown', function onEsc(event) {
      if (event.key === 'Escape') { document.removeEventListener('keydown', onEsc); close(); }
    });
    $('input[name="first_name"]', dialog).focus();
    if (isEdit) this.loadContactLists(dialog, c.id);

    $('[data-form="contact"]', dialog).addEventListener('submit', async (event) => {
      event.preventDefault();
      const submit = $('button[type="submit"]', event.target);
      submit.disabled = true;
      const body = formData(event.target);
      body.marketing_opted_in = event.target.marketing_opted_in.checked ? 1 : 0;
      try {
        if (isEdit) await api(`/contacts/${c.id}`, { method: 'PATCH', body: JSON.stringify(body) });
        else await api('/contacts', { method: 'POST', body: JSON.stringify(body) });
        publish('toast.show', { message: isEdit ? 'Contact updated.' : `Added ${fullName(body)}.` });
        close();
        this.reload();
      } catch (error) {
        $('[data-error]', event.target).textContent = error.message || 'Save failed.';
        submit.disabled = false;
      }
    });
  }
}

customElements.define('pb-contacts-page', ContactsPage);
