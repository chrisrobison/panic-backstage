// ── Panic Promote — Frontend Web Components ───────────────────────────────────
// Native Web Components for the Promote module. Extends PanicElement from
// core.js. All rendered values are escaped with esc(). Components communicate
// via publish/subscribe topics — never by direct reference.
//
// Components:
//   pb-promote-campaign-list     — #promote route: campaign cards for upcoming events
//   pb-promote-campaign-overview — #promote-event-{id}: rich single-campaign view
//   pb-promote-health-card       — checklist with done/warn/missing, expandable
//   pb-promote-post-list         — post cards with Edit/Preview/Broadcast buttons
//   pb-promote-post-editor       — modal: post CRUD + 9-channel variant tabs
//   pb-promote-broadcast-modal   — 4 destination groups + schedule + send
//   pb-promote-assets-card       — approved assets + aspect ratio placeholders
//   pb-promote-analytics-card    — 4 stub metric tiles with sparkline placeholders
//
// Topics published:
//   promote.broadcast.open       {campaignId, postId}
//   promote.post.created         {post, campaignId}
//   promote.post.updated         {post, campaignId}
//   promote.post.deleted         {postId, campaignId}
//   promote.variants.generated   {postId, variants}
//   promote.broadcast.created    {broadcast, campaignId}
//   promote.health.changed       {campaignId}
//   toast.show                   {message, tone}

import {
  esc, api, publish, subscribe, PanicElement, assetUrl,
  shortDate, titleCase, $, $$
} from './core.js';

// ── Helpers ──────────────────────────────────────────────────────────────────

const CHANNELS = ['instagram', 'facebook', 'tiktok', 'email', 'eventbrite', 'luma', 'funcheap', 'foopee', 'press'];

const CHANNEL_LABELS = {
  instagram: 'Instagram',
  facebook: 'Facebook',
  tiktok: 'TikTok',
  email: 'Email',
  eventbrite: 'Eventbrite',
  luma: 'Luma',
  funcheap: 'Funcheap',
  foopee: 'Foopee',
  press: 'Press',
};

const DEST_GROUP_LABELS = {
  direct_post: 'Direct Posts',
  event_platform: 'Event Platforms',
  editorial_submission: 'Editorial Submissions',
  email: 'Email Recipients',
};

const POST_STATUSES = ['draft', 'approved', 'scheduled', 'sent', 'archived'];

const VARIANT_STATUSES = ['draft', 'ready', 'needs_review', 'approved'];

// Tone for post status badges
function postStatusTone(status) {
  if (status === 'approved') return 'success';
  if (status === 'scheduled') return 'info';
  if (status === 'sent') return 'success';
  if (status === 'archived') return 'warning';
  return '';
}

// Tone for destination readiness
function destTone(status) {
  if (status === 'connected') return 'success';
  if (status === 'needs_auth') return 'warning';
  if (status === 'manual_submission') return 'info';
  if (status === 'ready') return 'success';
  if (status === 'needs_content') return 'warning';
  return '';
}

// Tone for health item severity
function healthTone(status) {
  if (status === 'done') return 'success';
  if (status === 'warn') return 'warning';
  if (status === 'missing') return 'error';
  return '';
}

// Icon for health item
function healthIcon(status) {
  if (status === 'done') return '<i class="fa-solid fa-circle-check" aria-hidden="true"></i>';
  if (status === 'warn') return '<i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>';
  return '<i class="fa-solid fa-circle-xmark" aria-hidden="true"></i>';
}

// Days until event date from today
function daysOut(dateStr) {
  if (!dateStr) return null;
  const event = new Date(`${dateStr}T12:00:00`);
  const today = new Date();
  today.setHours(12, 0, 0, 0);
  const diff = Math.round((event - today) / (1000 * 60 * 60 * 24));
  return diff;
}

function daysOutLabel(dateStr) {
  const d = daysOut(dateStr);
  if (d === null) return '';
  if (d < 0) return `${Math.abs(d)}d ago`;
  if (d === 0) return 'Today';
  return `${d}d out`;
}

// Small health score bar (inline)
function healthScoreBar(score) {
  const pct = Math.min(100, Math.max(0, Number(score) || 0));
  const cls = pct >= 75 ? 'success' : pct >= 40 ? 'warning' : 'error';
  return `<div class="promote-score-bar" title="${pct}% complete">
    <div class="promote-score-fill ${esc(cls)}" style="width:${pct}%"></div>
  </div>`;
}

// Stub inline-SVG sparkline (CSS polish agent will style it)
function sparkline() {
  return `<svg class="promote-spark" viewBox="0 0 80 24" aria-hidden="true" preserveAspectRatio="none">
    <polyline points="0,20 10,16 20,18 30,10 40,14 50,8 60,12 70,6 80,10"
      fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>
  </svg>`;
}

// Thumbnail for an asset (or placeholder)
function assetThumb(asset, cls = '') {
  if (asset && asset.file_path) {
    return `<img class="promote-thumb ${esc(cls)}" src="${esc(assetUrl(asset.file_path))}" alt="${esc(asset.title || 'Asset')}" loading="lazy">`;
  }
  return `<div class="promote-thumb promote-thumb-placeholder ${esc(cls)}"><i class="fa-solid fa-image" aria-hidden="true"></i></div>`;
}

// Aspect-ratio placeholder thumbnails
const RATIOS = [
  { key: '1:1',  label: '1:1',  cls: 'ratio-square' },
  { key: '4:5',  label: '4:5',  cls: 'ratio-portrait' },
  { key: '9:16', label: '9:16', cls: 'ratio-story' },
  { key: '16:9', label: '16:9', cls: 'ratio-landscape' },
];

// ── pb-promote-campaign-list ─────────────────────────────────────────────────
// Route: #promote — shows campaign cards for upcoming events.
// If an event has no campaign yet, shows a "Create campaign" CTA.

class PromoteCampaignList extends PanicElement {
  async connect() {
    this.setLoading('Loading campaigns');
    try {
      const data = await api('/promote/campaigns');
      this.render(data.campaigns || []);
    } catch (error) {
      this.showError(error);
    }
  }

  render(campaigns) {
    this.innerHTML = `<section class="page-head">
      <div>
        <h1><i class="fa-solid fa-bullhorn" aria-hidden="true"></i> Panic Promote</h1>
        <a class="button ghost small" href="#help-promote-overview" title="Promote help"><i class="fa-solid fa-circle-question" aria-hidden="true"></i> Help</a>
        <p class="subtle">Campaign command center &mdash; turn upcoming shows into coordinated promotions.</p>
      </div>
    </section>
    <div class="promote-campaign-grid" data-campaign-grid>
      ${campaigns.length ? campaigns.map((c) => this.campaignCard(c)).join('') : `<div class="panel padded"><p class="muted">No campaigns yet. Open an event and create a campaign to get started.</p></div>`}
    </div>`;

    $$('[data-open-campaign]', this).forEach((btn) => {
      btn.addEventListener('click', () => {
        location.hash = `#promote-event-${esc(btn.dataset.openCampaign)}`;
      });
    });
  }

  campaignCard(c) {
    const score = Number(c.health_score || 0);
    const days = daysOutLabel(c.event_date);
    const statusTone = c.status === 'active' ? 'success' : c.status === 'completed' ? 'info' : '';
    return `<article class="panel promote-campaign-card">
      <div class="promote-campaign-card-head">
        <div class="promote-campaign-meta">
          <h2 class="promote-campaign-title"><a href="#promote-event-${esc(c.event_id)}">${esc(c.event_title || c.title)}</a></h2>
          <div class="promote-campaign-sub">
            <span class="muted">${esc(shortDate(c.event_date ? new Date(`${c.event_date}T12:00:00`) : null))}</span>
            ${days ? `<span class="promote-days-badge">${esc(days)}</span>` : ''}
            <span class="badge ${esc(statusTone)}">${esc(titleCase(c.status))}</span>
          </div>
        </div>
        <button class="primary small" data-open-campaign="${esc(c.event_id)}">Open</button>
      </div>
      <div class="promote-campaign-stats">
        ${c.goal_tickets ? `<span class="promote-stat"><strong>${esc(String(c.goal_tickets))}</strong> goal</span>` : ''}
        <span class="promote-stat"><strong>${esc(String(score))}%</strong> health</span>
        ${c.primary_missing ? `<span class="promote-stat promote-missing"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i> ${esc(c.primary_missing)}</span>` : ''}
      </div>
      ${healthScoreBar(score)}
    </article>`;
  }
}

customElements.define('pb-promote-campaign-list', PromoteCampaignList);

// ── pb-promote-campaign-overview ─────────────────────────────────────────────
// Route: #promote-event-{eventId}
// Loads GET /api/promote/events/{eventId} (or redirects through campaign creation).

class PromoteCampaignOverview extends PanicElement {
  // eventId is set by AppShell.mount() as a property
  async connect() {
    this.setLoading('Loading campaign');
    subscribe('promote.post.created', () => this.reloadPosts(), this.abort.signal);
    subscribe('promote.post.updated', () => this.reloadPosts(), this.abort.signal);
    subscribe('promote.post.deleted', () => this.reloadPosts(), this.abort.signal);
    subscribe('promote.variants.generated', () => this.reloadPosts(), this.abort.signal);
    subscribe('promote.broadcast.created', () => this.reloadAll(), this.abort.signal);
    subscribe('promote.health.changed', (p) => {
      if (p.campaignId === this.campaignId) this.reloadHealth();
    }, this.abort.signal);
    await this.loadData();
  }

  async loadData() {
    try {
      const data = await api(`/promote/events/${this.eventId}`);
      if (data.campaign) {
        this.campaignId = Number(data.campaign.id);
        this.data = data;
        this.render();
      } else {
        // No campaign yet — show create prompt
        this.renderNoCampaign(data.event);
      }
    } catch (error) {
      this.showError(error);
    }
  }

  async reloadAll() {
    try {
      const data = await api(`/promote/events/${this.eventId}`);
      this.data = data;
      this.campaignId = Number(data.campaign?.id);
      this.render();
    } catch { /* silently skip reload on error */ }
  }

  async reloadPosts() {
    if (!this.campaignId) return;
    try {
      const data = await api(`/promote/campaigns/${this.campaignId}`);
      this.data = { ...this.data, ...data };
      const postList = $('pb-promote-post-list', this);
      if (postList) {
        postList.campaignId = this.campaignId;
        postList.posts = data.posts || [];
        postList.assets = this.data.assets || [];
        postList.render();
      }
    } catch { /* ignore */ }
  }

  async reloadHealth() {
    if (!this.campaignId) return;
    try {
      const health = await api(`/promote/campaigns/${this.campaignId}/health`);
      this.data = { ...this.data, health };
      const healthCard = $('pb-promote-health-card', this);
      if (healthCard) {
        healthCard.health = health;
        healthCard.render();
      }
    } catch { /* ignore */ }
  }

  renderNoCampaign(event) {
    const e = event || {};
    this.innerHTML = `<section class="page-head">
      <div><h1>${esc(e.title || 'Event')}</h1><p class="subtle">No promote campaign exists for this event.</p></div>
      <a href="#promote" class="secondary small">&larr; Back</a>
    </section>
    <div class="panel padded">
      <p>This event doesn&rsquo;t have a Panic Promote campaign yet.</p>
      <button class="primary" data-create-campaign>Create campaign</button>
    </div>`;

    $('[data-create-campaign]', this)?.addEventListener('click', async () => {
      const btn = $('[data-create-campaign]', this);
      btn.disabled = true;
      try {
        await api(`/promote/events/${this.eventId}/campaign`, { method: 'POST' });
        await this.loadData();
      } catch (error) {
        publish('toast.show', { message: error.message || 'Failed to create campaign.', tone: 'error' });
        btn.disabled = false;
      }
    });
  }

  render() {
    const { campaign, event, posts, assets, health, analytics, destinations } = this.data;
    const e = event || {};
    const c = campaign || {};
    const eventDate = e.date ? new Date(`${e.date}T12:00:00`) : null;

    this.innerHTML = `<section class="page-head">
      <div>
        <nav class="promote-breadcrumb"><a href="#promote">&larr; Campaigns</a></nav>
        <h1 class="promote-overview-title">${esc(e.title || c.title)}</h1>
        <p class="subtle">${esc(e.venue_name || '')}${e.venue_city ? ` &mdash; ${esc(e.venue_city)}` : ''}</p>
      </div>
      <div class="promote-head-actions">
        <button class="primary" data-new-post><i class="fa-solid fa-plus" aria-hidden="true"></i> New Post</button>
        <button class="primary promote-broadcast-btn" data-broadcast-all><i class="fa-solid fa-satellite-dish" aria-hidden="true"></i> Broadcast</button>
      </div>
    </section>

    <div class="promote-overview-layout">
      <div class="promote-main-col">

        <!-- Hero -->
        <article class="panel promote-hero">
          <div class="promote-hero-inner">
            <div class="promote-hero-flyer" data-hero-flyer></div>
            <div class="promote-hero-details">
              <h2>${esc(e.title || c.title)}</h2>
              <div class="promote-hero-meta">
                ${eventDate ? `<span><i class="fa-regular fa-calendar" aria-hidden="true"></i> ${esc(shortDate(eventDate))}</span>` : ''}
                ${e.doors_time ? `<span><i class="fa-regular fa-clock" aria-hidden="true"></i> Doors ${esc(e.doors_time)}</span>` : ''}
                ${e.show_time ? `<span>Show ${esc(e.show_time)}</span>` : ''}
                ${e.age_restriction ? `<span class="badge info">${esc(e.age_restriction)}</span>` : ''}
              </div>
              ${e.ticket_url ? `<a href="${esc(e.ticket_url)}" class="secondary small" target="_blank" rel="noopener">Tickets</a>` : ''}
            </div>
          </div>
          <div class="promote-metric-tiles">
            <div class="promote-metric-tile">
              <span class="promote-metric-value">${esc(String(c.goal_tickets || '—'))}</span>
              <span class="promote-metric-label">Goal</span>
            </div>
            <div class="promote-metric-tile">
              <span class="promote-metric-value">${esc(daysOutLabel(e.date) || '—')}</span>
              <span class="promote-metric-label">Days Out</span>
            </div>
            <div class="promote-metric-tile">
              <span class="promote-metric-value">${esc(String(posts?.length ?? 0))}</span>
              <span class="promote-metric-label">Posts</span>
            </div>
            <div class="promote-metric-tile">
              <span class="promote-metric-value">
                <span class="badge ${esc(c.status === 'active' ? 'success' : '')}">${esc(titleCase(c.status || 'draft'))}</span>
              </span>
              <span class="promote-metric-label">Status</span>
            </div>
          </div>
        </article>

        <!-- Posts -->
        <div data-promote-section-target="broadcasts">
          <pb-promote-post-list></pb-promote-post-list>
        </div>

      </div>
      <div class="promote-rail-col">

        <!-- Health -->
        <pb-promote-health-card></pb-promote-health-card>

        <!-- Assets -->
        <div data-promote-section-target="assets">
          <pb-promote-assets-card></pb-promote-assets-card>
        </div>

        <!-- Analytics -->
        <div data-promote-section-target="analytics">
          <pb-promote-analytics-card></pb-promote-analytics-card>
        </div>

      </div>
    </div>

    <!-- Broadcast modal (hidden, activated by topic) -->
    <pb-promote-broadcast-modal></pb-promote-broadcast-modal>`;

    // Wire up hero flyer
    const heroFlyer = $('[data-hero-flyer]', this);
    const flyer = (assets || []).find((a) => a.asset_type === 'flyer' && a.approval_status === 'approved')
      || (assets || []).find((a) => a.asset_type === 'flyer')
      || (assets || [])[0];
    if (heroFlyer) heroFlyer.innerHTML = assetThumb(flyer, 'promote-hero-flyer-img');

    // Wire child components
    const postList = $('pb-promote-post-list', this);
    if (postList) {
      postList.campaignId = this.campaignId;
      postList.posts = posts || [];
      postList.assets = assets || [];
    }

    const healthCard = $('pb-promote-health-card', this);
    if (healthCard) healthCard.health = health;

    const assetsCard = $('pb-promote-assets-card', this);
    if (assetsCard) assetsCard.assets = assets || [];

    const analyticsCard = $('pb-promote-analytics-card', this);
    if (analyticsCard) analyticsCard.analytics = analytics;

    const broadcastModal = $('pb-promote-broadcast-modal', this);
    if (broadcastModal) {
      broadcastModal.campaignId = this.campaignId;
      broadcastModal.destinations = destinations || [];
    }

    // "New Post" button
    $('[data-new-post]', this)?.addEventListener('click', () => {
      this.openPostEditor(null);
    });

    // "Broadcast" button (no specific post — opens modal with no pre-selected post)
    $('[data-broadcast-all]', this)?.addEventListener('click', () => {
      const bm = $('pb-promote-broadcast-modal', this);
      if (bm) bm.open(this.campaignId, null, destinations || []);
    });

    this.scrollToSection();
  }

  scrollToSection() {
    if (!this.section) return;
    requestAnimationFrame(() => {
      const target = $(`[data-promote-section-target="${this.section}"]`, this);
      target?.scrollIntoView({ block: 'start', behavior: 'smooth' });
    });
  }

  openPostEditor(post) {
    const editor = document.createElement('pb-promote-post-editor');
    editor.campaignId = this.campaignId;
    editor.post = post || null;
    editor.assets = this.data?.assets || [];
    document.body.appendChild(editor);
  }
}

customElements.define('pb-promote-campaign-overview', PromoteCampaignOverview);

// ── pb-promote-health-card ────────────────────────────────────────────────────
// Checklist panel with done/warn/missing icons, percentage, expandable view.

class PromoteHealthCard extends PanicElement {
  // health property set by parent
  connectedCallback() {
    super.connectedCallback();
    this.render();
  }

  render() {
    const h = this.health;
    if (!h) {
      this.innerHTML = `<article class="panel promote-health-card"><div class="section-head padded"><h3>Promotion Health</h3></div><p class="muted padded">Loading&hellip;</p></article>`;
      return;
    }

    const score = Number(h.score || 0);
    const complete = Number(h.complete || 0);
    const total = Number(h.total || 0);
    const items = h.items || [];
    // Show first 5 items in collapsed view
    const preview = items.slice(0, 5);
    const remaining = items.slice(5);

    this.innerHTML = `<article class="panel promote-health-card">
      <div class="section-head padded">
        <h3><i class="fa-solid fa-heart-pulse" aria-hidden="true"></i> Promotion Health</h3>
        <span class="promote-health-pct">${esc(String(score))}% complete</span>
      </div>
      ${healthScoreBar(score)}
      <div class="promote-health-meta padded">
        <span class="muted">${esc(String(complete))} / ${esc(String(total))} items complete</span>
      </div>
      <ul class="promote-health-list padded" data-health-list>
        ${preview.map((item) => this.healthItem(item)).join('')}
        ${remaining.length ? `<li class="promote-health-more" data-health-more><button class="small secondary" data-toggle-health>View full checklist (${esc(String(remaining.length))} more)</button></li>` : ''}
        ${remaining.length ? `<div class="promote-health-extra" data-health-extra hidden>${remaining.map((item) => this.healthItem(item)).join('')}</div>` : ''}
      </ul>
    </article>`;

    $('[data-toggle-health]', this)?.addEventListener('click', (event) => {
      const extra = $('[data-health-extra]', this);
      const btn = event.currentTarget;
      if (extra) {
        const hidden = extra.hasAttribute('hidden');
        extra.toggleAttribute('hidden', !hidden);
        btn.textContent = hidden ? 'Hide full checklist' : `View full checklist (${remaining.length} more)`;
      }
    });
  }

  healthItem(item) {
    const tone = healthTone(item.status);
    return `<li class="promote-health-item promote-health-${esc(tone)}">
      <span class="promote-health-icon">${healthIcon(item.status)}</span>
      <span class="promote-health-label">${esc(item.label)}</span>
      ${item.detail ? `<span class="promote-health-detail muted">${esc(item.detail)}</span>` : ''}
    </li>`;
  }
}

customElements.define('pb-promote-health-card', PromoteHealthCard);

// ── pb-promote-post-list ──────────────────────────────────────────────────────
// Post cards with asset thumb, status badge, Edit/Preview/Broadcast buttons.

class PromotePostList extends PanicElement {
  // campaignId, posts[], assets[] set by parent
  connectedCallback() {
    super.connectedCallback();
    this.render();
  }

  render() {
    const posts = this.posts || [];
    const assets = this.assets || [];

    this.innerHTML = `<article class="panel promote-post-list">
      <div class="section-head padded">
        <h3><i class="fa-solid fa-newspaper" aria-hidden="true"></i> Posts</h3>
        <button class="small secondary" data-new-post><i class="fa-solid fa-plus" aria-hidden="true"></i> New Post</button>
      </div>
      <div class="promote-posts" data-posts>
        ${posts.length ? posts.map((p) => this.postCard(p, assets)).join('') : `<p class="muted padded">No posts yet. Create a post to start building your promotion.</p>`}
      </div>
    </article>`;

    $('[data-new-post]', this)?.addEventListener('click', () => {
      this.openEditor(null);
    });

    $$('[data-edit-post]', this).forEach((btn) => {
      btn.addEventListener('click', () => {
        const post = posts.find((p) => String(p.id) === btn.dataset.editPost);
        this.openEditor(post);
      });
    });

    $$('[data-broadcast-post]', this).forEach((btn) => {
      const postId = Number(btn.dataset.broadcastPost);
      btn.addEventListener('click', () => {
        publish('promote.broadcast.open', { campaignId: this.campaignId, postId });
      });
    });

    $$('[data-delete-post]', this).forEach((btn) => {
      btn.addEventListener('click', async () => {
        const postId = Number(btn.dataset.deletePost);
        if (!confirm('Delete this post? This cannot be undone.')) return;
        try {
          await api(`/promote/campaigns/${this.campaignId}/posts/${postId}`, { method: 'DELETE' });
          publish('toast.show', { message: 'Post deleted.', tone: 'success' });
          publish('promote.post.deleted', { postId, campaignId: this.campaignId });
        } catch (error) {
          publish('toast.show', { message: error.message || 'Delete failed.', tone: 'error' });
        }
      });
    });
  }

  postCard(post, assets) {
    const asset = assets.find((a) => String(a.id) === String(post.asset_id));
    const tone = postStatusTone(post.status);
    return `<div class="promote-post-card">
      <div class="promote-post-thumb">${assetThumb(asset)}</div>
      <div class="promote-post-body">
        <div class="promote-post-head">
          <strong class="promote-post-title">${esc(post.title)}</strong>
          <span class="badge ${esc(tone)}">${esc(titleCase(post.status))}</span>
        </div>
        ${post.master_text ? `<p class="promote-post-preview muted">${esc(post.master_text.slice(0, 100))}${post.master_text.length > 100 ? '&hellip;' : ''}</p>` : ''}
        <div class="promote-post-actions">
          <button class="small secondary" data-edit-post="${esc(String(post.id))}">Edit</button>
          <button class="small secondary" data-broadcast-post="${esc(String(post.id))}"><i class="fa-solid fa-satellite-dish" aria-hidden="true"></i> Broadcast</button>
          <button class="small danger" data-delete-post="${esc(String(post.id))}">Delete</button>
        </div>
      </div>
    </div>`;
  }

  openEditor(post) {
    const editor = document.createElement('pb-promote-post-editor');
    editor.campaignId = this.campaignId;
    editor.post = post || null;
    editor.assets = this.assets || [];
    document.body.appendChild(editor);
  }
}

customElements.define('pb-promote-post-list', PromotePostList);

// ── pb-promote-post-editor ────────────────────────────────────────────────────
// Modal: create/edit post + 9-channel variant tabs.
// Appended to document.body by post-list or overview.

class PromotePostEditor extends PanicElement {
  // campaignId, post (null = create), assets[] set before append
  connectedCallback() {
    super.connectedCallback();
    this.activeChannel = CHANNELS[0];
    this.variants = {};
    if (this.post?.id) this.loadVariants();
    else this.renderModal();
  }

  async loadVariants() {
    try {
      // Load the full post to get existing variants (GET single post returns them)
      const data = await api(`/promote/campaigns/${this.campaignId}/posts/${this.post.id}`);
      this.variants = {};
      (data.variants || []).forEach((v) => { this.variants[v.channel] = v; });
    } catch { /* variants will be empty — that's fine */ }
    this.renderModal();
  }

  renderModal() {
    const post = this.post || {};
    const isEdit = Boolean(post.id);
    const assets = this.assets || [];

    const assetOptions = assets.map((a) =>
      `<option value="${esc(String(a.id))}" ${String(a.id) === String(post.asset_id || '') ? 'selected' : ''}>${esc(a.title || a.asset_type || 'Asset')}</option>`
    ).join('');

    const dialog = document.createElement('div');
    dialog.className = 'modal-backdrop';
    dialog.innerHTML = `<div class="modal-card wide">
      <div class="section-head padded">
        <h2>${isEdit ? 'Edit Post' : 'New Post'}</h2>
        <button class="small secondary" data-close type="button">Close</button>
      </div>
      <div class="modal-card-body">
        <form class="grid-form padded" data-post-form>
          <label class="wide">Title <input name="title" required value="${esc(post.title || '')}"></label>
          <label class="wide">Master Text
            <textarea name="master_text" rows="4">${esc(post.master_text || '')}</textarea>
          </label>
          <label>Target URL <input type="url" name="target_url" value="${esc(post.target_url || '')}"></label>
          <label>Status
            <select name="status">
              ${POST_STATUSES.map((s) => `<option value="${esc(s)}" ${s === (post.status || 'draft') ? 'selected' : ''}>${esc(titleCase(s))}</option>`).join('')}
            </select>
          </label>
          <label>Asset
            <select name="asset_id">
              <option value="">No asset</option>
              ${assetOptions}
            </select>
          </label>
          <div class="wide form-actions">
            <button type="submit" class="primary">${isEdit ? 'Save post' : 'Create post'}</button>
            <button type="button" class="secondary" data-close>Cancel</button>
          </div>
          <p class="error-text wide" data-error></p>
        </form>

        ${isEdit ? `<div class="promote-variants-section padded">
          <div class="promote-variants-head">
            <h3>Channel Variants</h3>
            <button class="secondary small" data-generate-variants>Generate variants</button>
          </div>
          <div class="promote-variant-tabs" role="tablist">
            ${CHANNELS.map((ch) => `<button class="promote-tab ${ch === this.activeChannel ? 'active' : ''}" role="tab" data-channel="${esc(ch)}">${esc(CHANNEL_LABELS[ch])}</button>`).join('')}
          </div>
          <div class="promote-variant-panels" data-variant-panels>
            ${this.renderVariantPanel(this.activeChannel)}
          </div>
        </div>` : ''}
      </div>
    </div>`;

    this.innerHTML = '';
    this.appendChild(dialog);

    // Close handlers
    const close = () => this.remove();
    $$('[data-close]', this).forEach((btn) => btn.addEventListener('click', close));
    dialog.addEventListener('click', (event) => { if (event.target === dialog) close(); });
    document.addEventListener('keydown', function onEsc(event) {
      if (event.key === 'Escape') { document.removeEventListener('keydown', onEsc); close(); }
    });

    // Focus first field
    $('input[name="title"]', this)?.focus();

    // Post form submit
    $('[data-post-form]', this).addEventListener('submit', async (event) => {
      event.preventDefault();
      const submit = $('button[type="submit"]', event.target);
      submit.disabled = true;
      $('[data-error]', event.target).textContent = '';
      const body = {
        title: event.target.title.value.trim(),
        master_text: event.target.master_text.value.trim(),
        target_url: event.target.target_url.value.trim(),
        status: event.target.status.value,
        asset_id: event.target.asset_id.value || null,
      };
      try {
        let result;
        if (isEdit) {
          result = await api(`/promote/campaigns/${this.campaignId}/posts/${post.id}`, { method: 'PATCH', body: JSON.stringify(body) });
          publish('toast.show', { message: 'Post updated.', tone: 'success' });
          publish('promote.post.updated', { post: result.post, campaignId: this.campaignId });
        } else {
          result = await api(`/promote/campaigns/${this.campaignId}/posts`, { method: 'POST', body: JSON.stringify(body) });
          publish('toast.show', { message: 'Post created.', tone: 'success' });
          publish('promote.post.created', { post: result.post, campaignId: this.campaignId });
          close();
        }
        // Refresh post reference and re-render (for edit, stay open)
        if (isEdit) {
          this.post = result.post;
          submit.disabled = false;
          publish('toast.show', { message: 'Saved.', tone: 'success' });
        }
      } catch (error) {
        $('[data-error]', event.target).textContent = error.message || 'Save failed.';
        submit.disabled = false;
      }
    });

    // Variant tabs (edit mode only)
    if (isEdit) {
      $$('[data-channel]', this).forEach((btn) => {
        btn.addEventListener('click', () => {
          this.activeChannel = btn.dataset.channel;
          $$('[data-channel]', this).forEach((b) => b.classList.toggle('active', b.dataset.channel === this.activeChannel));
          $('[data-variant-panels]', this).innerHTML = this.renderVariantPanel(this.activeChannel);
          this.bindVariantPanel();
        });
      });

      $('[data-generate-variants]', this)?.addEventListener('click', async (event) => {
        const btn = event.currentTarget;
        btn.disabled = true;
        btn.textContent = 'Generating…';
        try {
          const result = await api(`/promote/campaigns/${this.campaignId}/posts/${post.id}/variants/generate`, { method: 'POST' });
          result.variants.forEach((v) => { this.variants[v.channel] = v; });
          publish('toast.show', { message: 'Variants generated.', tone: 'success' });
          publish('promote.variants.generated', { postId: post.id, variants: result.variants });
          $('[data-variant-panels]', this).innerHTML = this.renderVariantPanel(this.activeChannel);
          this.bindVariantPanel();
        } catch (error) {
          publish('toast.show', { message: error.message || 'Generation failed.', tone: 'error' });
        } finally {
          btn.disabled = false;
          btn.textContent = 'Generate variants';
        }
      });

      this.bindVariantPanel();
    }
  }

  renderVariantPanel(channel) {
    const v = this.variants[channel] || {};
    const warnings = (typeof v.warnings_json === 'string' ? JSON.parse(v.warnings_json || '[]') : v.warnings_json) || [];
    const charCount = (v.body || '').length;
    const charLimits = { instagram: 2200, facebook: 63206, tiktok: 2200, email: null, eventbrite: 15000, luma: 5000, funcheap: 500, foopee: 500, press: 800 };
    const limit = charLimits[channel];

    return `<div class="promote-variant-panel" data-variant-panel data-vc="${esc(channel)}">
      ${v.id ? `<p class="promote-variant-status muted">Status: <span class="badge ${esc(v.status === 'approved' ? 'success' : '')}">${esc(titleCase(v.status || 'draft'))}</span></p>` : '<p class="muted promote-variant-empty">No variant yet — click Generate variants to create.</p>'}
      ${channel === 'email' ? `<label>Subject<input name="variant_title" class="wide" value="${esc(v.title || '')}"></label>` : ''}
      <label>Body
        <textarea name="variant_body" rows="6" data-variant-body>${esc(v.body || '')}</textarea>
      </label>
      <div class="promote-variant-meta">
        <span class="promote-char-count ${limit && charCount > limit ? 'error-text' : 'muted'}" data-char-count>${esc(String(charCount))}${limit ? ` / ${esc(String(limit))}` : ''} chars</span>
        ${v.id ? `<select name="variant_status" class="small" data-variant-status>
          ${VARIANT_STATUSES.map((s) => `<option value="${esc(s)}" ${s === (v.status || 'draft') ? 'selected' : ''}>${esc(titleCase(s))}</option>`).join('')}
        </select>` : ''}
        ${v.id ? `<button class="small primary" data-save-variant data-vid="${esc(String(v.id))}">Save variant</button>` : ''}
      </div>
      ${warnings.length ? `<ul class="promote-variant-warnings">${warnings.map((w) => `<li class="warning-item"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i> ${esc(String(w))}</li>`).join('')}</ul>` : ''}
    </div>`;
  }

  bindVariantPanel() {
    const panel = $('[data-variant-panel]', this);
    if (!panel) return;
    const channel = panel.dataset.vc;

    // Live char count
    const bodyArea = $('[data-variant-body]', panel);
    const charDisplay = $('[data-char-count]', panel);
    if (bodyArea && charDisplay) {
      bodyArea.addEventListener('input', () => {
        const charLimits = { instagram: 2200, facebook: 63206, tiktok: 2200, email: null, eventbrite: 15000, luma: 5000, funcheap: 500, foopee: 500, press: 800 };
        const limit = charLimits[channel];
        const count = bodyArea.value.length;
        charDisplay.textContent = `${count}${limit ? ` / ${limit}` : ''} chars`;
        charDisplay.className = `promote-char-count ${limit && count > limit ? 'error-text' : 'muted'}`;
      });
    }

    // Save variant
    $('[data-save-variant]', panel)?.addEventListener('click', async (event) => {
      const btn = event.currentTarget;
      const vid = btn.dataset.vid;
      btn.disabled = true;
      const body = {
        body: $('[data-variant-body]', panel)?.value || '',
        status: $('[data-variant-status]', panel)?.value || 'draft',
      };
      const titleInput = $('[name="variant_title"]', panel);
      if (titleInput) body.title = titleInput.value;
      try {
        const result = await api(`/promote/campaigns/${this.campaignId}/posts/${this.post.id}/variants/${vid}`, { method: 'PATCH', body: JSON.stringify(body) });
        this.variants[channel] = result.variant;
        publish('toast.show', { message: 'Variant saved.', tone: 'success' });
      } catch (error) {
        publish('toast.show', { message: error.message || 'Save failed.', tone: 'error' });
      } finally {
        btn.disabled = false;
      }
    });
  }
}

customElements.define('pb-promote-post-editor', PromotePostEditor);

// ── pb-promote-broadcast-modal ────────────────────────────────────────────────
// 4 grouped destination checkboxes, readiness labels, schedule, send.
// Subscribes to promote.broadcast.open; publishes promote.broadcast.created.

class PromoteBroadcastModal extends PanicElement {
  // campaignId, destinations[] set by parent overview
  connectedCallback() {
    super.connectedCallback();
    subscribe('promote.broadcast.open', (p) => {
      if (p.campaignId === this.campaignId) {
        this.pendingPostId = p.postId;
        this.open(p.campaignId, p.postId, this.destinations || []);
      }
    }, this.abort.signal);
  }

  open(campaignId, postId, destinations) {
    this.campaignId = campaignId;
    this.pendingPostId = postId;
    this.destinations = destinations;
    this.renderModal(destinations, postId);
  }

  renderModal(destinations, postId) {
    // Remove any prior dialog
    $('[data-broadcast-dialog]', this)?.remove();

    const groups = {};
    (destinations || []).forEach((d) => {
      const g = d.destination_group || 'other';
      if (!groups[g]) groups[g] = [];
      groups[g].push(d);
    });

    const groupOrder = ['direct_post', 'event_platform', 'editorial_submission', 'email'];
    let groupNum = 0;

    const groupHtml = groupOrder.map((gk) => {
      const items = groups[gk];
      if (!items || items.length === 0) return '';
      groupNum++;
      const label = DEST_GROUP_LABELS[gk] || titleCase(gk);
      const rows = items.map((d) => {
        const tone = destTone(d.status);
        const readinessLabel = {
          connected: 'Connected',
          needs_auth: 'Needs auth',
          manual_submission: 'Manual submission',
          ready: 'Ready',
          needs_content: 'Needs content',
        }[d.status] || titleCase(d.status);
        const dotCls = d.destination_key ? `dot-${esc(d.destination_key.split('_')[0])}` : '';
        return `<label class="promote-dest-row check-label">
          <input type="checkbox" name="destinations" value="${esc(d.destination_key)}" checked>
          <span class="promote-dest-dot ${dotCls}" aria-hidden="true"></span>
          <span class="promote-dest-label">${esc(d.label)}</span>
          <span class="promote-dest-status badge ${esc(tone)}">${esc(readinessLabel)}</span>
        </label>`;
      }).join('');
      return `<div class="promote-dest-group">
        <div class="promote-dest-group-head"><span class="promote-dest-num">${groupNum}</span><strong>${esc(label)}</strong></div>
        ${rows}
      </div>`;
    }).join('');

    const dialog = document.createElement('div');
    dialog.className = 'modal-backdrop';
    dialog.setAttribute('data-broadcast-dialog', '');
    dialog.innerHTML = `<div class="modal-card wide">
      <div class="section-head padded">
        <h2><i class="fa-solid fa-satellite-dish" aria-hidden="true"></i> Send Broadcast</h2>
        <button class="small secondary" data-close type="button">Close</button>
      </div>
      <form class="padded" data-broadcast-form>
        <div class="promote-dest-groups">
          ${groupHtml || '<p class="muted">No destinations configured.</p>'}
        </div>
        <div class="promote-when-section">
          <strong>When to send</strong>
          <label class="check-label"><input type="radio" name="send_mode" value="now" checked> Post now</label>
          <label class="check-label"><input type="radio" name="send_mode" value="scheduled"> Schedule for
            <input type="datetime-local" name="scheduled_at" class="promote-schedule-dt">
          </label>
        </div>
        <div class="form-actions">
          <button type="submit" class="primary"><i class="fa-solid fa-satellite-dish" aria-hidden="true"></i> Send Broadcast</button>
          <button type="button" class="secondary" data-close>Cancel</button>
        </div>
        <p class="error-text" data-error></p>
      </form>
    </div>`;

    this.appendChild(dialog);

    const close = () => dialog.remove();
    $$('[data-close]', dialog).forEach((btn) => btn.addEventListener('click', close));
    dialog.addEventListener('click', (event) => { if (event.target === dialog) close(); });
    document.addEventListener('keydown', function onEsc(event) {
      if (event.key === 'Escape') { document.removeEventListener('keydown', onEsc); close(); }
    });

    $('[data-broadcast-form]', dialog).addEventListener('submit', async (event) => {
      event.preventDefault();
      const submit = $('button[type="submit"]', event.target);
      $('[data-error]', dialog).textContent = '';
      submit.disabled = true;

      const checkedDests = $$('input[name="destinations"]:checked', event.target).map((cb) => cb.value);
      if (checkedDests.length === 0) {
        $('[data-error]', dialog).textContent = 'Select at least one destination.';
        submit.disabled = false;
        return;
      }

      const sendMode = event.target.send_mode.value;
      const scheduledAt = event.target.scheduled_at?.value || null;

      // Need a post_id — if no specific post, use most recent or prompt
      let postId = this.pendingPostId;
      if (!postId) {
        $('[data-error]', dialog).textContent = 'Select a post to broadcast first.';
        submit.disabled = false;
        return;
      }

      const body = {
        post_id: postId,
        send_mode: sendMode,
        scheduled_at: sendMode === 'scheduled' ? scheduledAt : null,
        destinations: checkedDests,
      };

      try {
        const result = await api(`/promote/campaigns/${this.campaignId}/broadcasts`, { method: 'POST', body: JSON.stringify(body) });
        publish('toast.show', { message: 'Broadcast sent!', tone: 'success' });
        publish('promote.broadcast.created', { broadcast: result.broadcast, campaignId: this.campaignId });
        close();
      } catch (error) {
        $('[data-error]', dialog).textContent = error.message || 'Broadcast failed.';
        submit.disabled = false;
      }
    });
  }
}

customElements.define('pb-promote-broadcast-modal', PromoteBroadcastModal);

// ── pb-promote-assets-card ────────────────────────────────────────────────────
// Approved event assets + 1:1/4:5/9:16/16:9 aspect placeholders.

class PromoteAssetsCard extends PanicElement {
  // assets[] set by parent
  connectedCallback() {
    super.connectedCallback();
    this.render();
  }

  render() {
    const assets = this.assets || [];
    const approvedAssets = assets.filter((a) => a.approval_status === 'approved');

    this.innerHTML = `<article class="panel promote-assets-card">
      <div class="section-head padded">
        <h3><i class="fa-solid fa-images" aria-hidden="true"></i> Assets</h3>
        ${assets.length ? `<a href="#" class="small muted" data-view-all-assets>View all ${esc(String(assets.length))}</a>` : ''}
      </div>
      <div class="promote-assets-grid padded">
        ${approvedAssets.slice(0, 6).map((a) => this.assetTile(a)).join('')}
        ${approvedAssets.length === 0 ? '<p class="muted">No approved assets yet.</p>' : ''}
      </div>
      <div class="promote-ratio-row padded">
        <strong class="promote-ratio-label muted">Format placeholders</strong>
        <div class="promote-ratio-thumbs">
          ${RATIOS.map((r) => `<div class="promote-ratio-thumb-wrap">
            <div class="promote-ratio-thumb ${esc(r.cls)}" title="${esc(r.key)} crop" aria-label="${esc(r.key)} aspect ratio"></div>
            <span class="promote-ratio-caption">${esc(r.label)}</span>
          </div>`).join('')}
        </div>
      </div>
    </article>`;
  }

  assetTile(asset) {
    return `<div class="promote-asset-tile">
      ${assetThumb(asset, 'promote-asset-img')}
      <div class="promote-asset-meta">
        <span class="promote-asset-name">${esc(asset.title || asset.asset_type || 'Asset')}</span>
        <span class="badge success">Approved</span>
      </div>
    </div>`;
  }
}

customElements.define('pb-promote-assets-card', PromoteAssetsCard);

// ── pb-promote-analytics-card ─────────────────────────────────────────────────
// 4 stub metric tiles with sparkline placeholders.

class PromoteAnalyticsCard extends PanicElement {
  // analytics object set by parent
  connectedCallback() {
    super.connectedCallback();
    this.render();
  }

  render() {
    const a = this.analytics || {};

    const tiles = [
      { key: 'website_clicks',     label: 'Website Clicks',      icon: 'fa-arrow-pointer',     value: a.website_clicks ?? 0 },
      { key: 'rsvps',              label: 'RSVPs',               icon: 'fa-calendar-check',    value: a.rsvps ?? 0 },
      { key: 'ticket_conversions', label: 'Ticket Conversions',  icon: 'fa-ticket',            value: a.ticket_conversions ?? 0 },
      { key: 'email_opens',        label: 'Email Opens',         icon: 'fa-envelope-open-text', value: a.email_opens ?? 0 },
    ];

    this.innerHTML = `<article class="panel promote-analytics-card">
      <div class="section-head padded">
        <h3><i class="fa-solid fa-chart-line" aria-hidden="true"></i> Analytics</h3>
        <span class="badge info muted">All time</span>
      </div>
      <div class="promote-analytics-tiles padded">
        ${tiles.map((t) => `<div class="promote-analytics-tile">
          <div class="promote-analytics-tile-head">
            <i class="fa-solid ${esc(t.icon)}" aria-hidden="true"></i>
            <span class="promote-analytics-label">${esc(t.label)}</span>
          </div>
          <strong class="promote-analytics-value">${esc(String(t.value).replace(/\B(?=(\d{3})+(?!\d))/g, ','))}</strong>
          ${sparkline()}
        </div>`).join('')}
      </div>
    </article>`;
  }
}

customElements.define('pb-promote-analytics-card', PromoteAnalyticsCard);

// ── pb-promote-settings ───────────────────────────────────────────────────────
// Per-venue platform credential manager. Shows each connectable destination as
// a card with status badge, platform-specific fields, and Save/Disconnect actions.

// Field definitions for each connectable destination.
// Each entry: { key, label, type ('password'|'text'), hint }
const PLATFORM_FIELDS = {
  facebook_page: {
    label: 'Facebook Page',
    icon: 'fa-brands fa-facebook',
    group: 'Direct Posts',
    docs: 'https://developers.facebook.com/docs/pages/access-tokens',
    fields: [
      { key: 'access_token', label: 'Page Access Token', type: 'password', hint: 'Long-lived page token from Facebook Developer App' },
      { key: 'config.page_id', label: 'Page ID', type: 'text', hint: 'Numeric Facebook Page ID' },
    ],
  },
  instagram: {
    label: 'Instagram',
    icon: 'fa-brands fa-instagram',
    group: 'Direct Posts',
    docs: 'https://developers.facebook.com/docs/instagram-api',
    fields: [
      { key: 'access_token', label: 'User Access Token', type: 'password', hint: 'From Facebook Developer App with instagram_content_publish scope' },
      { key: 'config.ig_account_id', label: 'Instagram Business Account ID', type: 'text', hint: 'Numeric IG Business Account ID (not username)' },
    ],
  },
  tiktok: {
    label: 'TikTok',
    icon: 'fa-brands fa-tiktok',
    group: 'Direct Posts',
    docs: 'https://developers.tiktok.com/doc/content-posting-api-reference-direct-post',
    fields: [
      { key: 'access_token', label: 'Access Token', type: 'password', hint: 'OAuth user access token from your TikTok Developer App' },
      { key: 'config.privacy_level', label: 'Privacy Level', type: 'text', hint: 'PUBLIC_TO_EVERYONE (default), MUTUAL_FOLLOW_FRIENDS, FOLLOWER_OF_CREATOR, or SELF_ONLY' },
    ],
  },
  eventbrite: {
    label: 'Eventbrite',
    icon: 'fa-solid fa-ticket',
    group: 'Event Platforms',
    docs: 'https://www.eventbrite.com/platform/api',
    fields: [
      { key: 'access_token', label: 'API Key (Private Token)', type: 'password', hint: 'From eventbrite.com/account-settings/apps' },
      { key: 'config.org_id', label: 'Organizer ID', type: 'text', hint: 'Numeric org ID — create an Organizer on eventbrite.com first, then fetch via the org lookup button' },
      { key: 'config.eb_venue_id', label: 'Venue ID (optional)', type: 'text', hint: 'Pre-created Eventbrite venue ID for Mabuhay Gardens — leave blank to embed venue text instead' },
    ],
  },
  luma: {
    label: 'Luma',
    icon: 'fa-solid fa-calendar-star',
    group: 'Event Platforms',
    docs: 'https://lu.ma/developers',
    fields: [
      { key: 'access_token', label: 'API Key', type: 'password', hint: 'From lu.ma/dashboard → Settings → API' },
    ],
  },
  bandsintown: {
    label: 'Bandsintown',
    icon: 'fa-solid fa-guitar',
    group: 'Event Platforms',
    docs: 'https://manager.bandsintown.com',
    fields: [
      { key: 'config.artist_name', label: 'Artist / Venue Name', type: 'text', hint: 'Your artist or venue name on Bandsintown (used for manager portal link)' },
    ],
  },
  email_general: {
    label: 'General Email List',
    icon: 'fa-solid fa-envelope',
    group: 'Email',
    docs: 'https://mailchimp.com/developer',
    fields: [
      { key: 'config.provider', label: 'Provider', type: 'text', hint: 'mailchimp or sendgrid' },
      { key: 'access_token', label: 'API Key', type: 'password', hint: 'Mailchimp or SendGrid API key' },
      { key: 'config.list_id', label: 'List / Audience ID', type: 'text', hint: 'Mailchimp audience ID or SendGrid list ID' },
      { key: 'config.from_name', label: 'From Name', type: 'text', hint: 'e.g. Mabuhay Gardens' },
      { key: 'config.from_email', label: 'From Email', type: 'text', hint: 'Reply-to address, e.g. hello@mabuhaygardens.com' },
      { key: 'config.sender_id', label: 'Sender ID (SendGrid only)', type: 'text', hint: 'Numeric Sender Authentication ID from SendGrid — leave blank for Mailchimp' },
    ],
  },
  email_press: {
    label: 'Press Email List',
    icon: 'fa-solid fa-newspaper',
    group: 'Email',
    docs: 'https://mailchimp.com/developer',
    fields: [
      { key: 'config.provider', label: 'Provider', type: 'text', hint: 'mailchimp or sendgrid' },
      { key: 'access_token', label: 'API Key', type: 'password', hint: 'Mailchimp or SendGrid API key' },
      { key: 'config.list_id', label: 'List / Audience ID', type: 'text', hint: 'Mailchimp audience ID or SendGrid list ID' },
      { key: 'config.from_name', label: 'From Name', type: 'text', hint: 'e.g. Mabuhay Gardens Press' },
      { key: 'config.from_email', label: 'From Email', type: 'text', hint: 'Reply-to address, e.g. press@mabuhaygardens.com' },
      { key: 'config.sender_id', label: 'Sender ID (SendGrid only)', type: 'text', hint: 'Numeric Sender Authentication ID from SendGrid — leave blank for Mailchimp' },
    ],
  },
  songkick: {
    label: 'SongKick',
    icon: 'fa-solid fa-music',
    group: 'Event Platforms',
    docs: 'https://www.songkick.com/artist-claim',
    fields: [
      { key: 'config.artist_url', label: 'Artist / Venue Page URL', type: 'text', hint: 'Your SongKick artist or venue page URL — used for link tracking' },
    ],
  },
  jambase: {
    label: 'JamBase',
    icon: 'fa-solid fa-compact-disc',
    group: 'Event Platforms',
    docs: 'https://www.jambase.com/submit',
    fields: [
      { key: 'config.artist_url', label: 'Artist / Venue Page URL', type: 'text', hint: 'Your JamBase artist or venue page URL — used for link tracking' },
    ],
  },
  sf_chronicle: {
    label: 'SF Chronicle',
    icon: 'fa-solid fa-pen-nib',
    group: 'Editorial Submissions',
    docs: 'https://www.sfchronicle.com/entertainment/music/',
    fields: [
      { key: 'config.contact_email', label: 'Submission Email', type: 'text', hint: 'e.g. datebook@sfchronicle.com — for manual pitch tracking' },
    ],
  },
  sf_station: {
    label: 'SF Station',
    icon: 'fa-solid fa-tower-broadcast',
    group: 'Editorial Submissions',
    docs: 'https://www.sfstation.com/submit/',
    fields: [
      { key: 'config.submission_url', label: 'Submission URL', type: 'text', hint: 'SF Station event submission form URL' },
    ],
  },
  dothebay: {
    label: 'DoTheBay',
    icon: 'fa-solid fa-calendar-days',
    group: 'Editorial Submissions',
    docs: 'https://dothebay.com/submit-event',
    fields: [
      { key: 'config.submission_url', label: 'Submission URL', type: 'text', hint: 'DoTheBay event submission form URL' },
    ],
  },
};

class PromoteSettings extends PanicElement {
  async connect() {
    this.venueId = 1;
    this.saving = {};
    await this.load();
  }

  async load() {
    this.innerHTML = '<div class="loading-state padded">Loading platform connections…</div>';
    try {
      const data = await api(`/promote/credentials?venue_id=${this.venueId}`);
      this.venues = data.venues || [];
      this.credentials = data.credentials || [];
      this.render();
    } catch (err) {
      this.innerHTML = `<div class="error-text padded">Failed to load credentials: ${esc(String(err?.message || err))}</div>`;
    }
  }

  render() {
    // Build a map of destKey → credential row
    const credMap = {};
    for (const c of this.credentials) credMap[c.destination_key] = c;

    // Group platforms
    const groups = {};
    for (const [destKey, def] of Object.entries(PLATFORM_FIELDS)) {
      if (!groups[def.group]) groups[def.group] = [];
      const cred = credMap[destKey] || { destination_key: destKey, cred_status: 'needs_auth', config: null };
      groups[def.group].push({ destKey, def, cred });
    }

    const venueSel = this.venues.length > 1
      ? `<div class="promote-settings-venue">
          <label>Venue:
            <select data-venue-select>
              ${this.venues.map((v) => `<option value="${esc(String(v.id))}" ${v.id === this.venueId ? 'selected' : ''}>${esc(v.name)}</option>`).join('')}
            </select>
          </label>
        </div>`
      : '';

    const groupsHtml = Object.entries(groups).map(([groupName, items]) => `
      <section class="promote-settings-group">
        <h3 class="promote-settings-group-title">${esc(groupName)}</h3>
        <div class="promote-settings-cards">
          ${items.map(({ destKey, def, cred }) => this.renderCard(destKey, def, cred)).join('')}
        </div>
      </section>
    `).join('');

    this.innerHTML = `
      <div class="promote-settings-page">
        <div class="promote-settings-header">
          <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
            <h2 style="margin:0"><i class="fa-solid fa-bullhorn" aria-hidden="true"></i> Promote Settings</h2>
            <a class="button ghost small" href="#help-promote-setup" title="Promote admin help"><i class="fa-solid fa-circle-question" aria-hidden="true"></i> Help</a>
          </div>
          <p class="subtle">Connect platforms so Panic Promote can post automatically. API keys and tokens are stored securely per venue and never exposed after saving.</p>
          ${venueSel}
        </div>
        ${groupsHtml}
      </div>`;

    // Venue selector
    this.$('[data-venue-select]')?.addEventListener('change', (e) => {
      this.venueId = Number(e.target.value);
      this.load();
    });

    // Wire up card forms
    for (const [destKey] of Object.entries(PLATFORM_FIELDS)) {
      this.wireCard(destKey);
    }
  }

  renderCard(destKey, def, cred) {
    const status = cred.cred_status || 'needs_auth';
    const statusTone = status === 'connected' ? 'success' : status === 'error' ? 'danger' : 'warning';
    const statusLabel = status === 'connected' ? 'Connected' : status === 'error' ? 'Error' : 'Not connected';
    const config = cred.config || {};

    const fieldsHtml = def.fields.map((f) => {
      const isConfig = f.key.startsWith('config.');
      const configKey = isConfig ? f.key.slice(7) : null;
      const val = isConfig ? (config[configKey] || '') : (cred.has_token ? '••••••••' : '');
      return `<div class="form-row">
        <label class="form-label">${esc(f.label)}
          <input
            type="${esc(f.type)}"
            data-field="${esc(f.key)}"
            data-dest="${esc(destKey)}"
            value="${esc(String(val))}"
            placeholder="${esc(f.hint)}"
            autocomplete="off"
            class="form-input"
          >
        </label>
        <p class="form-hint">${esc(f.hint)}</p>
      </div>`;
    }).join('');

    const extraButton = destKey === 'eventbrite'
      ? `<button type="button" class="button secondary small" data-eb-org-lookup>
           <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i> Fetch Org ID
         </button>`
      : '';

    return `<div class="promote-settings-card" data-card="${esc(destKey)}">
      <div class="promote-settings-card-head">
        <span class="promote-settings-platform-name">
          <i class="${esc(def.icon)}" aria-hidden="true"></i>
          ${esc(def.label)}
        </span>
        <span class="badge ${esc(statusTone)}">${esc(statusLabel)}</span>
      </div>
      ${cred.error_message ? `<p class="promote-settings-error">${esc(cred.error_message)}</p>` : ''}
      <div class="promote-settings-fields">
        ${fieldsHtml}
      </div>
      <div class="promote-settings-actions">
        ${extraButton}
        <button type="button" class="button primary small" data-save="${esc(destKey)}" ${this.saving[destKey] ? 'disabled' : ''}>
          ${this.saving[destKey] ? '<i class="fa-solid fa-spinner fa-spin"></i> Saving…' : '<i class="fa-solid fa-floppy-disk"></i> Save'}
        </button>
        ${status === 'connected' ? `<button type="button" class="button danger-outline small" data-disconnect="${esc(destKey)}">Disconnect</button>` : ''}
        <a href="${esc(def.docs)}" target="_blank" rel="noreferrer" class="button ghost small">Docs <i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i></a>
      </div>
    </div>`;
  }

  wireCard(destKey) {
    const card = this.$(`[data-card="${destKey}"]`);
    if (!card) return;

    card.querySelector(`[data-save="${destKey}"]`)?.addEventListener('click', () => this.save(destKey));
    card.querySelector(`[data-disconnect="${destKey}"]`)?.addEventListener('click', () => this.disconnect(destKey));
    card.querySelector('[data-eb-org-lookup]')?.addEventListener('click', () => this.fetchEventbriteOrg(destKey));
  }

  async save(destKey) {
    const def = PLATFORM_FIELDS[destKey];
    if (!def) return;

    const body = { venue_id: this.venueId, config: {} };

    for (const f of def.fields) {
      const input = this.$(`[data-field="${f.key}"][data-dest="${destKey}"]`);
      if (!input) continue;
      const val = input.value.trim();
      if (!val || val === '••••••••') continue; // skip blanks and masked placeholders

      if (f.key.startsWith('config.')) {
        body.config[f.key.slice(7)] = val;
      } else if (f.key === 'access_token') {
        body.access_token = val;
      } else if (f.key === 'refresh_token') {
        body.refresh_token = val;
      }
    }

    this.saving[destKey] = true;
    this.rerenderCard(destKey);

    try {
      await api(`/promote/credentials/${destKey}`, { method: 'PUT', body: JSON.stringify(body) });
      publish('toast.show', { message: `${def.label} credentials saved`, tone: 'success' });
      await this.load();
    } catch (err) {
      publish('toast.show', { message: `Save failed: ${err?.message || err}`, tone: 'error' });
      this.saving[destKey] = false;
      this.rerenderCard(destKey);
    }
  }

  async disconnect(destKey) {
    const def = PLATFORM_FIELDS[destKey];
    if (!def) return;
    if (!confirm(`Disconnect ${def.label}? This will remove the stored credentials.`)) return;

    try {
      await api(`/promote/credentials/${destKey}`, { method: 'DELETE' });
      publish('toast.show', { message: `${def.label} disconnected`, tone: 'info' });
      await this.load();
    } catch (err) {
      publish('toast.show', { message: `Disconnect failed: ${err?.message || err}`, tone: 'error' });
    }
  }

  async fetchEventbriteOrg(destKey) {
    try {
      const data = await api('/promote/eventbrite/org');
      const orgs = data.organizations || [];
      if (!orgs.length) {
        publish('toast.show', { message: data.instructions || 'No organizations found — set up an Organizer on eventbrite.com first.', tone: 'warning' });
        return;
      }
      // Auto-fill the org_id field with the first org, prompt if multiple
      const org = orgs.length === 1 ? orgs[0] : orgs.find((o) => o.name) || orgs[0];
      const input = this.$(`[data-field="config.org_id"][data-dest="${destKey}"]`);
      if (input) {
        input.value = org.id;
        publish('toast.show', { message: `Org ID fetched: ${org.name} (${org.id})`, tone: 'success' });
        if (orgs.length > 1) {
          publish('toast.show', { message: `${orgs.length} organizations found — verify the correct one is filled in.`, tone: 'info' });
        }
      }
    } catch (err) {
      publish('toast.show', { message: `Could not fetch org ID: ${err?.message || err}`, tone: 'error' });
    }
  }

  rerenderCard(destKey) {
    const def = PLATFORM_FIELDS[destKey];
    if (!def) return;
    const cred = (this.credentials || []).find((c) => c.destination_key === destKey)
      || { destination_key: destKey, cred_status: 'needs_auth', config: null };
    const card = this.$(`[data-card="${destKey}"]`);
    if (!card) return;
    const tmp = document.createElement('div');
    tmp.innerHTML = this.renderCard(destKey, def, cred);
    card.replaceWith(tmp.firstElementChild);
    this.wireCard(destKey);
  }

  // Scoped querySelector helper
  $(sel) { return this.querySelector(sel); }
}

customElements.define('pb-promote-settings', PromoteSettings);
