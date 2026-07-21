// <pb-processes-list> — Automation > Processes index. Lists process
// definitions with live instance counts and opens into the designer
// (#automation-process-{id}). Mirrors the list-page pattern used elsewhere
// (e.g. events list): a table + a "+ New" modal, nothing fancier.
import { $, $$, api, esc, formData, openModal, publish, emptyState, PanicElement } from '../core.js';

export class ProcessesListElement extends PanicElement {
  async connect() {
    publish('page.context', { title: 'Processes', blurb: 'Automation > Processes — visual process definitions that ARE the executable workflow.' });
    this.setLoading('Loading processes…');
    await this.load();
  }

  async load() {
    const data = await api('/processes');
    this.processes = data.processes;
    this.capabilities = data.capabilities || {};
    this.render();
  }

  render() {
    const canManage = this.capabilities.manage_processes;
    this.innerHTML = `
      <div class="page-head">
        <div><h1>Processes</h1><p class="subtle">The diagram is the program — each process below is a versioned, publishable graph.</p></div>
        ${canManage ? `<button type="button" data-new-process><i class="fa-solid fa-plus" aria-hidden="true"></i> New Process</button>` : ''}
      </div>
      <div class="panel">
        ${this.processes.length ? `<div class="table-scroll"><table class="data-table">
          <thead><tr><th>Name</th><th>Category</th><th>Status</th><th>Active</th><th>Waiting</th><th>Overdue</th><th>Updated</th></tr></thead>
          <tbody>${this.processes.map((p) => this.row(p)).join('')}</tbody>
        </table></div>` : emptyState('No processes yet. Create one to design your first workflow.')}
      </div>`;

    $$('[data-open-process]', this).forEach((el) => el.addEventListener('click', () => { location.hash = `automation-process-${el.dataset.openProcess}`; }));
    $('[data-new-process]', this)?.addEventListener('click', () => this.openCreateModal());
  }

  row(p) {
    const status = p.current_published_version_id
      ? `<span class="badge status-confirmed">Published v${p.published_version_number}</span>`
      : `<span class="badge status-draft">Draft only</span>`;
    return `<tr class="clickable-row" data-open-process="${p.id}">
      <td data-label="Name"><strong>${esc(p.name)}</strong>${p.description ? `<div class="muted small">${esc(p.description)}</div>` : ''}</td>
      <td data-label="Category">${esc(p.category || '—')}</td>
      <td data-label="Status">${status}</td>
      <td data-label="Active">${p.instance_counts.active}</td>
      <td data-label="Waiting">${p.instance_counts.waiting}</td>
      <td data-label="Overdue">${p.instance_counts.overdue ? `<span class="status-dot red"></span>${p.instance_counts.overdue}` : '0'}</td>
      <td data-label="Updated">${esc(p.updated_at)}</td>
    </tr>`;
  }

  openCreateModal() {
    const { dialog, close } = openModal({
      title: 'New Process',
      bodyHtml: `<form class="grid-form padded" data-form="new-process">
        <label class="wide">Name <span class="req">*</span><input type="text" name="name" required placeholder="e.g. Event Booking"></label>
        <label class="wide">Description<textarea name="description" placeholder="What this process automates"></textarea></label>
        <label class="wide">Category<input type="text" name="category" placeholder="e.g. booking"></label>
        <div class="wide"><button type="submit">Create</button></div>
      </form>`,
      focus: '[name="name"]',
    });
    $('[data-form="new-process"]', dialog).addEventListener('submit', async (e) => {
      e.preventDefault();
      const data = formData(e.target);
      if (!data.name?.trim()) return;
      try {
        const res = await api('/processes', { method: 'POST', body: JSON.stringify(data) });
        close();
        location.hash = `automation-process-${res.id}`;
      } catch (err) {
        publish('toast.show', { message: err.message, tone: 'error' });
      }
    });
  }
}
customElements.define('pb-processes-list', ProcessesListElement);
