// <pb-process-inspector> — the right-hand panel. Shows only the field
// groups relevant to the selected node's type (node-registry.js's
// `inspectorGroups`), per the brief's "expose relevant configuration
// without displaying every possible setting at once."
//
// Every field here writes back through `store.updateNode()` /
// `store.updateNodeConfig()` — the inspector never holds its own copy of
// node state, so there's nothing to get out of sync.
//
// The "Connections" section is the accessible, no-drag way to wire two
// nodes together (pick a target from a <select>, click Connect) — the
// keyboard/screen-reader alternative to canvas connect-by-drag the brief
// asks for.
import { $, $$, esc, formData } from '../core.js';
import { getNodeType, nodePorts } from './node-registry.js';

export class ProcessInspectorElement extends HTMLElement {
  // Property setters (store/assignableUsers/readOnly, below) can run before
  // connectedCallback — the designer builds this element and assigns its
  // properties before inserting it into the DOM (see process-designer.js
  // buildShell()). Defaults have to exist from construction so a
  // pre-connection setter has something safe to write into, and
  // connectedCallback must not stomp a value one of those setters already
  // populated (this previously reset `_assignableUsers` back to `[]` right
  // after buildShell had just set it — the Assignee dropdown was always
  // empty as a result).
  _assignableUsers = [];

  connectedCallback() {
    this.abort ||= new AbortController();
    this.render();
  }

  disconnectedCallback() { this.abort?.abort(); }

  set store(store) {
    this._store?.removeEventListener?.('selection', this._onSel);
    this._store?.removeEventListener?.('change', this._onSel);
    this._store = store;
    this._onSel = () => this.render();
    store.addEventListener('selection', this._onSel, { signal: this.abort?.signal });
    store.addEventListener('change', this._onSel, { signal: this.abort?.signal });
    this.render();
  }

  set assignableUsers(list) { this._assignableUsers = list || []; this.render(); }
  set readOnly(value) { this._readOnly = !!value; this.render(); }

  render() {
    const store = this._store;
    if (!store) { this.innerHTML = ''; return; }
    const ids = store.selectedIds();

    if (ids.length === 0) {
      this.innerHTML = `<div class="proc-inspector-empty padded muted">
        <i class="fa-solid fa-arrow-pointer" aria-hidden="true"></i>
        <p>Select a node to configure it.</p>
      </div>`;
      return;
    }
    if (ids.length > 1) {
      this.innerHTML = `<div class="section-head padded"><h2>${ids.length} nodes selected</h2></div>
        <div class="padded inline-actions">
          <button type="button" class="small secondary" data-bulk="duplicate"><i class="fa-solid fa-copy" aria-hidden="true"></i> Duplicate</button>
          <button type="button" class="small danger" data-bulk="delete"><i class="fa-solid fa-trash" aria-hidden="true"></i> Delete</button>
        </div>`;
      if (!this._readOnly) {
        $('[data-bulk="duplicate"]', this)?.addEventListener('click', () => store.duplicateNodes(ids));
        $('[data-bulk="delete"]', this)?.addEventListener('click', () => store.removeNodes(ids));
      }
      return;
    }

    const node = store.graph.nodes.find((n) => n.id === ids[0]);
    if (!node) { this.innerHTML = ''; return; }
    const def = getNodeType(node.type) || {};
    const groups = def.inspectorGroups || ['common'];
    const disabledAttr = this._readOnly ? ' disabled' : '';

    this.innerHTML = `
      <div class="section-head padded">
        <h2><i class="${esc(def.icon || 'fa-solid fa-circle')}" aria-hidden="true"></i> ${esc(def.label || node.type)}</h2>
        <button type="button" class="small secondary" data-close aria-label="Deselect"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
      </div>
      <div class="proc-inspector-body">
        <details class="proc-inspector-group" open>
          <summary>Configuration</summary>
          <form class="grid-form padded" data-form="common">
            <label class="wide">Name<input type="text" name="name" value="${esc(node.name)}"${disabledAttr}></label>
            <label class="wide">Description / notes<textarea name="description"${disabledAttr}>${esc(node.description)}</textarea></label>
          </form>
        </details>
        ${groups.includes('human') ? this.renderHumanGroup(node, disabledAttr) : ''}
        ${groups.includes('decision') ? this.renderBranchGroup(node, def, disabledAttr) : ''}
        ${groups.includes('action') ? this.renderActionGroup(node, disabledAttr) : ''}
        ${groups.includes('wait') ? this.renderWaitGroup(node, disabledAttr) : ''}
        ${groups.includes('ai') ? this.renderAiGroup(node, disabledAttr) : ''}
        ${this.renderConnectionsGroup(node)}
        ${this.renderAdvancedGroup(node, disabledAttr)}
      </div>`;

    this.bind(node, disabledAttr === '');
  }

  renderHumanGroup(node, disabledAttr) {
    const cfg = node.config || {};
    const userOptions = `<option value="">— No specific person —</option>` + this._assignableUsers.map((u) => `<option value="${u.id}" ${String(cfg.assigneeUserId || '') === String(u.id) ? 'selected' : ''}>${esc(u.name)}</option>`).join('');
    return `<details class="proc-inspector-group" open>
      <summary>Human Task</summary>
      <form class="grid-form padded" data-form="human">
        <label>Assignee<select name="assigneeUserId"${disabledAttr}>${userOptions}</select></label>
        <label>Role/team<input type="text" name="assigneeRole" value="${esc(cfg.assigneeRole || '')}" placeholder="e.g. Venue Manager"${disabledAttr}></label>
        <label>Due<input type="text" name="dueRule" value="${esc(cfg.dueRule || '')}" placeholder="24 hours"${disabledAttr}></label>
        <label>Escalation<input type="text" name="escalationRule" value="${esc(cfg.escalationRule || '')}" placeholder="After 12 hours, notify Owner"${disabledAttr}></label>
        <label class="wide">Instructions<textarea name="instructions"${disabledAttr}>${esc(cfg.instructions || '')}</textarea></label>
        <label class="wide">Required form<input type="text" name="requiredForm" value="${esc(cfg.requiredForm || '')}" placeholder="Optional form key"${disabledAttr}></label>
        <label class="wide toggle-row"><input type="checkbox" name="notifyByEmail" ${cfg.notifyByEmail !== false ? 'checked' : ''}${disabledAttr}> Notify assignee by email</label>
      </form>
    </details>`;
  }

  renderBranchGroup(node, def, disabledAttr) {
    const branches = node.config?.branches || [];
    const isApproval = false;
    const rows = branches.map((b, i) => `
      <div class="proc-branch-row" data-branch-index="${i}">
        <input type="text" data-branch-field="label" value="${esc(b.label || '')}" placeholder="Branch label"${disabledAttr}>
        <input type="text" data-branch-field="condition" value="${esc(b.condition || '')}" placeholder="Plain-language condition (e.g. date is available)"${disabledAttr}>
        <label class="toggle-row small"><input type="checkbox" data-branch-field="isDefault" ${b.isDefault ? 'checked' : ''}${disabledAttr}> Default</label>
        <button type="button" class="small danger" data-remove-branch="${i}"${disabledAttr} aria-label="Remove branch"><i class="fa-solid fa-trash" aria-hidden="true"></i></button>
      </div>`).join('');
    return `<details class="proc-inspector-group" open>
      <summary>Decision Branches</summary>
      <div class="padded">
        <div class="proc-branch-list" data-branch-list>${rows}</div>
        ${!disabledAttr ? `<button type="button" class="small secondary" data-add-branch><i class="fa-solid fa-plus" aria-hidden="true"></i> Add branch</button>` : ''}
        <p class="muted small">Exactly one branch must be marked Default so the process always has somewhere to go.</p>
      </div>
    </details>`;
  }

  renderActionGroup(node, disabledAttr) {
    const cfg = node.config || {};
    return `<details class="proc-inspector-group">
      <summary>Action</summary>
      <form class="grid-form padded" data-form="action">
        <label class="wide">CenterStage operation / connector<input type="text" name="operation" value="${esc(cfg.operation || '')}" placeholder="e.g. events.update_status"${disabledAttr}></label>
        <label class="wide">Field mappings (JSON)<textarea name="fieldMappings" placeholder='{ "status": "booked" }'${disabledAttr}>${esc(cfg.fieldMappings || '')}</textarea></label>
        <label>Idempotency key<input type="text" name="idempotencyKey" value="${esc(cfg.idempotencyKey || '')}" placeholder="instance.id + node.id"${disabledAttr}></label>
        <label>On error<select name="errorHandling"${disabledAttr}>
          <option value="fail" ${cfg.errorHandling === 'fail' || !cfg.errorHandling ? 'selected' : ''}>Fail the instance</option>
          <option value="retry" ${cfg.errorHandling === 'retry' ? 'selected' : ''}>Retry with backoff</option>
          <option value="continue" ${cfg.errorHandling === 'continue' ? 'selected' : ''}>Continue anyway</option>
        </select></label>
        <label>Max retries<input type="number" min="0" name="maxRetries" value="${esc(cfg.maxRetries ?? 3)}"${disabledAttr}></label>
        <label>Backoff (seconds)<input type="number" min="0" name="backoffSeconds" value="${esc(cfg.backoffSeconds ?? 30)}"${disabledAttr}></label>
      </form>
    </details>`;
  }

  renderWaitGroup(node, disabledAttr) {
    const cfg = node.config || {};
    return `<details class="proc-inspector-group" open>
      <summary>Wait</summary>
      <form class="grid-form padded" data-form="wait">
        <label class="wide">Awaited event<input type="text" name="awaitedEvent" value="${esc(cfg.awaitedEvent || '')}" placeholder="e.g. contract.signed"${disabledAttr}></label>
        <label>Correlation key<input type="text" name="correlationKey" value="${esc(cfg.correlationKey || '')}" placeholder="e.g. contract_id"${disabledAttr}></label>
        <label>Duration / timeout<input type="text" name="duration" value="${esc(cfg.duration || '')}" placeholder="e.g. 7 days"${disabledAttr}></label>
        <label class="wide">Reminder rule<input type="text" name="reminderRule" value="${esc(cfg.reminderRule || '')}" placeholder="e.g. remind after 3 days"${disabledAttr}></label>
      </form>
    </details>`;
  }

  renderAiGroup(node, disabledAttr) {
    const cfg = node.config || {};
    return `<details class="proc-inspector-group">
      <summary>AI Settings</summary>
      <form class="grid-form padded" data-form="ai">
        <label class="wide">Prompt / instructions<textarea name="prompt"${disabledAttr}>${esc(cfg.prompt || '')}</textarea></label>
        <label>Confidence threshold (%)<input type="number" min="0" max="100" name="confidenceThreshold" value="${esc(cfg.confidenceThreshold ?? 70)}"${disabledAttr}></label>
        <label class="toggle-row"><input type="checkbox" name="requireHumanFallback" ${cfg.requireHumanFallback !== false ? 'checked' : ''}${disabledAttr}> Require human fallback below threshold</label>
      </form>
    </details>`;
  }

  renderConnectionsGroup(node) {
    const store = this._store;
    const outgoing = store.graph.edges.filter((e) => e.source.nodeId === node.id);
    const ports = nodePorts(node).outputs || [];
    const otherNodes = store.graph.nodes.filter((n) => n.id !== node.id);
    const rows = outgoing.map((e) => {
      const target = store.graph.nodes.find((n) => n.id === e.target.nodeId);
      const portLabel = ports.find((p) => p.id === e.source.port)?.label || '';
      return `<div class="proc-connection-row">
        <span>${portLabel ? `<strong>${esc(portLabel)}</strong> → ` : '→ '}${esc(target?.name || target?.type || '(missing)')}</span>
        <button type="button" class="small danger" data-remove-edge="${esc(e.id)}" aria-label="Remove connection"><i class="fa-solid fa-trash" aria-hidden="true"></i></button>
      </div>`;
    }).join('') || '<p class="muted small">No outgoing connections yet.</p>';

    const portOptions = ports.length > 1 ? `<select data-connect-port>${ports.map((p) => `<option value="${esc(p.id)}">${esc(p.label || p.id)}</option>`).join('')}</select>` : '';
    const nodeOptions = `<option value="">Connect to…</option>` + otherNodes.map((n) => `<option value="${esc(n.id)}">${esc(n.name || n.type)}</option>`).join('');

    return `<details class="proc-inspector-group" open>
      <summary>Connections</summary>
      <div class="padded">
        <div class="proc-connection-list">${rows}</div>
        ${!this._readOnly ? `<div class="inline-actions" data-connect-row>
          <select data-connect-target>${nodeOptions}</select>
          ${portOptions}
          <button type="button" class="small secondary" data-do-connect>Connect</button>
        </div>` : ''}
      </div>
    </details>`;
  }

  renderAdvancedGroup(node, disabledAttr) {
    const cfg = node.config || {};
    const rt = node.runtimePolicy || {};
    return `<details class="proc-inspector-group">
      <summary>Advanced</summary>
      <form class="grid-form padded" data-form="advanced">
        <label class="wide">Preconditions<textarea name="preconditions" placeholder="Free-text guard checked before this node runs">${esc(cfg.preconditions || '')}</textarea></label>
        <label>Timeout (seconds)<input type="number" min="0" name="timeoutSeconds" value="${esc(rt.timeoutSeconds ?? '')}"${disabledAttr}></label>
        <label>Required permission<input type="text" name="requiredCapability" value="${esc(cfg.requiredCapability || '')}" placeholder="e.g. manage_processes"${disabledAttr}></label>
      </form>
    </details>`;
  }

  bind(node, editable) {
    $('[data-close]', this)?.addEventListener('click', () => this._store.clearSelection());
    if (!editable) return;

    $$('form[data-form]', this).forEach((form) => {
      form.addEventListener('input', () => this.applyForm(node, form));
      form.addEventListener('change', () => this.applyForm(node, form));
    });

    $$('[data-remove-edge]', this).forEach((btn) => btn.addEventListener('click', () => {
      this._store.removeEdges([btn.dataset.removeEdge]);
    }));
    $('[data-do-connect]', this)?.addEventListener('click', () => {
      const target = $('[data-connect-target]', this)?.value;
      if (!target) return;
      const port = $('[data-connect-port]', this)?.value || 'out';
      this._store.addEdge({ nodeId: node.id, port }, { nodeId: target, port: 'in' });
    });

    $('[data-add-branch]', this)?.addEventListener('click', () => {
      const branches = [...(node.config?.branches || []), { id: `b${Date.now().toString(36)}`, label: 'New branch' }];
      this._store.updateNodeConfig(node.id, { branches });
    });
    $$('[data-remove-branch]', this).forEach((btn) => btn.addEventListener('click', () => {
      const idx = Number(btn.dataset.removeBranch);
      const branches = (node.config?.branches || []).filter((_, i) => i !== idx);
      this._store.updateNodeConfig(node.id, { branches });
    }));
    $$('.proc-branch-row', this).forEach((row) => {
      row.addEventListener('input', () => this.applyBranchRow(node, row));
      row.addEventListener('change', () => this.applyBranchRow(node, row));
    });
  }

  applyForm(node, form) {
    const data = formData(form);
    if (form.dataset.form === 'common') {
      this._store.updateNode(node.id, { name: data.name || '', description: data.description || '' });
      return;
    }
    if (form.dataset.form === 'advanced') {
      this._store.updateNodeConfig(node.id, { preconditions: data.preconditions || '', requiredCapability: data.requiredCapability || '' });
      this._store.updateNode(node.id, { runtimePolicy: { ...node.runtimePolicy, timeoutSeconds: data.timeoutSeconds ? Number(data.timeoutSeconds) : undefined } });
      return;
    }
    if (form.dataset.form === 'human') {
      this._store.updateNodeConfig(node.id, {
        assigneeUserId: data.assigneeUserId || null,
        assigneeRole: data.assigneeRole || '',
        dueRule: data.dueRule || '',
        escalationRule: data.escalationRule || '',
        instructions: data.instructions || '',
        requiredForm: data.requiredForm || '',
        notifyByEmail: !!$('[name="notifyByEmail"]', form)?.checked,
      });
      return;
    }
    if (form.dataset.form === 'action') {
      this._store.updateNodeConfig(node.id, {
        operation: data.operation || '',
        fieldMappings: data.fieldMappings || '',
        idempotencyKey: data.idempotencyKey || '',
        errorHandling: data.errorHandling || 'fail',
        maxRetries: Number(data.maxRetries) || 0,
        backoffSeconds: Number(data.backoffSeconds) || 0,
      });
      return;
    }
    if (form.dataset.form === 'wait') {
      this._store.updateNodeConfig(node.id, {
        awaitedEvent: data.awaitedEvent || '',
        correlationKey: data.correlationKey || '',
        duration: data.duration || '',
        reminderRule: data.reminderRule || '',
      });
      return;
    }
    if (form.dataset.form === 'ai') {
      this._store.updateNodeConfig(node.id, {
        prompt: data.prompt || '',
        confidenceThreshold: Number(data.confidenceThreshold) || 0,
        requireHumanFallback: !!$('[name="requireHumanFallback"]', form)?.checked,
      });
    }
  }

  applyBranchRow(node, row) {
    const idx = Number(row.dataset.branchIndex);
    const branches = structuredClone(node.config?.branches || []);
    if (!branches[idx]) return;
    branches[idx].label = $('[data-branch-field="label"]', row)?.value || '';
    branches[idx].condition = $('[data-branch-field="condition"]', row)?.value || '';
    const isDefault = $('[data-branch-field="isDefault"]', row)?.checked;
    if (isDefault) branches.forEach((b, i) => { b.isDefault = i === idx; });
    else branches[idx].isDefault = false;
    this._store.updateNodeConfig(node.id, { branches });
  }
}
customElements.define('pb-process-inspector', ProcessInspectorElement);
