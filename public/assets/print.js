import { esc, titleCase, publish, eventDate, eventDateRangeLabel, timeLabel, money, statusLabel, can, table, mdToHtml, appUrl } from './core.js';


// ── Print feature ────────────────────────────────────────────────────────────
// Opens a new window with a self-contained, print-styled HTML document built
// from already-loaded event data. The user prints via Cmd/Ctrl+P (or the
// "Print" button injected into the printout). Five printout types are
// supported: lineup, staffing, run-of-show, guest-list, and master (combined).

const PRINT_TITLES = {
  lineup: 'Band Lineup',
  staffing: 'Staffing Schedule',
  'run-of-show': 'Run of Show',
  'guest-list': 'Door / Guest List',
  master: 'Master Event Packet',
  'one-sheet': 'One Sheet',
  contract: 'Contract',
  'qr-flyer': 'QR Flyer',
};


const PRINT_CSS = `
  *, *::before, *::after { box-sizing: border-box; }
  html, body { margin: 0; padding: 0; }
  body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; color: #111; background: #f5f5f5; padding: 24px; }
  .sheet { background: #fff; max-width: 8.5in; margin: 0 auto 24px; padding: 0.6in 0.65in; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
  .print-toolbar { max-width: 8.5in; margin: 0 auto 12px; display: flex; justify-content: flex-end; gap: 8px; }
  .print-toolbar button { font: inherit; padding: 8px 14px; border: 1px solid #888; background: #fff; border-radius: 4px; cursor: pointer; }
  .print-toolbar button.primary { background: #111; color: #fff; border-color: #111; }
  header.event-head { border-bottom: 2px solid #111; padding-bottom: 10px; margin-bottom: 18px; display: flex; justify-content: space-between; align-items: flex-end; gap: 18px; }
  header.event-head h1 { font-size: 22pt; margin: 0 0 4px; line-height: 1.15; }
  header.event-head .meta { font-size: 10pt; color: #444; }
  header.event-head .head-right { text-align: right; font-size: 10pt; }
  header.event-head .head-right strong { display: block; font-size: 14pt; color: #111; }
  h2.section { font-size: 14pt; margin: 22px 0 10px; padding-bottom: 4px; border-bottom: 1px solid #999; }
  h3.subsection { font-size: 11pt; margin: 14px 0 6px; color: #333; text-transform: uppercase; letter-spacing: 0.04em; }
  table { width: 100%; border-collapse: collapse; font-size: 10pt; margin-bottom: 12px; }
  th, td { text-align: left; padding: 6px 8px; border-bottom: 1px solid #ddd; vertical-align: top; }
  th { background: #f0f0f0; font-weight: 600; text-transform: uppercase; font-size: 8.5pt; letter-spacing: 0.04em; }
  td.num, th.num { text-align: right; font-variant-numeric: tabular-nums; }
  td.time, th.time { white-space: nowrap; font-variant-numeric: tabular-nums; }
  .facts { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px 16px; margin: 12px 0 18px; font-size: 10pt; }
  .facts .fact { border-left: 3px solid #111; padding-left: 8px; }
  .facts .fact label { display: block; font-size: 8pt; text-transform: uppercase; letter-spacing: 0.05em; color: #666; }
  .facts .fact strong { font-size: 11pt; }
  .notes-block { background: #f7f7f7; border-left: 3px solid #888; padding: 8px 10px; font-size: 10pt; white-space: pre-wrap; margin: 8px 0 12px; }
  .empty { color: #888; font-style: italic; font-size: 10pt; padding: 8px 0; }
  .pill { display: inline-block; padding: 1px 6px; border-radius: 10px; font-size: 8pt; background: #eee; color: #333; text-transform: uppercase; letter-spacing: 0.05em; }
  .pill.confirmed { background: #d4edda; color: #155724; }
  .pill.tentative { background: #fff3cd; color: #856404; }
  .pill.canceled { background: #f8d7da; color: #721c24; }
  .pill.invited { background: #d1ecf1; color: #0c5460; }
  footer.sheet-foot { margin-top: 24px; padding-top: 8px; border-top: 1px solid #ccc; font-size: 8.5pt; color: #777; display: flex; justify-content: space-between; }
  .page-break { page-break-before: always; break-before: page; }

  /* One Sheet — mirrors the artist/event one-sheet PDF layout. */
  .onesheet { font-size: 11pt; line-height: 1.45; color: #111; }
  .onesheet h1.os-title { font-size: 23pt; font-weight: 700; line-height: 1.15; margin: 0 0 14px; text-transform: uppercase; }
  .onesheet .os-meta { margin: 0 0 3px; }
  .onesheet .os-meta strong { font-weight: 700; }
  .onesheet .os-note { margin: 12px 0 4px; }
  .onesheet h2.os-section { font-size: 15pt; font-weight: 700; text-transform: uppercase; letter-spacing: 0.01em; margin: 24px 0 8px; }
  .onesheet p.os-para { margin: 0 0 10px; }
  .onesheet p.os-para strong { font-weight: 700; }
  .onesheet ul.os-list { margin: 4px 0 10px; padding-left: 24px; list-style: disc; }
  .onesheet ul.os-list li { margin: 3px 0; }
  .onesheet a { color: #1155cc; text-decoration: underline; word-break: break-word; }

  /* Contract — mirrors the venue event-agreement PDF layout. */
  .contract { font-size: 11pt; line-height: 1.5; color: #111; }
  .contract h1.k-brand { font-size: 21pt; font-weight: 700; text-transform: uppercase; line-height: 1.15; margin: 0 0 4px; }
  .contract h1.k-title { font-size: 18pt; font-weight: 700; text-transform: uppercase; margin: 0 0 16px; }
  .contract .k-meta { margin: 0 0 3px; }
  .contract .k-meta strong { font-weight: 700; }
  .contract h2.k-section { font-size: 14pt; font-weight: 700; text-transform: uppercase; margin: 24px 0 8px; }
  .contract h3.k-sub { font-size: 11pt; font-weight: 700; margin: 14px 0 5px; }
  .contract p.k-para { margin: 0 0 10px; }
  .contract ul.k-list { margin: 4px 0 10px; padding-left: 24px; list-style: disc; }
  .contract ul.k-list li { margin: 3px 0; }
  .contract .k-party { font-weight: 700; margin: 18px 0 8px; }
  .contract .k-sign-line { margin: 14px 0 4px; }
  .contract .k-fill { display: inline-block; min-width: 260px; border-bottom: 1px solid #111; }
  .contract .k-fill.short { min-width: 200px; }

  /* QR Flyer — bold door-poster sheet: huge title, huge scannable QR, price/doors,
     then a stacked all-caps lineup. Pure black-on-white, no boxes/rules, meant to
     be printed big and taped up or held at the door for walk-up card sales.
     Vertical rhythm is kept tight throughout (and the sheet's own padding and
     footer margin are trimmed for this printout only, via :has()) so a normal
     3-5 band bill fits a single 8.5x11 sheet instead of spilling a mostly-empty
     second page. */
  .sheet:has(.qr-flyer) { padding: 0.35in 0.55in 0.3in; }
  .sheet:has(.qr-flyer) footer.sheet-foot { margin-top: 10px; padding-top: 6px; }
  .qr-flyer { text-align: center; padding: 0; font-family: "Arial Black", Impact, "Franklin Gothic Bold", Arial, sans-serif; color: #000; }
  .qr-flyer .qf-title { font-size: 52pt; font-weight: 900; line-height: 0.94; margin: 0 0 6px; text-transform: uppercase; letter-spacing: -0.01em; word-break: break-word; }
  .qr-flyer .qf-scan-label { font-size: 15pt; font-weight: 900; letter-spacing: 0.03em; text-transform: uppercase; margin: 0 0 12px; }
  .qr-flyer .qf-qr-wrap { margin: 0 0 14px; }
  .qr-flyer .qf-qr { display: block; margin: 0 auto; }
  .qr-flyer .qf-facts { font-size: 23pt; font-weight: 900; text-transform: uppercase; line-height: 1.25; margin: 0 0 12px; }
  .qr-flyer .qf-lineup-head { font-size: 23pt; font-weight: 900; text-transform: uppercase; margin: 2px 0 6px; }
  .qr-flyer .qf-lineup { list-style: none; margin: 0; padding: 0; }
  .qr-flyer .qf-lineup li { font-size: 18pt; font-weight: 900; text-transform: uppercase; line-height: 1.15; margin: 0 0 4px; }
  .qr-flyer .qf-lineup li.empty { font-weight: normal; font-style: italic; font-family: -apple-system, BlinkMacSystemFont, sans-serif; }
  .qr-flyer .qf-lineup .qf-time { display: block; font-size: 10pt; font-weight: 600; letter-spacing: 0.03em; font-family: -apple-system, BlinkMacSystemFont, sans-serif; margin: -1px 0 0; }

  @media print {
    body { background: #fff; padding: 0; }
    .sheet { box-shadow: none; margin: 0; padding: 0.5in 0.55in; max-width: none; }
    .print-toolbar { display: none; }
    @page { size: letter; margin: 0.5in; }
  }
`;


function printDateRange(event) {
  const date = eventDate(event);
  if (!date) return 'Date TBA';
  const long = (d) => d.toLocaleDateString(undefined, { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
  if (!event?.end_date || event.end_date === event.date) return long(date);
  const end = eventDate({ date: event.end_date });
  return end ? `${long(date)} – ${long(end)}` : long(date);
}


function printDuration(minutes) {
  const value = Number(minutes);
  if (!value) return '';
  if (value < 60) return `${value} min`;
  const hours = Math.floor(value / 60);
  const rem = value % 60;
  return rem ? `${hours}h ${rem}m` : `${hours}h`;
}


function printPill(status) {
  if (!status) return '';
  const cls = String(status).toLowerCase().replace(/[^a-z]+/g, '');
  return `<span class="pill ${cls}">${esc(titleCase(status))}</span>`;
}


function printHeader(data, subtitle) {
  const event = data.event;
  const venueLine = [event.venue_name, event.venue_city, event.venue_state].filter(Boolean).join(', ');
  return `<header class="event-head">
    <div>
      <h1>${esc(event.title)}${event.external_id ? ` <span class="event-code">${esc(event.external_id)}</span>` : ''}</h1>
      <div class="meta">${esc(venueLine || 'Venue TBA')}${event.venue_address ? ' &middot; ' + esc(event.venue_address) : ''}</div>
      <div class="meta">${esc(printDateRange(event))}</div>
    </div>
    <div class="head-right">
      <strong>${esc(subtitle)}</strong>
      <div>Doors ${esc(timeLabel(event.doors_time))} &middot; Show ${esc(timeLabel(event.show_time))}</div>
      ${event.age_restriction ? `<div>${esc(event.age_restriction)}</div>` : ''}
    </div>
  </header>`;
}


function printFooter(data) {
  const stamp = new Date().toLocaleString();
  return `<footer class="sheet-foot">
    <span>${esc(data.event.title)} &middot; ${esc(printDateRange(data.event))}</span>
    <span>Printed ${esc(stamp)}</span>
  </footer>`;
}


function renderLineupSection(data) {
  const lineup = data.lineup || [];
  if (!lineup.length) return `<h2 class="section">Band Lineup</h2><p class="empty">No lineup entries.</p>`;
  const rows = lineup.map((item, index) => `<tr>
    <td class="num">${index + 1}</td>
    <td><strong>${esc(item.display_name || item.band_name || 'Untitled')}</strong>${item.band_name && item.band_name !== item.display_name ? `<br><span style="color:#666;font-size:9pt;">${esc(item.band_name)}</span>` : ''}</td>
    <td class="time">${esc(timeLabel(item.set_time))}</td>
    <td class="time">${esc(printDuration(item.set_length_minutes))}</td>
    <td>${printPill(item.status)}</td>
    <td>${esc(item.notes || '')}</td>
  </tr>`).join('');
  return `<h2 class="section">Band Lineup</h2>
    <table>
      <thead><tr><th class="num">#</th><th>Act</th><th class="time">Set Time</th><th class="time">Length</th><th>Status</th><th>Notes</th></tr></thead>
      <tbody>${rows}</tbody>
    </table>`;
}


// Long-form day heading for a Y-M-D string, e.g. "Friday, August 14" — used to
// break a multi-day event's staffing schedule into one table per day.
function dayHeading(dateStr) {
  const d = eventDate({ date: dateStr });
  return d ? d.toLocaleDateString(undefined, { weekday: 'long', month: 'long', day: 'numeric' }) : dateStr;
}

function renderStaffingSection(data) {
  const event = data.event || {};
  const isMultiDay = Boolean(event.end_date && event.end_date !== event.date);
  const shifts = (data.staffing || []).slice().sort((a, b) => {
    const da = a.shift_date || event.date || '';
    const db = b.shift_date || event.date || '';
    if (da !== db) return da.localeCompare(db);
    const ta = a.call_time || '99:99:99';
    const tb = b.call_time || '99:99:99';
    return ta.localeCompare(tb);
  });
  const collaborators = (data.collaborators || []).filter((c) => ['venue_admin','event_owner','promoter','staff','designer'].includes(c.event_role));
  const staffCalls = (data.schedule || []).filter((item) => item.item_type === 'staff_call');

  const shiftRow = (s) => `<tr>
    <td class="time">${esc(timeLabel(s.call_time))}${s.end_time ? `<br><span style="color:#666;">${esc(timeLabel(s.end_time))}</span>` : ''}</td>
    <td>${esc(titleCase(s.role))}</td>
    <td><strong>${esc(s.staff_name || 'TBD')}</strong>${s.staff_phone ? `<br><span style="color:#666;">${esc(s.staff_phone)}</span>` : ''}</td>
    <td>${printPill(s.status)}</td>
    <td>${esc(s.notes || '')}</td>
  </tr>`;
  const shiftTable = (rows) => `<table>
    <thead><tr><th class="time">Call / End</th><th>Role</th><th>Staff</th><th>Status</th><th>Notes</th></tr></thead>
    <tbody>${rows.map(shiftRow).join('')}</tbody>
  </table>`;

  let shiftsBlock;
  if (!shifts.length) {
    shiftsBlock = `<h3 class="subsection">Shifts</h3><p class="empty">No shifts scheduled.</p>`;
  } else if (isMultiDay) {
    // One table per calendar day so a multi-day event's crew list reads as
    // separate day-by-day call sheets rather than one undated pile of shifts.
    const byDate = shifts.reduce((map, s) => {
      const key = s.shift_date || event.date;
      (map[key] = map[key] || []).push(s);
      return map;
    }, {});
    shiftsBlock = Object.keys(byDate).sort()
      .map((date) => `<h3 class="subsection">${esc(dayHeading(date))}</h3>${shiftTable(byDate[date])}`)
      .join('');
  } else {
    shiftsBlock = `<h3 class="subsection">Shifts</h3>${shiftTable(shifts)}`;
  }

  const peopleRows = collaborators.length ? collaborators.map((c) => `<tr>
    <td><strong>${esc(c.name || '—')}</strong></td>
    <td>${esc(titleCase(c.event_role))}</td>
    <td>${esc(c.email || '')}</td>
  </tr>`).join('') : '';
  const callRows = staffCalls.length ? staffCalls.map((item) => `<tr>
    <td class="time">${esc(timeLabel(item.start_time))}</td>
    <td><strong>${esc(item.title)}</strong></td>
    <td>${esc(item.notes || '')}</td>
  </tr>`).join('') : '';

  return `<h2 class="section">Staffing Schedule</h2>
    ${shiftsBlock}
    <h3 class="subsection">Event Collaborators</h3>
    ${peopleRows ? `<table>
      <thead><tr><th>Name</th><th>Role</th><th>Email</th></tr></thead>
      <tbody>${peopleRows}</tbody>
    </table>` : `<p class="empty">No collaborators assigned.</p>`}
    <h3 class="subsection">Staff Call Times (Run Sheet)</h3>
    ${callRows ? `<table>
      <thead><tr><th class="time">Call</th><th>What</th><th>Notes</th></tr></thead>
      <tbody>${callRows}</tbody>
    </table>` : `<p class="empty">No staff call times scheduled.</p>`}`;
}


function renderRunOfShowSection(data) {
  const schedule = (data.schedule || []).slice().sort((a, b) => {
    const ta = a.start_time || '99:99:99';
    const tb = b.start_time || '99:99:99';
    return ta.localeCompare(tb);
  });
  if (!schedule.length) return `<h2 class="section">Run of Show</h2><p class="empty">No schedule items.</p>`;
  const rows = schedule.map((item) => `<tr>
    <td class="time">${esc(timeLabel(item.start_time))}${item.end_time ? `<br><span style="color:#666;">${esc(timeLabel(item.end_time))}</span>` : ''}</td>
    <td><strong>${esc(item.title)}</strong></td>
    <td>${esc(titleCase(item.item_type))}</td>
    <td>${esc(item.notes || '')}</td>
  </tr>`).join('');
  return `<h2 class="section">Run of Show</h2>
    <table>
      <thead><tr><th class="time">Start / End</th><th>Item</th><th>Type</th><th>Notes</th></tr></thead>
      <tbody>${rows}</tbody>
    </table>`;
}


function renderGuestListSection(data) {
  const guests = data.guests || [];
  if (!guests.length) {
    return `<h2 class="section">Door / Guest List</h2>
      <p class="empty">No guest list entries yet. Add entries to <code>event_guest_list</code> via the API to populate this printout.</p>`;
  }
  // Group by list_type for door-friendly layout
  const grouped = guests.reduce((map, g) => {
    const key = g.list_type || 'guest';
    (map[key] = map[key] || []).push(g);
    return map;
  }, {});
  const order = ['vip', 'press', 'industry', 'comp', 'guest', 'will_call'];
  const sections = order
    .filter((key) => grouped[key])
    .map((key) => {
      const rows = grouped[key].map((g) => `<tr>
        <td style="width:24px;"><span style="display:inline-block;width:14px;height:14px;border:1px solid #333;"></span></td>
        <td><strong>${esc(g.name)}</strong></td>
        <td class="num">${esc(g.party_size || 1)}</td>
        <td>${esc(g.guest_of || '')}</td>
        <td>${esc(g.notes || '')}</td>
      </tr>`).join('');
      const total = grouped[key].reduce((sum, g) => sum + Number(g.party_size || 1), 0);
      return `<h3 class="subsection">${esc(titleCase(key))} &middot; ${grouped[key].length} entries, ${total} seats</h3>
        <table>
          <thead><tr><th></th><th>Name</th><th class="num">+</th><th>Guest of</th><th>Notes</th></tr></thead>
          <tbody>${rows}</tbody>
        </table>`;
    }).join('');
  const grandTotal = guests.reduce((sum, g) => sum + Number(g.party_size || 1), 0);
  return `<h2 class="section">Door / Guest List <span style="font-size:10pt;font-weight:normal;color:#666;">(${guests.length} entries, ${grandTotal} seats)</span></h2>${sections}`;
}


function renderEventFactsSection(data) {
  const event = data.event;
  const facts = [
    ['Date', eventDateRangeLabel(event)],
    ['Doors', timeLabel(event.doors_time)],
    ['Show', timeLabel(event.show_time)],
    ['End', timeLabel(event.end_time)],
    ['Venue', event.venue_name || '—'],
    ['Room', event.room ? titleCase(event.room) : '—'],
    ['Capacity', event.capacity || '—'],
    ['Age', event.age_restriction || 'All ages'],
    ['Ticket', event.ticket_price ? money(event.ticket_price) : 'Free'],
    ['Promoter', event.promoter_name || '—'],
    ['Owner', event.owner_name || 'Unassigned'],
    ['Status', statusLabel(event.status)],
  ];
  const notes = event.description_internal ? `<h3 class="subsection">Internal Notes</h3><div class="notes-block">${esc(event.description_internal)}</div>` : '';
  return `<h2 class="section">Event Overview</h2>
    <div class="facts">${facts.map(([label, value]) => `<div class="fact"><label>${esc(label)}</label><strong>${esc(value)}</strong></div>`).join('')}</div>
    ${notes}`;
}


// One Sheet — a single-column promotional / overview sheet that mirrors the
// formatting of the artist one-sheet PDF: a large title, bold-labeled contact
// meta, then uppercase section headings with round-bullet lists or prose.
// Sections with no underlying data are omitted so the sheet stays clean.
function renderOneSheet(data) {
  const event = data.event;
  const blocks = [];

  // Title.
  blocks.push(`<h1 class="os-title">${esc(event.title)}</h1>`);

  // Contact meta — Date, Primary Contact, Emails, Venue. Bold label + value.
  const contacts = (data.collaborators || []).filter((c) => ['event_owner', 'promoter', 'venue_admin'].includes(c.event_role));
  const contactNames = contacts.length
    ? contacts.map((c) => esc(c.name)).filter(Boolean).join(' and ')
    : esc(event.owner_name || 'TBD');
  const emails = [...new Set(contacts.map((c) => c.email).filter(Boolean))];
  const venueLine = [event.venue_name, event.venue_address, event.venue_city, event.venue_state].filter(Boolean).join(', ');

  const meta = [];
  meta.push(['Date', esc(printDateRange(event))]);
  if (contactNames) meta.push(['Primary Contact', contactNames]);
  if (emails.length) meta.push(['Emails', emails.map((e) => `<a href="mailto:${esc(e)}">${esc(e)}</a>`).join(' | ')]);
  if (venueLine) meta.push(['Venue', esc(venueLine)]);
  blocks.push(meta.map(([label, value]) => `<p class="os-meta"><strong>${esc(label)}:</strong> ${value}</p>`).join(''));

  if (Number(event.walkthrough_done)) blocks.push(`<p class="os-note">Walk through completed.</p>`);

  // EVENT OVERVIEW — public description rendered as Markdown.
  if (event.description_public) {
    blocks.push(`<h2 class="os-section">Event Overview</h2><div class="os-description">${mdToHtml(event.description_public)}</div>`);
  }

  // FEATURED MUSICIANS — lineup acts as bullets.
  const lineup = data.lineup || [];
  if (lineup.length) {
    const items = lineup.map((item) => {
      const name = esc(item.display_name || item.band_name || 'Untitled');
      const time = item.set_time ? ` &mdash; ${esc(timeLabel(item.set_time))}` : '';
      return `<li>${name}${time}</li>`;
    }).join('');
    blocks.push(`<h2 class="os-section">Featured Musicians</h2><ul class="os-list">${items}</ul>`);
  }

  // TICKETING — price, ticketing system, link, age.
  const ticketing = [];
  if (Number(event.ticket_price) > 0) ticketing.push(`${money(event.ticket_price)} ${event.ticket_system ? esc(event.ticket_system) : 'advance'}`);
  else if (event.ticket_price !== undefined && event.ticket_price !== null) ticketing.push('Free / door');
  if (event.ticket_system && !(Number(event.ticket_price) > 0)) ticketing.push(`${esc(event.ticket_system)} ticketing`);
  if (event.ticket_url) ticketing.push(`Tickets: <a href="${esc(event.ticket_url)}">${esc(event.ticket_url)}</a>`);
  if (event.age_restriction) ticketing.push(esc(event.age_restriction));
  if (ticketing.length) {
    blocks.push(`<h2 class="os-section">Ticketing</h2><ul class="os-list">${ticketing.map((t) => `<li>${t}</li>`).join('')}</ul>`);
  }

  // PRODUCTION — schedule of doors/show/end, room, capacity, staffing roles.
  const production = [];
  const times = [
    event.doors_time ? `Doors ${esc(timeLabel(event.doors_time))}` : '',
    event.show_time ? `Show ${esc(timeLabel(event.show_time))}` : '',
    event.end_time ? `End ${esc(timeLabel(event.end_time))}` : '',
  ].filter(Boolean).join(' &middot; ');
  if (times) production.push(times);
  if (event.room) production.push(`${esc(titleCase(event.room))} room`);
  if (Number(event.capacity) > 0) production.push(`Capacity ${esc(event.capacity)}`);
  const staffRoles = [...new Set((data.staffing || []).map((s) => s.role).filter(Boolean))];
  if (staffRoles.length) production.push(`Staffing: ${staffRoles.map((r) => esc(titleCase(r))).join(', ')}`);
  if (event.contract_url) production.push(`Contract: ${/^https?:\/\//i.test(event.contract_url) ? `<a href="${esc(event.contract_url)}">${esc(event.contract_url)}</a>` : esc(event.contract_url)}`);
  if (production.length) {
    blocks.push(`<h2 class="os-section">Production</h2><ul class="os-list">${production.map((p) => `<li>${p}</li>`).join('')}</ul>`);
  }

  return `<div class="onesheet">${blocks.join('')}</div>`;
}


// Contract — a fill-and-sign venue event agreement that mirrors the formatting
// of the event-agreement PDF: a venue brand title, a bold-labeled party/meta
// block, then numbered uppercase sections. Event-specific fields (name, date,
// venue, lineup, ticketing, open items, signatories) are merged from the loaded
// event; the standard legal clauses are boilerplate template text. Blank merge
// fields fall back to a rule the parties can fill in by hand.
function renderContract(data) {
  const event = data.event;
  const blank = '<span class="k-fill"></span>';
  const list = (items) => `<ul class="k-list">${items.map((i) => `<li>${i}</li>`).join('')}</ul>`;
  const sub = (title) => `<h3 class="k-sub">${esc(title)}</h3>`;

  const collaborators = data.collaborators || [];
  const byRole = (role) => collaborators.filter((c) => c.event_role === role).map((c) => esc(c.name)).filter(Boolean);
  const promoterNames = byRole('promoter').concat(byRole('event_owner'));
  const promoter = promoterNames.length ? promoterNames.join(' and ') : esc(event.owner_name || event.promoter_name || '');
  const venueReps = byRole('venue_admin');
  const venueRep = venueReps.length ? venueReps.join(', ') : '';
  const location = [event.venue_address, event.venue_city, event.venue_state].filter(Boolean).join(', ');

  const blocks = [];

  // Brand title + agreement heading.
  blocks.push(`<h1 class="k-brand">${esc(event.venue_name || 'Venue')}</h1>`);
  blocks.push(`<h1 class="k-title">Event Agreement</h1>`);

  // Party / event meta block.
  const meta = [
    ['Event Name', esc(event.title)],
    ['Event Date', esc(printDateRange(event))],
    ['Venue Name', esc(event.venue_name || '')],
    ['Location', esc(location) || blank],
    ['Age Restriction', esc(event.age_restriction || 'All Ages')],
    ['Maximum Capacity', event.capacity ? `${esc(event.capacity)} persons (hard cap)` : blank],
    ['Promoter / Organizer', promoter || blank],
    ['Venue Representative', venueRep || blank],
  ];
  blocks.push(meta.map(([label, value]) => `<p class="k-meta"><strong>${esc(label)}:</strong> ${value}</p>`).join(''));

  // 1. EVENT OVERVIEW — rendered as Markdown.
  const overview = event.description_public
    ? `<div class="k-description">${mdToHtml(event.description_public)}</div>`
    : `<p class="k-para">${blank}</p>`;
  blocks.push(`<h2 class="k-section">1. Event Overview</h2>${overview}`);

  // 2. EVENT DETAILS
  const schedule = (data.schedule || []).slice().sort((a, b) => (a.start_time || '99').localeCompare(b.start_time || '99'));
  const scheduleItems = schedule.length
    ? schedule.map((s) => `${esc(s.title)}: ${esc(timeLabel(s.start_time))}${s.end_time ? ` &ndash; ${esc(timeLabel(s.end_time))}` : ''}`)
    : [
        `Load-In / Setup: ${blank}`,
        `Soundcheck: ${blank}`,
        `Doors: ${esc(timeLabel(event.doors_time))}`,
        `Show Start: ${esc(timeLabel(event.show_time))}`,
        `Event End: ${esc(timeLabel(event.end_time))}`,
        'Strike Complete: Within one (1) hour after event end unless otherwise approved by Venue',
      ];
  blocks.push(`<h2 class="k-section">2. Event Details</h2>
    ${sub('Space Requested')}${list([event.room ? esc(titleCase(event.room)) : blank])}
    ${sub('Event Classification')}${list(['Public Event', 'Public Ticketed Show'])}
    ${sub('Estimated Attendance')}${list([event.capacity ? `Approximately ${esc(event.capacity)} attendees` : blank])}
    ${sub('Tentative Schedule')}${list(scheduleItems)}
    <p class="k-para">Final schedule and Run of Show shall be provided by Organizer no later than fourteen (14) days prior to the event.</p>`);

  // 3. PROGRAMMING & LINEUP
  const lineup = data.lineup || [];
  const lineupItems = lineup.length
    ? lineup.map((i) => `${esc(i.display_name || i.band_name || 'Untitled')}${i.notes ? ` (${esc(i.notes)})` : ''}`)
    : [blank];
  blocks.push(`<h2 class="k-section">3. Programming &amp; Lineup</h2>
    ${sub('Current Lineup')}${list(lineupItems)}
    ${sub('Program Elements')}${list([
      'Recording/sound team provided by Organizer',
      'Multi-band live sound setup required',
      '1 bar/security per 100 people',
      `${esc(event.ticket_system || 'Approved ticketing platform')} for public ticketed events`,
      '70/30 ticket split after production costs and house to take the bar',
    ])}`);

  // 4. TICKETING & REVENUE
  const ticketItems = [];
  if (Number(event.ticket_price) > 0) ticketItems.push(`${money(event.ticket_price)} Advance / Early Bird`);
  ticketItems.push(Number(event.ticket_price) > 0 ? `${money(Number(event.ticket_price) + 5)} Door` : `${blank} Door`);
  blocks.push(`<h2 class="k-section">4. Ticketing &amp; Revenue</h2>
    ${sub('Ticketing')}${list(ticketItems)}
    ${sub('Revenue Structure')}
    <p class="k-para">Ticket revenue shall be split as follows:</p>
    ${list(['Promoter / Organizer: 70%', 'Venue: 30%'])}
    <p class="k-para">Approved ticketing fees, processing fees, and production costs including staff, shall be deducted prior to revenue split calculations.</p>
    <p class="k-para">Venue bar revenue shall remain with Venue unless otherwise agreed in writing.</p>`);

  // 5. PRODUCTION & STAFFING
  const staffRoles = [...new Set((data.staffing || []).map((s) => s.role).filter(Boolean))];
  const staffItems = staffRoles.length
    ? staffRoles.map((r) => esc(titleCase(r)))
    : [
        'Sound Engineer &ndash; estimated (1) one',
        'Security Personnel &ndash; estimated (1) one',
        'House Manager &ndash; estimated (1) one',
        'Bartenders &ndash; estimated (2) two',
        'Door Staff / Ticketing &ndash; estimated (1) one',
      ];
  blocks.push(`<h2 class="k-section">5. Production &amp; Staffing</h2>
    ${sub('Technical Requirements')}${list([
      'Full live band sound setup for multi-band bill',
      'Standard microphones for bands and MC',
      'Standard venue lighting package',
      'Live sound engineering support',
    ])}
    ${sub('Staffing May Include')}${list(staffItems)}
    <p class="k-para">Any extraordinary production requests beyond standard venue capabilities must be approved in writing and may incur additional charges.</p>`);

  // 6. BAR & HOSPITALITY
  blocks.push(`<h2 class="k-section">6. Bar &amp; Hospitality</h2>
    <p class="k-para">Venue may offer themed drink specials at its discretion. Hospitality needs for performers, crew, or hosts shall be coordinated separately if requested.</p>`);

  // 7. PROMOTION & MARKETING
  blocks.push(`<h2 class="k-section">7. Promotion &amp; Marketing</h2>
    <p class="k-para">Organizer shall be primarily responsible for event promotion.</p>
    ${sub('Promotion Channels')}${list(['Instagram', 'TikTok', 'Reels / Social Media Campaigns', 'Community promotion networks', 'News outlets'])}`);

  // 8. AGE POLICY
  blocks.push(`<h2 class="k-section">8. Age Policy</h2>
    <p class="k-para">Final event age classification (All Ages vs. 21+) must be confirmed in writing prior to public announcement and ticket launch.</p>`);

  // 9. MUTUAL INDEMNIFICATION
  blocks.push(`<h2 class="k-section">9. Mutual Indemnification</h2>
    <p class="k-para">Each party agrees to indemnify, defend, and hold harmless the other party, including its officers, employees, contractors, and agents, from and against third-party claims, damages, liabilities, losses, costs, and reasonable attorneys&rsquo; fees arising from:</p>
    ${list(['Breach of this Agreement', 'Negligence or willful misconduct', 'Violation of applicable laws or regulations'])}`);

  // 10. GENERAL TERMS
  blocks.push(`<h2 class="k-section">10. General Terms</h2>
    ${list([
      'Venue reserves the right to remove any attendee behaving in a dangerous, illegal, or disruptive manner.',
      'Organizer agrees not to exceed legal occupancy limits.',
      'Outside vendors, decorators, and contractors require prior Venue approval.',
      'Written terms in this Agreement are binding over any verbal agreements or understandings.',
      'Any amendments to this Agreement must be made in writing and signed by both parties.',
    ])}`);

  // 11. NEXT STEPS / OPEN ITEMS
  const openBlockers = (data.blockers || []).filter((b) => ['open', 'waiting'].includes(b.status)).map((b) => esc(b.title));
  const openItems = openBlockers.length ? openBlockers : [
    'Final show timing and Run of Show',
    'Full band lineup',
    'All-ages vs. 21+ designation',
    'Backline requirements',
    'Staffing and security needs',
    'Production schedule and changeover timing',
    'Marketing assets and promotional rollout',
  ];
  blocks.push(`<h2 class="k-section">11. Next Steps / Open Items</h2>
    <p class="k-para">The following items remain subject to confirmation:</p>
    ${list(openItems)}`);

  // 12. SIGNATURES
  blocks.push(`<h2 class="k-section">12. Signatures</h2>
    <div class="k-party">For ${esc(event.venue_name || 'Venue')}</div>
    <p class="k-sign-line">Name: ${venueRep || blank}</p>
    <p class="k-sign-line">Signature: ${blank}</p>
    <p class="k-sign-line">Date: <span class="k-fill short"></span></p>
    <div class="k-party">For Organizer / Promoter</div>
    <p class="k-sign-line">Name: ${promoter || blank}</p>
    <p class="k-sign-line">Signature: ${blank}</p>
    <p class="k-sign-line">Date: <span class="k-fill short"></span></p>`);

  return `<div class="contract">${blocks.join('')}</div>`;
}


// QR Flyer — a bold door-poster for walk-up credit-card sales: show title in
// huge type, "Scan to Buy Tickets", a big scannable QR code straight to the
// event's public page (where in-house ticketing, if enabled, presents the
// purchase/checkout form), the price, doors time, and the band lineup with
// set times. No internal facts — this is meant to be printed big and taped
// up or held at the door.
function renderQrFlyer(data) {
  const event = data.event;
  const url = appUrl(data.links.public_page);
  const qrImage = appUrl(`assets/qr.png?text=${encodeURIComponent(url)}&size=500`);
  const lineup = (data.lineup || []).slice().sort((a, b) => (a.set_time || '99:99:99').localeCompare(b.set_time || '99:99:99'));
  const priceLabel = Number(event.ticket_price) > 0 ? money(event.ticket_price) : 'Free';
  const lineupItems = lineup.length
    ? lineup.map((item) => `<li>${esc(item.display_name || item.band_name || 'Untitled')}${item.set_time ? `<span class="qf-time">${esc(timeLabel(item.set_time))}</span>` : ''}</li>`).join('')
    : '<li class="empty">Lineup TBA</li>';
  // Long titles get a smaller font instead of wrapping to two lines — a two-line
  // title at 52pt would eat ~0.7in of the already-tight one-page budget.
  const title = String(event.title || '');
  const titleSize = title.length > 34 ? 30 : title.length > 24 ? 38 : title.length > 16 ? 44 : 52;

  return `<div class="qr-flyer">
    <h1 class="qf-title" style="font-size:${titleSize}pt;">${esc(event.title)}</h1>
    <div class="qf-scan-label">Scan to Buy Tickets</div>
    <div class="qf-qr-wrap">
      <img class="qf-qr" src="${esc(qrImage)}" width="300" height="300" alt="Scan to buy tickets">
    </div>
    <div class="qf-facts">
      Price: ${priceLabel}${event.doors_time ? `<br>Doors: ${esc(timeLabel(event.doors_time))}` : ''}
    </div>
    <h2 class="qf-lineup-head">Lineup:</h2>
    <ul class="qf-lineup">${lineupItems}</ul>
  </div>`;
}


function renderPrintBody(type, data) {
  switch (type) {
    case 'one-sheet':    return renderOneSheet(data);
    case 'contract':     return renderContract(data);
    case 'qr-flyer':     return renderQrFlyer(data);
    case 'lineup':       return renderLineupSection(data);
    case 'staffing':     return renderStaffingSection(data);
    case 'run-of-show':  return renderRunOfShowSection(data);
    case 'guest-list':   return renderGuestListSection(data);
    case 'master':       return [
      renderEventFactsSection(data),
      `<div class="page-break"></div>` + renderLineupSection(data),
      `<div class="page-break"></div>` + renderRunOfShowSection(data),
      `<div class="page-break"></div>` + renderStaffingSection(data),
      `<div class="page-break"></div>` + renderGuestListSection(data),
    ].join('');
    default:             return `<p class="empty">Unknown printout: ${esc(type)}</p>`;
  }
}


function openPrintWindow(type, data) {
  const title = PRINT_TITLES[type] || 'Printout';
  const win = window.open('', '_blank', 'width=900,height=1100');
  if (!win) {
    publish('toast.show', { message: 'Pop-up blocked — allow pop-ups to print.' });
    return;
  }
  const body = renderPrintBody(type, data);
  const docTitle = `${data.event.title} — ${title}`;
  win.document.open();
  win.document.write(`<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>${esc(docTitle)}</title>
  <style>${PRINT_CSS}</style>
</head>
<body>
  <div class="print-toolbar">
    <button type="button" onclick="window.print()" class="primary">Print</button>
    <button type="button" onclick="window.close()">Close</button>
  </div>
  <article class="sheet">
    ${type === 'one-sheet' || type === 'contract' || type === 'qr-flyer' ? '' : printHeader(data, title)}
    ${body}
    ${printFooter(data)}
  </article>
</body>
</html>`);
  win.document.close();
  win.focus();
}

export { openPrintWindow };
