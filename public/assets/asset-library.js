// ── Asset Library ────────────────────────────────────────────────────────────
// Top-level, cross-event gallery of every uploaded asset (flyers, band
// photos, contracts, etc.) — GET /api/asset-library, scoped to whatever
// events the signed-in user can already see (same rule as Dashboard/Events).
// Images render as thumbnails and open in the shared lightbox modal on
// click; anything else (PDFs, etc.) renders as a plain document icon and
// opens in a new browser tab on click.
import { esc, api, appUrl, assetUrl, titleCase, PanicElement, publish, openImageLightbox, emptyState, $, $$ } from './core.js';

const ASSET_TYPES = ['flyer', 'poster', 'band_photo', 'logo', 'social_square', 'social_story', 'press_photo', 'qr_code', 'other'];
const APPROVAL_STATUSES = ['draft', 'needs_review', 'approved', 'rejected'];
const IMAGE_EXT = /\.(png|jpe?g|gif|webp|svg)$/i;
const PDF_EXT = /\.pdf$/i;
const DOC_EXT = /\.docx?$/i;

function fmtDate(value) {
  if (!value) return '—';
  const d = new Date(String(value).replace(' ', 'T'));
  return Number.isNaN(d.getTime()) ? '—' : d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
}

function fileIcon(filename) {
  if (PDF_EXT.test(filename)) return { icon: 'fa-file-pdf', label: 'PDF' };
  if (DOC_EXT.test(filename)) return { icon: 'fa-file-word', label: 'DOC' };
  const ext = (filename.split('.').pop() || 'file').toUpperCase();
  return { icon: 'fa-file', label: ext };
}

class AssetLibraryPage extends PanicElement {
  async connect() {
    this.state = { q: '', asset_type: '', approval_status: '', page: 1, limit: 60 };
    publish('page.context', { title: 'Asset Library', blurb: 'Every flyer, photo, and document uploaded across all your events, in one place.' });
    this.setLoading('Loading asset library');
    try {
      this.renderShell(await this.fetch());
    } catch (error) {
      this.showError(error);
    }
  }

  fetch() {
    const s = this.state;
    const qs = new URLSearchParams({ page: String(s.page), limit: String(s.limit) });
    if (s.q) qs.set('q', s.q);
    if (s.asset_type) qs.set('asset_type', s.asset_type);
    if (s.approval_status) qs.set('approval_status', s.approval_status);
    return api('/asset-library?' + qs.toString());
  }

  async reload() {
    try {
      this.applyData(await this.fetch());
    } catch (error) {
      publish('toast.show', { message: error.message || 'Could not load assets.', tone: 'error' });
    }
  }

  renderShell(data) {
    this.innerHTML = `<article class="panel">
      <div class="list-controls contacts-controls">
        <label class="search contacts-search"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i><input data-q type="search" placeholder="Search title, filename, or event" aria-label="Search assets"></label>
        <label class="select-inline">Type
          <select data-type>
            <option value="">All types</option>
            ${ASSET_TYPES.map((t) => `<option value="${esc(t)}">${esc(t === 'qr_code' ? 'QR Code' : titleCase(t))}</option>`).join('')}
          </select>
        </label>
        <label class="select-inline">Status
          <select data-status>
            <option value="">All statuses</option>
            ${APPROVAL_STATUSES.map((s) => `<option value="${esc(s)}">${esc(titleCase(s))}</option>`).join('')}
          </select>
        </label>
      </div>
      <div data-grid></div>
      <div class="pager" data-pager></div>
    </article>`;

    let debounce;
    $('[data-q]', this).addEventListener('input', (event) => {
      clearTimeout(debounce);
      debounce = setTimeout(() => { this.state.q = event.target.value.trim(); this.state.page = 1; this.reload(); }, 250);
    });
    $('[data-type]', this).addEventListener('change', (event) => { this.state.asset_type = event.target.value; this.state.page = 1; this.reload(); });
    $('[data-status]', this).addEventListener('change', (event) => { this.state.approval_status = event.target.value; this.state.page = 1; this.reload(); });
    this.applyData(data);
  }

  applyData(data) {
    this.data = data;
    const assets = data.assets || [];

    $('[data-grid]', this).innerHTML = assets.length ? `<div class="asset-grid">${assets.map((asset) => {
      const url = assetUrl(asset.file_path);
      const isImage = IMAGE_EXT.test(asset.filename || '');
      const tile = isImage
        ? `<img class="asset-image" src="${esc(url)}" alt="${esc(asset.title)}" tabindex="0" role="button" aria-label="View ${esc(asset.title)} full size" data-open-image="${esc(url)}" data-open-alt="${esc(asset.title)}">`
        : (() => {
            const { icon, label } = fileIcon(asset.filename || '');
            return `<button type="button" class="asset-icon-tile" data-open-file="${esc(url)}" aria-label="Open ${esc(asset.title)} in a new tab"><i class="fa-solid ${icon}" aria-hidden="true"></i><span>${esc(label)}</span></button>`;
          })();
      return `<article class="asset-card">
        ${tile}
        <strong>${esc(asset.title)}</strong>
        <span>${esc(asset.asset_type === 'qr_code' ? 'QR Code' : titleCase(asset.asset_type))} - ${esc(titleCase(asset.approval_status))}</span>
        <span class="asset-card-event muted">
          <a href="${esc(appUrl('#event-' + asset.event_id))}">${esc(asset.event_title)}</a>
          - ${esc(fmtDate(asset.event_date))}
        </span>
      </article>`;
    }).join('')}</div>` : emptyState('No assets match your search.');

    const { page, pages, total, limit } = data;
    const from = total ? (page - 1) * limit + 1 : 0;
    const to = Math.min(page * limit, total);
    $('[data-pager]', this).innerHTML = `<span class="muted">${from.toLocaleString()}–${to.toLocaleString()} of ${total.toLocaleString()}</span>
      <span class="pager-buttons">
        <button class="small secondary" data-page="prev" ${page <= 1 ? 'disabled' : ''}>‹ Prev</button>
        <span class="muted">Page ${page} of ${Math.max(pages, 1)}</span>
        <button class="small secondary" data-page="next" ${page >= pages ? 'disabled' : ''}>Next ›</button>
      </span>`;

    $$('[data-page]', this).forEach((btn) => btn.addEventListener('click', () => {
      this.state.page += btn.dataset.page === 'next' ? 1 : -1;
      this.reload();
    }));

    $$('[data-open-image]', this).forEach((img) => {
      const open = () => openImageLightbox(img.dataset.openImage, img.dataset.openAlt);
      img.addEventListener('click', open);
      img.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); open(); }
      });
    });
    $$('[data-open-file]', this).forEach((btn) => btn.addEventListener('click', () => {
      window.open(btn.dataset.openFile, '_blank', 'noopener');
    }));
  }
}

customElements.define('pb-asset-library', AssetLibraryPage);
