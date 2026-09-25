<?php

namespace App\Support;

use App\Models\Appointment;
use App\Models\CallRecord;
use App\Models\Demo;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\Proposal;
use Illuminate\Database\Eloquent\Model;

/**
 * Walks the FULL downstream chain a Call's outcome — and everything that
 * outcome's own downstream record subsequently led to — created. Built
 * for the Flag-as-Incorrect review UI (a reviewer needs to see the whole
 * story, not just the Call's own immediate Follow-Up/Appointment/Lead —
 * see CallRecord::downstreamRecord()/downstreamRecordLabel()) and the
 * one-click "Delete Call + Downstream Chain" action, which must check
 * the TRUE end's own deletionBlockers(), not assume the immediate record
 * is the end.
 *
 * No existing reusable "what did this record itself lead to" mechanism
 * existed before this — only individual REVERSE-lookup relations (e.g.
 * Demo::generatedFollowUp()) and the replacedBy()/origin_type+origin_id
 * columns already written by WorkflowTransitionService/
 * CallRoutingService/RescheduleService. This walker is built entirely
 * from those existing columns/relations — no new migration, no new
 * lineage tracking introduced.
 *
 * THE REAL "Call -> Follow-Up -> Appointment" PATH: a Follow-Up's own
 * "Completed" row action (FollowUpResource, ->action(fn ($record, $data)
 * => $record->completeWithCall(...))) creates a brand-new CallRecord
 * (FollowUp::completeWithCall(), call_records.follow_up_id) that goes
 * through the EXACT same CallRecordObserver -> CallRoutingService path any
 * other logged call does — so that byproduct CallRecord can itself route
 * to a fresh Appointment/Lead/Follow-Up exactly like the original flagged
 * Call did. That byproduct CallRecord (FollowUp::generatedCallRecord()) is
 * therefore walked as a real chain node in its own right, using the same
 * CallRecord::downstreamRecord()/downstreamRecordLabel() the top-level
 * walk already starts from — see nextForFollowUp()/nextForCallRecord().
 *
 * Every hop only ever moves to a record created STRICTLY AFTER the one
 * it came from (a reschedule replacement, an origin_type/origin_id
 * successor, or a Follow-Up's own generated Call Record is always created
 * later in time than what it replaces/originates from/completes —
 * confirmed by reading every write path that sets these: RescheduleService,
 * WorkflowTransitionService, CallRoutingService, FollowUp::completeWithCall()),
 * so a genuine cycle is not structurally possible. A visited-set + depth
 * cap is kept anyway as a defensive backstop, not because a real cycle
 * was found.
 *
 * BLOCKER DISCOUNTING: a model's own deletionBlockers() counts a real,
 * currently-existing dependent — including, unavoidably, the very next
 * chain link this walker is about to visit (e.g. FollowUp::deletionBlockers()
 * always reports 'Call Record' => 1 once completeWithCall() has run, and
 * Lead::deletionBlockers() always reports 'Proposal' => 1 once one exists).
 * Since the one-click delete removes the chain deepest-first inside a
 * single transaction, that specific next-link dependent is not a real
 * obstacle — it is deleted moments before its parent, in the same
 * transaction. Reporting it as a blocker would make every chain of length
 * 2+ look permanently blocked even when it is fully, cleanly deletable.
 * Each node's stored 'own_blockers' is therefore deletionBlockers() with
 * exactly the ONE count belonging to the specific relation this walker
 * used to reach the next node discounted by exactly 1 (never more) —
 * still leaving any OTHER, unrelated blocker fully counted (e.g. a
 * Follow-Up's own 'Proposal client response' blocker, or a Lead with two
 * Demos when only the latest is walked further). The true end (last node,
 * no next hop) gets no discount at all — whatever it reports there is a
 * genuine, real block.
 *
 * KNOWN GAP, NOT introduced or widened by this feature: an Appointment's
 * RequirementIdentified outcome creates a Lead
 * (WorkflowTransitionService::createLeadFromAppointment()) with no
 * lineage column recorded at all — `leads` has no origin_type/origin_id
 * column (only follow_ups/appointments/demos do — confirmed against
 * every add-lineage migration). That Lead is genuinely untraceable from
 * the Appointment, so it cannot appear in the walked chain.
 * Appointment::deletionBlockers() already only checks replacedBy() and
 * never accounted for such a Lead either — an Appointment that spawned
 * one was already freely deletable before this feature existed, so this
 * walker inherits an existing blind spot rather than creating one.
 * Similarly, a Follow-Up that BOTH scheduled a Demo (FollowUpResource's
 * "Schedule Demo" action, which never changes the Follow-Up's own status)
 * AND was separately completed is not something FollowUp::deletionBlockers()
 * accounts for either — this walker follows whichever real path exists
 * (generated Call Record takes priority, matching "Completed" being the
 * terminal action a Pending Follow-Up normally takes), inheriting the same
 * pre-existing gap rather than widening it.
 */
class CallDownstreamChain
{
    private const MAX_DEPTH = 20;

    /**
     * @return array<int, array{label: string, record: Model, own_blockers: array<string, int>}>
     * ordered from the Call's own immediate downstream record to the
     * chain's true end, inclusive. Empty if the Call has no downstream
     * record.
     */
    public static function walk(CallRecord $call): array
    {
        $chain = [];
        $record = $call->downstreamRecord();
        $label = $call->downstreamRecordLabel();
        $visited = [];
        $depth = 0;

        while ($record !== null && $depth < self::MAX_DEPTH) {
            $key = get_class($record).':'.$record->getKey();

            if (isset($visited[$key])) {
                break; // Defensive only — every real hop moves strictly forward in time; see class docblock.
            }

            $visited[$key] = true;

            [$nextRecord, $nextLabel] = self::next($record);

            $chain[] = [
                'label' => $label,
                'record' => $record,
                'own_blockers' => self::ownBlockers($record, $nextRecord, $nextLabel),
            ];

            $record = $nextRecord;
            $label = $nextLabel;
            $depth++;
        }

        return $chain;
    }

    /**
     * Whether every record in the Call's chain — not just the true end —
     * is individually free of its own (discounted) blockers. Checking
     * every node, not only the final one, is deliberately stricter than
     * the literal "check the true end" instruction: an intermediate node
     * can independently carry an unrelated blocker of its own that has
     * nothing to do with this chain continuing past it (e.g. a Follow-Up
     * that is also a Proposal client response's origin —
     * FollowUp::resultingFromClientResponses()).
     */
    public static function isClean(CallRecord $call): bool
    {
        foreach (self::walk($call) as $node) {
            if ($node['own_blockers'] !== []) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, int> $record's deletionBlockers(), with the
     * one count belonging to the relation used to reach $nextRecord
     * discounted by 1 (see class docblock), then emptied of zero entries.
     */
    private static function ownBlockers(Model $record, ?Model $nextRecord, ?string $nextLabel): array
    {
        $blockers = $record->deletionBlockers();

        if ($nextRecord !== null) {
            $discountKey = self::discountKeyForHop($record, $nextLabel);

            if ($discountKey !== null && isset($blockers[$discountKey])) {
                $blockers[$discountKey] = max(0, $blockers[$discountKey] - 1);
            }
        }

        return array_filter($blockers);
    }

    /**
     * Which deletionBlockers() key on $record corresponds exactly to the
     * single relation this walker followed to reach the next node labeled
     * $nextLabel — see the class docblock's "BLOCKER DISCOUNTING" section.
     * Returns null when $record's own deletionBlockers() has no key for
     * that hop at all (nothing to discount — e.g. Appointment/Proposal
     * never count their own FollowUp/Demo origin successors as blockers).
     */
    private static function discountKeyForHop(Model $record, ?string $nextLabel): ?string
    {
        return match (true) {
            $record instanceof FollowUp && $nextLabel === 'Follow-Up (rescheduled)' => 'replacement Follow-Up',
            $record instanceof FollowUp && $nextLabel === 'Call Record' => 'Call Record',
            $record instanceof Appointment && $nextLabel === 'Appointment (rescheduled)' => 'replacement Appointment',
            $record instanceof Demo && $nextLabel === 'Demo (rescheduled)' => 'replacement Demo',
            $record instanceof Lead && $nextLabel === 'Proposal' => 'Proposal',
            $record instanceof Lead && $nextLabel === 'Demo' => 'Demo(s)',
            $record instanceof CallRecord && $nextLabel === 'Follow-Up' => 'Follow-Up',
            $record instanceof CallRecord && $nextLabel === 'Appointment' => 'Appointment',
            $record instanceof CallRecord && $nextLabel === 'Lead' => 'Lead',
            default => null,
        };
    }

    /** @return array{0: ?Model, 1: ?string} */
    private static function next(Model $record): array
    {
        return match (true) {
            $record instanceof FollowUp => self::nextForFollowUp($record),
            $record instanceof Appointment => self::nextForAppointment($record),
            $record instanceof Demo => self::nextForDemo($record),
            $record instanceof Lead => self::nextForLead($record),
            $record instanceof Proposal => self::nextForProposal($record),
            $record instanceof CallRecord => self::nextForCallRecord($record),
            default => [null, null],
        };
    }

    /** @return array{0: ?Model, 1: ?string} */
    private static function nextForFollowUp(FollowUp $followUp): array
    {
        if ($followUp->replacedBy) {
            return [$followUp->replacedBy, 'Follow-Up (rescheduled)'];
        }

        // The real "Call -> Follow-Up -> Appointment" path: completing this
        // Follow-Up (FollowUpResource's "Completed" action) creates a brand
        // new Call Record that itself routes through CallRoutingService —
        // see class docblock. Checked before the Demo-via-origin_type
        // fallback below since "Completed" is the terminal action a Pending
        // Follow-Up normally takes.
        if ($followUp->generatedCallRecord) {
            return [$followUp->generatedCallRecord, 'Call Record'];
        }

        if ($demo = Demo::where('origin_type', 'follow_up')->where('origin_id', $followUp->id)->first()) {
            return [$demo, 'Demo'];
        }

        return [null, null];
    }

    /** @return array{0: ?Model, 1: ?string} */
    private static function nextForAppointment(Appointment $appointment): array
    {
        if ($appointment->replacedBy) {
            return [$appointment->replacedBy, 'Appointment (rescheduled)'];
        }

        if ($followUp = FollowUp::where('origin_type', 'appointment')->where('origin_id', $appointment->id)->first()) {
            return [$followUp, 'Follow-Up'];
        }

        if ($demo = Demo::where('origin_type', 'appointment')->where('origin_id', $appointment->id)->first()) {
            return [$demo, 'Demo'];
        }

        // "Another Appointment Required" also writes origin_type='appointment' onto a NEW appointments row.
        if ($repeat = Appointment::where('origin_type', 'appointment')->where('origin_id', $appointment->id)->first()) {
            return [$repeat, 'Appointment'];
        }

        // See class docblock: RequirementIdentified's resulting Lead is genuinely untraceable — no lineage column exists for it.
        return [null, null];
    }

    /** @return array{0: ?Model, 1: ?string} */
    private static function nextForDemo(Demo $demo): array
    {
        if ($demo->replacedBy) {
            return [$demo->replacedBy, 'Demo (rescheduled)'];
        }

        if ($followUp = $demo->generatedFollowUp) {
            return [$followUp, 'Follow-Up'];
        }

        // A Demo's StartProposal outcome attaches a Proposal to the SAME
        // Lead (Proposal.lead_id = demo.lead_id), not a new row pointing
        // back to this Demo — that Proposal already surfaces one tier up
        // this same walk, via the Lead's own proposal() relation, so
        // nothing is silently hidden.
        return [null, null];
    }

    /** @return array{0: ?Model, 1: ?string} */
    private static function nextForLead(Lead $lead): array
    {
        // At most one Proposal per Lead (AGENTS.md section 26, a unique
        // index) — walk into it first; it's the more final/authoritative
        // next step. A Lead can accumulate MULTIPLE Demos over time
        // (repeat demos), so only the most recent is walked further for
        // display — any OTHER Demo(s) still fully count via ownBlockers()'s
        // discount-by-exactly-1, so the clean/blocked verdict is never
        // silently wrong when more than one Demo exists.
        if ($lead->proposal) {
            return [$lead->proposal, 'Proposal'];
        }

        if ($demo = $lead->demos()->latest()->first()) {
            return [$demo, 'Demo'];
        }

        return [null, null];
    }

    /** @return array{0: ?Model, 1: ?string} */
    private static function nextForProposal(Proposal $proposal): array
    {
        if ($followUp = FollowUp::where('origin_type', 'proposal')->where('origin_id', $proposal->id)->first()) {
            return [$followUp, 'Follow-Up'];
        }

        if ($demo = Demo::where('origin_type', 'proposal')->where('origin_id', $proposal->id)->first()) {
            return [$demo, 'Demo'];
        }

        return [null, null];
    }

    /**
     * The byproduct Call Record a Follow-Up's own "Completed" action
     * creates (see nextForFollowUp()) is a real Call Record like any
     * other — it can route to its own Follow-Up/Appointment/Lead exactly
     * like the original flagged Call did, via the same
     * downstreamRecord()/downstreamRecordLabel() pair.
     *
     * @return array{0: ?Model, 1: ?string}
     */
    private static function nextForCallRecord(CallRecord $callRecord): array
    {
        return [$callRecord->downstreamRecord(), $callRecord->downstreamRecordLabel()];
    }
}
