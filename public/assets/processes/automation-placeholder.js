// <pb-automation-placeholder> — honest "not built yet" pages for the
// Automation nav items that Phase 1 doesn't implement (Cases/Tasks/Activity/
// Connections are cross-process views Phase 4 builds on top of the
// per-process Live Cases/History/registry work Phase 1 ships). Rather than
// hide these nav entries or fake content, each one says plainly what it
// will be and points at what already exists today.
import { publish, PanicElement } from '../core.js';

const PAGES = {
  cases: {
    title: 'Cases',
    blurb: 'A cross-process view of every running instance.',
    body: `<p>This will roll up every process instance across every process definition into one searchable/filterable list — the same data each process's own <strong>Live Cases</strong> tab shows today, just not yet aggregated across processes.</p>
      <p>Until then, open a process under <a href="#automation-processes">Automation &gt; Processes</a> and use its Live Cases tab.</p>`,
  },
  tasks: {
    title: 'Tasks',
    blurb: 'Your inbox of human-work items created by running processes.',
    body: `<p>Human-task nodes (Approval, Assign Task, Complete Form, …) will hand work to this inbox once the execution runtime (Phase 2) exists to actually create and resolve tasks. The node types and inspector fields for human work are already built into the process designer.</p>`,
  },
  activity: {
    title: 'Activity',
    blurb: 'A live feed of executions, failures, and retries across every process.',
    body: `<p>This aggregates the per-process audit trail (already available on each process's <strong>History</strong> tab) into one feed, plus the execution/retry events the Phase 2 runtime will produce.</p>`,
  },
  connections: {
    title: 'Connections',
    blurb: 'Credentials for the external services process actions call.',
    body: `<p>Action nodes (Send Email, HTTP Request, Create Contract, …) will resolve a named connection from here at runtime instead of embedding credentials in the graph document — see the "No direct storage of plaintext connector credentials in graph documents" requirement. Not wired up yet.</p>`,
  },
};

export class AutomationPlaceholderElement extends PanicElement {
  connect() {
    const page = PAGES[this.page] || PAGES.cases;
    publish('page.context', { title: page.title, blurb: `Automation > ${page.title}` });
    this.innerHTML = `
      <div class="page-head"><div><h1>${page.title}</h1><p class="subtle">${page.blurb}</p></div></div>
      <div class="panel padded">
        <span class="pill pill-muted">Planned — not yet built</span>
        ${page.body}
      </div>`;
  }
}
customElements.define('pb-automation-placeholder', AutomationPlaceholderElement);
