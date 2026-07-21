// Graph document shape — the JSON that IS the executable process
// definition (see src/Processes.php::defaultGraph(), which this must stay
// in sync with for what a brand-new draft starts as).
//
// Kept deliberately dumb: this module only knows the document's *shape*
// (what fields exist, what a stable id looks like, how to normalize/migrate
// an older document on load). It has no opinion about node types — that's
// node-registry.js — and no opinion about whether a graph is *valid* to
// publish — that's validator.js. graph-store.js is the only thing that
// mutates a loaded document.

export const SCHEMA_VERSION = 1;

export function createEmptyGraph(name = 'Untitled Process') {
  return {
    schemaVersion: SCHEMA_VERSION,
    meta: { name, description: '' },
    nodes: [],
    edges: [],
    viewport: { x: 0, y: 0, zoom: 1 },
    variables: [],
    permissions: {},
    runtimePolicy: {},
  };
}

let _idCounter = 0;
/** Short, stable, human-scannable ids (n1, n2, e1, e2...) — graph JSON that
 *  a person will read in the History tab's before/after diffs shouldn't be
 *  a wall of UUIDs. */
export function nextId(prefix) {
  _idCounter += 1;
  return `${prefix}${Date.now().toString(36)}${(_idCounter).toString(36)}`;
}

/** Ensure every top-level field exists and is the right shape, without
 *  throwing on a document saved by an older/partial client. Called once on
 *  load; graph-store.js can assume a normalized document from then on. */
export function normalizeGraph(doc) {
  const graph = (doc && typeof doc === 'object') ? doc : {};
  return {
    schemaVersion: graph.schemaVersion || SCHEMA_VERSION,
    meta: { name: '', description: '', ...(graph.meta || {}) },
    nodes: Array.isArray(graph.nodes) ? graph.nodes.map(normalizeNode) : [],
    edges: Array.isArray(graph.edges) ? graph.edges.map(normalizeEdge) : [],
    viewport: { x: 0, y: 0, zoom: 1, ...(graph.viewport || {}) },
    variables: Array.isArray(graph.variables) ? graph.variables : [],
    permissions: graph.permissions && typeof graph.permissions === 'object' ? graph.permissions : {},
    runtimePolicy: graph.runtimePolicy && typeof graph.runtimePolicy === 'object' ? graph.runtimePolicy : {},
  };
}

function normalizeNode(node) {
  return {
    id: String(node.id ?? nextId('n')),
    type: node.type || 'op.run_script',
    name: node.name || '',
    description: node.description || '',
    position: { x: Number(node.position?.x) || 0, y: Number(node.position?.y) || 0 },
    config: node.config && typeof node.config === 'object' ? node.config : {},
    runtimePolicy: node.runtimePolicy && typeof node.runtimePolicy === 'object' ? node.runtimePolicy : {},
    ui: node.ui && typeof node.ui === 'object' ? node.ui : {},
  };
}

function normalizeEdge(edge) {
  return {
    id: String(edge.id ?? nextId('e')),
    source: { nodeId: String(edge.source?.nodeId ?? ''), port: edge.source?.port ?? 'out' },
    target: { nodeId: String(edge.target?.nodeId ?? ''), port: edge.target?.port ?? 'in' },
    type: edge.type || 'normal', // normal | conditional | data | error | timeout | escalation
    outcome: edge.outcome ?? null,
    isDefault: !!edge.isDefault,
    label: edge.label || '',
    priority: Number.isFinite(edge.priority) ? edge.priority : 0,
  };
}

/**
 * Schema migration hook. There is exactly one schema version today, so this
 * is a no-op passthrough — it exists so a future schemaVersion bump has a
 * single, obvious place to add a step-migration instead of every caller
 * needing to know graph-document history.
 */
export function migrateGraph(doc) {
  const graph = normalizeGraph(doc);
  if (graph.schemaVersion > SCHEMA_VERSION) {
    throw new Error(`This process was saved with a newer schema (v${graph.schemaVersion}) than this app understands (v${SCHEMA_VERSION}).`);
  }
  return graph;
}
