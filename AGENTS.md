# AGENTS.md — Aculyze-LM canonical rules

This document is the repository's source of truth for the business and
technical rules the code already enforces. Dozens of docblocks, comments,
migrations and tests cite it by number (`AGENTS.md section 16`,
`AGENTS.md sections 24-27, 44`, `AGENTS.md section 61, Question 6`), so the
numbering here is fixed: **a section number means the same thing forever.**

## How this document was produced

It was reconstructed from the current codebase — models, services,
policies, migrations, config and tests — after the original specification
stopped being tracked in the repository. Every numbered rule below is
backed by code that exists today, and each section names where it is
enforced so a reader can verify it rather than trust it.

Two consequences of that method, stated plainly:

- **Nothing here is invented.** Where the code does not settle a question,
  the section says so and points at `docs/OPEN_BUSINESS_DECISIONS.md` or
  section 61 rather than guessing an answer.
- **Some numbers have no surviving reference.** Those are marked
  *Not reconstructed* and deliberately left empty rather than filled with
  plausible-sounding rules. Renumbering to close the gaps would break every
  citation in the codebase, so the gaps stay.

## Terminology

| Term | Meaning |
|---|---|
| **Senior Manager** | The `UserRole::Admin` tier. The label changed to "Senior Manager"; the stored enum value is still `admin`, so every existing check, policy, fixture and row keeps working (`app/Enums/UserRole.php`). Senior Manager is a superset of every other tier, not a read-only reporting role. |
| **Manager** | `UserRole::Manager`. Sees and manages their own records plus their direct reports'. |
| **Employee** | `UserRole::Employee`. Sees and manages only their own records. |
| **Prospect** | A company/contact in the Database module. The master record everything else hangs off. |
| **Activity Log** | Not a separate entity — it *is* the Call Record list (sections 12, 51). |

---

## 1-5. Not reconstructed

No rule in the current codebase cites these numbers.

## 6. Record visibility is hierarchical and enforced server-side

Senior Managers see every record in their organization; Managers see their
own plus their direct reports'; Employees see only their own. This is
enforced by `App\Support\Authorization\HierarchyVisibility`, applied through
each model's `scopeVisibleTo()` and each policy — never by hiding a
navigation link. Changing a URL or guessing a record ID cannot reach another
employee's data.

Organization isolation is enforced separately and **first**, by
`App\Models\Scopes\OrganizationScope`, which fails closed: with no tenant
context established it throws rather than returning unscoped rows.

*Enforced in:* `app/Support/Authorization/HierarchyVisibility.php`, every
`*Policy`, `app/Models/Scopes/OrganizationScope.php`.
*Verified by:* `tests/Feature/AuthorizationTest.php`.

## 7. Senior Manager is also an active salesperson

The Senior Manager tier is not purely supervisory. They own and work their
own Prospects, calls and Proposals like anyone else, which is why the panel's
landing page is "My Dashboard" (the logged-in user's *own* numbers) and the
company-wide view is a separate, admin-only Main Dashboard.

*Enforced in:* `app/Enums/UserRole.php`, `app/Filament/Pages/MyDashboard.php`.

## 8. Employee accounts are managed only by Senior Manager

Creating a user in Employee Management immediately grants them login and
their own dashboard — there is no separate "set up a dashboard" step. The
per-employee dashboard is one dynamic route keyed by user ID
(`EmployeeDashboard`), never a hard-coded page per person.

Authorization is enforced in `mount()`, not only by hiding the link: a
non-admin hitting another employee's dashboard URL directly gets a 403.

*Enforced in:* `app/Filament/Resources/UserResource.php`,
`app/Filament/Pages/EmployeeDashboard.php`, `app/Policies/UserPolicy.php`.

## 9. Not reconstructed

## 10. The Database module is the entry point

`Prospect` is the master list of companies/contacts, and every sales
workflow starts from it. Imported legacy data lands here regardless of what
the source sheet calls its columns — the imported rows describe Prospects,
not mid-funnel Leads.

*Enforced in:* `app/Models/Prospect.php`,
`app/Filament/Resources/ProspectResource.php`,
`app/Filament/Pages/ImportProspects.php`.

## 11. Only Senior Manager assigns and reassigns

An Employee may not hand their own Prospect to someone else. The
assignment field is disabled for non-admins on the form, and the dedicated
Assign action is gated by `ProspectPolicy::assign()`.

*Enforced in:* `app/Filament/Resources/ProspectResource.php`,
`app/Policies/ProspectPolicy.php`.

## 12. The Call Record IS the Activity Log

There is no separate Activity Log entity. Every call made against a
Prospect creates exactly one Call Record, whatever its outcome, so call
coverage is complete by construction. Call Records are permanent history:
they are never deleted as a side effect of anything else.

*Enforced in:* `app/Models/CallRecord.php`,
`app/Filament/Resources/CallRecordResource.php`,
`app/Policies/CallRecordPolicy.php`. See also section 51.

## 13. Not reconstructed

## 14. Call outcomes are a closed, centralized set

`App\Enums\CallOutcome` is the single list of possible outcomes. Its stored
values are stable identifiers; labels may be renamed freely without touching
business logic.

## 15. A call's outcome decides what it creates

The `routesTo*()` helpers on `CallOutcome` are the single source of truth
for routing, consumed by `App\Services\CallRoutingService`. Routing decides
which downstream record — Follow-Up, Appointment or Lead — a given outcome
creates. The rules live in the service and the enum, deliberately not inline
in a Filament resource or an observer body, so they stay findable and
testable.

*Enforced in:* `app/Enums/CallOutcome.php`,
`app/Services/CallRoutingService.php`.
*Verified by:* `tests/Feature/CallRoutingTest.php`. See also section 46.

## 16. Routing happens once, on creation only

Routing runs when a Call Record is *created*. Editing one later never
re-triggers it: `CallRecordObserver` observes `created` and not `updated`,
and `call_records.processed_at` is stamped once routing has run, so a retry
or a re-save can never produce duplicate downstream records.

*Enforced in:* `app/Observers/CallRecordObserver.php`,
`app/Services/CallRoutingService.php`,
`database/migrations/..._create_call_records_table.php`.
*Verified by:* `tests/Feature/CallRoutingTest.php`.

## 17. The Follow-Ups panel

Mostly populated automatically by `CallRoutingService` from No Answer /
Switched Off / Not Reachable / Callback Requested calls, but Follow-Ups can
also be created directly.

*Enforced in:* `app/Filament/Resources/FollowUpResource.php`.

## 18. The Appointment Call Sheet

Appointments are the scheduled-meeting stage of the workflow. Stage names
and order are provisional — see section 61, Question 1.

*Enforced in:* `app/Filament/Resources/AppointmentResource.php`,
`app/Enums/AppointmentStage.php`. See also section 42.

## 19. An Appointment's stage clock moves only on real stage movement

`appointments.stage_changed_at` is updated only when `stage` itself changes,
never on an unrelated edit such as notes. Accurate lifecycle reporting
depends on it.

*Enforced in:* `app/Models/Appointment.php` (`booted()`).
*Verified by:* `tests/Feature/StageTimingTest.php`. See also section 45.

## 20. The Lead Sheet

A Lead is a qualified opportunity created from a call outcome that
identified a requirement. Stage names and order are provisional — see
section 61, Question 2.

*Enforced in:* `app/Filament/Resources/LeadResource.php`,
`app/Enums/LeadStage.php`. See also section 43.

## 21. A Lead ready for Proposal must carry Notes/Remarks

A Lead cannot be saved in the "ready for Proposal" state without
Notes/Remarks. This is a hard model-level guard on every write path, not
just a form rule, so a path that bypasses the visible form still cannot
persist one.

*Enforced in:* `app/Models/Lead.php` (`booted()`).

## 22. A Lead's stage clock moves only on real stage movement

Editing notes must not make a 25-day-old Lead look freshly moved. Phase 2
added a second, independent clock for the normalized `status` column
(`status_changed_at`); legacy `stage`/`stage_changed_at` and normalized
`status`/`status_changed_at` are permanently separate columns and are never
conflated.

*Enforced in:* `app/Models/Lead.php` (`booted()`).
*Verified by:* `tests/Feature/StageTimingTest.php`.

## 23. A Lead is stale after 30 days without movement

Configurable via `config('aculyze.lead_stale_after_days')` (default 30).
Phase 3 migrated staleness from legacy `stage`/`stage_changed_at` to
normalized `status`/`status_changed_at`, so a Lead progressing through the
new workflow is not falsely reported stale merely because its legacy stage
never advances. A Lost Lead, or one in a status that is terminal for
staleness, is never stale.

*Enforced in:* `app/Models/Lead.php` (`isStale()`, `scopeStale()`),
`config/aculyze.php`.
*Verified by:* `tests/Feature/StaleLeadTest.php`.
*Surfaced by:* `app/Filament/Widgets/StaleLeadsTable.php`.

## 24. Proposal processing

A Proposal is the commercial offer made against a validated Lead. Stage
names and order are provisional — see section 61, Question 3.

*Enforced in:* `app/Filament/Resources/ProposalResource.php`,
`app/Enums/ProposalStage.php`. See also section 44.

## 25. Proposal Stage and Proposal Outcome are different things

`ProposalStage` tracks the internal preparation/sending process.
`ProposalOutcome` is the final Won/Hold/Lost decision, and null means the
Proposal is still in progress. Neither is a substitute for the other.

Phase 4A added a **third**, separate concept: Commercial Version Status, the
lifecycle of an individual `ProposalVersion`. All three legitimately coexist
while `PHASE4_OUTCOME_CUTOVER_GATE` is OPEN, so every Proposal screen labels
which one it is showing — see `docs/PHASE4_OUTCOME_CUTOVER_GATE.md`.

*Enforced in:* `app/Enums/ProposalStage.php`, `app/Enums/ProposalOutcome.php`,
`app/Filament/Resources/ProposalResource.php`.

## 26. One Proposal per Lead

Enforced by a unique index on `proposals.lead_id`, not by application
convention alone. Revisit only if multi-proposal-per-lead is confirmed as a
requirement — see section 61, Question 7.

*Enforced in:* `database/migrations/..._create_proposals_table.php`.

## 27. A Proposal is stale after 20 days without movement

Configurable via `config('aculyze.proposal_stale_after_days')` (default 20).
Won and Lost are always closed and never stale. Hold's behavior is an open
business question and is config-driven rather than hard-coded — see section
61, Question 6.

*Enforced in:* `app/Models/Proposal.php` (`isStale()`, `scopeStale()`),
`config/aculyze.php`.
*Verified by:* `tests/Feature/StaleProposalTest.php`.
*Surfaced by:* `app/Filament/Widgets/StaleProposalsTable.php`.

## 28. A Proposal's stale clock moves only on a genuine stage change

**Corrected in Phase 4A-3.1 (locked Decision 18) — this section previously
said outcome changes also reset the clock; that is no longer true.**

A new Proposal initializes `stage_changed_at` on creation. After that, only
a genuine change of `stage` resets it — `outcome` changing by itself, even
the first time (e.g. a Proposal moving to Hold), does **not**. Editing notes
or the proposal value must never reset it either, exactly as before.

This was narrowed once client-response processing needed to distinguish "the
Proposal's stage genuinely moved" from "the outcome changed as a side effect
of a client response" — every existing runtime writer of `outcome` was
verified to always change `stage` in the same write before this was adopted,
so no real behavior changed for any current Proposal transition.

Meaningful CLIENT ACTIVITY (as opposed to a stage change) is tracked
separately via `proposals.last_client_activity_at` — a service-owned cache
written only by the client-response service for specific response types
(More Time; Other → Create Follow-Up), never for an arbitrary response and
never by any form/board write. A Proposal's effective staleness reference is
the later of `stage_changed_at` and `last_client_activity_at`.

*Enforced in:* `app/Models/Proposal.php` (`booted()`, `isStale()`, `scopeStale()`).
*Verified by:* `tests/Feature/StageTimingTest.php`, `tests/Feature/ProposalStageChangedAtTest.php`.

## 29. Reassignment changes responsibility, never history

Reassigning a Prospect updates who is currently responsible. It does **not**
rewrite who created the record, and it does **not** rewrite who actually
made each historical call — `call_records.user_id` continues to name the
person who really made that call.

*Verified by:* `tests/Feature/ReassignmentTest.php`. See also section 58.

## 30. Not reconstructed

## 31. The Main Dashboard is admin-only and company-wide

It aggregates activity across every employee. Access is enforced in
`mount()`, not merely hidden from navigation, so a non-admin hitting the URL
directly gets a 403.

*Enforced in:* `app/Filament/Pages/MainDashboard.php`.
*Verified by:* `tests/Feature/DashboardScopingTest.php`. See also section 39.

## 32. "Company growth" is an unresolved metric

No revenue-growth formula has been confirmed by the business, so none was
invented. The Main Dashboard shows a clearly-labelled Leads/Proposals
*activity* trend over the last 12 weeks, explicitly described in the widget
itself as provisional and not a revenue metric. It can be swapped for a real
KPI once one is agreed — see section 61, Question 5.

*Enforced in:* `app/Filament/Widgets/GrowthTrendChart.php`.

## 33. Employee vs. company-wide dashboard scoping

The Employee Dashboard shows only that employee's own numbers; the Main
Dashboard aggregates everyone's. Widgets take an optional `employeeId`: set
means per-employee, null means company-wide. The same widget class serves
both, so the two views can never drift apart.

*Verified by:* `tests/Feature/DashboardScopingTest.php`.

## 34. Dashboard date filtering is centralized

`App\Support\DashboardPeriod` turns the shared dashboard filter form data
into a concrete `[from, until]` range, or `[null, null]` for All Time, so
every widget applies date filtering identically.

*Enforced in:* `app/Support/DashboardPeriod.php`.

## 35-36. Not reconstructed

## 37. Only Senior Manager may delete sales history

Call Records, Leads, Appointments and Proposals are critical sales history.
Deletion of each is restricted to the Senior Manager (Admin) tier in the
corresponding policy, and the UI's delete actions are additionally gated on
`isAdmin()`.

Being permitted to delete is not the same as being able to: deletion is also
blocked whenever real dependent history exists — see section 59.

*Enforced in:* `app/Policies/CallRecordPolicy.php`,
`app/Policies/LeadPolicy.php`, `app/Policies/AppointmentPolicy.php`,
`app/Policies/ProposalPolicy.php`.

## 38. Not reconstructed

## 39. Authorization is enforced in code, never by hiding UI

Every access rule in this document is enforced at the policy, scope, or
`mount()` level. Hiding a navigation item or a button is a usability
measure, never the security boundary — a user who types the URL still gets
a 403 or a 404.

*Verified by:* `tests/Feature/AuthorizationTest.php`,
`tests/Feature/DashboardScopingTest.php`.

## 40-41. Not reconstructed

## 42. Appointment module reference

Companion number to section 18 for the Appointment Call Sheet.

## 43. Lead module reference

Companion number to sections 20-23 for the Lead Sheet.

## 44. Proposal module reference

Companion number to sections 24-27 for Proposal processing.

## 45. Stage timing applies across every module

Sections 19, 22 and 27 are one rule applied three times: a stage clock moves
only when the stage itself changes, never on an unrelated edit. For a
Proposal specifically, `outcome` changing alone is also an unrelated edit as
far as `stage_changed_at` is concerned (corrected in section 28, Phase
4A-3.1) — meaningful client activity that isn't a stage change is tracked
separately via `last_client_activity_at`, not by reinterpreting this rule.

*Verified by:* `tests/Feature/StageTimingTest.php`, `tests/Feature/ProposalStageChangedAtTest.php`.

## 46. Call routing reference

Companion number to sections 14-16 for `CallRoutingService`.

## 47. Actor fields are never accepted from the request

`created_by` and `user_id` are always set from the authenticated user
server-side, never taken from submitted form data, so they cannot be
spoofed.

*Enforced in:* `app/Filament/Resources/ProspectResource/Pages/CreateProspect.php`,
`app/Filament/Resources/CallRecordResource/Pages/CreateCallRecord.php`.

## 48-50. Not reconstructed

## 51. Activity Log reference

Companion number to section 12: the Call Record list is the Activity Log.

## 52. Seed data is development-only

`DatabaseSeeder` produces sample data with sample passwords. It is never
real production data and must not be treated as such. Seeded Call Records go
through normal Eloquent `create()` calls so that routing behaves exactly as
it does in the application.

*Enforced in:* `database/seeders/DatabaseSeeder.php`.

## 53-57. Not reconstructed

## 58. Reassignment reference

Companion number to sections 11 and 29 for Prospect reassignment.

## 59. Every foreign key is RESTRICT; deletion is blocked, never cascaded

There are no cascading deletes on business history in this schema. Deleting
a record that still has dependents is blocked up front by
`App\Support\DeletionGuard`, which reads the model's own
`deletionBlockers(): array<string, int>` and shows a friendly notification
naming exactly what is still attached. The database constraint remains as a
last line of defence, never as the user-facing behaviour.

Two consequences that follow from this rule rather than from any separate
decision:

- A Proposal with any commercial Version cannot be deleted at all —
  ProposalVersions are permanent commercial history.
- Employee offboarding preserves Proposals and Demos and hands them to a
  replacement, rather than deleting them. An employee recorded as a formal
  ProposalVersion actor (`submitted_by` / `approved_by` / `returned_by`)
  cannot be hard-deleted at all, because that evidence is never rewritten.

*Enforced in:* `app/Support/DeletionGuard.php`, each model's
`deletionBlockers()`, `app/Services/EmployeeDeletionService.php`.
*Verified by:* `tests/Feature/DeletionGuardTest.php`,
`tests/Feature/DeletionSafetyAuditFixTest.php`,
`tests/Feature/DeletionGuardCompletionSweepTest.php`,
`tests/Feature/EmployeeDeletionTest.php`.

## 60. Not reconstructed

## 61. Open business questions

Questions the business has not yet answered. The code takes an explicit,
documented default for each rather than guessing silently, and each default
is isolated so it can change without a rewrite.

**Question 1 — Appointment stage names and order.** Provisional. Centralized
in `app/Enums/AppointmentStage.php` so they can be renamed, reordered, added
or removed without touching another file; case order is also the display
order.

**Question 2 — Lead stage names and order.** Provisional, same treatment, in
`app/Enums/LeadStage.php`.

**Question 3 — Proposal stage names and order.** Provisional, same
treatment, in `app/Enums/ProposalStage.php`.

**Question 4 — Not reconstructed.**

**Question 5 — What does "company growth" mean?** Unanswered. See section 32:
the dashboard shows a labelled activity trend instead of an invented revenue
formula.

**Question 6 — Does a Proposal on Hold keep aging toward stale?** Unanswered.
Default: **no exemption** — Hold is treated as active and keeps stale-timing,
via `config('aculyze.hold_is_terminal_for_staleness') = false`. Flip the
config once the business confirms Hold should be excluded like Won/Lost.

**Question 7 — Can one Lead have more than one Proposal?** Unanswered.
Current answer: no, enforced by a unique index on `proposals.lead_id`
(section 26).

---

## Phase 4A additions (commercial ProposalVersion)

Phase 4A introduced an immutable commercial-document layer under Proposal.
These rules are newer than the numbered sections above and are recorded here
rather than given numbers, so no future renumbering is implied.

- **A ProposalVersion is contextual under Proposal.** There is no global
  ProposalVersion resource and no sidebar entry; it is reachable only from a
  Proposal (its View page header, its list row action, or the Version
  History list).
- **Versions are append-only.** V1 is created atomically with the Proposal by
  `App\Services\ProposalCreationService` — no runtime path creates a Proposal
  without one. Later Versions are created only by
  `ProposalVersionWorkflowService::createRevision()`, which supersedes rather
  than edits.
- **Money is decimal, never float.** `App\Services\ProposalVersionCalculator`
  is the only place totals are computed, using `Brick\Math\BigDecimal` with
  `RoundingMode::HalfUp`, backend-independently. A Version's subtotal is the
  sum of its persisted line totals, never re-derived from raw inputs.
- **Workflow actors are permanent evidence.** `submitted_by`, `approved_by`
  and `returned_by` record who actually performed each action. They are never
  reassigned, blanked, or rewritten — including during employee offboarding.
- **Draft saves use optimistic concurrency** on the Version's `updated_at`;
  a Draft changed after it was opened is rejected with a clear message rather
  than silently overwriting.
- **Self-approval is never allowed.** The Senior Manager who approves a
  Version cannot be the actor who submitted it.
- **`PHASE4_OUTCOME_CUTOVER_GATE` is OPEN.** Legacy `Proposal.outcome` and
  the new Version lifecycle coexist deliberately. See
  `docs/PHASE4_OUTCOME_CUTOVER_GATE.md` for the closure checklist.

## Related documents

- `docs/PHASE4_OUTCOME_CUTOVER_GATE.md` — the checklist Phase 4A-3 must
  satisfy before the legacy outcome system can be retired.
- `docs/OPEN_BUSINESS_DECISIONS.md` — decisions the business still owes,
  with the current behaviour and its known consequence for each.
