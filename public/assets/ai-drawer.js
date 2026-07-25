import { api, esc, mdToHtml, PanicElement, $ } from './core.js';

/**
 * <pb-ai-drawer> — the AI Assistant drawer.
 *
 * A persistent right-side slide-over PANEL, not a modal: openModal()
 * deliberately isn't used here, and unlike the mobile nav drawer
 * (app.js's setupMobileDrawer()) there is no backdrop/scrim element — the
 * rest of the app stays fully visible, scrollable, and clickable while
 * this is open. Only an explicit close button and Escape dismiss it; there
 * is no click-outside-to-dismiss since there's nothing to click outside of.
 * See the `.ai-drawer-*` rules in app.css for the slide mechanics (borrowed
 * from the mobile drawer's translateX/transition approach, not its markup).
 *
 * Mounted once in the app shell (app.js's renderShell()), gated on
 * `capabilities.use_ai_assistant`. Two entry points open the same shared
 * instance:
 *   - the topbar trigger (general — no event scope)
 *   - an event workspace's "Ask AI" button (see event-workspace.js), which
 *     calls open({ eventId }) so "what's the status of this event" works
 *     without the user having to name it.
 *
 * Phase 2: when POST /ai/ask's response includes a `proposal` (the model
 * called propose_booker_update or propose_recurring_series), it's rendered
 * as a distinct transcript entry — a diff card with Apply/Discard buttons.
 * Apply uses a native confirm() before POSTing to
 * /ai/proposals/{id}/apply, matching the destructive-action confirm
 * pattern used elsewhere in the app (e.g. event-workspace.js's payment
 * void). The model itself never has an "apply" tool — see
 * docs/AI-ASSISTANT-PLAN.md.
 */
class AiDrawer extends PanicElement {
  connect() {
    this.isOpen = false;
    this.eventId = null;
    this.conversationId = null;
    this.messages = []; // [{ role: 'user'|'assistant'|'error', content }]
    this.sending = false;
    this.render();

    window.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && this.isOpen) this.close();
    }, { signal: this.abort.signal });
  }

  /**
   * Open the drawer, optionally (re-)scoping it to an event. Passing a
   * different eventId than the drawer is currently scoped to starts a
   * fresh conversation — the transcript so far belonged to the old scope.
   * Called from app.js (topbar, eventId: null) and event-workspace.js
   * (per-event "Ask AI" button).
   */
  open({ eventId } = {}) {
    if (eventId !== undefined && eventId !== this.eventId) {
      this.eventId = eventId ?? null;
      this.conversationId = null;
      this.messages = [];
      this.renderTranscript();
    }
    this.isOpen = true;
    this.classList.add('ai-drawer-open');
    this.setAttribute('aria-hidden', 'false');
    window.setTimeout(() => $('[data-ai-input]', this)?.focus(), 60);
  }

  close() {
    this.isOpen = false;
    this.classList.remove('ai-drawer-open');
    this.setAttribute('aria-hidden', 'true');
  }

  render() {
    this.setAttribute('aria-hidden', 'true');
    this.innerHTML = `<div class="ai-drawer-panel" role="dialog" aria-label="AI Assistant" aria-modal="false">
      <div class="ai-drawer-head">
        <strong><i class="fa-solid fa-sparkles" aria-hidden="true"></i> AI Assistant</strong>
        <button type="button" class="ai-drawer-close" data-ai-close aria-label="Close AI Assistant"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
      </div>
      <div class="ai-drawer-transcript" data-ai-transcript aria-live="polite"></div>
      <form class="ai-drawer-form" data-ai-form>
        <textarea class="ai-drawer-input" data-ai-input rows="1" placeholder="Ask about this event, or events in general…" aria-label="Message the AI Assistant"></textarea>
        <button type="submit" class="ai-drawer-send" data-ai-send>Send</button>
      </form>
    </div>`;

    $('[data-ai-close]', this).addEventListener('click', () => this.close());

    const textarea = $('[data-ai-input]', this);
    $('[data-ai-form]', this).addEventListener('submit', (event) => {
      event.preventDefault();
      this.send(textarea.value);
    });
    textarea.addEventListener('keydown', (event) => {
      if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        this.send(textarea.value);
      }
    });
    // Grow the input up to the CSS max-height as the user types a longer question.
    textarea.addEventListener('input', () => {
      textarea.style.height = 'auto';
      textarea.style.height = `${textarea.scrollHeight}px`;
    });

    // Delegated click handler for proposal-card Apply/Discard buttons —
    // renderTranscript() rebuilds the transcript's innerHTML on every
    // message, so per-button listeners would be lost each time; one
    // listener on the (stable) transcript container survives that.
    $('[data-ai-transcript]', this).addEventListener('click', (event) => {
      const applyBtn = event.target.closest('[data-proposal-apply]');
      const discardBtn = event.target.closest('[data-proposal-discard]');
      if (applyBtn) this.applyProposal(Number(applyBtn.dataset.proposalApply));
      else if (discardBtn) this.discardProposal(Number(discardBtn.dataset.proposalDiscard));
    });

    this.renderTranscript();
  }

  renderTranscript() {
    const el = $('[data-ai-transcript]', this);
    if (!el) return;
    if (!this.messages.length) {
      el.innerHTML = `<p class="ai-drawer-empty">Ask a question about ${this.eventId ? 'this event' : 'an event, or events in general'} — I can look things up, and propose changes (like updating booker info or setting up a recurring series) for you to review before anything happens.</p>`;
      return;
    }
    el.innerHTML = this.messages.map((message) => {
      if (message.role === 'pending') {
        return `<div class="ai-msg ai-msg-pending">Thinking…</div>`;
      }
      if (message.role === 'error') {
        return `<div class="ai-msg ai-msg-error">${esc(message.content)}</div>`;
      }
      if (message.role === 'proposal') {
        return this.renderProposalCard(message);
      }
      // Assistant replies render as the app's shared Markdown subset (safe
      // by construction — see core.js's mdToHtml); the user's own message
      // is rendered as plain escaped text.
      const body = message.role === 'assistant' ? mdToHtml(message.content) : esc(message.content);
      return `<div class="ai-msg ai-msg-${esc(message.role)}">${body}</div>`;
    }).join('');
    el.scrollTop = el.scrollHeight;
  }

  /**
   * A proposal never applies itself — see the class docblock. `status`
   * drives which UI shows: 'pending' (Apply/Discard buttons), 'applying'/
   * 'discarding' (in flight, buttons hidden), or a terminal 'applied'/
   * 'discarded' (buttons replaced with a plain status line).
   */
  renderProposalCard(message) {
    const isTerminal = message.status === 'applied' || message.status === 'discarded';
    const isBusy = message.status === 'applying' || message.status === 'discarding';
    const body = message.toolName === 'propose_recurring_series'
      ? this.renderSeriesDiff(message.diff)
      : this.renderBookerDiff(message.diff);

    const statusLabel = { applied: 'Applied ✓', discarded: 'Discarded', applying: 'Applying…', discarding: 'Discarding…' }[message.status] || '';
    const actions = isTerminal || isBusy
      ? `<div class="ai-proposal-status ai-proposal-status-${esc(message.status)}">${esc(statusLabel)}</div>`
      : `<div class="ai-proposal-actions">
           <button type="button" class="ai-proposal-apply" data-proposal-apply="${message.proposalId}">Apply</button>
           <button type="button" class="ai-proposal-discard" data-proposal-discard="${message.proposalId}">Discard</button>
         </div>`;
    const error = message.error ? `<p class="ai-proposal-error">${esc(message.error)}</p>` : '';

    return `<div class="ai-msg ai-msg-proposal">
      <div class="ai-proposal-head"><i class="fa-solid fa-file-pen" aria-hidden="true"></i> Proposed change — nothing has happened yet</div>
      ${body}
      ${error}
      ${actions}
    </div>`;
  }

  renderBookerDiff(diff) {
    if (!Array.isArray(diff) || !diff.length) {
      return `<p class="ai-proposal-empty">No matching events.</p>`;
    }
    const rows = diff.map((row) => {
      const fields = Object.entries(row.fields || {}).map(([key, change]) =>
        `<li><span class="ai-diff-field">${esc(key)}</span>: <span class="ai-diff-before">${esc(change.before || '—')}</span> → <span class="ai-diff-after">${esc(change.after || '—')}</span></li>`
      ).join('');
      return `<li class="ai-proposal-event"><strong>${esc(row.title || `Event #${row.event_id}`)}</strong><ul class="ai-diff-fields">${fields}</ul></li>`;
    }).join('');
    return `<ul class="ai-proposal-list">${rows}</ul>`;
  }

  renderSeriesDiff(diff) {
    const dates = Array.isArray(diff?.dates) ? diff.dates : [];
    if (!dates.length) {
      return `<p class="ai-proposal-empty">No occurrence dates.</p>`;
    }
    const summary = `${esc(diff.anchor_title || 'This event')} → ${dates.length} new occurrence${dates.length === 1 ? '' : 's'}`;
    return `<p class="ai-proposal-summary">${summary}</p>
      <ul class="ai-proposal-dates">${dates.map((date) => `<li>${esc(date)}</li>`).join('')}</ul>`;
  }

  async send(rawMessage) {
    const message = (rawMessage || '').trim();
    if (!message || this.sending) return;

    const textarea = $('[data-ai-input]', this);
    textarea.value = '';
    textarea.style.height = 'auto';

    this.messages = [...this.messages, { role: 'user', content: message }, { role: 'pending', content: '' }];
    this.renderTranscript();

    this.sending = true;
    this.setSendingUi(true);

    try {
      const body = { message };
      if (this.conversationId) body.conversation_id = this.conversationId;
      if (this.eventId) body.event_id = this.eventId;
      const data = await api('/ai/ask', { method: 'POST', body: JSON.stringify(body) });
      this.conversationId = data.conversation_id;
      const next = [...this.messages.filter((m) => m.role !== 'pending'), { role: 'assistant', content: data.reply }];
      if (data.proposal) {
        next.push({
          role: 'proposal',
          proposalId: data.proposal.id,
          toolName: data.proposal.tool_name,
          diff: data.proposal.diff,
          expiresAt: data.proposal.expires_at,
          status: 'pending',
        });
      }
      this.messages = next;
    } catch (error) {
      this.messages = [...this.messages.filter((m) => m.role !== 'pending'), { role: 'error', content: error.message || 'Something went wrong asking the AI Assistant.' }];
    } finally {
      this.sending = false;
      this.setSendingUi(false);
      this.renderTranscript();
    }
  }

  /** Human-clicked "Apply" on a proposal card — see the class docblock. */
  async applyProposal(proposalId) {
    const message = this.messages.find((m) => m.role === 'proposal' && m.proposalId === proposalId);
    if (!message || message.status !== 'pending') return;
    if (!confirm('Apply this change now? This will update real event data.')) return;

    message.status = 'applying';
    message.error = null;
    this.renderTranscript();

    try {
      await api(`/ai/proposals/${proposalId}/apply`, { method: 'POST' });
      message.status = 'applied';
    } catch (error) {
      message.status = 'pending';
      message.error = error.message || 'Could not apply this proposal.';
    } finally {
      this.renderTranscript();
    }
  }

  /** Human-clicked "Discard" on a proposal card. */
  async discardProposal(proposalId) {
    const message = this.messages.find((m) => m.role === 'proposal' && m.proposalId === proposalId);
    if (!message || message.status !== 'pending') return;

    message.status = 'discarding';
    message.error = null;
    this.renderTranscript();

    try {
      await api(`/ai/proposals/${proposalId}`, { method: 'DELETE' });
      message.status = 'discarded';
    } catch (error) {
      message.status = 'pending';
      message.error = error.message || 'Could not discard this proposal.';
    } finally {
      this.renderTranscript();
    }
  }

  setSendingUi(sending) {
    const btn = $('[data-ai-send]', this);
    if (btn) {
      btn.disabled = sending;
      btn.textContent = sending ? 'Thinking…' : 'Send';
    }
    const textarea = $('[data-ai-input]', this);
    if (textarea) textarea.disabled = sending;
  }
}
customElements.define('pb-ai-drawer', AiDrawer);
