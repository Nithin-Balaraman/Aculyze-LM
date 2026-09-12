# PHASE4_OUTCOME_CUTOVER_GATE

**Status: CLOSED** (Phase 4A-3.5)

All ten items below are done and verified. The new Phase 4A-3 workflow
(`ProposalClientResponseService`, `ProposalSendService`) is now the ONLY
supported writer of Proposal commercial lifecycle/outcome state. Legacy
direct-mutation routes (the generic Filament stage/outcome form, Pipeline
Board drag handlers) were removed or made unreachable in Phase 4A-3.5, and a
database-level CHECK constraint (`proposals_won_requires_winning_version`)
now enforces "Won requires a winning Version" independently of the
application layer. See the Phase 4A-3.5 implementation report for the full
inventory, the classified anti-bypass search, and the exact migration.

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
(`ProposalCreationService`, `ProposalVersionWorkflowService`) — describing
the now-closed pre-cutover coexistence period, kept as historical context —
and in `tests/Feature/ProposalOutcomeCutoverGateTest.php`, the dedicated
Phase 4A-3.5 tripwire suite that fails loudly if any of the closure
conditions below are ever quietly reopened.

The former tripwire,
`ManageCommercialVersionHistoryAndRegressionTest::test_phase4_outcome_cutover_gate_remains_open`,
was rewritten (per item 10) to
`test_phase4_outcome_cutover_gate_is_closed` and now asserts the CLOSED
state instead of documenting the old OPEN coexistence.

## Closure checklist

Every item must be complete before the gate can be marked CLOSED.

### 1. Append-only client response model tied to the exact Sent Version
- [x] A client response is recorded against the specific `ProposalVersion`
      that was actually sent, never against the Proposal in the abstract.
- [x] Responses are append-only: a new response never edits or deletes a
      prior one.
- [x] A response cannot be attached to a Version that was never Sent.

### 2. Accepted → Won, with the exact winning Version and its value
- [x] Accepting a Sent Version sets `proposals.outcome = Won`.
- [x] The same operation sets `winning_version_id` to that exact Version.
- [x] The Proposal's recorded value is taken from that Version's own frozen
      grand total, not re-entered by hand.
- [x] Won is unreachable without a winning Version once the gate is closed.

### 3. Revision Requested → preserve the Sent Version, create a new Draft
- [x] The Sent Version is preserved exactly as sent — never edited in place.
- [x] A new Draft Version is created from it, following the existing
      `createRevision()` supersede semantics.
- [x] The Proposal does not become Lost or Won as a side effect.

### 4. More Time → Hold, with exactly one required Follow-Up
- [x] Sets the Proposal to Hold.
- [x] Creates exactly one Follow-Up — not zero, not two.
- [x] The Follow-Up is mandatory, not optional, and is linked back to the
      Proposal through the existing origin lineage.

### 5. Rejected → Lost, with a reason
- [x] Sets the Proposal to Lost.
- [x] A reason is required and stored; a blank or whitespace-only reason is
      rejected.

### 6. Other → notes plus an explicit next action
- [x] Notes are required.
- [x] An explicit next action must be chosen — "Other" may never be a dead
      end that leaves the Proposal in an undefined state.

### 7. Remove uncontrolled generic `Proposal.outcome` editing
- [x] `outcome` is no longer a freely editable Select on the Proposal form.
- [x] Outcome changes flow only through the client-response model above.
- [x] Any remaining write path is deliberate, named, and covered by a test.

### 8. Replace direct Pipeline Board Won/Lost writes
- [x] The board no longer writes `outcome` directly.
- [x] Board interactions that used to set Won/Lost route through the same
      service as every other outcome write.
- [x] Board grouping behaviour is considered separately from outcome
      authorship — changing one must not silently change the other.

### 9. Repository-wide audit of final outcome writes
- [x] Every runtime path that writes `proposals.outcome`,
      `winning_version_id`, or `proposals.value` is enumerated.
- [x] Each is either routed through the new model or explicitly justified in
      writing.
- [x] A codebase-shape test prevents new uncontrolled writers appearing —
      the same technique `TenancyBypassUsageTest` already uses for
      `OrganizationScope` bypasses.

### 10. Enforce Won → `winning_version_id` integrity in service *and* database
- [x] The service refuses to record Won without a winning Version.
- [x] A database-level CHECK constraint (or equivalent) enforces the same
      invariant, so a direct SQL write cannot violate it.
- [x] Existing rows are audited and migrated before the constraint is added —
      a Proposal that is Won today with a null `winning_version_id` is
      legitimate pre-cutover data and must be resolved, not silently broken.
- [x] `test_phase4_outcome_cutover_gate_remains_open` is rewritten (as
      `test_phase4_outcome_cutover_gate_is_closed`) to assert the closed
      state.

## Explicitly NOT part of this gate

- Renaming or reordering `ProposalStage` (`AGENTS.md` section 61, Question 3).
- Changing what "stale" means for a Proposal (section 27, Question 6).
- Anything in `docs/OPEN_BUSINESS_DECISIONS.md` — those are independent.

## Sign-off

| Item | Done | Verified by | Date |
|---|---|---|---|
| 1 | ☑ | Phase 4A-3.4 implementation + Phase 4A-3.5 cutover regression | 2026-09-12 |
| 2 | ☑ | Phase 4A-3.4 implementation + Phase 4A-3.5 cutover regression | 2026-09-12 |
| 3 | ☑ | Phase 4A-3.4 implementation + Phase 4A-3.5 cutover regression | 2026-09-12 |
| 4 | ☑ | Phase 4A-3.4 implementation + Phase 4A-3.5 cutover regression | 2026-09-12 |
| 5 | ☑ | Phase 4A-3.4 implementation + Phase 4A-3.5 cutover regression | 2026-09-12 |
| 6 | ☑ | Phase 4A-3.4 implementation + Phase 4A-3.5 cutover regression | 2026-09-12 |
| 7 | ☑ | Phase 4A-3.5 ProposalResource form cutover | 2026-09-12 |
| 8 | ☑ | Phase 4A-3.5 PipelineBoard cutover | 2026-09-12 |
| 9 | ☑ | Phase 4A-3.5 inventory + ProposalOutcomeCutoverGateTest anti-bypass sweep | 2026-09-12 |
| 10 | ☑ | Phase 4A-3.5 DB CHECK migration + ProposalOutcomeCutoverGateTest | 2026-09-12 |

**Gate status: CLOSED.** Every box above is ticked and the full suite
(including `tests/Feature/ProposalOutcomeCutoverGateTest.php`, the
rewritten tripwire test) passes — see the Phase 4A-3.5 implementation
report for the complete regression run.
