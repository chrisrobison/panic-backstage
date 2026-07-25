# Mabuhay Backstage — Staff Training Lesson Plan
## Session: Introduction & Core Workflow
**Duration:** ~3–3.5 hours | **Audience:** New staff (all roles)

---

## Pre-Training Checklist (You — before everyone arrives)
- [ ] Each trainee has a login link / magic-link email sent to them
- [ ] At least one test event pre-created in the system (a "Training Night" hold)
- [ ] At least one test inquiry sitting in the **Booking Inbox** unassigned queue (send a test
      email to `bookings@themab.org`, or add one manually) for the claim/reply demo in Module 3
- [ ] TV/projector showing the live app at the dashboard
- [ ] Ops manual URL written on whiteboard: `docs/ops-manual.html`

---

## Module 1 — Orientation (20 min)

**Goal:** Everyone knows what the system is and why it exists.

### Topics
- What is Mabuhay Backstage? ("One place where every show lives, from first inquiry to final settlement")
- How to log in — magic link email flow, no passwords to remember
- Tour of the interface: sidebar, dashboard, calendar view
- The **Getting Started checklist** on the dashboard — what each item means and who's responsible for completing it (venue admin only, but good for everyone to know)

### Talking Points
- "Everything that used to live in spreadsheets, texts, and sticky notes lives here"
- "Your role determines what you can see and do — we'll cover that shortly"

### Hands-On
> Everyone logs in. Confirm the dashboard loads. Point out the checklist items.

---

## Module 2 — The Event Lifecycle (45 min)

**Goal:** Staff understands how a show moves from idea to close.

### The Status Pipeline
Walk through each stage on a whiteboard or in the app:

| Stage | What it means |
|---|---|
| **Hold** | Date is being considered; not confirmed |
| **Intake Complete** | Details filled in, contract not yet signed |
| **Booked** | Contract signed, show is confirmed |
| **Settled** | Post-show accounting is done |

### Topics
- Creating a new event (walk through the form fields together)
- Filling in: artist, date/time, event type, capacity, ticket price
- Attaching a **contract template** and sending for e-signature
- What the artist sees when they get the signing link
- Watching the audit log update as they sign

### Hands-On
> Create a new "Training Night" event as a group. Walk it from Hold → Intake Complete. Assign it a contract template. Don't send (or send to a test address).

---

## Module 3 — The Booking Inbox: Claiming & Working Inquiries (30 min)

**Goal:** Everyone knows how an inbound inquiry gets safely picked up, worked, and handed off —
without two people replying to the same person, or an inquiry quietly disappearing into someone's
personal inbox.

### The Core Idea
- "Every inquiry belongs to the venue, not to whoever happened to see it first."
- All booking inquiries — email to `bookings@themab.org`, the website form, phone, manual entry —
  land in one shared **Booking Inbox**, not a personal mailbox. Nobody can hide, permanently
  delete, or privately redirect one.
- **Leads** (the pipeline you may already know) and **Inbox** are two views of the same inquiry:
  Leads is where you evaluate the deal; Inbox is where you actually work it day to day — read
  messages, claim it, reply, hand it off.

### Assigned vs. Claimed vs. Owned — three different things
| Term | What it means |
|---|---|
| **Assigned** | The system (or a manager) has pointed the inquiry at you. You haven't started yet. |
| **Claimed** | You've clicked **Claim** — you're actively working it right now. Only one person can hold an active claim. |
| **Owned** | Set automatically once you send the first real reply (or a manager assigns it long-term). Survives future claim changes. |

### Topics
- The left-nav **Inbox** views: **My Inquiries**, **Unassigned**, **All Inquiries**, **Follow Up**,
  **Archived** — and the badge counts next to each.
- Opening an inquiry: the AI **classification** panel (event type, genre, attendance, budget, a
  confidence score) and the **routing explanation** ("Routed to Kathy because... 94% confidence") —
  this is a suggestion trail, not something that changed anything on its own; a human always
  claims and decides.
- Clicking **Claim** — and the countdown that appears ("Claim expires in 37 minutes"). If nobody
  claims an assigned inquiry in time, or a claimed one goes unanswered too long, it automatically
  returns to the queue so it can't quietly die in someone's queue.
- Replying through the Conversation tab — every outbound message goes out looking like it's from
  the venue (`bookings@themab.org`), never your personal account, and is logged for everyone to see.
- **Duplicate-reply protection:** if you have the reply box open and a message arrives that changes
  the conversation, sending is blocked until you review it — and if a teammate is drafting a reply
  right now, you'll see "**Kathy is currently drafting a reply**" so you don't step on each other.
- **Internal notes** vs. replies — notes are for staff only and never go to the customer.
- The green **Onboard Lead** button: turns a promising inquiry into a real event opportunity. It
  checks for duplicate/conflicting bookings first, lets you fix any AI-extracted details, and
  creates the event at **Proposed** status — onboarding is a handoff, not a "booked" confirmation.
- What gets logged: literally everything (claims, replies, status changes, reassignments) — visible
  on the inquiry's **History** tab.

### Who can do what (quick version — full detail in Module 5)
- **Trusted staff** (bookers/managers) can see and claim across the whole pipeline.
- A **restricted external booker** — e.g. an outside promoter helping triage — only sees full
  detail on inquiries assigned or shared with them, can't see the raw mailbox, can't export
  contact lists, and can't decline/archive a high-value inquiry without a manager's approval.

### Hands-On
> As a group, open the **Unassigned** queue and find the test inquiry. Have one trainee claim it
> and point out the countdown. Send a reply from the Conversation tab (to a test address) and show
> how it's logged. Open the **Onboard Lead** dialog together and walk through the review screen —
> don't actually submit it unless you want a throwaway test event.

---

## Module 4 — Night-Of Workflows (30 min)

**Goal:** Door and floor staff know exactly what to do on show night.

### Topics
- Opening the **Run Sheet** — what's in it (schedule, staff, notes)
- The **Guest List** — how to look up a name, check in manually
- The **QR ticket scanner** (`/scanner.html`) — scan tickets from any phone browser, no app install needed
  - Open the scanner URL on your phone right now
  - How a valid vs. invalid scan looks/sounds
- During the show: logging notes, updating headcount

### Hands-On
> Pull up `scanner.html` on everyone's phones. Scan a test ticket QR code. Show what a success and a failure look like.

---

## Module 5 — Roles & Permissions (15 min)

**Goal:** Everyone knows what their account can and can't do — and who to ask when they hit a wall.

### Role Summary
| Role | Can do |
|---|---|
| **Venue Admin** | Everything — settings, users, templates, settlement, routing rules |
| **Booker** (Trusted booker) | Create/edit events, manage contracts, claim/reassign/onboard any Booking Inbox inquiry |
| **Manager** | Edit events, run sheets, day-of ops, approve high-value declines |
| **Door Person** | Guest list + scanner only |
| **Restricted External Booker** *(e.g. an outside promoter)* | Claim and work only inquiries assigned/shared with them; can't see the raw mailbox, export contact lists, change routing rules, or decline a high-value inquiry without manager approval |

### Topics
- Where to find your own role (profile/account settings)
- How to invite a new staff member (admin only — show where)
- Why the Restricted External Booker role exists: it lets an outside promoter help triage and
  respond to inquiries in their own genre/lane without ever touching the venue's actual email
  account or seeing the full customer list
- What to do if you can't access something you think you should

---

## Module 6 — Post-Event & Settlement (20 min)

**Goal:** Managers/admins know how to close out a show properly.

### Topics
- After the show: **auto-archive** timing — the system sets Settled automatically after a window, or you can do it manually
- Filing **settlement** — what data to enter (attendance, bar, ticket revenue)
- Marking the event **Settled**
- The **Activity Log** — your audit trail for anything that happened on an event

### Hands-On
> Walk through the settlement fields on the Training Night event. Don't save — just show where everything goes.

---

## Module 7 — Q&A + Reference Resources (20 min)

### Where to Get Help
- **Ops Manual** — `[your-domain]/docs/ops-manual.html` — full reference, bookmark it
- **Booking Inbox reference** — `[your-domain]/docs/training.html` (this document) and
  `docs/booking-inbox.md` for the deeper architecture/API notes
- **The activity log** on any event, or the **History** tab on any inquiry — shows who did what
  and when
- **Admin contact:** [your name / email]

### What We're NOT Covering Today
Let trainees know these exist for later:
- **Social Queue** — the multi-channel social-post approval/publishing workflow that follows an
  onboarded inquiry (lives inside Panic Promote — Chapter 4 in ops manual)
- **Routing rules administration** — how a venue admin edits/publishes the rules that auto-route
  inquiries (covered in the admin-only follow-up session)
- **In-house ticketing** setup and payment processing (Chapter 5)
- **Event templates** for repeating shows
- **Google Sheet sync** for reporting

---

## Quick-Reference Card (Hand Out or Share)

| Task | Where |
|---|---|
| Log in | Magic link to your email |
| View all shows | Dashboard → event list or calendar |
| Create a show | Dashboard → **+ New Event** |
| Work an inquiry | **Inbox** → My Inquiries / Unassigned → open it |
| Claim an inquiry | Open the inquiry → **Claim** button |
| Reply to an inquiry | Inquiry → Conversation tab → Reply |
| Turn an inquiry into an event | Inquiry → bottom action bar → **Onboard Lead** |
| Send a contract | Event → Contracts tab → Send for signature |
| Night-of scanner | Go to `[url]/scanner.html` on any phone |
| Guest list | Event → Door tab |
| Settle a show | Event → Settlement tab |
| Add staff | Admin → Users → Invite |
| Full manual | `[url]/docs/ops-manual.html` |
| Booking Inbox reference | `[url]/docs/training.html` · `docs/booking-inbox.md` |

---

## Tips for Delivery

1. **Do it live, not slides.** People learn the app by watching someone use it, then doing it themselves.
2. **One test event, many hands.** Have each person create one action (add a task, check in a guest) so they get muscle memory.
3. **End with the scanner demo** — it's the most immediately tactile part and ends the session on a high note.
4. **Roles first, details second** — if someone knows what their job is, they'll naturally focus on the relevant modules.
