// ── Panic Promote — Frontend Web Components ───────────────────────────────────
// Native Web Components for the Promote module. Extends PanicElement from
// core.js. All rendered values are escaped with esc(). Components communicate
// via publish/subscribe topics — never by direct reference.
//
// Components:
//   pb-promote-campaign-list     — #promote route: event cards for upcoming events
//   pb-promote-campaign-overview — #promote-event-{id}: rich single-event promote view
//   pb-promote-health-card       — checklist with done/warn/missing, expandable
//   pb-promote-post-list         — post cards with Edit/Preview/Broadcast buttons
//   pb-promote-post-editor       — modal: post CRUD + 15-channel variant tabs
//   pb-promote-broadcast-modal   — 4 destination groups + schedule + send
//   pb-promote-assets-card       — approved assets + aspect ratio placeholders
//   pb-promote-analytics-card    — 4 stub metric tiles with sparkline placeholders
//
// Topics published:
//   promote.broadcast.open       {eventId, postId}
//   promote.post.created         {post, eventId}
//   promote.post.updated         {post, eventId}
//   promote.post.deleted         {postId, eventId}
//   promote.variants.generated   {postId, variants}
//   promote.broadcast.created    {broadcast, eventId}
//   promote.health.changed       {eventId}
//   toast.show                   {message, tone}

import {
  esc, api, publish, subscribe, PanicElement, assetUrl,
  shortDate, titleCase, $, $$
} from './core.js';

// ── Helpers ──────────────────────────────────────────────────────────────────

const CHANNELS = [
  'instagram', 'facebook', 'tiktok', 'twitter', 'threads', 'bluesky',
  'email', 'email_adhoc',
  'eventbrite', 'luma', 'dice', 'resident_advisor',
  'funcheap', 'foopee', 'press',
  'sf_chronicle', 'sf_station', 'dothebay',
  'songkick', 'jambase',
];

const CHANNEL_LABELS = {
  instagram:       'Instagram',
  facebook:        'Facebook',
  tiktok:          'TikTok',
  twitter:         'Twitter / X',
  threads:         'Threads',
  bluesky:         'Bluesky',
  email:           'Email List',
  email_adhoc:     'Ad-hoc Email',
  eventbrite:      'Eventbrite',
  luma:            'Luma',
  dice:            'Dice.fm',
  resident_advisor:'Resident Advisor',
  funcheap:        'Funcheap',
  foopee:          'Foopee',
  press:           'Press',
  sf_chronicle:    'SF Chronicle',
  sf_station:      'SF Station',
  dothebay:        'DoTheBay',
  songkick:        'SongKick',
  jambase:         'JamBase',
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
// Route: #promote — shows a card per upcoming event with promote activity.

class PromoteCampaignList extends PanicElement {
  async connect() {
    this.setLoading('Loading promote');
    try {
      const data = await api('/promote/events');
      this.render(data.events || []);
    } catch (error) {
      this.showError(error);
    }
  }

  render(events) {
    publish('page.context', { title: 'Panic Promote', blurb: 'Promotion command center — turn upcoming shows into coordinated promotions.' });
    this.innerHTML = `<div class="promote-campaign-grid" data-campaign-grid>
      ${events.length ? events.map((e) => this.eventCard(e)).join('') : `<div class="panel padded"><p class="muted">No upcoming events found. Add events to get started.</p></div>`}
    </div>`;

    $$('[data-open-event]', this).forEach((btn) => {
      btn.addEventListener('click', () => {
        location.hash = `#promote-event-${esc(btn.dataset.openEvent)}`;
      });
    });
  }

  eventCard(e) {
    const days = daysOutLabel(e.event_date);
    const statusTone = e.promote_status === 'active' ? 'success' : e.promote_status === 'completed' ? 'info' : '';
    const postCount = Number(e.post_count || 0);
    return `<article class="panel promote-campaign-card">
      <div class="promote-campaign-card-head">
        <div class="promote-campaign-meta">
          <h2 class="promote-campaign-title"><a href="#promote-event-${esc(String(e.event_id))}">${esc(e.event_title)}</a></h2>
          <div class="promote-campaign-sub">
            <span class="muted">${esc(shortDate(e.event_date ? new Date(`${e.event_date}T12:00:00`) : null))}</span>
            ${days ? `<span class="promote-days-badge">${esc(days)}</span>` : ''}
            ${e.promote_status ? `<span class="badge ${esc(statusTone)}">${esc(titleCase(e.promote_status))}</span>` : ''}
          </div>
        </div>
        <button class="primary small" data-open-event="${esc(String(e.event_id))}">Open</button>
      </div>
      <div class="promote-campaign-stats">
        ${e.goal_tickets ? `<span class="promote-stat"><strong>${esc(String(e.goal_tickets))}</strong> goal</span>` : ''}
        <span class="promote-stat"><strong>${esc(String(postCount))}</strong> post${postCount !== 1 ? 's' : ''}</span>
      </div>
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
    this.setLoading('Loading promote');
    subscribe('promote.post.created', () => this.reloadPosts(), this.abort.signal);
    subscribe('promote.post.updated', () => this.reloadPosts(), this.abort.signal);
    subscribe('promote.post.deleted', () => this.reloadPosts(), this.abort.signal);
    subscribe('promote.variants.generated', () => this.reloadPosts(), this.abort.signal);
    subscribe('promote.broadcast.created', () => this.reloadAll(), this.abort.signal);
    subscribe('promote.health.changed', (p) => {
      if (p.eventId === this.eventId) this.reloadHealth();
    }, this.abort.signal);
    await this.loadData();
  }

  async loadData() {
    try {
      const data = await api(`/promote/events/${this.eventId}`);
      this.data = data;
      this.render();
    } catch (error) {
      this.showError(error);
    }
  }

  async reloadAll() {
    try {
      const data = await api(`/promote/events/${this.eventId}`);
      this.data = data;
      this.render();
    } catch { /* silently skip reload on error */ }
  }

  async reloadPosts() {
    try {
      const data = await api(`/promote/events/${this.eventId}`);
      this.data = { ...this.data, ...data };
      const postList = $('pb-promote-post-list', this);
      if (postList) {
        postList.eventId = this.eventId;
        postList.posts = data.posts || [];
        postList.assets = this.data.assets || [];
        postList.render();
      }
    } catch { /* ignore */ }
  }

  async reloadHealth() {
    try {
      const { health } = await api(`/promote/events/${this.eventId}/health`);
      this.data = { ...this.data, health };
      const healthCard = $('pb-promote-health-card', this);
      if (healthCard) {
        healthCard.health = health;
        healthCard.render();
      }
    } catch { /* ignore */ }
  }

  render() {
    const { settings, event, posts, assets, health, analytics, destinations } = this.data;
    const e = event || {};
    const s = settings || {};
    const eventDate = e.date ? new Date(`${e.date}T12:00:00`) : null;

    publish('page.context', {
      title: e.title || 'Promote',
      blurb: `${e.venue_name || ''}${e.venue_city ? ` — ${e.venue_city}` : ''}`,
    });
    this.innerHTML = `<section class="page-head">
      <nav class="promote-breadcrumb"><a href="#promote">&larr; Promote</a></nav>
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
              <h2>${esc(e.title || '')}</h2>
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
              <span class="promote-metric-value">${esc(String(s.goal_tickets || '—'))}</span>
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
                <span class="badge ${esc(s.status === 'active' ? 'success' : '')}">${esc(titleCase(s.status || 'draft'))}</span>
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
    // NOTE: connectedCallback fires synchronously when innerHTML is assigned above,
    // before these properties are set — so we must explicitly call render() after
    // setting props to ensure each component renders with real data.
    const postList = $('pb-promote-post-list', this);
    if (postList) {
      postList.eventId = this.eventId;
      postList.posts = posts || [];
      postList.assets = assets || [];
      postList.render();
    }

    const healthCard = $('pb-promote-health-card', this);
    if (healthCard) {
      healthCard.health = health;
      healthCard.render();
    }

    const assetsCard = $('pb-promote-assets-card', this);
    if (assetsCard) {
      assetsCard.eventId = this.eventId;
      assetsCard.assets = assets || [];
      assetsCard.render();
    }

    const analyticsCard = $('pb-promote-analytics-card', this);
    if (analyticsCard) {
      analyticsCard.analytics = analytics;
      analyticsCard.render();
    }

    const broadcastModal = $('pb-promote-broadcast-modal', this);
    if (broadcastModal) {
      broadcastModal.eventId = this.eventId;
      broadcastModal.destinations = destinations || [];
    }

    // "New Post" button
    $('[data-new-post]', this)?.addEventListener('click', () => {
      this.openPostEditor(null);
    });

    // "Broadcast" button (no specific post — opens modal with no pre-selected post)
    $('[data-broadcast-all]', this)?.addEventListener('click', () => {
      const bm = $('pb-promote-broadcast-modal', this);
      if (bm) bm.open(this.eventId, null, destinations || []);
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
    editor.eventId = this.eventId;
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
  // eventId, posts[], assets[] set by parent
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
        publish('promote.broadcast.open', { eventId: this.eventId, postId });
      });
    });

    $$('[data-delete-post]', this).forEach((btn) => {
      btn.addEventListener('click', async () => {
        const postId = Number(btn.dataset.deletePost);
        if (!confirm('Delete this post? This cannot be undone.')) return;
        try {
          await api(`/promote/events/${this.eventId}/posts/${postId}`, { method: 'DELETE' });
          publish('toast.show', { message: 'Post deleted.', tone: 'success' });
          publish('promote.post.deleted', { postId, eventId: this.eventId });
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
    editor.eventId = this.eventId;
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
  // eventId, post (null = create), assets[] set before append
  connectedCallback() {
    super.connectedCallback();
    this.activeChannel = CHANNELS[0];
    this.activeMainTab = 'post';
    this.variants = {};
    if (this.post?.id) this.loadVariants();
    else this.renderModal();
  }

  async loadVariants() {
    try {
      // Load the full post to get existing variants (GET single post returns them)
      const data = await api(`/promote/events/${this.eventId}/posts/${this.post.id}`);
      this.variants = {};
      (data.post?.variants || []).forEach((v) => { this.variants[v.channel] = v; });
    } catch { /* variants will be empty — that's fine */ }
    this.renderModal();
  }

  renderModal() {
    const post = this.post || {};
    const isEdit = Boolean(post.id);
    const assets = this.assets || [];
    const variantCount = Object.values(this.variants).filter((v) => v.id).length;

    const assetOptions = assets.map((a) =>
      `<option value="${esc(String(a.id))}" ${String(a.id) === String(post.asset_id || '') ? 'selected' : ''}>${esc(a.title || a.asset_type || 'Asset')}</option>`
    ).join('');

    // ── Reusable HTML fragments ───────────────────────────────────────────────

    const postFormHtml = `<form class="grid-form padded" data-post-form>
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
    </form>`;

    const variantsPanelHtml = `<div class="promote-variants-section padded">
      <div class="promote-variants-head">
        <span class="promote-variants-intro muted">Edit per-channel copy below, or generate all at once from the master text.</span>
        <button class="secondary small" data-generate-variants>Generate variants</button>
      </div>
      <div class="promote-variant-tabs" role="tablist">
        ${CHANNELS.map((ch) => `<button class="promote-tab ${ch === this.activeChannel ? 'active' : ''}" role="tab" data-channel="${esc(ch)}">${esc(CHANNEL_LABELS[ch])}</button>`).join('')}
      </div>
      <div class="promote-variant-panels" data-variant-panels>
        ${this.renderVariantPanel(this.activeChannel)}
      </div>
    </div>`;

    // ── Main tab bar (edit mode only) ─────────────────────────────────────────

    const tabBarHtml = isEdit ? `<div class="promote-editor-tabbar" role="tablist">
      <button class="promote-editor-tab ${this.activeMainTab === 'post' ? 'active' : ''}"
              role="tab" aria-selected="${this.activeMainTab === 'post'}" data-main-tab="post">
        <i class="fa-solid fa-file-pen" aria-hidden="true"></i> Post
      </button>
      <button class="promote-editor-tab ${this.activeMainTab === 'variants' ? 'active' : ''}"
              role="tab" aria-selected="${this.activeMainTab === 'variants'}" data-main-tab="variants">
        <i class="fa-solid fa-layer-group" aria-hidden="true"></i> Channel Variants
        ${variantCount > 0 ? `<span class="promote-editor-tab-badge">${esc(String(variantCount))}&#8202;/&#8202;${esc(String(CHANNELS.length))}</span>` : ''}
      </button>
    </div>` : '';

    // ── Modal body ────────────────────────────────────────────────────────────

    const bodyHtml = isEdit
      ? `<div data-main-panel="post"${this.activeMainTab !== 'post' ? ' hidden' : ''}>${postFormHtml}</div>
         <div data-main-panel="variants"${this.activeMainTab !== 'variants' ? ' hidden' : ''}>${variantsPanelHtml}</div>`
      : postFormHtml;

    // ── Build dialog ──────────────────────────────────────────────────────────

    const dialog = document.createElement('div');
    dialog.className = 'modal-backdrop';
    dialog.innerHTML = `<div class="modal-card wide">
      <div class="section-head padded">
        <h2>${isEdit ? 'Edit Post' : 'New Post'}</h2>
        <button class="small secondary" data-close type="button">Close</button>
      </div>
      ${tabBarHtml}
      <div class="modal-card-body">
        ${bodyHtml}
      </div>
    </div>`;

    this.innerHTML = '';
    this.appendChild(dialog);

    // ── Close handlers ────────────────────────────────────────────────────────

    const close = () => this.remove();
    $$('[data-close]', this).forEach((btn) => btn.addEventListener('click', close));
    dialog.addEventListener('click', (event) => { if (event.target === dialog) close(); });
    document.addEventListener('keydown', function onEsc(event) {
      if (event.key === 'Escape') { document.removeEventListener('keydown', onEsc); close(); }
    });

    // Focus first field
    $('input[name="title"]', this)?.focus();

    // ── Main tab switching (edit only) ────────────────────────────────────────

    if (isEdit) {
      $$('[data-main-tab]', this).forEach((btn) => {
        btn.addEventListener('click', () => {
          this.activeMainTab = btn.dataset.mainTab;
          $$('[data-main-tab]', this).forEach((b) => {
            b.classList.toggle('active', b.dataset.mainTab === this.activeMainTab);
            b.setAttribute('aria-selected', String(b.dataset.mainTab === this.activeMainTab));
          });
          $$('[data-main-panel]', this).forEach((p) => {
            p.toggleAttribute('hidden', p.dataset.mainPanel !== this.activeMainTab);
          });
        });
      });
    }

    // ── Post form submit ──────────────────────────────────────────────────────

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
          result = await api(`/promote/events/${this.eventId}/posts/${post.id}`, { method: 'PATCH', body: JSON.stringify(body) });
          publish('toast.show', { message: 'Post updated.', tone: 'success' });
          publish('promote.post.updated', { post: result.post, eventId: this.eventId });
        } else {
          result = await api(`/promote/events/${this.eventId}/posts`, { method: 'POST', body: JSON.stringify(body) });
          publish('toast.show', { message: 'Post created.', tone: 'success' });
          publish('promote.post.created', { post: result.post, eventId: this.eventId });
          close();
        }
        if (isEdit) {
          this.post = result.post;
          submit.disabled = false;
        }
      } catch (error) {
        $('[data-error]', event.target).textContent = error.message || 'Save failed.';
        submit.disabled = false;
      }
    });

    // ── Channel variant tabs + generate (edit only) ───────────────────────────

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
          const result = await api(`/promote/events/${this.eventId}/posts/${post.id}/variants/generate`, { method: 'POST' });
          result.variants.forEach((v) => { this.variants[v.channel] = v; });
          // Update the badge count on the Variants tab
          const badge = $('[data-main-tab="variants"] .promote-editor-tab-badge', this);
          const newCount = Object.values(this.variants).filter((v) => v.id).length;
          if (badge) {
            badge.textContent = `${newCount} / ${CHANNELS.length}`;
          } else if (newCount > 0) {
            const varTab = $('[data-main-tab="variants"]', this);
            if (varTab) varTab.insertAdjacentHTML('beforeend', `<span class="promote-editor-tab-badge">${esc(String(newCount))}&#8202;/&#8202;${esc(String(CHANNELS.length))}</span>`);
          }
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
    const charLimits = {
      instagram: 2200, facebook: 63206, tiktok: 2200,
      twitter: 280, threads: 500, bluesky: 300,
      email: null, eventbrite: 15000, luma: 5000,
      funcheap: 500, foopee: 500, press: 800,
    };
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
        const charLimits = {
          instagram: 2200, facebook: 63206, tiktok: 2200,
          twitter: 280, threads: 500, bluesky: 300,
          email: null, eventbrite: 15000, luma: 5000,
          funcheap: 500, foopee: 500, press: 800,
        };
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
        const result = await api(`/promote/events/${this.eventId}/posts/${this.post.id}/variants/${vid}`, { method: 'PATCH', body: JSON.stringify(body) });
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
  // eventId, destinations[] set by parent overview
  connectedCallback() {
    super.connectedCallback();
    subscribe('promote.broadcast.open', (p) => {
      if (p.eventId === this.eventId) {
        this.pendingPostId = p.postId;
        this.open(p.eventId, p.postId, this.destinations || []);
      }
    }, this.abort.signal);
  }

  open(eventId, postId, destinations) {
    this.eventId = eventId;
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
        const isManual = d.status === 'manual_submission' || d.status === 'needs_auth';
        return `<div class="promote-dest-row">
          <label class="check-label promote-dest-check-label">
            <input type="checkbox" name="destinations" value="${esc(d.destination_key)}" checked>
            <span class="promote-dest-dot ${dotCls}" aria-hidden="true"></span>
            <span class="promote-dest-label">${esc(d.label)}</span>
            <span class="promote-dest-status badge ${esc(tone)}">${esc(readinessLabel)}</span>
          </label>
          ${isManual ? `<button type="button" class="promote-dest-action-btn"
                data-action-dest="${esc(d.destination_key)}"
                data-dest-label="${esc(d.label)}"
                title="How to submit to ${esc(d.label)}">
              <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
            </button>` : ''}
        </div>`;
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

    // Wire action info buttons — open the action modal for manual/needs_auth destinations
    $$('[data-action-dest]', dialog).forEach((btn) => {
      btn.addEventListener('click', () => {
        const modal = document.createElement('pb-promote-action-modal');
        modal.eventId   = this.eventId;
        modal.postId    = this.pendingPostId;
        modal.destKey   = btn.dataset.actionDest;
        modal.destLabel = btn.dataset.destLabel;
        document.body.appendChild(modal);
      });
    });

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
        const result = await api(`/promote/events/${this.eventId}/broadcasts`, { method: 'POST', body: JSON.stringify(body) });
        publish('toast.show', { message: 'Broadcast sent!', tone: 'success' });
        publish('promote.broadcast.created', { broadcast: result.broadcast, eventId: this.eventId });
        close();
      } catch (error) {
        $('[data-error]', dialog).textContent = error.message || 'Broadcast failed.';
        submit.disabled = false;
      }
    });
  }
}

customElements.define('pb-promote-broadcast-modal', PromoteBroadcastModal);

// ── pb-promote-action-modal ───────────────────────────────────────────────────
// Opens when a manual-submission destination's ℹ button is clicked.
// Shows the channel variant content, instructions, and action buttons:
//   • Copy to clipboard
//   • Open in local email app  (mailto: pre-filled)
//   • Send from events@panicbooking.com  (server-side send)
//   • Open submission form  (for platform / form-based destinations)
//
// Required props set before DOM append: eventId, postId, destKey, destLabel

class PromoteActionModal extends PanicElement {
  async connectedCallback() {
    super.connectedCallback();
    this.renderShell();
    if (this.postId) {
      await this.loadInfo();
    } else {
      $('[data-action-body]', this).innerHTML =
        '<p class="muted">Select a specific post before previewing action content.</p>';
    }
  }

  // ── Shell (shown while loading) ───────────────────────────────────────────

  renderShell() {
    const label = this.destLabel || this.destKey || 'Action';
    this.innerHTML = `<div class="modal-backdrop" data-action-backdrop>
      <div class="modal-card promote-action-card">
        <div class="section-head padded">
          <div>
            <h2 class="promote-action-title">
              <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
              ${esc(label)}
            </h2>
          </div>
          <button class="small secondary" data-close type="button">Close</button>
        </div>
        <div class="promote-action-body padded" data-action-body>
          <p class="muted"><i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i> Loading…</p>
        </div>
      </div>
    </div>`;

    const close = () => this.remove();
    $('[data-close]', this)?.addEventListener('click', close);
    $('[data-action-backdrop]', this)?.addEventListener('click', (e) => {
      if (e.target === $('[data-action-backdrop]', this)) close();
    });
    const onEsc = (e) => { if (e.key === 'Escape') { document.removeEventListener('keydown', onEsc); close(); } };
    document.addEventListener('keydown', onEsc);
  }

  // ── Fetch action info from server ─────────────────────────────────────────

  async loadInfo() {
    try {
      const info = await api(`/promote/events/${this.eventId}/posts/${this.postId}/action/${this.destKey}`);
      this.renderContent(info);
    } catch (err) {
      $('[data-action-body]', this).innerHTML =
        `<p class="error-text">${esc(err.message || 'Failed to load action info.')}</p>`;
    }
  }

  // ── Render loaded content ─────────────────────────────────────────────────

  renderContent(info) {
    const { dest_label, dest_group, variant, config, can_email, can_form } = info;

    const subject  = (variant?.title || '').trim();
    const body     = (variant?.body  || '').trim();
    const toEmail  = (config?.contact_email || '').trim();

    // Parse warnings/instructions stored in the variant
    let warnings = [];
    try {
      warnings = typeof variant?.warnings_json === 'string'
        ? JSON.parse(variant.warnings_json || '[]')
        : (Array.isArray(variant?.warnings_json) ? variant.warnings_json : []);
    } catch {}

    // Collect all submission / platform URLs from config (shown as links in the modal)
    const urlFieldLabels = {
      submission_url:     'Submission Form',
      partner_url:        'Partner Portal',
      promoter_url:       'Promoter Portal',
      artist_url:         'Artist Page',
      artist_page_url:    'Artist Profile',
      event_platform_url: 'Event Platform',
    };
    const submitLinks = Object.entries(urlFieldLabels)
      .filter(([key]) => config?.[key])
      .map(([key, label]) => ({ label, url: (config[key] || '').trim() }))
      .filter(({ url }) => url);

    const groupLabel = {
      direct_post:          'Direct Post',
      event_platform:       'Event Platform',
      editorial_submission: 'Editorial Submission',
      email:                'Email Campaign',
    }[dest_group] || titleCase(dest_group || '');

    const noContent = !body;

    const mailtoHref = (toEmail && subject && body)
      ? `mailto:${encodeURIComponent(toEmail)}?subject=${encodeURIComponent(subject)}&body=${encodeURIComponent(body)}`
      : '';

    const bodyEl = $('[data-action-body]', this);
    if (!bodyEl) return;

    bodyEl.innerHTML = `
      ${groupLabel ? `<p class="promote-action-group-label">${esc(groupLabel)}</p>` : ''}

      ${warnings.length ? `
        <div class="action-instructions">
          <h4><i class="fa-solid fa-lightbulb" aria-hidden="true"></i> Instructions</h4>
          <ul class="action-instruction-list">
            ${warnings.map((w) => `<li>${esc(String(w))}</li>`).join('')}
          </ul>
        </div>` : ''}

      ${submitLinks.length ? `
        <div class="action-field">
          <div class="action-field-label">Submit To</div>
          <div class="action-submit-links">
            ${submitLinks.map(({ label, url }) =>
              `<a href="${esc(url)}" target="_blank" rel="noopener noreferrer" class="action-submit-link">
                <i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i> ${esc(label)}
              </a>`
            ).join('')}
          </div>
        </div>` : ''}

      ${noContent ? `
        <div class="action-no-content">
          <i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i>
          No variant generated yet — open the post editor and click
          <strong>Generate variants</strong> first.
        </div>` : `

        <div class="action-content-section">
          ${subject ? `
            <div class="action-field">
              <div class="action-field-label">Subject</div>
              <div class="action-field-value">${esc(subject)}</div>
            </div>` : ''}

          <div class="action-field">
            <div class="action-field-label">
              <span>Message</span>
              <button type="button" class="small ghost action-copy-btn" data-copy-body>
                <i class="fa-regular fa-clipboard" aria-hidden="true"></i> Copy
              </button>
            </div>
            <textarea class="action-body-textarea" readonly rows="9">${esc(body)}</textarea>
          </div>

          ${can_email ? `
            <div class="action-field">
              <div class="action-field-label">To</div>
              <input type="email" class="action-to-input" data-to-email
                     value="${esc(toEmail)}"
                     placeholder="recipient@example.com">
            </div>` : ''}
        </div>

        <div class="action-buttons">
          ${can_email && mailtoHref ? `
            <a class="button secondary" href="${esc(mailtoHref)}" data-mailto-link target="_blank" rel="noopener">
              <i class="fa-solid fa-envelope" aria-hidden="true"></i> Open in email app
            </a>` : ''}
          ${can_email ? `
            <button type="button" class="primary" data-send-server>
              <i class="fa-solid fa-paper-plane" aria-hidden="true"></i> Send from events@panicbooking.com
            </button>` : ''}
          ${submitLinks.map(({ label, url }) =>
            `<a class="button secondary" href="${esc(url)}" target="_blank" rel="noopener noreferrer">
              <i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i> ${esc(label)}
            </a>`
          ).join('')}
        </div>
        <p class="action-status-msg" data-action-status></p>
      `}
    `;

    // Copy to clipboard
    $('[data-copy-body]', this)?.addEventListener('click', async () => {
      try {
        await navigator.clipboard.writeText(body);
        publish('toast.show', { message: 'Copied to clipboard.', tone: 'success' });
      } catch {
        publish('toast.show', { message: 'Copy failed — select and copy manually.', tone: 'warning' });
      }
    });

    // Keep mailto link in sync with editable "To" field
    if (can_email) {
      const toInput    = $('[data-to-email]', this);
      const mailtoLink = $('[data-mailto-link]', this);

      if (toInput && mailtoLink) {
        toInput.addEventListener('input', () => {
          const addr = toInput.value.trim();
          if (addr && subject && body) {
            mailtoLink.href =
              `mailto:${encodeURIComponent(addr)}?subject=${encodeURIComponent(subject)}&body=${encodeURIComponent(body)}`;
          } else {
            mailtoLink.href = '#';
          }
        });
      }

      // Send via server
      $('[data-send-server]', this)?.addEventListener('click', async (ev) => {
        const btn      = ev.currentTarget;
        const toInput2 = $('[data-to-email]', this);
        const to       = (toInput2?.value || toEmail).trim();
        const statusEl = $('[data-action-status]', this);

        if (!to) {
          if (statusEl) { statusEl.textContent = 'Enter a recipient email address.'; statusEl.className = 'action-status-msg error-text'; }
          return;
        }

        const origHtml = btn.innerHTML;
        btn.disabled   = true;
        btn.innerHTML  = '<i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i> Sending…';

        try {
          const result = await api(
            `/promote/events/${this.eventId}/posts/${this.postId}/action/${this.destKey}/send`,
            { method: 'POST', body: JSON.stringify({ to }) }
          );
          publish('toast.show', { message: `Sent to ${result.to}`, tone: 'success' });
          btn.innerHTML = '<i class="fa-solid fa-circle-check" aria-hidden="true"></i> Sent!';
          if (statusEl) {
            statusEl.textContent = `Sent to ${result.to} from events@panicbooking.com`;
            statusEl.className   = 'action-status-msg promote-action-sent';
          }
        } catch (err) {
          publish('toast.show', { message: err.message || 'Send failed.', tone: 'error' });
          btn.disabled  = false;
          btn.innerHTML = origHtml;
          if (statusEl) {
            statusEl.textContent = err.message || 'Send failed.';
            statusEl.className   = 'action-status-msg error-text';
          }
        }
      });
    }
  }
}

customElements.define('pb-promote-action-modal', PromoteActionModal);

// ── pb-promote-assets-card ────────────────────────────────────────────────────
// Event assets + quick-upload button + 1:1/4:5/9:16/16:9 aspect placeholders.
// eventId and assets[] are set by the parent (PromoteCampaignOverview).

class PromoteAssetsCard extends PanicElement {
  connectedCallback() {
    super.connectedCallback();
    this.render();
  }

  render() {
    const assets = this.assets || [];

    // Show approved assets first, then pending/other — most-recently uploaded first
    // within each group so a fresh upload appears at the top immediately.
    const sorted = [
      ...assets.filter((a) => a.approval_status === 'approved'),
      ...assets.filter((a) => a.approval_status !== 'approved'),
    ];

    this.innerHTML = `<article class="panel promote-assets-card">
      <div class="section-head padded">
        <h3><i class="fa-solid fa-images" aria-hidden="true"></i> Assets</h3>
        <div class="section-head-actions">
          <button class="small secondary" data-upload-asset
            ${this.eventId ? '' : 'disabled title="Event ID not available"'}>
            <i class="fa-solid fa-arrow-up-from-bracket" aria-hidden="true"></i> Upload
          </button>
          ${assets.length ? `<span class="muted small">View all ${esc(String(assets.length))}</span>` : ''}
        </div>
      </div>
      <div class="promote-assets-grid padded">
        ${sorted.slice(0, 6).map((a) => this.assetTile(a)).join('')}
        ${assets.length === 0 ? '<p class="muted">No assets yet — upload a flyer to get started.</p>' : ''}
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

    // Wire the upload button → hidden file input (created fresh each render
    // so it's not trapped inside the replaced innerHTML).
    const uploadBtn = $('[data-upload-asset]', this);
    if (uploadBtn && this.eventId) {
      const fileInput = document.createElement('input');
      fileInput.type = 'file';
      fileInput.accept = 'image/png,image/jpeg,image/gif,image/webp,application/pdf';
      fileInput.style.display = 'none';
      fileInput.addEventListener('change', () => {
        const file = fileInput.files?.[0];
        if (file) this.handleUpload(file, uploadBtn);
        fileInput.value = ''; // reset so re-selecting the same file still fires change
      });
      uploadBtn.addEventListener('click', () => fileInput.click());
    }
  }

  assetTile(asset) {
    const tone  = asset.approval_status === 'approved' ? 'success'
      : asset.approval_status === 'rejected'           ? 'error'
      : 'warning';
    const label = asset.approval_status === 'approved' ? 'Approved'
      : asset.approval_status === 'rejected'           ? 'Rejected'
      : 'Pending';
    return `<div class="promote-asset-tile">
      ${assetThumb(asset, 'promote-asset-img')}
      <div class="promote-asset-meta">
        <span class="promote-asset-name">${esc(asset.title || asset.asset_type || 'Asset')}</span>
        <span class="badge ${esc(tone)}">${esc(label)}</span>
      </div>
    </div>`;
  }

  // ── Upload ──────────────────────────────────────────────────────────────────

  async handleUpload(file, btn) {
    const title = this.uniqueTitle(
      file.name.replace(/\.[^/.]+$/, '').trim() || 'Asset'
    );

    // Loading state
    const originalHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i>';

    try {
      // 1. Upload the file
      const fd = new FormData();
      fd.append('asset', file);
      fd.append('title', title);
      fd.append('asset_type', 'flyer');
      const result = await api(`/events/${this.eventId}/assets`, { method: 'POST', body: fd });

      // 2. Auto-approve (best-effort — silently skipped if user lacks manage_assets)
      let approvalStatus = 'needs_review';
      try {
        await api(`/events/${this.eventId}/assets/${result.id}`, {
          method: 'PATCH',
          body: JSON.stringify({ approval_status: 'approved' }),
        });
        approvalStatus = 'approved';
      } catch { /* stays needs_review */ }

      // 3. Prepend new asset to local list and re-render
      const newAsset = {
        id: result.id,
        title,
        filename: file.name,
        original_filename: file.name,
        file_path: result.file_path,
        asset_type: 'flyer',
        approval_status: approvalStatus,
        created_at: new Date().toISOString(),
      };
      this.assets = [newAsset, ...(this.assets || [])];

      publish('toast.show', {
        message: approvalStatus === 'approved'
          ? `"${title}" uploaded.`
          : `"${title}" uploaded — pending approval.`,
        tone: 'success',
      });

      this.render(); // btn is recreated by render — no need to restore innerHTML

    } catch (error) {
      publish('toast.show', { message: error.message || 'Upload failed.', tone: 'error' });
      btn.disabled = false;
      btn.innerHTML = originalHtml;
    }
  }

  // Return a title that doesn't already exist in this.assets.
  // "My Flyer" → "My Flyer 2" → "My Flyer 3" → …
  uniqueTitle(base) {
    const taken = new Set((this.assets || []).map((a) => (a.title || '').toLowerCase()));
    if (!taken.has(base.toLowerCase())) return base;
    let n = 2;
    while (taken.has(`${base} ${n}`.toLowerCase())) n++;
    return `${base} ${n}`;
  }
}

customElements.define('pb-promote-assets-card', PromoteAssetsCard);

// ── pb-promote-analytics-card ─────────────────────────────────────────────────
// Broadcast metrics from the DB + null-placeholder tiles for platform-specific
// data (Eventbrite ticket sales, email opens, Luma RSVPs).

const DEST_STATUS_ICONS = {
  sent:             '<i class="fa-solid fa-circle-check" style="color:var(--color-success)"></i>',
  queued:           '<i class="fa-solid fa-clock" style="color:var(--color-info)"></i>',
  manual_required:  '<i class="fa-solid fa-clipboard-list" style="color:var(--color-warning)"></i>',
  needs_auth:       '<i class="fa-solid fa-key" style="color:var(--color-warning)"></i>',
  failed:           '<i class="fa-solid fa-circle-xmark" style="color:var(--color-danger)"></i>',
  skipped:          '<i class="fa-solid fa-minus-circle" style="color:var(--color-muted)"></i>',
};

const DEST_STATUS_LABELS = {
  sent:            'Sent',
  queued:          'Queued',
  manual_required: 'Manual to-do',
  needs_auth:      'Needs setup',
  failed:          'Failed',
  skipped:         'Skipped',
};

class PromoteAnalyticsCard extends PanicElement {
  // analytics object set by parent component
  connectedCallback() {
    super.connectedCallback();
    this.render();
  }

  render() {
    const a = this.analytics || {};
    const fmt = (n) => n == null ? '—' : String(n).replace(/\B(?=(\d{3})+(?!\d))/g, ',');

    // Row 1 — broadcast metrics derived from our DB (always real)
    const broadcastTiles = [
      { label: 'Platforms Reached', icon: 'fa-share-nodes',        value: fmt(a.destinations_reached ?? 0), note: null },
      { label: 'Live Listings',     icon: 'fa-link',               value: fmt(a.listings_live       ?? 0), note: null },
      { label: 'Manual To-Do',      icon: 'fa-clipboard-list',     value: fmt(a.manual_pending      ?? 0), note: null },
      { label: 'Issues',            icon: 'fa-triangle-exclamation', value: fmt(a.failed_count       ?? 0), note: null },
    ];

    // Row 2 — platform-specific data (null = not yet connected)
    const platformTiles = [
      { label: 'Ticket Sales',  icon: 'fa-ticket',            value: fmt(a.ticket_sales),  note: 'via Eventbrite' },
      { label: 'RSVPs',         icon: 'fa-calendar-check',    value: fmt(a.luma_rsvps),    note: 'via Luma' },
      { label: 'Email Opens',   icon: 'fa-envelope-open-text', value: fmt(a.email_opens),  note: 'via Mailchimp/SG' },
      { label: 'Email Clicks',  icon: 'fa-arrow-pointer',     value: fmt(a.email_clicks),  note: 'via Mailchimp/SG' },
    ];

    const tileHtml = (tiles) => tiles.map((t) => `
      <div class="promote-analytics-tile${t.value === '—' ? ' promote-analytics-tile--na' : ''}">
        <div class="promote-analytics-tile-head">
          <i class="fa-solid ${esc(t.icon)}" aria-hidden="true"></i>
          <span class="promote-analytics-label">${esc(t.label)}</span>
        </div>
        <strong class="promote-analytics-value">${esc(t.value)}</strong>
        ${t.note ? `<span class="promote-analytics-note">${esc(t.note)}</span>` : ''}
      </div>`).join('');

    const destResults = a.destination_results || [];
    const destHtml = destResults.length === 0
      ? '<p class="muted padded" style="font-size:0.85rem">No broadcasts sent yet.</p>'
      : this.renderDestTable(destResults);

    this.innerHTML = `<article class="panel promote-analytics-card">
      <div class="section-head padded">
        <h3><i class="fa-solid fa-chart-line" aria-hidden="true"></i> Analytics</h3>
        ${a.broadcast_count ? `<span class="badge info">${esc(String(a.broadcast_count))} broadcast${a.broadcast_count === 1 ? '' : 's'}</span>` : '<span class="badge muted">No broadcasts yet</span>'}
      </div>

      <div class="promote-analytics-section-label padded-x">Broadcast reach</div>
      <div class="promote-analytics-tiles padded">
        ${tileHtml(broadcastTiles)}
      </div>

      <div class="promote-analytics-section-label padded-x">Platform metrics
        <span class="muted" style="font-size:0.8rem;font-weight:400"> — connect platforms to unlock</span>
      </div>
      <div class="promote-analytics-tiles padded">
        ${tileHtml(platformTiles)}
      </div>

      <div class="promote-analytics-section-label padded-x">Destination status</div>
      ${destHtml}
    </article>`;
  }

  renderDestTable(results) {
    // Group by destination_group
    const groups = {};
    for (const r of results) {
      const g = r.destination_group || 'other';
      if (!groups[g]) groups[g] = [];
      groups[g].push(r);
    }

    const groupOrder = ['direct_post', 'event_platform', 'editorial_submission', 'email'];
    const sortedGroups = [
      ...groupOrder.filter((g) => groups[g]),
      ...Object.keys(groups).filter((g) => !groupOrder.includes(g)),
    ];

    return `<div class="promote-dest-results padded">
      ${sortedGroups.map((g) => `
        <div class="promote-dest-group-label">${esc(DEST_GROUP_LABELS[g] || g)}</div>
        ${groups[g].map((r) => `
          <div class="promote-dest-result-row">
            <span class="promote-dest-result-icon">${DEST_STATUS_ICONS[r.status] || ''}</span>
            <span class="promote-dest-result-key">${esc(r.destination_key.replace(/_/g, ' '))}</span>
            <span class="promote-dest-result-status muted">${esc(DEST_STATUS_LABELS[r.status] || r.status)}</span>
            ${r.external_url
              ? `<a class="promote-dest-result-link" href="${esc(r.external_url)}" target="_blank" rel="noopener">
                   <i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i> View
                 </a>`
              : ''}
          </div>`).join('')}
      `).join('')}
    </div>`;
  }
}

customElements.define('pb-promote-analytics-card', PromoteAnalyticsCard);

// ── pb-promote-settings ───────────────────────────────────────────────────────
// Per-venue platform credential manager. Shows each connectable destination as
// a card with status badge, platform-specific fields, and Save/Disconnect actions.

// Field definitions for each connectable destination.
// Each entry: { key, label, type ('password'|'text'), hint }
const PLATFORM_FIELDS = {
  twitter: {
    label: 'Twitter / X',
    icon: 'fa-brands fa-x-twitter',
    group: 'Direct Posts',
    docs: 'https://developer.twitter.com/en/docs/twitter-api/tweets/manage-tweets/api-reference/post-tweets',
    fields: [
      {
        key: 'access_token',
        label: 'OAuth 2.0 User Access Token',
        type: 'password',
        hint: 'From X Developer Portal → Your App → Keys & Tokens. Requires tweet.write + offline.access scopes via the OAuth 2.0 PKCE flow.',
      },
      {
        key: 'refresh_token',
        label: 'Refresh Token (recommended)',
        type: 'password',
        hint: 'Returned alongside the access token when offline.access scope is included. Used to renew expired tokens without re-authenticating.',
      },
    ],
  },
  threads: {
    label: 'Threads',
    icon: 'fa-brands fa-threads',
    group: 'Direct Posts',
    docs: 'https://developers.facebook.com/docs/threads',
    fields: [
      {
        key: 'access_token',
        label: 'Threads Access Token',
        type: 'password',
        hint: 'Long-lived token from the Meta Developer Portal (threads_content_publish scope). Exchange a short-lived token at graph.threads.net/access_token.',
      },
      {
        key: 'config.threads_user_id',
        label: 'Threads User ID',
        type: 'text',
        hint: 'Numeric Threads user ID — fetch via: GET https://graph.threads.net/v1.0/me?fields=id&access_token={token}',
      },
    ],
  },
  bluesky: {
    label: 'Bluesky',
    icon: 'fa-brands fa-bluesky',
    group: 'Direct Posts',
    docs: 'https://docs.bsky.app/docs/tutorials/creating-a-post',
    fields: [
      {
        key: 'config.identifier',
        label: 'Bluesky Handle',
        type: 'text',
        hint: 'Your full Bluesky handle, e.g. mabuhaygardens.bsky.social (or a custom domain if configured)',
      },
      {
        key: 'access_token',
        label: 'App Password',
        type: 'password',
        hint: 'From bsky.app → Settings → Privacy and Security → App Passwords. Use an App Password — NOT your main account password.',
      },
    ],
  },
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
  email_adhoc: {
    label: 'Ad-hoc Email Recipients',
    icon: 'fa-solid fa-at',
    group: 'Email',
    docs: null,
    fields: [
      { key: 'config.default_bcc', label: 'Default BCC (optional)', type: 'text', hint: 'Comma-separated addresses to always BCC (e.g. archive inbox) — leave blank if not needed' },
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
  dice: {
    label: 'Dice.fm',
    icon: 'fa-solid fa-dice',
    group: 'Event Platforms',
    docs: 'https://dice.fm/partners',
    fields: [
      {
        key: 'config.partner_url',
        label: 'Dice Partner Dashboard URL',
        type: 'text',
        hint: 'Your Dice.fm partner/promoter dashboard URL — dice.fm/partners. Submissions are manual via the dashboard.',
      },
    ],
  },
  resident_advisor: {
    label: 'Resident Advisor',
    icon: 'fa-solid fa-headphones',
    group: 'Event Platforms',
    docs: 'https://ra.co/promoters',
    fields: [
      {
        key: 'config.promoter_url',
        label: 'RA Promoter Account URL',
        type: 'text',
        hint: 'Your Resident Advisor promoter login/dashboard URL — ra.co/promoters. Submissions are manual.',
      },
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
