# Aculyze-LM — What's Actually Live in Production

This document describes the Aculyze-LM sales CRM **as it behaves on the live
production site today**, not as the codebase's newest commits would suggest.
Production is deployed by hand (individual files uploaded to Hostinger, not a
full git checkout), so it lags behind the repository in two significant,
deliberate ways — both explained plainly wherever they matter, and
summarized in a final section. Everything else described here is live.

---

## 1. Prospects (the "Database")

The Database is the starting point of every sales relationship — a list of
companies/contacts. Every other module (Calls, Follow-Ups, Appointments,
Leads, Demos, Proposals) ultimately traces back to a Prospect.

**What a Prospect record holds:** company name, contact person, designation,
telephone, mobile, email, website, industry, source, full address (address,
locality, city, state, pincode), assigned employee, and free-text notes.

**Creating and editing:**
- A company can be added directly, or picked up automatically when a call
  is logged against a brand-new company (see Calls, below).
- Company records can be imported in bulk from an Excel spreadsheet: upload
  a file, confirm which row is the real header row (skipping any
  instructional rows above it), map spreadsheet columns to Prospect fields
  (the system guesses obvious matches automatically), and review it before
  committing. If a row's company name matches an existing Prospect, the
  import flags it as a possible duplicate and asks — per row — whether to
  skip it, or an "apply to all" option can resolve every remaining duplicate
  the same way in one step. Every row that isn't a duplicate is saved
  immediately, so the duplicate-review step never blocks the rest of the
  import.
- Any spreadsheet columns that don't map to a real Prospect field are not
  discarded — their values are appended into the Notes field, one line per
  column, so nothing from the source sheet is silently lost.

**Viewing a company:** opening a Prospect shows a tabbed page — an Overview
tab with the company's own details (contact info, address, assigned
employee, notes — shown in a clean two-column label/value layout, not a
disabled form), plus one tab each for that company's Call Records,
Follow-Ups, Appointments, Leads, and Proposals — every activity ever
recorded against this company, filterable by a shared date-period and (for
Senior Managers) an employee filter. A "Demos" activity tab exists in the
same list.

**List search:** typing into the Database list's search box ranks
"starts-with" matches on company name ahead of matches where the term only
appears elsewhere in the name — e.g. searching "corp" shows "Corp
Solutions Ltd" before "Acme Corp Industries" — with the normal sort order
used as a tie-breaker. The list can also be sorted by any column, and which
columns are visible/hidden is remembered per user.

**Navigation:** a "Back" button (in a distinct gold color, separate from the
blue Edit button) is available from the View, Edit, Create, and Import
pages, and goes back to wherever the user actually came from (real browser
history), falling back to the Database list if there's nowhere to go back
to (e.g. a bookmarked link opened fresh).

**Reassignment:** only a Senior Manager can assign or change who a Prospect
is assigned to — the assignment field is not editable by an ordinary
Employee or Manager. Reassigning a company only changes who is currently
responsible for it; it never rewrites who originally created the record or
who actually made any historical call against it.

**Deletion:** deleting is blocked with a friendly message (naming exactly
what's still attached) if the Prospect has any Call Records, Follow-Ups,
Appointments, Leads, or Proposals against it — nothing is ever silently
cascade-deleted.

---

## 2. Calls (the Activity Log)

Every phone call made against a Prospect is logged here as a Call Record —
this list **is** the company's full activity history; there is no separate
"activity log" anywhere else.

**Logging a call:** pick the company (typing to search — see "search
ranking" below — or use "+ Create new company…" to add a brand-new one
inline without leaving the form), the date/time called, and an **Outcome**.
The Outcome dropdown shows, at the right of each option, a small badge
indicating what that outcome will do next — for example "Callback
Requested" shows "→ Follow-Up", "Appointment Set" shows "→ Appointment",
"Requirement Identified" shows "→ Lead", "No Answer"/"Switched
Off"/"Not Reachable"/"No Current Requirement" show "Stays at Call", and
"Others" shows "You choose next action". This badge is generated directly
from the same routing rule the system actually uses — it can never drift
out of sync with what really happens.

**What each outcome actually does (fully automatic, happens once, the
moment the call is saved):**
- **Callback Requested** → always creates a Follow-Up.
- **Concerned Person Not Available** / **Profile Requested** → creates a
  Follow-Up only if a specific follow-up date was actually given; otherwise
  the call is just logged with no automatic next step.
- **Appointment Set** → creates an Appointment.
- **Requirement Identified** → creates a Lead.
- **No Answer**, **Switched Off**, **Not Reachable**, **No Current
  Requirement** → nothing further is created; the call itself is the only
  record.
- **Others** → the caller must explicitly pick what happens next (no
  further action, create a Follow-Up, or create an Appointment) — there is
  no silent "nothing happens" for this outcome.

Editing a saved call afterward never re-triggers this routing — it only
ever runs once, at the moment of creation, so retrying or re-saving a call
can never create a duplicate Follow-Up/Appointment/Lead.

**Mandatory fields depend on the outcome:**
- Notes are required for every outcome except the three "never actually
  spoke to anyone" outcomes (No Answer, Switched Off, Not Reachable).
- Contact Person, Designation, and Phone Called are required for every
  outcome where something meaningful happens next (Callback Requested,
  Concerned Person Not Available, Profile Requested, Appointment Set,
  Requirement Identified, No Current Requirement) — optional for the three
  "never connected" outcomes and for Others.
- Profile Requested additionally opens a "Profile Sent" section asking
  whether/how/when the company profile was actually sent, with notes
  required if the method was "Other."

These rules are enforced both in the form (so a user sees the requirement
immediately) and again at the database level, so no route — including one
that bypasses the visible form — can save an invalid call.

**Search ranking:** both the Calls list's own search box and the Company
picker inside "Log a Call" rank company names that *start with* the typed
term ahead of names where the term merely appears somewhere else in the
name, within the same result list — the same behavior as the Database
list's search.

**The Call's View page** shows every field the record holds in a clean,
read-only two-column layout (label on the left, value on the right — no
disabled dropdowns or greyed-out boxes), grouped into: Call Details
(Company, Called At, Called By, the Outcome as a colored badge, and Next
Action when relevant); Contact & Follow-Up (Contact Person, Designation,
Phone Called, Follow-Up At, Appointment At — a dash shown for anything not
set); Profile Sent (only shown at all when the outcome is Profile
Requested); and Notes & Review (Notes — preserving any line breaks exactly
as typed — plus a correction reason/date and a flag reason/date, each shown
only once it actually applies).

**Correcting a mistaken outcome:** if a call's outcome was logged wrong and
nothing real has happened downstream yet (no Follow-Up/Appointment/Lead
exists from it), "Correct Outcome" lets an authorized user pick the right
outcome, give a reason, and re-route it properly — the original wrong
routing is undone and the correct one runs instead. Once a downstream
record *does* exist, Correct Outcome is no longer offered.

**Flag as Incorrect:** for a call whose outcome already created a real
downstream record, the fix instead is to flag it (with an optional reason)
for manual review — this never deletes or changes anything by itself, it
only marks the call for a Senior Manager to look at.

**One-click chain delete:** for a flagged call, a Senior Manager can view
the complete downstream chain it created (e.g. Call → Follow-Up →
Appointment) and, if — and only if — every single link in that chain is
itself free of further history, delete the entire chain in one action. If
any link further down the chain has its own history attached (for example
a Lead that already has a Proposal), the one-click delete is refused
entirely and the screen explains exactly which link is blocking it — there
is no partial delete.

**List and table:** the Outcome column shows the same colored badge/label
used everywhere else; "Called By" is shown only to Senior Managers (an
Employee only ever sees their own name there anyway); Follow-Up/Appointment
dates and Notes are optional columns a user can show or hide; a small
flag icon indicates whether a call has been flagged for review. Delete and
"Deselect all" appear as their own separate buttons rather than tucked into
a dropdown menu. Only a Senior Manager can delete a Call Record outright,
and only when it has no downstream history (the same RESTRICT-style
protection every module uses — see Deletion Safeguards below).

**Button colors:** Create/Save actions, Cancel, View, and Export/Import use
a consistent, deliberately distinct color scheme across the Calls pages so
each action type is visually recognizable at a glance (e.g. Export always
matches the color of "Import from Excel," rather than each page inventing
its own scheme).

---

## 3. Follow-Ups

The Follow-Ups panel exists to make sure a promised callback never gets
forgotten. It's mostly populated automatically — a call outcome of No
Answer, Switched Off, Not Reachable, or Callback Requested style
routing (see Calls, above) creates one — but a Follow-Up can also be
created directly by hand.

**Resolving a Follow-Up** happens one of two ways:
- **Completed** — the retry call finally reached the company. This logs a
  brand-new real Call Record and routes it through the exact same
  outcome-based routing every other call goes through (so a "Completed"
  Follow-Up can itself go on to create another Follow-Up, an Appointment,
  or a Lead, depending on what actually happened on the retry).
- **Close** — giving up on this one. This just archives it; no new activity
  is created.

An optional **Contact Mode** (how contact was attempted/made) can be
recorded on a Follow-Up.

Only a Senior Manager sees the option to delete a Follow-Up outright;
ordinary users don't see Delete here at all.

---

## 4. Appointments (the Appointment Call Sheet)

Appointments track the scheduled-meeting stage of the sales process.

**Fields:** company, assigned employee (only a Senior Manager can change
who it's assigned to), the appointment date/time, a stage
(Appointment Made → Visit Conducted → Discussion Completed → Succeeded /
Not Succeeded), meeting notes, and outcome notes (required once the stage
reaches a final Succeeded/Not Succeeded state).

**Rescheduling:** once an appointment date/time is actually set, it can't
be silently changed via the ordinary Edit form — a dedicated "Reschedule"
action is the only way to move it, keeping a clear record that it was
rescheduled rather than quietly edited. A date that was never set yet (e.g.
auto-created from a call before an exact time was agreed) can still be
filled in normally the first time.

**Recording the outcome:** the only way to move a Scheduled appointment to
a real conclusion is the "Record Outcome" action, which requires notes and
one of: Follow-Up Required (creates a Follow-Up), Requirement Identified
(creates a Lead), Another Appointment Required (creates a new
Appointment), Demo Required or Proposal Required (both require picking
which Lead this relates to, then create a Demo or a Proposal respectively),
or No Current Requirement (closes it out with no further action). Marking
an appointment "Not Succeeded" also records it as Lost, with the reason
preserved and visible on the record afterward.

---

## 5. Leads (the Lead Sheet)

A Lead represents a qualified opportunity — created automatically the
moment a call's outcome is "Requirement Identified" (or from an
Appointment/Demo outcome that identifies one), or created by hand.

**Fields:** company, assigned employee, stage (Requirement Collection →
Demo Scheduled/Done → Validated), temperature (how warm the opportunity
is), requirement details, and notes.

**Validated requires Notes:** a Lead cannot be saved in the "Validated"
state without Notes/Remarks — this is enforced everywhere, not just in the
visible form.

**Updating status:** business progress is tracked through a dedicated
"Update Status" action (Requirement Collection, More Information Required,
Requirement Confirmed, Follow-Up Required, Appointment Required, Demo
Required, Proposal Required, or No Current Progression) rather than by
hand-editing the legacy stage field, which becomes read-only once a Lead
exists.

**Stale alerting:** a Lead that hasn't moved in 30 days is flagged as
stale (surfaced on the dashboards) — unless it's in a status considered
closed/terminal for this purpose.

**Creating a Proposal from a validated Lead:** choosing "Proposal Required"
(or the equivalent Appointment/Demo outcome) creates a brand-new Proposal
for that Lead, starting in the "Being Prepared" stage — one Proposal per
Lead; a second attempt against the same Lead reuses the existing one rather
than creating a duplicate.

---

## 6. Demos

A Demo is a scheduled product walkthrough, created from a Lead (directly,
or via an Appointment's "Demo Required" outcome).

**Fields:** the related Lead/company, assigned employee, date/time, mode
(On-Site or Online), and a meeting link when applicable.

**Recording the outcome** is the only way to close out a Scheduled demo,
and follows a fixed rule table:
- **Another Demo Required** → automatically schedules a new Demo.
- **More Time For Discussion** → automatically creates a Follow-Up.
- **Requirement Clarification Needed** → automatically sends the Lead back
  to Requirement Collection.
- **Proposal Required** → automatically creates a Proposal for the Lead.
- **Not Interested / No Progression** → closes it out, no further action.
- **Interested, OK** and **Correction Needed** are open-ended — the user
  picks what happens next from the same set of options above.
- **Other** requires notes and also asks what happens next.

"Correction Needed" additionally requires correction/customer comments to
be recorded.

---

## 7. Proposals

A Proposal is the commercial offer made once a Lead has been validated.

**Fields:** the Lead/company it belongs to, assigned employee, a Proposal
Stage (Being Prepared → Sent → Customer Accepted / Customer Rejected), a
separate final Outcome (Won / Hold / Lost — set alongside certain stage
moves), and a monetary value.

**One Proposal per Lead** is enforced — a Lead cannot end up with two
independent Proposals.

**Stage vs. Outcome** are two different things shown separately: Stage
tracks the internal preparation/sending process; Outcome is the final
Won/Hold/Lost business decision. A Proposal can be in any stage while its
outcome is still unset (still in progress).

**Stale alerting:** a Proposal that hasn't had its stage — or its outcome —
change in 20 days is flagged stale, unless it's Won or Lost (always
considered closed) or Hold (configurable; currently still counts toward
staleness like any active Proposal).

**Editing:** the Stage and Outcome fields, and the Value, are directly
editable on the Proposal's own form by an authorized user — there is no
separate multi-version commercial document, PDF generation, formal
send/release approval step, or customer-response recording workflow. A
Proposal is a single record that gets updated in place as it progresses.

**Deleting a Proposal** is blocked if it's tied to further downstream
history, following the same RESTRICT-style protection as every other
module.

---

## 8. Pipeline Board

The Pipeline Board is a single visual Kanban-style working surface showing
the whole active sales pipeline at once, in six lanes, left to right:
**Calls | Follow-Ups | Appointments | Leads | Demo | Proposals.**

**Cards and lanes:** each lane shows the relevant records as cards; each
card shows a small badge for its own current stage/status. A period filter
(Today/Week/Month/Quarter) and, for Senior Managers, an employee filter
apply across the whole board.

**Dragging a card** to a different stage/box always opens a confirmation
dialog first — nothing moves immediately just from the drag gesture — and
asks for whatever information that specific move needs (for example,
dragging a Proposal card onto "Customer Accepted" or "Customer Rejected"
records the Won/Lost outcome and any final notes right there; dragging it
onto "Sent" can attach files, a value, and a sent date). Cancelling, or any
failure while saving, leaves the original record completely unchanged.

**Dragging a Call card** opens a single call-logging dialog — the same
Outcome dropdown (with the same routing badges), Contact fields, Notes, and
Profile Sent section as the ordinary "Log a Call" form — and logs a new
real Call Record for that company through the exact same routing every
other call goes through. It is not a set of separate, destination-specific
dialogs — one form handles every outcome.

**Marking Lost:** dragging a Lead or Appointment card onto its board's
"Lost" area asks for a reason and marks it Lost — a one-way action (a Lost
record can't be dragged back to an active stage from the board; it has to
be reopened from its own page if that's ever needed).

**In-board editing:** every card can be opened for a full View or Edit
directly from the board, without navigating away to that module's own list
page.

> **Note on "Pipeline Board V2":** a newer, more elaborate Pipeline Board
> redesign exists in the codebase — separate pop-up dialogs specific to
> each Calls destination (Follow-Up/Appointment/Lead) with extra fields
> like Appointment Mode, Person Meeting, Location, and a Lead Opportunity
> Title — but **this redesign is not live.** The database changes it needs
> were never applied to production, and the corresponding application code
> was deliberately kept off production after an earlier attempt to deploy
> it broke the "Appointment Set" call-logging flow site-wide. What's
> described above (the single generic Call dialog, no extra
> mode/location/opportunity-title fields anywhere) is what actually runs
> today.

---

## Roles and Permissions

Three tiers, enforced on the server for every screen and action — never
just by hiding a button:

- **Employee** — sees and manages only their own records (the calls they
  made, the Prospects/Leads/Appointments/Follow-Ups/Proposals assigned to
  them).
- **Manager** — sees and manages their own records plus their direct
  reports'.
- **Senior Manager** — sees and manages every record in the organization.
  Also an active salesperson in their own right (their own dashboard shows
  their own numbers, same as everyone else), plus exclusive access to:
  assigning/reassigning Prospects, deleting sales history (Calls,
  Follow-Ups, Appointments, Leads, Proposals), reviewing flagged calls and
  their one-click chain delete, and the company-wide Main Dashboard.

Typing a different record's URL directly never bypasses these rules — an
Employee hitting another employee's page gets refused, not shown a
stripped-down version of it. Each organization's data is also fully
isolated from every other organization's.

---

## Deletion Safeguards

Nothing in this system cascades a delete. Every relationship between
records is protected: attempting to delete a Prospect, Call, Follow-Up,
Appointment, Lead, or Proposal that still has real dependent history
attached is refused with a plain-language message naming exactly what's
still attached, instead of a cryptic error or a silent cascade. Real sales
history is never destroyed as a side effect of deleting something else.

---

## Export and Import

**Importing:** company records can be bulk-imported from an Excel file
into the Database (see Prospects, above) — header-row confirmation, column
mapping with automatic guesses, and per-row duplicate resolution.

**Exporting:** a Senior Manager can export any list (Calls, Follow-Ups,
Appointments, Leads, Proposals) to CSV immediately. An ordinary Employee or
Manager instead submits a Request Export (with the same filter criteria
options) for a Senior Manager to review and approve or deny — every
request and its decision is kept as a visible record, and a duplicate
pending request for the same criteria is caught rather than creating a
second one.

---

## What's Not Live (and Why)

Two significant bodies of work exist in the codebase but do **not** run on
production today:

1. **Pipeline Board V2** — the destination-specific Calls drag dialogs
   described above under Pipeline Board, plus the extra fields
   (Appointment Mode/Person Meeting/Location, Lead Opportunity Title) they
   would have added to Appointments and Leads. The database changes this
   needed were never applied.

2. **A fuller commercial Proposal workflow** — a multi-version proposal
   document system with PDF generation, a formal
   submit/approve/release/send process, and a structured way to record what
   a customer said in response — has been built but has not been deployed.
   Proposals today work exactly as described in the Proposals section
   above: a single record, directly editable, no versioning or PDF
   involved.

If a future feature request references "the new Proposal versions," "PDF
proposals," "sending a proposal for approval," or "the new Calls board
dialogs," none of that exists for end users yet, regardless of what the
underlying code can already do.
