// Node type registry for the process-graph designer.
//
// This is the one place that knows what node types exist, how they look
// (icon/tone), what a fresh instance of one defaults to, and which
// inspector field groups apply to it. Everything else (canvas, palette,
// inspector, validator) reads from here instead of hardcoding a node-type
// switch statement — per the spec, this is what lets new CenterStage
// operations get added "without modifying the graph editor."
//
// `tone` drives the visual language (see app.css .proc-tone-*): it is
// deliberately separate from `category` because e.g. flow.decision and
// flow.end are both "flow control" but need different colors (amber
// diamond vs. green terminal).
//
// A type's `getPorts(node)` returns its CURRENT output ports, which for
// most types is a fixed list but for decision/parallel/approval nodes is
// derived from the node's own config (branches/outcomes) so the palette
// stays generic and the canvas just asks each node for its ports.

const DEFAULT_OUTPUT = [{ id: 'out', label: '' }];
const NO_OUTPUT = [];

function branchPorts(node, fallback) {
  const branches = node?.config?.branches;
  if (Array.isArray(branches) && branches.length) {
    return branches.map((b) => ({ id: b.id, label: b.label || b.id, isDefault: !!b.isDefault }));
  }
  return fallback;
}

function outcomePorts(node, fallback) {
  const outcomes = node?.config?.outcomes;
  if (Array.isArray(outcomes) && outcomes.length) {
    return outcomes.map((o) => ({ id: o.id, label: o.label || o.id, isDefault: !!o.isDefault }));
  }
  return fallback;
}

export const CATEGORIES = [
  { id: 'trigger', label: 'Triggers' },
  { id: 'operation', label: 'Operations' },
  { id: 'flow', label: 'Flow Control' },
  { id: 'human', label: 'Human Work' },
  { id: 'ai', label: 'Optional Intelligence' },
];

// Visual tone → CSS custom property + icon fallback. Non-color cues (the
// icon, the label, the border style below) are what actually distinguish
// node roles — tone is the accent, not the only signal (see app.css).
export const TONES = {
  trigger:   { swatch: '--proc-trigger' },
  operation: { swatch: '--proc-operation' },
  decision:  { swatch: '--proc-decision' },
  human:     { swatch: '--proc-human' },
  wait:      { swatch: '--proc-wait' },
  ai:        { swatch: '--proc-ai' },
  failure:   { swatch: '--proc-failure' },
  end:       { swatch: '--proc-end' },
  disabled:  { swatch: '--proc-disabled' },
};

export const NODE_TYPES = [
  // ── Triggers ────────────────────────────────────────────────────────────
  { id: 'trigger.manual', label: 'Manual Start', category: 'trigger', tone: 'trigger', icon: 'fa-solid fa-hand-pointer',
    description: 'Started by a person, on demand.', inspectorGroups: ['common'] },
  { id: 'trigger.form_submitted', label: 'Form Submitted', category: 'trigger', tone: 'trigger', icon: 'fa-solid fa-file-pen',
    description: 'Starts when a form is submitted.', inspectorGroups: ['common'] },
  { id: 'trigger.email_received', label: 'Email Received', category: 'trigger', tone: 'trigger', icon: 'fa-solid fa-envelope',
    description: 'Starts when a matching email arrives.', inspectorGroups: ['common'] },
  { id: 'trigger.webhook', label: 'Webhook Received', category: 'trigger', tone: 'trigger', icon: 'fa-solid fa-satellite-dish',
    description: 'Starts from an inbound webhook call.', inspectorGroups: ['common'] },
  { id: 'trigger.scheduled', label: 'Scheduled Time', category: 'trigger', tone: 'trigger', icon: 'fa-solid fa-clock',
    description: 'Starts on a recurring schedule.', inspectorGroups: ['common'] },
  { id: 'trigger.record_changed', label: 'Record Created/Changed', category: 'trigger', tone: 'trigger', icon: 'fa-solid fa-database',
    description: 'Starts when a record is created or updated.', inspectorGroups: ['common'] },
  { id: 'trigger.centerstage_event', label: 'Booking Inquiry Created', category: 'trigger', tone: 'trigger', icon: 'fa-solid fa-ticket',
    description: 'Starts when a booking inquiry is created or updated.', inspectorGroups: ['common'] },

  // ── Operations ──────────────────────────────────────────────────────────
  { id: 'op.create_update_record', label: 'Create/Update Record', category: 'operation', tone: 'operation', icon: 'fa-solid fa-pen-to-square',
    description: 'Writes a record via the registry handler.', inspectorGroups: ['common', 'action'] },
  { id: 'op.send_email', label: 'Send Email', category: 'operation', tone: 'operation', icon: 'fa-solid fa-paper-plane',
    description: 'Sends an email to a resolved recipient.', inspectorGroups: ['common', 'action'] },
  { id: 'op.send_sms', label: 'Send SMS', category: 'operation', tone: 'operation', icon: 'fa-solid fa-comment-sms',
    description: 'Sends a text message.', inspectorGroups: ['common', 'action'] },
  { id: 'op.generate_document', label: 'Generate Document', category: 'operation', tone: 'operation', icon: 'fa-solid fa-file-lines',
    description: 'Renders a document from a template.', inspectorGroups: ['common', 'action'] },
  { id: 'op.http_request', label: 'HTTP Request', category: 'operation', tone: 'operation', icon: 'fa-solid fa-globe',
    description: 'Calls an external HTTP endpoint.', inspectorGroups: ['common', 'action'] },
  { id: 'op.transform_data', label: 'Transform Data', category: 'operation', tone: 'operation', icon: 'fa-solid fa-shuffle',
    description: 'Maps/reshapes instance variables.', inspectorGroups: ['common', 'action'] },
  { id: 'op.run_script', label: 'Run Script / Handler', category: 'operation', tone: 'operation', icon: 'fa-solid fa-code',
    description: 'Invokes a registered runtime handler.', inspectorGroups: ['common', 'action'] },
  { id: 'op.add_event_task', label: 'Add Event Task', category: 'operation', tone: 'operation', icon: 'fa-solid fa-list-check',
    description: 'Creates a production task on the event.', inspectorGroups: ['common', 'action'] },
  { id: 'op.create_contract', label: 'Create Contract', category: 'operation', tone: 'operation', icon: 'fa-solid fa-file-signature',
    description: 'Generates a contract for the booking.', inspectorGroups: ['common', 'action'] },
  { id: 'op.request_deposit', label: 'Request Deposit', category: 'operation', tone: 'operation', icon: 'fa-solid fa-money-check-dollar',
    description: 'Requests a deposit payment.', inspectorGroups: ['common', 'action'] },
  { id: 'op.update_event_status', label: 'Update Event Status', category: 'operation', tone: 'operation', icon: 'fa-solid fa-flag',
    description: 'Moves the linked event to a new status.', inspectorGroups: ['common', 'action'] },

  // ── Flow control ────────────────────────────────────────────────────────
  { id: 'flow.decision', label: 'Decision', category: 'flow', tone: 'decision', icon: 'fa-solid fa-diamond', shape: 'diamond',
    description: 'Branches on a condition.', inspectorGroups: ['common', 'decision'],
    defaultConfig: { branches: [{ id: 'yes', label: 'Yes' }, { id: 'no', label: 'No', isDefault: true }] },
    getPorts: (node) => branchPorts(node, [{ id: 'yes', label: 'Yes' }, { id: 'no', label: 'No', isDefault: true }]) },
  { id: 'flow.parallel_split', label: 'Parallel Split', category: 'flow', tone: 'operation', icon: 'fa-solid fa-arrows-split-up-and-left',
    description: 'Runs multiple branches at once.', inspectorGroups: ['common'],
    defaultConfig: { branches: [{ id: 'a', label: 'Branch A' }, { id: 'b', label: 'Branch B' }] },
    getPorts: (node) => branchPorts(node, [{ id: 'a', label: 'Branch A' }, { id: 'b', label: 'Branch B' }]) },
  { id: 'flow.join', label: 'Join', category: 'flow', tone: 'operation', icon: 'fa-solid fa-arrows-to-dot',
    description: 'Waits for parallel branches to converge.', inspectorGroups: ['common'], inputs: '*' },
  { id: 'flow.wait', label: 'Wait', category: 'flow', tone: 'wait', icon: 'fa-solid fa-hourglass-half',
    description: 'Pauses until an event or timeout.', inspectorGroups: ['common', 'wait'],
    getPorts: () => [{ id: 'resumed', label: 'Resumed' }, { id: 'timeout', label: 'Timeout' }] },
  { id: 'flow.timer', label: 'Timer', category: 'flow', tone: 'wait', icon: 'fa-solid fa-stopwatch',
    description: 'Waits for a fixed duration.', inspectorGroups: ['common', 'wait'],
    getPorts: () => [{ id: 'resumed', label: 'Elapsed' }] },
  { id: 'flow.delay', label: 'Delay', category: 'flow', tone: 'wait', icon: 'fa-solid fa-clock-rotate-left',
    description: 'Short, fixed delay before continuing.', inspectorGroups: ['common', 'wait'] },
  { id: 'flow.subprocess', label: 'Subprocess', category: 'flow', tone: 'operation', icon: 'fa-solid fa-diagram-project',
    description: 'Runs another process definition as a step.', inspectorGroups: ['common', 'action'] },
  { id: 'flow.end', label: 'End', category: 'flow', tone: 'end', icon: 'fa-solid fa-flag-checkered',
    description: 'Successful terminal state.', inspectorGroups: ['common'], outputs: NO_OUTPUT },
  { id: 'flow.failure_end', label: 'Failure End', category: 'flow', tone: 'failure', icon: 'fa-solid fa-circle-xmark',
    description: 'Failed terminal state.', inspectorGroups: ['common'], outputs: NO_OUTPUT },

  // ── Human work ──────────────────────────────────────────────────────────
  { id: 'human.assign_task', label: 'Assign Task', category: 'human', tone: 'human', icon: 'fa-solid fa-user-plus',
    description: 'Creates a task for a person, role, or team.', inspectorGroups: ['common', 'human'] },
  { id: 'human.approval', label: 'Approval', category: 'human', tone: 'human', icon: 'fa-solid fa-user-check',
    description: 'Waits for a human approve/revise/reject decision.', inspectorGroups: ['common', 'human'],
    defaultConfig: { outcomes: [{ id: 'approve', label: 'Approve' }, { id: 'revise', label: 'Revise' }, { id: 'reject', label: 'Reject', isDefault: true }] },
    getPorts: (node) => outcomePorts(node, [{ id: 'approve', label: 'Approve' }, { id: 'revise', label: 'Revise' }, { id: 'reject', label: 'Reject', isDefault: true }]) },
  { id: 'human.complete_form', label: 'Complete Form', category: 'human', tone: 'human', icon: 'fa-solid fa-clipboard-list',
    description: 'Waits for a person to fill out a form.', inspectorGroups: ['common', 'human'] },
  { id: 'human.review_document', label: 'Review Document', category: 'human', tone: 'human', icon: 'fa-solid fa-file-circle-check',
    description: 'Waits for a document to be reviewed.', inspectorGroups: ['common', 'human'] },
  { id: 'human.contact_customer', label: 'Contact Customer', category: 'human', tone: 'human', icon: 'fa-solid fa-phone',
    description: 'Assigns a task to reach out to the customer.', inspectorGroups: ['common', 'human'] },
  { id: 'human.resolve_exception', label: 'Resolve Exception', category: 'human', tone: 'human', icon: 'fa-solid fa-triangle-exclamation',
    description: 'Manual intervention on a failed step.', inspectorGroups: ['common', 'human'] },

  // ── Optional intelligence ───────────────────────────────────────────────
  { id: 'ai.classify_text', label: 'Classify Text', category: 'ai', tone: 'ai', icon: 'fa-solid fa-tags',
    description: 'Classifies free text into categories.', inspectorGroups: ['common', 'ai'] },
  { id: 'ai.extract_data', label: 'Extract Structured Data', category: 'ai', tone: 'ai', icon: 'fa-solid fa-table-list',
    description: 'Pulls structured fields out of free text.', inspectorGroups: ['common', 'ai'] },
  { id: 'ai.summarize', label: 'Summarize', category: 'ai', tone: 'ai', icon: 'fa-solid fa-compress',
    description: 'Summarizes long text.', inspectorGroups: ['common', 'ai'] },
  { id: 'ai.generate_content', label: 'Generate Content', category: 'ai', tone: 'ai', icon: 'fa-solid fa-wand-magic-sparkles',
    description: 'Drafts copy for a later step to use.', inspectorGroups: ['common', 'ai'] },
  { id: 'ai.decision', label: 'AI Decision', category: 'ai', tone: 'ai', icon: 'fa-solid fa-brain',
    description: 'AI-assisted branch with a confidence threshold and a required human fallback.', inspectorGroups: ['common', 'decision', 'ai'],
    defaultConfig: { branches: [{ id: 'confident', label: 'Confident' }, { id: 'fallback', label: 'Human Fallback', isDefault: true }] },
    getPorts: (node) => branchPorts(node, [{ id: 'confident', label: 'Confident' }, { id: 'fallback', label: 'Human Fallback', isDefault: true }]) },
];

const BY_ID = new Map(NODE_TYPES.map((t) => [t.id, t]));

export function getNodeType(typeId) {
  return BY_ID.get(typeId) || null;
}

export function categorized() {
  return CATEGORIES.map((cat) => ({ ...cat, types: NODE_TYPES.filter((t) => t.category === cat.id) }));
}

/** Ports a given node instance currently exposes — dynamic for decision/parallel/approval nodes. */
export function nodePorts(node) {
  const def = getNodeType(node?.type);
  if (!def) return { inputs: 1, outputs: DEFAULT_OUTPUT };
  const outputs = typeof def.getPorts === 'function' ? def.getPorts(node) : (def.outputs || DEFAULT_OUTPUT);
  return { inputs: def.inputs ?? 1, outputs };
}

export function defaultConfigFor(typeId) {
  const def = getNodeType(typeId);
  return def?.defaultConfig ? structuredClone(def.defaultConfig) : {};
}

export function toneFor(typeId) {
  const def = getNodeType(typeId);
  return def?.tone || 'disabled';
}
