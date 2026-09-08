# PHASE4_OUTCOME_CUTOVER_GATE

**Status: OPEN**

Nothing in this document is satisfied yet. The gate closes only when all ten
items below are done, verified and signed off — and closing it is Phase 4A-3
work, explicitly out of scope for anything before that.

## What this gate is

Aculyze-LM currently runs **two** ways of recording how a Proposal ended:

| System | Where it lives | Status |
|---|---|---|
| **Legacy** | `proposals.outcome` (Won / Hold / Lost), edited directly on the Proposal form and written directly by the Pipeline Board | Still authoritative |
| **Commercial** | `proposal_versions.lifecycle_status` plus `proposals.winning_version_id` | Built, but not yet the source of truth for outcome |

That coexistence is deliberate, not an oversight. Phase 4A-1 and 4A-2 built
the commercial Version layer without disturbing the legacy outcome system, so
the two can run side by side while the client-response model is designed. A
Proposal can legitimately carry a legacy `Won` outcome while its current
Version is still a `Draft`, and `winning_version_id` can legitimately be null
on a Won Proposal.

Because that is genuinely confusing for day-to-day users, every Proposal
screen names which system it is showing — "Proposal Stage", "Proposal
Outcome" and "Commercial Version Status" are distinct, explicit labels (see
`AGENTS.md` section 25). Clarity is the mitigation; the cutover below is the
fix.

## Where the gate is referenced today

The gate exists in the code as prose in two service docblocks
(`ProposalCreationService`, `ProposalVersionWorkflowService`) and in the name
of a regression test that asserts the two systems still coexist untouched:
`ManageCommercialVersionHistoryAndRegressionTest::test_phase4_outcome_cutover_gate_remains_open`.

That test is the tripwire. It should fail — loudly and deliberately — on the
day someone starts the cutover, and be rewritten as part of item 10 rather
than deleted.

## Closure checklist

Every item must be complete before the gate can be marked CLOSED.

### 1. Append-only client response model tied to the exact Sent Version
- [ ] A client response is recorded against the specific `ProposalVersion`
      that was actually sent, never against the Proposal in the abstract.
- [ ] Responses are append-only: a new response never edits or deletes a
      prior one.
- [ ] A response cannot be attached to a Version that was never Sent.

### 2. Accepted → Won, with the exact winning Version and its value
- [ ] Accepting a Sent Version sets `proposals.outcome = Won`.
- [ ] The same operation sets `winning_version_id` to that exact Version.
- [ ] The Proposal's recorded value is taken from that Version's own frozen
      grand total, not re-entered by hand.
- [ ] Won is unreachable without a winning Version once the gate is closed.

### 3. Revision Requested → preserve the Sent Version, create a new Draft
- [ ] The Sent Version is preserved exactly as sent — never edited in place.
- [ ] A new Draft Version is created from it, following the existing
      `createRevision()` supersede semantics.
- [ ] The Proposal does not become Lost or Won as a side effect.

### 4. More Time → Hold, with exactly one required Follow-Up
- [ ] Sets the Proposal to Hold.
- [ ] Creates exactly one Follow-Up — not zero, not two.
- [ ] The Follow-Up is mandatory, not optional, and is linked back to the
      Proposal through the existing origin lineage.

### 5. Rejected → Lost, with a reason
- [ ] Sets the Proposal to Lost.
- [ ] A reason is required and stored; a blank or whitespace-only reason is
      rejected.

### 6. Other → notes plus an explicit next action
- [ ] Notes are required.
- [ ] An explicit next action must be chosen — "Other" may never be a dead
      end that leaves the Proposal in an undefined state.

### 7. Remove uncontrolled generic `Proposal.outcome` editing
- [ ] `outcome` is no longer a freely editable Select on the Proposal form.
- [ ] Outcome changes flow only through the client-response model above.
- [ ] Any remaining write path is deliberate, named, and covered by a test.

### 8. Replace direct Pipeline Board Won/Lost writes
- [ ] The board no longer writes `outcome` directly.
- [ ] Board interactions that used to set Won/Lost route through the same
      service as every other outcome write.
- [ ] Board grouping behaviour is considered separately from outcome
      authorship — changing one must not silently change the other.

### 9. Repository-wide audit of final outcome writes
- [ ] Every runtime path that writes `proposals.outcome`,
      `winning_version_id`, or `proposals.value` is enumerated.
- [ ] Each is either routed through the new model or explicitly justified in
      writing.
- [ ] A codebase-shape test prevents new uncontrolled writers appearing —
      the same technique `TenancyBypassUsageTest` already uses for
      `OrganizationScope` bypasses.

### 10. Enforce Won → `winning_version_id` integrity in service *and* database
- [ ] The service refuses to record Won without a winning Version.
- [ ] A database-level CHECK constraint (or equivalent) enforces the same
      invariant, so a direct SQL write cannot violate it.
- [ ] Existing rows are audited and migrated before the constraint is added —
      a Proposal that is Won today with a null `winning_version_id` is
      legitimate pre-cutover data and must be resolved, not silently broken.
- [ ] `test_phase4_outcome_cutover_gate_remains_open` is rewritten to assert
      the closed state.

## Explicitly NOT part of this gate

- Renaming or reordering `ProposalStage` (`AGENTS.md` section 61, Question 3).
- Changing what "stale" means for a Proposal (section 27, Question 6).
- Anything in `docs/OPEN_BUSINESS_DECISIONS.md` — those are independent.

## Sign-off

| Item | Done | Verified by | Date |
|---|---|---|---|
| 1 | ☐ | | |
| 2 | ☐ | | |
| 3 | ☐ | | |
| 4 | ☐ | | |
| 5 | ☐ | | |
| 6 | ☐ | | |
| 7 | ☐ | | |
| 8 | ☐ | | |
| 9 | ☐ | | |
| 10 | ☐ | | |

**Gate status: OPEN.** Do not mark this CLOSED until every box above is
ticked and the full suite passes with the rewritten tripwire test.
