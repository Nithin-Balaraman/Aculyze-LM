<?php

namespace App\Services;

use App\Exceptions\EmployeeDeletionFailedException;
use App\Models\Lead;
use App\Models\Prospect;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;

/**
 * UX Fixes Batch Issue 5: replaces the old dead-end User DeletionGuard with
 * a guided cleanup/reassignment workflow. App\Support\DeletionGuard is
 * unchanged and still used as-is for direct CallRecord/Lead deletion — this
 * service is specifically for deleting a User.
 *
 * Ownership, everywhere in this service, means the genuine assignment/
 * ownership field for that record type (assigned_to for Prospect/
 * Appointment/Lead/Proposal, user_id for CallRecord/FollowUp) — never a
 * broader relationship like "shares a Prospect" or "created from the same
 * call". See dependencyBreakdown() and the regression test covering the
 * reported "Jisha" scenario.
 */
class EmployeeDeletionService
{
    /**
     * The itemized counts shown in the guided dialog before any option is
     * chosen — this and every deletion below always reads live counts,
     * never a snapshot passed in from the UI.
     *
     * Audit fix pass 1 adds `demos` (F3 — Demo was never integrated when it
     * landed in Phase 2) and the three `versions*` actor counts (F2/locked
     * Decision D3), which are deliberately a different KIND of entry: every
     * other line here is cleanup a replacement can absorb, while those
     * three are permanent commercial evidence that blocks hard deletion
     * outright and can never be reassigned away.
     *
     * @return array{prospects: int, callRecords: int, followUps: int, appointments: int, demos: int, leads: int, proposals: int, directReports: int, versionsSubmitted: int, versionsApproved: int, versionsReturned: int}
     */
    public function dependencyBreakdown(User $employee): array
    {
        return [
            'prospects' => $employee->assignedProspects()->count(),
            'callRecords' => $employee->callRecords()->count(),
            'followUps' => $employee->followUps()->count(),
            'appointments' => $employee->assignedAppointments()->count(),
            'demos' => $employee->assignedDemos()->count(),
            'leads' => $employee->assignedLeads()->count(),
            'proposals' => $employee->assignedProposals()->count(),
            'directReports' => $employee->directReports()->count(),
            'versionsSubmitted' => $employee->submittedProposalVersions()->count(),
            'versionsApproved' => $employee->approvedProposalVersions()->count(),
            'versionsReturned' => $employee->returnedProposalVersions()->count(),
        ];
    }

    public function hasDependencies(User $employee): bool
    {
        // Anything that forces a replacement is by definition a dependency.
        // Keeping these two in step matters: deleteWithoutDependencies()
        // is chosen purely on hasDependencies(), so a case that needs a
        // replacement but reported "no dependencies" would take the simple
        // path and hit a raw FK error instead (audit fix pass 1, F3 point C).
        if ($this->requiresReplacement($employee) || $this->isProposalVersionActor($employee)) {
            return true;
        }

        return $employee->assignedProspects()->count()
            + $employee->followUps()->count()
            + $employee->assignedAppointments()->count()
            + $employee->assignedDemos()->count()
            + $employee->assignedLeads()->count()
            + $employee->assignedProposals()->count()
            > 0;
    }

    /**
     * A replacement must be chosen whenever the employee has Call Records
     * (which must always be reassigned, never deleted), direct reports
     * (Phase 1 hierarchy — manager_id is a RESTRICT foreign key like
     * everything else here, so a Manager/Senior Manager with reports
     * cannot simply be deleted out from under them), or any record they
     * merely *created* that is currently owned by someone else — that
     * record isn't part of this employee's cleanup (it stays, it isn't
     * theirs), but its created_by foreign key still points at this
     * employee and would otherwise block deletion.
     *
     * Audit fix pass 1 adds every record type that now SURVIVES offboarding
     * rather than being deleted with the employee — assigned Proposals
     * (locked Decision D2), assigned Demos (D4), and the Leads those hang
     * off (D2/D5, see survivingLeads()). Each of those tables' assigned_to
     * is a NOT NULL RESTRICT column, so unlike a Prospect they cannot
     * simply be unassigned: someone real has to take them over.
     */
    public function requiresReplacement(User $employee): bool
    {
        if ($employee->callRecords()->exists() || $employee->directReports()->exists()) {
            return true;
        }

        if ($employee->assignedProspects()->where('created_by', $employee->id)->exists()) {
            return true;
        }

        if ($employee->assignedProposals()->exists() || $employee->assignedDemos()->exists()) {
            return true;
        }

        if ($this->survivingLeads($employee)->exists()) {
            return true;
        }

        foreach ($this->orphanedCreatorshipQueries($employee) as $query) {
            if ($query->exists()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Records whose created_by FK still needs handing off even though the
     * record itself is not part of this employee's own cleanup.
     *
     * Appointment/Lead/Prospect entries are filtered to rows owned by
     * someone ELSE: an Appointment the employee owns is hard-deleted in
     * execute() (taking its created_by with it), and a Prospect they own is
     * handled explicitly there because it survives. Proposal and Demo carry
     * NO such filter any more — after audit fix pass 1 neither is ever
     * deleted during offboarding (D1/D2/D4), so every one the employee
     * created survives and needs a new creator regardless of who owns it.
     * Leads the employee both created and owns are covered in execute()'s
     * own surviving-Lead step for the same reason.
     *
     * @return array<int, Builder>
     */
    private function orphanedCreatorshipQueries(User $employee): array
    {
        return [
            $employee->createdProspects()->where('assigned_to', '!=', $employee->id),
            $employee->createdAppointments()->where('assigned_to', '!=', $employee->id),
            $employee->createdLeads()->where('assigned_to', '!=', $employee->id),
            $employee->createdProposals(),
            $employee->createdDemos(),
        ];
    }

    /**
     * Leads assigned to this employee that CANNOT be hard-deleted during
     * offboarding, because something that survives still points at them:
     * a Proposal (locked Decision D2) or a Demo (D4/D5). Both
     * proposals.lead_id and demos.lead_id are RESTRICT, so preserving the
     * child necessarily means preserving its Lead — deleting the Lead
     * anyway would simply move the same FK failure one table along. Any
     * other assigned Lead keeps the existing hard-delete behaviour.
     *
     * @return HasMany<Lead>
     */
    private function survivingLeads(User $employee): HasMany
    {
        return $employee->assignedLeads()->where(
            fn (Builder $query) => $query->whereHas('proposal')->orWhereHas('demos')
        );
    }

    /**
     * The three formal ProposalVersion actor references, filtered to those
     * that actually exist. Locked Decision D3: submitted_by/approved_by/
     * returned_by record who really performed a commercial action and are
     * never reassigned, nulled or rewritten — so they can only ever block.
     *
     * @return array<string, int>
     */
    public function proposalVersionActorBlockers(User $employee): array
    {
        return array_filter([
            'submitted' => $employee->submittedProposalVersions()->count(),
            'approved' => $employee->approvedProposalVersions()->count(),
            'returned' => $employee->returnedProposalVersions()->count(),
        ]);
    }

    public function isProposalVersionActor(User $employee): bool
    {
        return $this->proposalVersionActorBlockers($employee) !== [];
    }

    /**
     * A deliberate, temporary hard-delete blocker until an explicit
     * account-deactivation/archival design is approved — deliberately NOT
     * worked around here with a soft delete, a null-out, or an actor
     * rewrite.
     *
     * @throws EmployeeDeletionFailedException
     */
    private function assertNotAProposalVersionActor(User $employee): void
    {
        $blockers = $this->proposalVersionActorBlockers($employee);

        if ($blockers === []) {
            return;
        }

        $breakdown = collect($blockers)
            ->map(fn (int $count, string $verb) => "{$count} they {$verb}")
            ->implode(', ');

        throw new EmployeeDeletionFailedException(
            "{$employee->name} can't be deleted: they are the recorded actor on Proposal commercial Version history ({$breakdown}). ".
            'Who submitted, approved or returned a commercial Version is permanent evidence and is never reassigned, blanked or rewritten, '.
            'so this employee cannot be removed. Nothing was changed.'
        );
    }

    /**
     * The simple case (Section 5.7): nothing to clean up, so just delete.
     * Still re-validated inside the transaction in case a dependency was
     * created between the dialog opening and this being confirmed.
     */
    public function deleteWithoutDependencies(User $employee): void
    {
        DB::transaction(function () use ($employee) {
            $fresh = User::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();

            // Checked explicitly (rather than left to hasDependencies()
            // below) so the admin gets the real reason — permanent
            // commercial evidence — not "records that weren't there a
            // moment ago".
            $this->assertNotAProposalVersionActor($fresh);

            if ($this->hasDependencies($fresh)) {
                throw new EmployeeDeletionFailedException(
                    "{$fresh->name} now has related records that weren't there a moment ago. Please try again — you'll see the up-to-date breakdown."
                );
            }

            $fresh->delete();
        });
    }

    /**
     * Option A — "Keep Companies (Reassign)": Prospects assigned to the
     * employee are kept and unassigned; Proposals, Demos and any Lead those
     * hang off are kept and handed to the replacement (audit fix pass 1,
     * locked Decisions D2/D4); Appointments, Follow-Ups and Leads with no
     * Proposal or Demo history are deleted.
     */
    public function reassignAndDelete(User $employee, ?User $replacement): void
    {
        $this->execute($employee, $replacement, deleteProspects: false);
    }

    /**
     * Option B — "Delete Everything (Including Companies)": Prospects
     * assigned to the employee are soft-deleted (never forceDelete — Pre-
     * Answered Question 6) instead of unassigned; everything else is the
     * same as Option A. It does NOT extend to Proposals, their commercial
     * Version history, or Demos: those are permanent records and are kept
     * and handed over under either option.
     */
    public function deleteEverything(User $employee, ?User $replacement): void
    {
        $this->execute($employee, $replacement, deleteProspects: true);
    }

    private function execute(User $employee, ?User $replacement, bool $deleteProspects): void
    {
        try {
            DB::transaction(function () use ($employee, $replacement, $deleteProspects) {
                $fresh = User::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();

                // 0. Immutable commercial evidence, checked FIRST — before
                // a replacement is even validated and long before anything
                // is reassigned or deleted (locked Decision D3). Nothing
                // partially moves and no FK is ever reached.
                $this->assertNotAProposalVersionActor($fresh);

                $replacement = $this->validateReplacement($fresh, $replacement);

                // Captured before any assignment changes below, since each
                // of these queries keys off assigned_to = this employee.
                $survivingLeadIds = $this->survivingLeads($fresh)->pluck('id');

                // 1. Call Records are permanent Activity Log history and
                // are never deleted — only ever reassigned.
                if ($replacement) {
                    $fresh->callRecords()->update(['user_id' => $replacement->id]);
                }

                // 2. Records this employee merely created but that belong
                // to someone else keep existing untouched — only their
                // created_by authorship is handed to the replacement so
                // the FK no longer points at a deleted user.
                if ($replacement) {
                    foreach ($this->orphanedCreatorshipQueries($fresh) as $query) {
                        $query->update(['created_by' => $replacement->id]);
                    }
                }

                // 2.5. Direct reports (Phase 1 hierarchy): manager_id is a
                // RESTRICT foreign key, so anyone still reporting to this
                // employee must be reassigned before it can be deleted.
                // Updated one at a time via Eloquent (never a bulk query-
                // builder update) so App\Models\User's own hierarchy guard
                // — same-organization, correct tier, no cycle — actually
                // runs for each one; a bulk update() would silently bypass
                // it entirely.
                if ($replacement) {
                    foreach ($fresh->directReports as $report) {
                        $report->update(['manager_id' => $replacement->id]);
                    }
                }

                // 3. Prospects genuinely assigned to this employee. A
                // Prospect survives either option (soft-deleted, or just
                // unassigned) rather than being hard-deleted, so a Prospect
                // this employee also created needs its created_by
                // reassigned too, or that FK would still block deleting the
                // employee. Unlike the Proposals and Demos in step 4, a
                // Prospect's assigned_to IS nullable, which is why this one
                // can be unassigned rather than requiring a new owner.
                // Captured as plain IDs up front since both operations
                // below would otherwise change what assignedProspects()
                // itself matches on the next call.
                $assignedProspectIds = $fresh->assignedProspects()->pluck('id');

                if ($replacement) {
                    Prospect::query()
                        ->whereIn('id', $assignedProspectIds)
                        ->where('created_by', $fresh->id)
                        ->update(['created_by' => $replacement->id]);
                }

                // Unassigned either way: a soft-deleted row still
                // physically exists (that's the whole point of
                // SoftDeletes), so assigned_to must be cleared here too or
                // it would keep pointing at the employee being deleted.
                Prospect::query()->whereIn('id', $assignedProspectIds)->update(['assigned_to' => null]);

                if ($deleteProspects) {
                    Prospect::query()->whereIn('id', $assignedProspectIds)->get()->each->delete();
                }

                // 4. Proposals and Demos are never deleted by offboarding
                // (locked Decisions D2 and D4) — a Proposal carries
                // permanent commercial Version history, and a Demo is real
                // activity history. Only current ownership moves; id,
                // lead_id/prospect_id/organization_id, stage/outcome/value,
                // every ProposalVersion, current_version_id and
                // winning_version_id are all left exactly as they are. Their
                // created_by is handled by step 2 above, which covers every
                // Proposal/Demo this employee created regardless of owner.
                if ($replacement) {
                    $fresh->assignedProposals()->update(['assigned_to' => $replacement->id]);
                    $fresh->assignedDemos()->update(['assigned_to' => $replacement->id]);
                }

                // 5. Leads. One a surviving Proposal or Demo still points
                // at survives with them (both FKs are RESTRICT — see
                // survivingLeads()); the rest keep the existing hard-delete
                // behaviour. Reassigned first so the delete below no longer
                // matches them.
                if ($replacement && $survivingLeadIds->isNotEmpty()) {
                    Lead::query()
                        ->whereIn('id', $survivingLeadIds)
                        ->where('created_by', $fresh->id)
                        ->update(['created_by' => $replacement->id]);

                    Lead::query()
                        ->whereIn('id', $survivingLeadIds)
                        ->update(['assigned_to' => $replacement->id]);
                }

                // 6. Everything else genuinely owned by this employee.
                $fresh->assignedLeads()->delete();
                $fresh->assignedAppointments()->delete();
                $fresh->followUps()->delete();

                $fresh->delete();
            });
        } catch (EmployeeDeletionFailedException $e) {
            throw $e;
        } catch (LogicException $e) {
            // Thrown by App\Models\User's own hierarchy guard when
            // reassigning a direct report to the chosen replacement would
            // be invalid (different organization, wrong tier, or a cycle).
            throw new EmployeeDeletionFailedException(
                "{$employee->name}'s direct reports could not be reassigned to the chosen replacement: {$e->getMessage()} ".
                'Choose a different replacement and try again.'
            );
        } catch (Throwable $e) {
            Log::error('Employee deletion failed', [
                'employee_id' => $employee->id,
                'exception' => $e,
            ]);

            // Genuine last resort: every dependency this service knows
            // about is now identified and explained by name before it can
            // get here (audit fix pass 1, Section 7), so this deliberately
            // no longer guesses at a cause it hasn't actually established.
            throw new EmployeeDeletionFailedException(
                "{$employee->name} could not be deleted — something still referencing them could not be resolved automatically. Nothing was changed. Please report this so the remaining reference can be identified."
            );
        }
    }

    /**
     * @throws EmployeeDeletionFailedException
     */
    private function validateReplacement(User $employee, ?User $replacement): ?User
    {
        if (! $this->requiresReplacement($employee)) {
            return null;
        }

        if (! $replacement) {
            throw new EmployeeDeletionFailedException(
                'Choose who should take over their '.$this->describeReplacementReasons($employee).' before continuing.'
            );
        }

        $fresh = User::query()->find($replacement->id);

        if (! $fresh) {
            throw new EmployeeDeletionFailedException('The selected replacement no longer exists. Choose another employee and try again.');
        }

        if ($fresh->is($employee)) {
            throw new EmployeeDeletionFailedException('The employee being deleted cannot be their own replacement. Choose someone else.');
        }

        // UserResource's replacement Select already only offers users in
        // the same organization, but that is UI filtering, not enforcement
        // — and every record handed over below (Proposal, Demo, Lead, Call
        // Record, Prospect creatorship) is written with a bulk update that
        // deliberately bypasses model events, so
        // EnforcesSameOrganizationRelations would never see it. This is the
        // server-side stop (audit fix pass 1, F3 point D).
        if ($fresh->organization_id !== $employee->organization_id) {
            throw new EmployeeDeletionFailedException(
                'The selected replacement belongs to a different organization. Choose someone from the same organization as this employee.'
            );
        }

        return $fresh;
    }

    /**
     * Names what actually needs a new owner, rather than always saying
     * "Call Records" (audit fix pass 1, Section 7) — an employee can now
     * need a replacement purely because they own Proposals or Demos.
     */
    private function describeReplacementReasons(User $employee): string
    {
        $reasons = [];

        if ($employee->callRecords()->exists()) {
            $reasons[] = 'Call Records';
        }

        if ($employee->assignedProposals()->exists()) {
            $reasons[] = 'assigned Proposals';
        }

        if ($employee->assignedDemos()->exists()) {
            $reasons[] = 'assigned Demos';
        }

        if ($this->survivingLeads($employee)->exists()) {
            $reasons[] = 'Leads with Proposal or Demo history';
        }

        if ($employee->directReports()->exists()) {
            $reasons[] = 'direct reports';
        }

        if ($employee->assignedProspects()->where('created_by', $employee->id)->exists()) {
            $reasons[] = 'Companies they created';
        }

        foreach ($this->orphanedCreatorshipQueries($employee) as $query) {
            if ($query->exists()) {
                $reasons[] = 'records they created for other people';
                break;
            }
        }

        return $reasons === [] ? 'records' : implode(', ', $reasons);
    }
}
