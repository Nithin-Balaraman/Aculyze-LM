# Open business decisions

Decisions the business still owes the codebase. Each entry states the
**current behaviour** (what the code does today), the **known consequence**
of that behaviour, and the **decision needed** — so nobody has to
reverse-engineer the trade-off from the code later.

Nothing here is a bug report. Each item is a deliberate, documented position
taken because the alternative would have required inventing a business rule.

Questions about *provisional naming* and *metric definitions* live in
`AGENTS.md` section 61 instead. This file is for decisions with a behavioural
consequence that is already visible in production use.

---

## OPEN-1 — Appointment / Follow-Up employee offboarding retention

**Current behaviour**

`App\Services\EmployeeDeletionService` hard-deletes an offboarded employee's
assigned Appointments and Follow-Ups. Proposals, Demos, Call Records and the
Leads carrying commercial or Demo history are all preserved and handed to the
replacement instead — Appointments and Follow-Ups are the two record types
still deleted.

**Known consequence**

`appointments.rescheduled_from_id` and `follow_ups.rescheduled_from_id` are
self-referencing RESTRICT foreign keys. If the employee owns *both* halves of
a rescheduled chain, the bulk delete violates that constraint. The
transaction rolls back cleanly and the admin sees a friendly message — no raw
SQL error, no partial change, nothing lost — but **that employee cannot be
offboarded at all** until someone reassigns the chain by hand.

This is verified behaviour, not a theory: it was reproduced directly for both
Appointment and Follow-Up chains during the deletion-guard completion sweep.

**Decision needed**

Should Appointments and Follow-Ups be preserved and reassigned like Demos and
Proposals, or should the current hard-delete stand?

- *Preserve* is consistent with how every other history type is now treated,
  and removes the offboarding dead end entirely.
- *Keep deleting* is defensible if a scheduled meeting that never happened is
  considered scratch rather than history — but then the reschedule-chain dead
  end needs its own answer.

**Not decided unilaterally because** it changes what "delete this employee's
records" means to an admin who has been using the current behaviour, and the
same reasoning would apply retroactively to existing data.

---

## OPEN-2 — `origin_type` / `origin_id` lineage retention

**Current behaviour**

Follow-Ups, Appointments and Demos each carry a polymorphic
`origin_type`/`origin_id` pair recording which prior activity caused them to
be created — a completed Appointment whose outcome was "Schedule a Demo", for
example. Being a morph, it has **no foreign key**, so nothing at the database
level prevents the origin record from being deleted.

**Known consequence**

Deleting an activity that another activity names as its origin leaves a
dangling lineage pointer. There is no error and no visible failure at delete
time; the descendant simply can no longer resolve where it came from, so its
History view loses a link in the chain.

Note this is *not* the reschedule linkage, which is a real RESTRICT foreign
key on all three tables and is already fully guarded. The two concepts are
deliberately separate (`rescheduled_from_id` = this record replaced that one;
`origin_type`/`origin_id` = this record was the next business action after
that one).

**Decision needed**

Should deletion be blocked when another activity references the record as its
origin?

- *Block* treats lineage as history, consistent with `AGENTS.md` section 59,
  and would extend each model's `deletionBlockers()` to count descendants.
- *Allow* accepts that lineage is best-effort context rather than a record in
  its own right — in which case the History view should say "origin no longer
  available" rather than rendering a broken link.

**Not decided unilaterally because** unlike the reschedule chains, no database
constraint forces an answer here. Blocking would be a new restriction on
deletions that work fine today, and that is a product call, not a technical
inevitability.

---

## How to close an item

1. Record the decision and its date in this file, replacing the "Decision
   needed" section with "Decision taken".
2. Implement it with tests that encode the decision, not the implementation.
3. Update `AGENTS.md` if the decision creates or changes a numbered rule.
4. Keep the entry — a closed decision with its reasoning is more useful later
   than a deleted one.
