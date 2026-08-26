<?php

namespace App\Filament\Pages;

use App\Enums\AppointmentStage;
use App\Enums\CallOutcome;
use App\Enums\FollowUpStatus;
use App\Enums\LeadStage;
use App\Enums\LeadTemperature;
use App\Enums\ProposalOutcome;
use App\Enums\ProposalStage;
use App\Filament\Resources\AppointmentResource;
use App\Filament\Resources\CallRecordResource;
use App\Filament\Resources\FollowUpResource;
use App\Filament\Resources\LeadResource;
use App\Filament\Resources\ProposalResource;
use App\Models\Appointment;
use App\Models\CallRecord;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\Prospect;
use App\Models\Proposal;
use Filament\Actions;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Pipeline Board: a drag-and-drop visual view of the sales pipeline,
 * additive to every existing List/Edit/Create page. Five lanes side by
 * side — Call, Follow-up, Appointment, Lead, Proposal — each showing that
 * resource's own real records grouped into their real stage/status boxes.
 *
 * Phase 1: read-only. Five independent queries, one per resource, each
 * reusing that resource's own getEloquentQuery() — which already applies
 * ->visibleTo(auth()->user()) internally — so per-employee scoping is
 * inherited for free, identically to every existing list page, with zero
 * new authorization logic written for reads.
 *
 * Phase 2: same-lane stage drags for Appointment, Lead, and Proposal — a
 * single real stage mutation on the same record, going through the exact
 * same Eloquent ->update() every Edit form uses, so every existing model
 * `saving()` guard fires identically (see Appointment::booted()/Lead::
 * booted()/Proposal::booted()).
 *
 * Phase 3 (this file, now): same-lane Follow-up (Pending -> Completed via
 * the shared FollowUp::completeWithCall(), or -> Cancelled), plus
 * cross-lane drag between any two of Follow-up/Appointment/Lead/Proposal —
 * two real writes in one DB transaction: a brand-new record of the target
 * type is created for the same Prospect (whatever that destination stage
 * itself requires), and — unless the dragged source is already sitting at
 * its own terminal state — the source is ALSO resolved forward (Appointment
 * -> Succeeded, Follow-up -> Completed, Lead -> Validated, Proposal ->
 * Customer Accepted/Won), asking for whatever that resolution itself
 * requires. Both halves reuse the exact same per-resource/per-stage field
 * builders as the same-lane dialog (stageFields()) — field names are
 * prefixed ('destination_'/'source_') only in the cross-lane dialog, since
 * that one form can otherwise ask for two different resources' `notes` at
 * once.
 *
 * Proposal can only be a valid cross-lane DESTINATION when the dragged
 * source is a Validated Lead (mirrors LeadResource's own "Create Proposal"
 * row action's visibility condition exactly) — a Proposal always needs a
 * real lead_id, and Follow-up/Appointment cards carry no Lead to attach to.
 * Dragging one of those onto the Proposal lane shows an explanatory,
 * submit-disabled dialog rather than silently doing nothing or fabricating
 * a Lead. A destination Follow-up is always created Pending regardless of
 * which stage box it was dropped onto — unlike Appointment/Lead/Proposal,
 * "Completed" isn't just a stage value, it's specifically defined as
 * "a real Call Record exists for this" (see FollowUp::completeWithCall()),
 * so creating one pre-"Completed" with nothing behind it would violate that
 * invariant everywhere else in the app relies on.
 *
 * Phase 4: Call becomes a valid cross-lane drag SOURCE into Follow-up/
 * Appointment/Lead (never a destination — a Call Record is only ever
 * created by logging a real call, never by dragging one onto it). Unlike
 * every other cross-lane drag, this does NOT create the destination type
 * directly: dragging a Call card logs a brand-new Call Record for the same
 * Prospect (an ordinary Eloquent ::create(), so CallRecordObserver fires and
 * routes it through the exact same CallRoutingService a fresh "Log a Call"
 * would), and the outcome the rep picks in that dialog decides what (if
 * anything) gets created downstream — exactly like any other logged call.
 * The dragged Call Record itself is never touched or "resolved forward";
 * see logNewCall()/callLogFormSchema(), which mirror
 * CallRecordResource::form()'s own Call Details fields (minus Company,
 * already implied by the dragged card). Deliberately NOT the generic
 * "create the destination type directly + resolve the source forward"
 * shape every other resource above uses — Call has no stage of its own to
 * resolve, and fabricating a Follow-up/Appointment/Lead without a real
 * logged call behind it would leave CallOutcome's routesTo*() invariants
 * (the "single source of truth" for what routing exists — see the enum's
 * own docblock) silently lying about that Prospect's history. Always
 * eligible regardless of what the dragged Call's own outcome already
 * routed to — logging another real call for the same prospect is always a
 * legitimate action, so there's no "already linked" restriction the way
 * Proposal's Lead-source gate has.
 *
 * The drop-confirm dialogs are genuine Filament page-level Actions (mounted
 * via Alpine's `$wire.mountAction(...)`, mirroring the exact mechanism
 * ListFollowUps::summaryAction() already uses in this codebase) — not a
 * hand-rolled modal — so their `->form()` schemas reuse the same Filament
 * form components (Textarea, Select, FileUpload) with the same
 * validation/config as the matching Resource's own form, and every
 * `->action()` ends in ordinary Eloquent ->update()/::create() calls.
 * Authorization is explicit (auth()->user()->can('update', $record)) rather
 * than automatic the way a real Resource's row action gets it for free —
 * creating the destination needs no separate check since every Policy's
 * own create() already returns true unconditionally for any authenticated
 * user (see e.g. LeadPolicy::create()).
 *
 * Deliberately a standalone Page, not a Resource (a Resource is
 * fundamentally single-model; this spans five) and not a Widget (this needs
 * full-page real estate and its own Livewire state, not something embedded
 * inside another page).
 */
class PipelineBoard extends Page implements HasActions, HasForms
{
    use InteractsWithActions;
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-view-columns';

    protected static ?string $navigationLabel = 'Pipeline Board';

    protected static ?string $navigationGroup = 'Sales';

    protected static ?int $navigationSort = 0;

    protected static string $view = 'filament.pages.pipeline-board';

    /**
     * One entry per lane, in left-to-right display order. Each lane's
     * `stages` is itself ordered (display/progression order, same
     * convention as every stage enum's own case order elsewhere in this
     * app) and keyed by a stable string the view can key off (not the
     * enum's own ->value, so the Call lane — which has no real enum behind
     * its single box — fits the same shape).
     *
     * @return array<string, array{label: string, stages: array<string, array{label: string, terminal: bool, cards: array<int, array<string, mixed>>}>}>
     */
    /**
     * "+ Log a call" — Call is the pipeline's ORIGIN lane (see callLane()'s
     * own docblock), so this is where a brand-new company enters the board,
     * exactly like before. Reuses CallRecordResource::formSchema()
     * verbatim rather than a board-specific copy: its prospect_id field is
     * a searchable select over existing companies AND carries the same
     * "+ Create new company…" sentinel/nested-action pattern the real Call
     * Record create form uses (see that field's own docblock), so this one
     * dialog now covers both "log a call against an existing company" and
     * "create a brand-new company's first call" — no separate flow needed.
     * See performCreateCompany().
     */
    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('createCompany')
                ->label('+ Log a call')
                ->modalHeading('Log a Call')
                ->modalWidth('2xl')
                ->modalSubmitActionLabel('Log call')
                ->form(CallRecordResource::formSchema())
                ->action(function (array $data) {
                    $this->performCreateCompany($data);
                }),
        ];
    }

    /**
     * A single ordinary Eloquent ::create() — CallRecordResource::
     * formSchema()'s prospect_id field already resolved to either an
     * existing Prospect's id or one just created by its own nested
     * "+ Create new company…" action (see that field's own docblock), so
     * there's no separate Prospect-creation step left here to orchestrate.
     * CallRecordObserver fires exactly as it would from the real Call
     * Record create form, routing through CallRoutingService as usual.
     */
    private function performCreateCompany(array $data): void
    {
        $data['user_id'] = auth()->id();

        $callData = collect($data)->only((new CallRecord)->getFillable())->all();

        try {
            $call = CallRecord::create($callData);
        } catch (\LogicException $e) {
            Notification::make()->title("Couldn't log the call")->body($e->getMessage())->danger()->send();
            throw new Halt;
        }

        Notification::make()->title('Logged call for '.$call->prospect->company_name)->success()->send();
    }

    public function getLanes(): array
    {
        return [
            'call' => $this->callLane(),
            'follow_up' => $this->followUpLane(),
            'appointment' => $this->appointmentLane(),
            'lead' => $this->leadLane(),
            'proposal' => $this->proposalLane(),
        ];
    }

    /**
     * The drop-confirm dialog for every same-lane stage drag — a single
     * Filament page-level Action (see the class docblock), dispatched via
     * mountAction('drop', ['resource' => ..., 'id' => ..., 'stage' => ...])
     * from the card/stage-box's Alpine drag handlers in the Blade view.
     * Which fields it asks for, and what the confirm button actually
     * writes, both key off those same $arguments.
     */
    public function dropAction(): Actions\Action
    {
        return Actions\Action::make('drop')
            ->modalHeading(fn (array $arguments) => $this->dropModalHeading($arguments))
            ->modalSubmitActionLabel('Confirm move')
            ->modalSubmitAction(fn (array $arguments) => $this->isDropEligible($arguments) ? null : false)
            ->modalCancelActionLabel(fn (array $arguments) => $this->isDropEligible($arguments) ? 'Cancel' : 'Close')
            ->form(fn (array $arguments) => $this->dropFormSchema($arguments))
            ->action(function (array $data, array $arguments) {
                $this->performDrop($arguments, $data);
            });
    }

    /**
     * The cross-lane counterpart — dispatched via mountAction('crossDrop',
     * ['sourceResource' => ..., 'sourceId' => ..., 'destResource' => ...,
     * 'destStage' => ...]). See the class docblock for the two-write shape
     * and the Proposal-destination restriction.
     */
    public function crossDropAction(): Actions\Action
    {
        return Actions\Action::make('crossDrop')
            ->modalHeading(fn (array $arguments) => $this->crossDropModalHeading($arguments))
            ->modalSubmitActionLabel('Confirm move')
            ->modalSubmitAction(fn (array $arguments) => $this->isCrossDropEligible($arguments) ? null : false)
            ->modalCancelActionLabel(fn (array $arguments) => $this->isCrossDropEligible($arguments) ? 'Cancel' : 'Close')
            ->form(fn (array $arguments) => $this->crossDropFormSchema($arguments))
            ->action(function (array $data, array $arguments) {
                $this->performCrossDrop($arguments, $data);
            });
    }

    /**
     * Phase 5: reuses ListFollowUps::summaryAction() verbatim — same
     * FollowUpResource::companyFollowUpHistory() data and the same
     * modal view, just resolving the target Follow-Up from this page's own
     * {id} argument instead of a table row's followUpId. A small "review"
     * button on Follow-up lane cards only (see the stage-box partial),
     * dispatched via mountAction('reviewFollowUp', ['id' => ...]).
     */
    public function reviewFollowUpAction(): Actions\Action
    {
        return Actions\Action::make('reviewFollowUp')
            ->modalHeading(fn (array $arguments) => ($this->reviewFollowUpRecord($arguments)?->prospect?->company_name ?? 'Unknown Company').' — Follow-Up History')
            ->modalContent(function (array $arguments) {
                $followUp = $this->reviewFollowUpRecord($arguments);

                return view('filament.resources.follow-up-resource.pages.follow-up-summary-modal', [
                    'entries' => $followUp ? FollowUpResource::companyFollowUpHistory($followUp) : collect(),
                ]);
            })
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close');
    }

    private function reviewFollowUpRecord(array $arguments): ?FollowUp
    {
        return FollowUpResource::getEloquentQuery()->with('prospect')->find($arguments['id'] ?? null);
    }

    /**
     * Phase 5: a right-click "History" menu item on every card, regardless
     * of resource (see the stage-box partial's contextmenu handler),
     * dispatched via mountAction('cardHistory', ['resource' => ...,
     * 'id' => ...]). Deliberately different in kind from
     * reviewFollowUpAction() above (which is per-company, Follow-up only,
     * and reuses an existing feature verbatim): this is new, per-record,
     * and spans all five resources.
     *
     * There is no audit-log table anywhere in this schema — every model
     * carries only a single `stage_changed_at` timestamp for its LAST
     * transition, never a full history of every prior one (confirmed
     * across Appointment/Lead/Proposal's migrations and casts()). So this
     * is scoped honestly to what the data actually supports: that one
     * record's own current detail, plus its lineage — what created it
     * (e.g. "Created from Call #12"), and what it in turn created (e.g.
     * "Created Appointment #4") — never a fabricated change-by-change
     * timeline the schema doesn't have.
     */
    public function cardHistoryAction(): Actions\Action
    {
        return Actions\Action::make('cardHistory')
            ->modalHeading(fn (array $arguments) => $this->cardHistoryHeading($arguments))
            ->modalContent(fn (array $arguments) => view(
                'filament.pages.partials.pipeline-board-history-modal',
                $this->cardHistoryData($arguments)
            ))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close');
    }

    private function cardHistoryHeading(array $arguments): string
    {
        $record = $this->resolveDropRecord($arguments);
        $company = $record?->prospect?->company_name ?? 'Unknown Company';
        $label = $this->resourceLabel((string) ($arguments['resource'] ?? ''));

        return "{$company} — {$label} History";
    }

    /**
     * @return array{fields: array<int, array{label: string, value: string}>, lineage: array<int, string>, resource: ?string, id: ?int, canEdit: bool}
     */
    private function cardHistoryData(array $arguments): array
    {
        $resource = $arguments['resource'] ?? null;
        $record = $this->resolveDropRecord($arguments);

        if (! $record) {
            return ['fields' => [], 'lineage' => [], 'resource' => $resource, 'id' => null, 'canEdit' => false];
        }

        return [
            'fields' => match ($resource) {
                'call' => $this->callHistoryFields($record),
                'follow_up' => $this->followUpHistoryFields($record),
                'appointment' => $this->appointmentHistoryFields($record),
                'lead' => $this->leadHistoryFields($record),
                'proposal' => $this->proposalHistoryFields($record),
                default => [],
            },
            'lineage' => $this->recordLineage($resource, $record),
            'resource' => $resource,
            'id' => $record->getKey(),
            'canEdit' => (bool) auth()->user()?->can('update', $record),
        ];
    }

    /**
     * Nothing ever navigates the user away from the Pipeline Board (see
     * the class docblock) — the popup's "Edit" button and "View full
     * record →" link both dispatch these instead of a Filament resource
     * URL. Both reuse the real Resource's own formSchema() for full
     * parity, exactly like Filament's own EditAction/ViewAction (see
     * vendor/filament/actions/src/EditAction.php and ViewAction.php) —
     * just without the Resource/Table coupling those classes assume,
     * since one Action here has to span five different models.
     * ->record() (Concerns\InteractsWithRecord) gives relationship-based
     * fields (prospect_id, assigned_to) a real container model to resolve
     * against, exactly like CallRecordResource's own nested "+ Create new
     * company…" action needed ->actionFormModel() for (see that field's
     * own docblock) — just at the page level instead of the field level.
     */
    public function editRecordAction(): Actions\Action
    {
        return Actions\Action::make('editRecord')
            ->modalHeading(fn (array $arguments) => $this->recordModalHeading($arguments, 'Edit'))
            ->modalWidth('2xl')
            ->modalSubmitActionLabel('Save changes')
            ->record(fn (array $arguments) => $this->resolveDropRecord($arguments))
            ->fillForm(fn (array $arguments) => $this->recordFillData(
                $arguments['resource'] ?? null,
                $this->resolveDropRecord($arguments),
            ))
            ->form(fn (array $arguments) => $this->recordFormSchema($arguments['resource'] ?? null))
            ->action(function (array $data, array $arguments) {
                $record = $this->resolveDropRecord($arguments);

                if (! $record) {
                    return;
                }

                $this->authorizeUpdate($record);
                $this->saveRecordEdit($arguments['resource'] ?? null, $record, $data);

                Notification::make()->title('Saved')->success()->send();
            });
    }

    /**
     * The "View full record →" counterpart — disabledForm() + no submit
     * action, exactly like Filament's own ViewAction, so it's a genuine
     * read-only rendering of the same real form rather than a hand-rolled
     * summary.
     */
    public function viewRecordAction(): Actions\Action
    {
        return Actions\Action::make('viewRecord')
            ->modalHeading(fn (array $arguments) => $this->recordModalHeading($arguments, 'View'))
            ->modalWidth('2xl')
            ->record(fn (array $arguments) => $this->resolveDropRecord($arguments))
            ->fillForm(fn (array $arguments) => $this->recordFillData(
                $arguments['resource'] ?? null,
                $this->resolveDropRecord($arguments),
            ))
            ->form(fn (array $arguments) => $this->recordFormSchema($arguments['resource'] ?? null))
            ->disabledForm()
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close');
    }

    private function recordModalHeading(array $arguments, string $verb): string
    {
        $record = $this->resolveDropRecord($arguments);
        $company = $record?->prospect?->company_name ?? 'Unknown Company';
        $label = $this->resourceLabel((string) ($arguments['resource'] ?? ''));

        return "{$company} — {$verb} {$label}";
    }

    /**
     * @return array<int, Forms\Components\Component>
     */
    private function recordFormSchema(?string $resource): array
    {
        return match ($resource) {
            'call' => CallRecordResource::formSchema(),
            'follow_up' => FollowUpResource::formSchema(),
            'appointment' => AppointmentResource::formSchema(),
            'lead' => LeadResource::formSchema(),
            'proposal' => ProposalResource::formSchema(),
            default => [],
        };
    }

    /**
     * Mirrors EditFollowUp::mutateFormDataBeforeFill() exactly for
     * Follow-up: none of outcome/call_notes/appointment_at/
     * new_follow_up_at persists on the FollowUp record itself once
     * Completed (see that page's own docblock) — they only ever live on
     * the Call Record its completion created — so an already-Completed
     * Follow-up needs the same pre-fill, or its own required-when-
     * Completed rules would demand they be re-entered just to resave an
     * unrelated field. Every other resource's real attribute data needs
     * no such massaging.
     *
     * @return array<string, mixed>
     */
    private function recordFillData(?string $resource, ?Model $record): array
    {
        if (! $record) {
            return [];
        }

        $data = $record->attributesToArray();

        if ($resource === 'follow_up' && $record instanceof FollowUp && $record->status === FollowUpStatus::Completed) {
            $callRecord = $record->generatedCallRecord;
            $data['outcome'] = $callRecord?->outcome?->value;
            $data['call_notes'] = $callRecord?->notes;
            $data['appointment_at'] = $callRecord?->appointment_at;
            $data['new_follow_up_at'] = $callRecord?->follow_up_at;
        }

        return $data;
    }

    /**
     * Mirrors EditFollowUp::handleRecordUpdate() exactly for Follow-up: a
     * genuine Pending -> Completed transition here creates a real Call
     * Record via completeWithCall() (routed through CallRoutingService
     * exactly like any other logged call), same as the row-action
     * "Completed" modal and the real Edit page both already do — never a
     * bare ->update() that would leave a Completed Follow-up with no call
     * behind it. Every other resource just updates directly.
     */
    private function saveRecordEdit(?string $resource, Model $record, array $data): void
    {
        if ($resource === 'follow_up' && $record instanceof FollowUp) {
            $outcome = Arr::pull($data, 'outcome');
            $callNotes = Arr::pull($data, 'call_notes');
            $appointmentAt = Arr::pull($data, 'appointment_at');
            $newFollowUpAt = Arr::pull($data, 'new_follow_up_at');

            $isCompleting = $record->status === FollowUpStatus::Pending
                && FollowUpResource::resolveStatus($data['status'] ?? null) === FollowUpStatus::Completed;

            if ($isCompleting) {
                $record->completeWithCall([
                    'outcome' => $outcome,
                    'notes' => $callNotes,
                    'appointment_at' => $appointmentAt,
                    'follow_up_at' => $newFollowUpAt,
                ]);
            }

            $record->update($data);

            return;
        }

        $record->update($data);
    }

    /**
     * @return array<int, array{label: string, value: string}>
     */
    private function callHistoryFields(CallRecord $call): array
    {
        return [
            ['label' => 'Outcome', 'value' => $call->outcome->getLabel()],
            ['label' => 'Called At', 'value' => $call->called_at?->format('d M Y, h:i A') ?? '—'],
            ['label' => 'Contact Person Spoken To', 'value' => $call->contact_person_spoken_to ?: '—'],
            ['label' => 'Designation', 'value' => $call->designation ?: '—'],
            ['label' => 'Phone Called', 'value' => $call->phone_called ?: '—'],
            ['label' => 'Notes', 'value' => $call->notes ?: '—'],
        ];
    }

    /**
     * @return array<int, array{label: string, value: string}>
     */
    private function followUpHistoryFields(FollowUp $followUp): array
    {
        // Completing a Follow-Up stores its Notes on the CALL RECORD that
        // completion creates (see FollowUp::completeWithCall()), never on
        // the Follow-Up's own `notes` column — mirrors the exact same
        // conditional FollowUpResource::companyFollowUpHistory() already
        // uses, which this method previously failed to (a real bug: a
        // completed Follow-Up's real Notes always read back as "—" here).
        $notes = $followUp->status === FollowUpStatus::Completed
            ? $followUp->generatedCallRecord?->notes
            : $followUp->notes;

        return [
            ['label' => 'Status', 'value' => $followUp->status->getLabel()],
            ['label' => 'Reason', 'value' => $followUp->reason ?: '—'],
            ['label' => 'Follow Up At', 'value' => $followUp->follow_up_at?->format('d M Y, h:i A') ?? '—'],
            ['label' => 'Notes', 'value' => $notes ?: '—'],
            ['label' => 'Created', 'value' => $followUp->created_at?->format('d M Y, h:i A') ?? '—'],
            ...$this->originatingCallFields($followUp->callRecord),
        ];
    }

    /**
     * @return array<int, array{label: string, value: string}>
     */
    private function appointmentHistoryFields(Appointment $appointment): array
    {
        return [
            ['label' => 'Stage', 'value' => $appointment->stage->getLabel()],
            ['label' => 'Stage Changed At', 'value' => $appointment->stage_changed_at?->format('d M Y, h:i A') ?? '—'],
            ['label' => 'Appointment At', 'value' => $appointment->appointment_at?->format('d M Y, h:i A') ?? '—'],
            ['label' => 'Meeting Notes', 'value' => $appointment->meeting_notes ?: '—'],
            ['label' => 'Outcome Notes', 'value' => $appointment->outcome_notes ?: '—'],
            ['label' => 'Lost', 'value' => $appointment->is_lost ? ('Yes — '.($appointment->lost_reason ?: 'no reason given')) : 'No'],
            ['label' => 'Created', 'value' => $appointment->created_at?->format('d M Y, h:i A') ?? '—'],
            ...$this->originatingCallFields($appointment->callRecord),
        ];
    }

    /**
     * @return array<int, array{label: string, value: string}>
     */
    private function leadHistoryFields(Lead $lead): array
    {
        return [
            ['label' => 'Stage', 'value' => $lead->stage->getLabel()],
            ['label' => 'Stage Changed At', 'value' => $lead->stage_changed_at?->format('d M Y, h:i A') ?? '—'],
            ['label' => 'Temperature', 'value' => $lead->temperature->getLabel()],
            ['label' => 'Requirement Details', 'value' => $lead->requirement_details ?: '—'],
            ['label' => 'Notes', 'value' => $lead->notes ?: '—'],
            ['label' => 'Lost', 'value' => $lead->is_lost ? ('Yes — '.($lead->lost_reason ?: 'no reason given')) : 'No'],
            ['label' => 'Created', 'value' => $lead->created_at?->format('d M Y, h:i A') ?? '—'],
            ...$this->originatingCallFields($lead->callRecord),
        ];
    }

    /**
     * The originating Call Record's own outcome/notes, surfaced as
     * read-only reference context on any record it routed to (Follow-up/
     * Appointment/Lead) — not stored on that resource's own columns at all,
     * so without this there was no way to see "why does this record
     * exist" short of separately following the lineage link.
     *
     * @return array<int, array{label: string, value: string}>
     */
    private function originatingCallFields(?CallRecord $callRecord): array
    {
        if (! $callRecord) {
            return [];
        }

        // Completing a Follow-up always creates a new Call Record (see
        // FollowUp::completeWithCall()), which CallRoutingService can then
        // route right back into ANOTHER Follow-up (e.g. a second "No
        // Answer" in a row) — so this Call being generated by an earlier
        // Follow-up's completion is not a one-off, one-hop-deeper case;
        // that earlier Follow-up can just as easily trace back through
        // another Call and another Follow-up before it, arbitrarily deep.
        // Only when this Call was directly logged (no preceding Follow-up
        // at all) does the plain single-hop Outcome/Notes pair below apply.
        if (! $callRecord->generatedByFollowUp) {
            return [
                ['label' => 'From Call — Outcome', 'value' => $callRecord->outcome->getLabel()],
                ['label' => 'From Call — Notes', 'value' => $callRecord->notes ?: '—'],
            ];
        }

        return [
            ['label' => 'Origin Chain', 'value' => $this->originatingChainDescription($callRecord)],
        ];
    }

    /**
     * Walks the full chain of Follow-up completions and Calls that led to
     * $callRecord, oldest first, ending "→ this record" — see
     * originatingCallFields() for why this can't be assumed to stop after
     * a single extra hop.
     */
    private function originatingChainDescription(CallRecord $callRecord): string
    {
        $hops = [];
        $call = $callRecord;

        while ($call !== null) {
            $callDescription = $call->outcome->getLabel();

            if (filled($call->notes)) {
                $callDescription .= ': "'.$call->notes.'"';
            }

            array_unshift($hops, "Call ({$callDescription})");

            $precedingFollowUp = $call->generatedByFollowUp;

            if (! $precedingFollowUp) {
                break;
            }

            array_unshift($hops, 'Follow-up ("'.$precedingFollowUp->reason.'")');

            $call = $precedingFollowUp->callRecord;
        }

        $hops[] = 'this record';

        return implode(' → ', $hops);
    }

    /**
     * @return array<int, array{label: string, value: string}>
     */
    private function proposalHistoryFields(Proposal $proposal): array
    {
        return [
            ['label' => 'Stage', 'value' => $proposal->stage->getLabel()],
            ['label' => 'Stage Changed At', 'value' => $proposal->stage_changed_at?->format('d M Y, h:i A') ?? '—'],
            ['label' => 'Outcome', 'value' => $proposal->outcome?->getLabel() ?? '—'],
            ['label' => 'Value', 'value' => $proposal->value ? '₹'.number_format((float) $proposal->value) : '—'],
            ['label' => 'Notes', 'value' => $proposal->notes ?: '—'],
            ['label' => 'Created', 'value' => $proposal->created_at?->format('d M Y, h:i A') ?? '—'],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function recordLineage(?string $resource, Model $record): array
    {
        return match ($resource) {
            'call' => $this->callLineage($record),
            'follow_up' => $this->followUpLineage($record),
            'appointment' => $this->appointmentLineage($record),
            'lead' => $this->leadLineage($record),
            'proposal' => $this->proposalLineage($record),
            default => [],
        };
    }

    /**
     * @return array<int, string>
     */
    private function callLineage(CallRecord $call): array
    {
        $lines = [
            $call->follow_up_id
                ? 'Generated by completing Follow-up #'.$call->follow_up_id
                : 'Logged directly — not generated by completing a Follow-up',
        ];

        if ($call->followUp) {
            $lines[] = 'Created Follow-up #'.$call->followUp->id;
        }
        if ($call->appointment) {
            $lines[] = 'Created Appointment #'.$call->appointment->id;
        }
        if ($call->lead) {
            $lines[] = 'Created Lead #'.$call->lead->id;
        }

        return $lines;
    }

    /**
     * @return array<int, string>
     */
    private function followUpLineage(FollowUp $followUp): array
    {
        $lines = [
            $followUp->call_record_id
                ? 'Created from Call #'.$followUp->call_record_id
                : 'Created directly — not from a logged call',
        ];

        if ($followUp->generatedCallRecord) {
            $lines[] = 'Completing this created Call #'.$followUp->generatedCallRecord->id;
        }

        return $lines;
    }

    /**
     * @return array<int, string>
     */
    private function appointmentLineage(Appointment $appointment): array
    {
        return [
            $appointment->call_record_id
                ? 'Created from Call #'.$appointment->call_record_id
                : 'Created directly — not from a logged call',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function leadLineage(Lead $lead): array
    {
        $lines = [
            $lead->call_record_id
                ? 'Created from Call #'.$lead->call_record_id
                : 'Created directly — not from a logged call',
        ];

        if ($lead->proposal) {
            $lines[] = 'Has Proposal #'.$lead->proposal->id;
        }

        return $lines;
    }

    /**
     * @return array<int, string>
     */
    private function proposalLineage(Proposal $proposal): array
    {
        return ['Created from Lead #'.$proposal->lead_id];
    }

    private function dropModalHeading(array $arguments): string
    {
        $record = $this->resolveDropRecord($arguments);
        $label = $this->targetStageLabel($arguments['resource'] ?? null, (string) ($arguments['stage'] ?? ''));

        return ($record?->prospect?->company_name ?? 'Company').' → '.$label;
    }

    private function crossDropModalHeading(array $arguments): string
    {
        $source = $this->resolveDropRecord(['resource' => $arguments['sourceResource'] ?? null, 'id' => $arguments['sourceId'] ?? null]);
        $company = $source?->prospect?->company_name ?? 'Company';

        if (($arguments['sourceResource'] ?? null) === 'call') {
            return "{$company} → Log a New Call";
        }

        $destLabel = $this->resourceLabel($arguments['destResource'] ?? '');

        return "{$company} → New {$destLabel}";
    }

    /**
     * @return array<int, Forms\Components\Component>
     */
    private function dropFormSchema(array $arguments): array
    {
        if (! $this->isDropEligible($arguments)) {
            return [
                Forms\Components\Placeholder::make('unsupported')
                    ->label('Not available')
                    ->content($this->unsupportedDropReason($arguments)),
            ];
        }

        $resource = $arguments['resource'] ?? null;
        $stage = (string) ($arguments['stage'] ?? '');
        $record = $this->resolveDropRecord($arguments);

        return $this->stageFields($resource, $stage, '', $record);
    }

    /**
     * Lost is a one-way door for Lead, matching the rest of the app —
     * there is no "un-lose" mechanism anywhere else (is_lost/lost_reason
     * aren't even in Lead's own $fillable, only markLost() sets them via
     * forceFill()), so dragging a Lost Lead into any active stage is
     * rejected outright — a genuine drop-eligibility gate, mirroring the
     * same submit-disabled placeholder pattern crossDropAction() already
     * uses for its own blocked cases, rather than silently reviving it or
     * failing quietly while still claiming success.
     */
    private function isDropEligible(array $arguments): bool
    {
        $resource = $arguments['resource'] ?? null;
        $stage = (string) ($arguments['stage'] ?? '');
        $record = $this->resolveDropRecord($arguments);

        if ($resource === 'lead' && $record instanceof Lead && $record->is_lost && $stage !== 'lost') {
            return false;
        }

        return true;
    }

    private function unsupportedDropReason(array $arguments): string
    {
        return 'This Lead is marked Lost — open it directly if you need to revisit that.';
    }

    private function isCrossDropEligible(array $arguments): bool
    {
        $source = $this->resolveDropRecord(['resource' => $arguments['sourceResource'] ?? null, 'id' => $arguments['sourceId'] ?? null]);

        return $this->crossDropSupported(
            $arguments['sourceResource'] ?? null,
            $arguments['destResource'] ?? null,
            $source,
            (string) ($arguments['destStage'] ?? '')
        );
    }

    /**
     * @return array<int, Forms\Components\Component>
     */
    private function crossDropFormSchema(array $arguments): array
    {
        $sourceResource = $arguments['sourceResource'] ?? null;
        $destResource = $arguments['destResource'] ?? null;
        $destStage = (string) ($arguments['destStage'] ?? '');
        $source = $this->resolveDropRecord(['resource' => $sourceResource, 'id' => $arguments['sourceId'] ?? null]);

        if (! $this->crossDropSupported($sourceResource, $destResource, $source, $destStage)) {
            return [
                Forms\Components\Placeholder::make('unsupported')
                    ->label('Not available yet')
                    ->content($this->unsupportedCrossDropReason($sourceResource, $destResource, $source, $destStage)),
            ];
        }

        // Dragging a Call card doesn't create the destination type directly
        // at all — see performCrossDrop() and the class docblock — so this
        // dialog is an entirely different form: the same Call Details
        // fields CallRecordResource's own create form asks for (minus
        // Company, which is already implied by the dragged card).
        if ($sourceResource === 'call') {
            return [
                Forms\Components\Section::make('Log a New Call')
                    ->schema($this->callLogFormSchema()),
            ];
        }

        // A destination Follow-up is always created Pending — see the class
        // docblock for why "Completed" can't be a valid initial state.
        $destinationStageForCreate = $destResource === 'follow_up' ? FollowUpStatus::Pending->value : $destStage;

        $schema = [
            Forms\Components\Section::make('New '.$this->resourceLabel($destResource))
                ->schema(array_merge(
                    $this->creationFields($destResource, 'destination_'),
                    $this->stageFields($destResource, $destinationStageForCreate, 'destination_', null),
                )),
        ];

        if ($source && ! $this->isAlreadyResolved($sourceResource, $source)) {
            $sourceFields = $this->stageFields($sourceResource, $this->forwardStageFor($sourceResource), 'source_', $source);

            if ($sourceFields !== []) {
                $schema[] = Forms\Components\Section::make('Also resolving: '.$this->resourceLabel($sourceResource))
                    ->schema($sourceFields);
            }
        }

        return $schema;
    }

    /**
     * The one place every per-resource/per-stage "what extra fields does
     * landing on this stage require" rule lives — shared by the same-lane
     * dialog (prefix '') and both halves of the cross-lane dialog (prefix
     * 'destination_'/'source_'), so there is exactly one definition of e.g.
     * "Appointment requires Outcome Notes once terminal", not one per
     * dialog.
     *
     * @return array<int, Forms\Components\Component>
     */
    private function stageFields(?string $resource, ?string $stage, string $prefix, ?Model $record): array
    {
        return match ($resource) {
            'appointment' => $this->appointmentStageFields($stage, $record, $prefix),
            'lead' => $this->leadStageFields($stage, $record, $prefix),
            'proposal' => $this->proposalStageFields($stage, $record, $prefix),
            'follow_up' => $this->followUpStageFields($stage, $record, $prefix),
            default => [],
        };
    }

    /**
     * @return array<int, Forms\Components\Component>
     */
    private function appointmentStageFields(?string $stage, ?Appointment $record, string $prefix): array
    {
        $resolved = AppointmentStage::tryFrom((string) $stage);

        // Mirrors AppointmentResource::form()'s own meeting_notes field
        // exactly — optional there, so optional here too rather than
        // introducing a stricter rule the resource's own form doesn't have.
        if ($resolved === AppointmentStage::VisitConducted) {
            return [
                Forms\Components\Textarea::make("{$prefix}meeting_notes")
                    ->label('Meeting Notes')
                    ->rows(3)
                    ->default($record?->meeting_notes),
            ];
        }

        // Discussion Completed writes into the SAME outcome_notes column the
        // terminal branch below required()s — not yet required here (the
        // model only requires it once terminal, matching
        // AppointmentResource::form()'s own outcome_notes rule), but the
        // terminal branch's ->default($record?->outcome_notes) means
        // whatever's typed here carries forward automatically once the
        // Appointment actually reaches Succeeded/Not Succeeded, so the rep
        // isn't retyping the same context.
        if ($resolved === AppointmentStage::DiscussionCompleted) {
            return [
                Forms\Components\Textarea::make("{$prefix}outcome_notes")
                    ->label('Outcome Notes')
                    ->rows(3)
                    ->default($record?->outcome_notes),
            ];
        }

        if (! ($resolved?->isTerminal() ?? false)) {
            return [];
        }

        return [
            Forms\Components\Textarea::make("{$prefix}outcome_notes")
                ->label('Outcome Notes')
                ->rows(3)
                ->required()
                ->default($record?->outcome_notes)
                // Not Succeeded now also marks the Appointment Lost (see
                // dropAppointment()) — this same text is stored as the Lost
                // reason too, rather than asking for near-duplicate content
                // twice in one dialog.
                ->helperText($resolved === AppointmentStage::NotSucceeded
                    ? 'Required — also recorded as this Appointment\'s Lost reason.'
                    : null),
        ];
    }

    /**
     * @return array<int, Forms\Components\Component>
     */
    private function leadStageFields(?string $stage, ?Lead $record, string $prefix): array
    {
        // "lost" is a board-only display grouping (see leadLane()'s own
        // extractLostBox()), not a real LeadStage case — dragging onto it
        // asks for the same Reason field LeadResource's own "Mark Lost" row
        // action does, mirrored here exactly.
        if ($stage === 'lost') {
            return [
                Forms\Components\Textarea::make("{$prefix}reason")
                    ->label('Reason')
                    ->required()
                    ->rows(3)
                    ->helperText('Required — why this Lead is being marked Lost.'),
            ];
        }

        if ($stage !== LeadStage::Validated->value) {
            return [];
        }

        return [
            Forms\Components\Textarea::make("{$prefix}notes")
                ->label('Notes / Remarks')
                ->rows(3)
                ->required()
                ->default($record?->notes),
        ];
    }

    /**
     * @return array<int, Forms\Components\Component>
     */
    private function proposalStageFields(?string $stage, ?Proposal $record, string $prefix): array
    {
        $resetFields = $this->proposalOutcomeResetFields($stage, $record, $prefix);

        if ($stage === ProposalStage::Sent->value) {
            // Same fields/config as ProposalResource::form()'s own value/
            // sent_at/pdf_path — neither value nor sent_at is required
            // there either, so this dialog mirrors that exactly rather than
            // introducing a stricter rule the resource's own form doesn't
            // have. pdf_path is required the moment the stage is Sent, same
            // disk/visibility/validation, so this dialog can never accept
            // something that Proposal's own Edit form would reject.
            return array_merge($resetFields, [
                Forms\Components\TextInput::make("{$prefix}value")
                    ->label('Proposal Value (₹)')
                    ->numeric()
                    ->prefix('₹')
                    ->default($record?->value),
                Forms\Components\DatePicker::make("{$prefix}sent_at")
                    ->default($record?->sent_at),
                Forms\Components\FileUpload::make("{$prefix}pdf_path")
                    ->label('Proposal PDF')
                    ->disk('local')
                    ->directory('proposal-pdfs')
                    ->visibility('private')
                    ->acceptedFileTypes(['application/pdf'])
                    ->maxSize(10240)
                    ->previewable(false)
                    ->default($record?->pdf_path)
                    ->required()
                    ->deleteUploadedFileUsing(function (string|TemporaryUploadedFile $file): void {
                        if (is_string($file)) {
                            Storage::disk('local')->delete($file);
                        }
                    }),
            ]);
        }

        // Confirmed: dragging all the way to Accepted/Rejected also sets the
        // Final Outcome in this same action (Won/Lost respectively) rather
        // than leaving that as a separate manual step — and since Won/Lost
        // both require Notes per Proposal's own model guard
        // (Proposal::booted()), this dialog asks for it here too.
        if (in_array($stage, [ProposalStage::CustomerAccepted->value, ProposalStage::CustomerRejected->value], true)) {
            $outcomeLabel = $stage === ProposalStage::CustomerAccepted->value ? 'Won' : 'Lost';

            return [
                Forms\Components\Placeholder::make("{$prefix}outcome_preview")
                    ->label('Final Outcome')
                    ->content($outcomeLabel),
                Forms\Components\Textarea::make("{$prefix}notes")
                    ->label('Notes')
                    ->rows(3)
                    ->required()
                    ->default($record?->notes)
                    ->helperText("Required — Final Outcome {$outcomeLabel} always needs Notes."),
            ];
        }

        // Being Prepared with no outcome to reset: no fields at all, same
        // as before.
        return $resetFields;
    }

    /**
     * Moving backward out of a decided Final Outcome (Won/Lost) into a
     * non-terminal stage doesn't silently drop that decision — outcome and
     * stage are independent Proposal columns with no automatic sync
     * anywhere in the app (ProposalResource's own Edit form treats Final
     * Outcome as a plain, independently-editable Select the human manages
     * themselves), so the rep must explicitly confirm clearing it via this
     * required, ->accepted() checkbox. Once Filament's own validation lets
     * the submission through at all, dropProposal() knows the confirmation
     * already happened and clears `outcome` unconditionally.
     *
     * @return array<int, Forms\Components\Component>
     */
    private function proposalOutcomeResetFields(?string $stage, ?Proposal $record, string $prefix): array
    {
        if (! in_array($stage, [ProposalStage::BeingPrepared->value, ProposalStage::Sent->value], true)) {
            return [];
        }

        if ($record?->outcome === null) {
            return [];
        }

        return [
            Forms\Components\Placeholder::make("{$prefix}outcome_reset_notice")
                ->label('Final Outcome will be cleared')
                ->content("Currently {$record->outcome->getLabel()} — moving this Proposal back means it's no longer decided."),
            Forms\Components\Checkbox::make("{$prefix}confirm_outcome_reset")
                ->label('Yes, clear the Final Outcome and move this Proposal back')
                ->required()
                ->accepted(),
        ];
    }

    /**
     * @return array<int, Forms\Components\Component>
     */
    private function followUpStageFields(?string $stage, ?FollowUp $record, string $prefix): array
    {
        if ($stage === FollowUpStatus::Completed->value) {
            // Mirrors FollowUpResource::table()'s "Completed" row-action
            // modal exactly — an outcome here can route to an Appointment
            // and/or a new Follow-up exactly like any other logged call
            // (FollowUp::completeWithCall() doesn't treat this any
            // differently), so the same conditional fields are needed here.
            return [
                Forms\Components\Select::make("{$prefix}outcome")
                    ->label('Call Outcome')
                    ->options(CallOutcome::class)
                    ->required()
                    ->live()
                    ->helperText('You reached them — log what happened on this call.'),
                Forms\Components\DateTimePicker::make("{$prefix}appointment_at")
                    ->label('Appointment At')
                    ->seconds(false)
                    ->visible(fn (Forms\Get $get) => CallOutcome::tryFrom((string) $get("{$prefix}outcome"))?->routesToAppointment() ?? false)
                    ->required(fn (Forms\Get $get) => CallOutcome::tryFrom((string) $get("{$prefix}outcome"))?->routesToAppointment() ?? false),
                Forms\Components\DateTimePicker::make("{$prefix}new_follow_up_at")
                    ->label('Follow Up At')
                    ->seconds(false)
                    ->visible(fn (Forms\Get $get) => CallOutcome::tryFrom((string) $get("{$prefix}outcome"))?->routesToFollowUp() ?? false)
                    ->required(fn (Forms\Get $get) => CallOutcome::tryFrom((string) $get("{$prefix}outcome"))?->routesToFollowUp() ?? false),
                Forms\Components\Textarea::make("{$prefix}notes")
                    ->label('Notes')
                    ->rows(3)
                    ->required(),
            ];
        }

        if ($stage === FollowUpStatus::Cancelled->value) {
            return [
                Forms\Components\Textarea::make("{$prefix}notes")
                    ->label('Notes')
                    ->rows(3)
                    ->required()
                    ->default($record?->notes)
                    ->helperText('Required — why this follow-up is being closed.'),
            ];
        }

        return [];
    }

    /**
     * Baseline fields a brand-new record of this type needs regardless of
     * which stage it's created at — only relevant for cross-lane
     * destination creation, since same-lane dragging always has an
     * already-existing record that already satisfies these.
     *
     * @return array<int, Forms\Components\Component>
     */
    private function creationFields(string $resource, string $prefix): array
    {
        return match ($resource) {
            'follow_up' => [
                Forms\Components\TextInput::make("{$prefix}reason")
                    ->label('Reason')
                    ->required()
                    ->maxLength(255),
                Forms\Components\DateTimePicker::make("{$prefix}follow_up_at")
                    ->label('Followed Up At')
                    ->seconds(false)
                    ->required()
                    ->default(now()),
            ],
            'appointment' => [
                Forms\Components\DateTimePicker::make("{$prefix}appointment_at")
                    ->label('Appointment At')
                    ->seconds(false)
                    ->required()
                    ->default(now()),
            ],
            'lead' => [
                Forms\Components\Select::make("{$prefix}temperature")
                    ->label('Temperature')
                    ->options(LeadTemperature::class)
                    ->required()
                    ->default(LeadTemperature::Warm),
            ],
            default => [],
        };
    }

    /**
     * Mirrors CallRecordResource::form()'s own Call Details fields exactly —
     * same validation/config, same outcome-driven conditional visibility for
     * Follow Up At/Appointment At/Notes — minus the Company select, which is
     * already implied by which Call card was dragged. Used only by the
     * cross-lane dialog when the dragged source is itself a Call (see
     * crossDropFormSchema()/logNewCall()); Call is never a same-lane drag
     * (it has only the one box) and never a cross-lane destination. The
     * "+ Log a call" header action (getHeaderActions()) reuses
     * CallRecordResource::formSchema() directly instead, since it needs the
     * real Company select too.
     *
     * @return array<int, Forms\Components\Component>
     */
    private function callLogFormSchema(string $prefix = ''): array
    {
        return [
            Forms\Components\DateTimePicker::make("{$prefix}called_at")
                ->label('Called At')
                ->seconds(false)
                ->required()
                ->default(now()),
            Forms\Components\Select::make("{$prefix}outcome")
                ->label('Call Outcome')
                ->options(CallOutcome::class)
                ->required()
                ->live()
                ->helperText('Determines what happens next — see the Follow-Ups, Appointments, and Leads panels.'),
            Forms\Components\TextInput::make("{$prefix}contact_person_spoken_to")
                ->label('Contact Person Spoken To')
                ->maxLength(255),
            Forms\Components\TextInput::make("{$prefix}designation")
                ->label('Designation')
                ->placeholder('e.g. Manager, Owner, Procurement Head')
                ->maxLength(255),
            Forms\Components\TextInput::make("{$prefix}phone_called")
                ->label('Phone Called')
                ->tel()
                ->maxLength(20),
            Forms\Components\DateTimePicker::make("{$prefix}follow_up_at")
                ->label('Follow Up At')
                ->seconds(false)
                ->visible(fn (Forms\Get $get) => CallOutcome::tryFrom((string) $get("{$prefix}outcome"))?->routesToFollowUp() ?? false)
                ->required(fn (Forms\Get $get) => CallOutcome::tryFrom((string) $get("{$prefix}outcome"))?->routesToFollowUp() ?? false),
            Forms\Components\DateTimePicker::make("{$prefix}appointment_at")
                ->label('Appointment At')
                ->seconds(false)
                ->visible(fn (Forms\Get $get) => CallOutcome::tryFrom((string) $get("{$prefix}outcome"))?->routesToAppointment() ?? false)
                ->required(fn (Forms\Get $get) => CallOutcome::tryFrom((string) $get("{$prefix}outcome"))?->routesToAppointment() ?? false),
            Forms\Components\Textarea::make("{$prefix}notes")
                ->label('Call Notes')
                ->rows(3)
                ->required(fn (Forms\Get $get) => CallOutcome::tryFrom((string) $get("{$prefix}outcome"))?->requiresNotes() ?? false)
                ->validationMessages([
                    'required' => 'Notes are required for this outcome — only No Answer, Switched Off, and Not Reachable are exempt.',
                ]),
        ];
    }

    private function performDrop(array $arguments, array $data): void
    {
        match ($arguments['resource'] ?? null) {
            'appointment' => $this->dropAppointment($arguments, $data),
            'lead' => $this->dropLead($arguments, $data),
            'proposal' => $this->dropProposal($arguments, $data),
            'follow_up' => $this->dropFollowUp($arguments, $data),
            default => null,
        };
    }

    private function dropAppointment(array $arguments, array $data): void
    {
        $resolved = AppointmentStage::tryFrom((string) ($arguments['stage'] ?? ''));
        /** @var ?Appointment $appointment */
        $appointment = $this->resolveDropRecord($arguments);

        if (! $appointment || ! $resolved || $appointment->stage === $resolved) {
            return;
        }

        $this->authorizeUpdate($appointment);

        if ($resolved === AppointmentStage::NotSucceeded) {
            $this->applyAppointmentNotSucceeded($appointment, $data);

            return;
        }

        $update = ['stage' => $resolved];

        if ($resolved === AppointmentStage::VisitConducted) {
            $update['meeting_notes'] = $data['meeting_notes'] ?? null;
        } elseif ($resolved === AppointmentStage::DiscussionCompleted || $resolved->isTerminal()) {
            $update['outcome_notes'] = $data['outcome_notes'] ?? null;
        }

        $this->applyDrop($appointment, $update, $resolved->getLabel());
    }

    /**
     * Not Succeeded always means Lost now — there is no separate Mark Lost
     * button any more (see the class docblock). is_lost/lost_at_stage/
     * lost_reason/lost_at are deliberately absent from Appointment's own
     * $fillable (see Appointment::markLost(), which forceFill()s them for
     * the same reason), so this can't go through applyDrop()'s plain
     * ->update() the way every other same-lane drop does — those keys
     * would silently be dropped. lost_at_stage captures the PRE-drop stage
     * (not NotSucceeded itself, which would just duplicate `stage` and
     * lose the "what stage was this really sitting in" signal the field
     * exists for), and lost_reason reuses the same Outcome Notes text
     * rather than asking twice for near-duplicate content.
     */
    private function applyAppointmentNotSucceeded(Appointment $appointment, array $data): void
    {
        $lostAtStage = $appointment->stage;

        try {
            $appointment->forceFill([
                'stage' => AppointmentStage::NotSucceeded,
                'outcome_notes' => $data['outcome_notes'] ?? null,
                'is_lost' => true,
                'lost_at_stage' => $lostAtStage,
                'lost_reason' => $data['outcome_notes'] ?? null,
                'lost_at' => now(),
            ])->save();
        } catch (\LogicException $e) {
            Notification::make()->title("Couldn't move to Not Succeeded")->body($e->getMessage())->danger()->send();
            throw new Halt;
        }

        Notification::make()->title('Moved to Not Succeeded')->success()->send();
    }

    private function dropLead(array $arguments, array $data): void
    {
        $stage = (string) ($arguments['stage'] ?? '');
        /** @var ?Lead $lead */
        $lead = $this->resolveDropRecord($arguments);

        if (! $lead) {
            return;
        }

        // "lost" is a board-only display grouping, not a real LeadStage —
        // dragging onto it calls the exact same markLost() LeadResource's
        // own row action does; `stage` deliberately stays untouched (see
        // Lead::markLost()'s own docblock: "Lost is an outcome applied on
        // top of wherever the Lead currently is"), unlike Not Succeeded on
        // the Appointment lane above.
        if ($stage === 'lost') {
            if ($lead->is_lost) {
                return;
            }

            $this->authorizeUpdate($lead);
            $lead->markLost($data['reason'] ?? '');
            Notification::make()->title('Marked Lost')->success()->send();

            return;
        }

        // Lost is a one-way door — re-verified here server-side, not just
        // in isDropEligible()'s dialog-gating, since arguments travel from
        // client-side JS (same reasoning as the cross-drop eligibility
        // re-checks elsewhere in this file).
        if ($lead->is_lost) {
            return;
        }

        $resolved = LeadStage::tryFrom($stage);

        if (! $resolved || $lead->stage === $resolved) {
            return;
        }

        $this->authorizeUpdate($lead);

        $update = ['stage' => $resolved];

        if ($resolved === LeadStage::Validated) {
            $update['notes'] = $data['notes'] ?? null;
        }

        $this->applyDrop($lead, $update, $resolved->getLabel());
    }

    private function dropProposal(array $arguments, array $data): void
    {
        $resolved = ProposalStage::tryFrom((string) ($arguments['stage'] ?? ''));
        /** @var ?Proposal $proposal */
        $proposal = $this->resolveDropRecord($arguments);

        if (! $proposal || ! $resolved || $proposal->stage === $resolved) {
            return;
        }

        $this->authorizeUpdate($proposal);

        $update = ['stage' => $resolved];

        if ($resolved === ProposalStage::Sent) {
            $update['pdf_path'] = $data['pdf_path'] ?? null;
            $update['value'] = $data['value'] ?? null;
            $update['sent_at'] = $data['sent_at'] ?? null;
        } elseif ($resolved === ProposalStage::CustomerAccepted) {
            $update['outcome'] = ProposalOutcome::Won;
            $update['notes'] = $data['notes'] ?? null;
        } elseif ($resolved === ProposalStage::CustomerRejected) {
            $update['outcome'] = ProposalOutcome::Lost;
            $update['notes'] = $data['notes'] ?? null;
        }

        // Moving backward out of a decided Final Outcome — the dialog's own
        // required ->accepted() checkbox (see proposalOutcomeResetFields())
        // already gated reaching this line at all whenever there was an
        // outcome to clear, so it's cleared unconditionally here.
        if (! $resolved->isTerminal() && $proposal->outcome !== null) {
            $update['outcome'] = null;
        }

        $this->applyDrop($proposal, $update, $resolved->getLabel());
    }

    /**
     * Follow-up's own model guard rejects a blank Follow Up At on update
     * only when it's actually dirty (see FollowUp::booted()), so a plain
     * ->update(['status' => Cancelled, 'notes' => ...]) here behaves
     * identically to the existing "Close" row action. Completed goes
     * through the shared FollowUp::completeWithCall() instead — never a raw
     * ->update() — since it also has to create the real Call Record.
     * Dragging back onto Pending isn't a supported transition (there is no
     * "reopen" action anywhere else in the app either) and is a no-op.
     */
    private function dropFollowUp(array $arguments, array $data): void
    {
        $resolved = FollowUpStatus::tryFrom((string) ($arguments['stage'] ?? ''));
        /** @var ?FollowUp $followUp */
        $followUp = $this->resolveDropRecord($arguments);

        if (! $followUp || ! $resolved || $followUp->status === $resolved || $resolved === FollowUpStatus::Pending) {
            return;
        }

        $this->authorizeUpdate($followUp);

        if ($resolved === FollowUpStatus::Completed) {
            $this->completeFollowUp($followUp, $data, '');

            return;
        }

        $this->applyDrop($followUp, ['status' => FollowUpStatus::Cancelled, 'notes' => $data['notes'] ?? null], 'Cancelled');
    }

    private function completeFollowUp(FollowUp $followUp, array $data, string $prefix): void
    {
        try {
            $followUp->completeWithCall([
                'outcome' => $data["{$prefix}outcome"] ?? null,
                'notes' => $data["{$prefix}notes"] ?? null,
                'appointment_at' => $data["{$prefix}appointment_at"] ?? null,
                'follow_up_at' => $data["{$prefix}new_follow_up_at"] ?? null,
            ]);
        } catch (\LogicException $e) {
            Notification::make()->title("Couldn't move to Completed")->body($e->getMessage())->danger()->send();
            throw new Halt;
        }

        Notification::make()->title('Moved to Completed')->success()->send();
    }

    /**
     * The actual write — an ordinary Eloquent ->update(), so every existing
     * model `saving()` guard (mandatory Outcome Notes/Notes, etc.) fires
     * exactly as it would from that resource's own Edit form. A guard
     * throwing \LogicException (defense in depth catching something the
     * dialog's own ->required() should already have stopped) is surfaced as
     * a friendly notification rather than a raw error page — mirrors the
     * Notification+Halt pattern already used elsewhere in this app (see
     * UserResource's delete-guard notifications).
     */
    private function applyDrop(Model $record, array $update, string $targetLabel): void
    {
        try {
            $record->update($update);
        } catch (\LogicException $e) {
            Notification::make()->title("Couldn't move to {$targetLabel}")->body($e->getMessage())->danger()->send();
            throw new Halt;
        }

        Notification::make()->title("Moved to {$targetLabel}")->success()->send();
    }

    /**
     * Two real writes in one transaction — see the class docblock. Both the
     * eligibility check (Proposal needs a Validated Lead source) and the
     * "already resolved, skip source resolution" check are re-verified here
     * server-side, not just in the form-schema/modalSubmitAction closures,
     * since arguments travel from client-side JS.
     */
    private function performCrossDrop(array $arguments, array $data): void
    {
        $sourceResource = $arguments['sourceResource'] ?? null;
        $destResource = $arguments['destResource'] ?? null;
        $destStage = (string) ($arguments['destStage'] ?? '');

        $source = $this->resolveDropRecord(['resource' => $sourceResource, 'id' => $arguments['sourceId'] ?? null]);

        if (! $source || ! $this->crossDropSupported($sourceResource, $destResource, $source, $destStage)) {
            return;
        }

        $this->authorizeUpdate($source);

        if ($sourceResource === 'call') {
            $this->logNewCall($source, $data);

            return;
        }

        try {
            DB::transaction(function () use ($sourceResource, $source, $destResource, $destStage, $data) {
                $this->createCrossDropDestination($destResource, $destStage, $source, $data);

                if (! $this->isAlreadyResolved($sourceResource, $source)) {
                    $this->resolveCrossDropSource($sourceResource, $source, $data);
                }
            });
        } catch (\LogicException $e) {
            Notification::make()->title("Couldn't complete the move")->body($e->getMessage())->danger()->send();
            throw new Halt;
        }

        $company = $source->prospect?->company_name ?? 'this company';
        Notification::make()->title('Created '.$this->resourceLabel($destResource)." for {$company}")->success()->send();
    }

    /**
     * Dragging a Call card logs a brand-new Call Record for the same
     * Prospect — an ordinary Eloquent ::create(), so CallRecordObserver
     * fires exactly as it would from CallRecordResource's own Create page,
     * routing to whichever of Follow-up/Appointment/Lead the picked outcome
     * implies via the normal CallRoutingService (see the class docblock).
     * The dragged Call Record itself is never touched — same as logging a
     * fresh call always would be, regardless of which existing call led the
     * rep to place this one.
     */
    private function logNewCall(Model $source, array $data): void
    {
        $prospect = $source->prospect;

        try {
            CallRecord::create([
                'prospect_id' => $prospect->id,
                'user_id' => auth()->id(),
                'called_at' => $data['called_at'] ?? now(),
                'outcome' => $data['outcome'] ?? null,
                'notes' => $data['notes'] ?? null,
                'follow_up_at' => $data['follow_up_at'] ?? null,
                'appointment_at' => $data['appointment_at'] ?? null,
                'contact_person_spoken_to' => $data['contact_person_spoken_to'] ?? null,
                'designation' => $data['designation'] ?? null,
                'phone_called' => $data['phone_called'] ?? null,
            ]);
        } catch (\LogicException $e) {
            Notification::make()->title("Couldn't log the call")->body($e->getMessage())->danger()->send();
            throw new Halt;
        }

        Notification::make()->title('Logged a new call for '.($prospect?->company_name ?? 'this company'))->success()->send();
    }

    private function createCrossDropDestination(string $destResource, string $destStage, Model $source, array $data): void
    {
        $prospect = $source->prospect;
        $assignedTo = $prospect?->assigned_to ?? auth()->id();

        match ($destResource) {
            'follow_up' => FollowUp::create([
                'prospect_id' => $prospect->id,
                'user_id' => $assignedTo,
                'reason' => $data['destination_reason'] ?? null,
                'follow_up_at' => $data['destination_follow_up_at'] ?? null,
                // Always Pending regardless of which box was dropped onto —
                // see the class docblock.
                'status' => FollowUpStatus::Pending,
            ]),
            'appointment' => Appointment::create([
                'prospect_id' => $prospect->id,
                'assigned_to' => $assignedTo,
                'created_by' => auth()->id(),
                'appointment_at' => $data['destination_appointment_at'] ?? null,
                'stage' => $destStage,
                'meeting_notes' => $data['destination_meeting_notes'] ?? null,
                'outcome_notes' => $data['destination_outcome_notes'] ?? null,
            ]),
            'lead' => Lead::create([
                'prospect_id' => $prospect->id,
                'assigned_to' => $assignedTo,
                'created_by' => auth()->id(),
                'stage' => $destStage,
                'temperature' => $data['destination_temperature'] ?? LeadTemperature::Warm,
                'notes' => $data['destination_notes'] ?? null,
            ]),
            'proposal' => Proposal::create([
                'lead_id' => $source instanceof Lead ? $source->id : null,
                'prospect_id' => $prospect->id,
                'assigned_to' => $assignedTo,
                'created_by' => auth()->id(),
                'stage' => $destStage,
                'pdf_path' => $data['destination_pdf_path'] ?? null,
                'value' => $data['destination_value'] ?? null,
                'sent_at' => $data['destination_sent_at'] ?? null,
                'outcome' => match ($destStage) {
                    ProposalStage::CustomerAccepted->value => ProposalOutcome::Won,
                    ProposalStage::CustomerRejected->value => ProposalOutcome::Lost,
                    default => null,
                },
                'notes' => $data['destination_notes'] ?? null,
            ]),
            default => null,
        };
    }

    private function resolveCrossDropSource(string $sourceResource, Model $source, array $data): void
    {
        match ($sourceResource) {
            'appointment' => $source->update([
                'stage' => AppointmentStage::Succeeded,
                'outcome_notes' => $data['source_outcome_notes'] ?? null,
            ]),
            'lead' => $source->update([
                'stage' => LeadStage::Validated,
                'notes' => $data['source_notes'] ?? null,
            ]),
            'proposal' => $source->update([
                'stage' => ProposalStage::CustomerAccepted,
                'outcome' => ProposalOutcome::Won,
                'notes' => $data['source_notes'] ?? null,
            ]),
            'follow_up' => $this->completeFollowUp($source, $data, 'source_'),
            default => null,
        };
    }

    /**
     * Proposal is the one destination type that isn't always well-defined —
     * it always needs a real lead_id, so it's only a valid cross-lane
     * destination when the dragged source is itself a Lead (using that
     * Lead's own id) that has actually reached Validated AND doesn't already
     * have one — mirrors LeadResource's own "Create Proposal" row action's
     * visibility condition (`$record->stage->isEligibleForProposal() &&
     * $record->proposal === null`) exactly, rather than allowing the board
     * to create a Proposal the rest of the app would never let you reach
     * this way. Missing the second half of that condition is exactly how a
     * live-browser check caught a real \Illuminate\Database\
     * UniqueConstraintViolationException on `proposals_lead_id_unique` —
     * the seeded Metro Auto Components Lead already has a Sent Proposal.
     */
    private function crossDropSupported(?string $sourceResource, ?string $destResource, ?Model $source, string $destStage = ''): bool
    {
        $validSourceResources = ['follow_up', 'appointment', 'lead', 'proposal', 'call'];
        $validDestResources = ['follow_up', 'appointment', 'lead', 'proposal'];

        if (! in_array($sourceResource, $validSourceResources, true) || ! in_array($destResource, $validDestResources, true)) {
            return false;
        }

        if ($sourceResource === $destResource) {
            return false;
        }

        // Lead's "lost" box is a board-only display grouping, not a real
        // LeadStage a brand-new Lead could ever be created at — a Lead
        // needs a real stage before "Lost" (an outcome applied on top of
        // wherever it already is) can mean anything. Drag an EXISTING Lead
        // card there instead (see dropLead()).
        if ($destResource === 'lead' && $destStage === 'lost') {
            return false;
        }

        if ($destResource === 'proposal') {
            return $sourceResource === 'lead'
                && $source instanceof Lead
                && $source->stage->isEligibleForProposal()
                && $source->proposal === null;
        }

        // Dragging a Call card never creates a Follow-up/Appointment/Lead
        // directly — it logs a brand-new Call Record for the same Prospect,
        // through the exact same CallRoutingService/CallRecordObserver path
        // a fresh "Log a Call" goes through (see performCrossDrop()), and
        // lets the picked outcome decide what (if anything) gets created
        // downstream, exactly like any other logged call. So it's always
        // eligible into any of the three lanes — logging another real call
        // for the same prospect is always a legitimate action, unlike the
        // other resources above there's no "already linked" restriction.
        if ($sourceResource === 'call') {
            return $source instanceof CallRecord;
        }

        return true;
    }

    /**
     * The specific reason a blocked cross-drop into Proposal isn't
     * supported, so the dialog tells the truth about *this* card rather
     * than always showing the same generic "needs a Validated Lead"
     * message even when the dragged Lead already is one (e.g. it's
     * Validated but already has a Proposal — a different, equally real
     * reason to block it).
     */
    private function unsupportedCrossDropReason(?string $sourceResource, ?string $destResource, ?Model $source, string $destStage = ''): string
    {
        if ($destResource === 'lead' && $destStage === 'lost') {
            return 'A new Lead can\'t be created directly as Lost — drag an existing Lead card into this box instead.';
        }

        if ($destResource === 'proposal') {
            if ($sourceResource !== 'lead' || ! $source instanceof Lead) {
                return 'A Proposal needs an existing, Validated Lead behind it — drag a Validated Lead card into this lane instead.';
            }

            if (! $source->stage->isEligibleForProposal()) {
                return 'This Lead needs to reach Validated before it can have a Proposal.';
            }

            if ($source->proposal !== null) {
                return 'This Lead already has a Proposal — open it directly instead of creating a new one.';
            }

            return 'This combination is not supported yet.';
        }

        return 'This combination is not supported yet.';
    }

    private function forwardStageFor(?string $resource): ?string
    {
        return match ($resource) {
            'appointment' => AppointmentStage::Succeeded->value,
            'lead' => LeadStage::Validated->value,
            'proposal' => ProposalStage::CustomerAccepted->value,
            'follow_up' => FollowUpStatus::Completed->value,
            default => null,
        };
    }

    private function isAlreadyResolved(?string $resource, Model $record): bool
    {
        return match ($resource) {
            'appointment', 'lead', 'proposal' => $record->stage->isTerminal(),
            'follow_up' => $record->status !== FollowUpStatus::Pending,
            default => true,
        };
    }

    private function resourceLabel(string $resource): string
    {
        return match ($resource) {
            'follow_up' => 'Follow-up',
            'appointment' => 'Appointment',
            'lead' => 'Lead',
            'proposal' => 'Proposal',
            'call' => 'Call',
            default => ucfirst($resource),
        };
    }

    /**
     * Explicit per-action authorization — unlike a real Resource's row
     * action (whose ->visible() already hides the button from anyone
     * unauthorized), this board's actions aren't wrapped by any Resource
     * page class, so nothing checks this for free.
     */
    private function authorizeUpdate(Model $record): void
    {
        if (! auth()->user()?->can('update', $record)) {
            Notification::make()->title("You can't move this card")->danger()->send();
            throw new Halt;
        }
    }

    private function resolveDropRecord(array $arguments): ?Model
    {
        $id = (int) ($arguments['id'] ?? 0);

        return match ($arguments['resource'] ?? null) {
            'appointment' => AppointmentResource::getEloquentQuery()->with('prospect')->find($id),
            'lead' => LeadResource::getEloquentQuery()->with('prospect')->find($id),
            'proposal' => ProposalResource::getEloquentQuery()->with('prospect')->find($id),
            'follow_up' => FollowUpResource::getEloquentQuery()->with('prospect')->find($id),
            'call' => CallRecordResource::getEloquentQuery()->with('prospect')->find($id),
            default => null,
        };
    }

    private function targetStageLabel(?string $resource, string $stage): string
    {
        if ($resource === 'lead' && $stage === 'lost') {
            return 'Lost';
        }

        return match ($resource) {
            'appointment' => AppointmentStage::tryFrom($stage)?->getLabel() ?? $stage,
            'lead' => LeadStage::tryFrom($stage)?->getLabel() ?? $stage,
            'proposal' => ProposalStage::tryFrom($stage)?->getLabel() ?? $stage,
            'follow_up' => FollowUpStatus::tryFrom($stage)?->getLabel() ?? $stage,
            default => $stage,
        };
    }

    /**
     * A Call Record has no `stage` at all — its `outcome` is fixed the
     * instant it's created (CallRecordObserver only fires on `created`,
     * never `updated`) and whatever it routes to already happened
     * automatically by the time a card could exist to look at. So this is
     * a single box, not a real progression — matches the audit finding that
     * Call is structurally different from the other four lanes.
     *
     * CallRecordResource::getEloquentQuery() already applies
     * ->directlyLogged() (excludes the invisible Follow-Up-completion
     * byproduct calls — see CallRecord::scopeDirectlyLogged()), so those
     * never surface as their own board cards, consistent with every other
     * place in the app that hides them.
     */
    private function callLane(): array
    {
        $calls = CallRecordResource::getEloquentQuery()
            ->with('prospect')
            ->latest('called_at')
            ->get();

        return [
            'label' => 'Call',
            'stages' => [
                'logged' => [
                    'label' => 'Outcome Logged',
                    'terminal' => false,
                    'cards' => $calls->map(fn (CallRecord $call) => $this->card(
                        resource: 'call',
                        id: $call->id,
                        prospect: $call->prospect,
                        meta: $call->outcome->getLabel().' · '.$call->called_at->diffForHumans(),
                        url: CallRecordResource::getUrl('view', ['record' => $call]),
                    ))->all(),
                ],
            ],
        ];
    }

    private function followUpLane(): array
    {
        $followUps = FollowUpResource::getEloquentQuery()
            ->with('prospect')
            ->latest('follow_up_at')
            ->get()
            ->groupBy(fn (FollowUp $followUp) => $followUp->status->value);

        $stageMeta = [
            FollowUpStatus::Pending->value => ['label' => 'Pending', 'terminal' => false],
            FollowUpStatus::Completed->value => ['label' => 'Completed', 'terminal' => true],
            FollowUpStatus::Cancelled->value => ['label' => 'Cancelled', 'terminal' => true],
        ];

        return [
            'label' => 'Follow-up',
            'stages' => collect($stageMeta)->map(function (array $meta, string $status) use ($followUps) {
                $cards = ($followUps[$status] ?? collect())->map(fn (FollowUp $followUp) => $this->card(
                    resource: 'follow_up',
                    id: $followUp->id,
                    prospect: $followUp->prospect,
                    meta: $followUp->reason.($followUp->follow_up_at ? ' · '.$followUp->follow_up_at->format('d M, h:i A') : ''),
                    url: FollowUpResource::getUrl('view', ['record' => $followUp]),
                ));

                return [
                    'label' => $meta['label'],
                    'terminal' => $meta['terminal'],
                    'cards' => $cards->all(),
                ];
            })->all(),
        ];
    }

    private function appointmentLane(): array
    {
        return $this->stageBasedLane(
            label: 'Appointment',
            records: AppointmentResource::getEloquentQuery()->with('prospect')->latest('appointment_at')->get(),
            cases: AppointmentStage::cases(),
            stageOf: fn (Appointment $appointment) => $appointment->stage,
            meta: fn (Appointment $appointment) => $appointment->appointment_at?->format('d M, h:i A') ?? 'Not scheduled',
            isLost: fn (Appointment $appointment) => $appointment->is_lost,
            resourceKey: 'appointment',
            urlFor: fn (Appointment $appointment) => AppointmentResource::getUrl('view', ['record' => $appointment]),
        );
    }

    private function leadLane(): array
    {
        $lane = $this->stageBasedLane(
            label: 'Lead',
            records: LeadResource::getEloquentQuery()->with('prospect')->latest('created_at')->get(),
            cases: LeadStage::cases(),
            stageOf: fn (Lead $lead) => $lead->stage,
            meta: fn (Lead $lead) => $lead->temperature->getLabel().' · since '.$lead->stage_changed_at?->format('d M'),
            isLost: fn (Lead $lead) => $lead->is_lost,
            resourceKey: 'lead',
            urlFor: fn (Lead $lead) => LeadResource::getUrl('view', ['record' => $lead]),
        );

        return $this->extractLostBox($lane);
    }

    /**
     * Lead's "Lost" box is a board-only display grouping, not a real
     * LeadStage — is_lost is orthogonal to stage (Lead::markLost() never
     * touches `stage`, see its own docblock: "Lost is an outcome applied
     * on top of wherever the Lead currently is"). Pulled out of its real
     * stage's box here so each card sits in exactly one box, matching
     * every other lane's mutually-exclusive-terminal-boxes convention
     * (e.g. Succeeded vs Not Succeeded), rather than appearing both in its
     * normal stage AND in Lost. `terminal => true` sweeps it into the same
     * branching terminal-pair rendering Validated already uses — no Blade
     * change needed beyond the negativeWords list.
     */
    private function extractLostBox(array $lane): array
    {
        $lostCards = [];

        foreach ($lane['stages'] as $stageKey => $stage) {
            $stillHere = [];

            foreach ($stage['cards'] as $card) {
                if ($card['isLost']) {
                    $lostCards[] = $card;
                } else {
                    $stillHere[] = $card;
                }
            }

            $lane['stages'][$stageKey]['cards'] = $stillHere;
        }

        $lane['stages']['lost'] = [
            'label' => 'Lost',
            'terminal' => true,
            'cards' => $lostCards,
        ];

        return $lane;
    }

    private function proposalLane(): array
    {
        return $this->stageBasedLane(
            label: 'Proposal',
            records: ProposalResource::getEloquentQuery()->with('prospect')->latest('created_at')->get(),
            cases: ProposalStage::cases(),
            stageOf: fn (Proposal $proposal) => $proposal->stage,
            meta: fn (Proposal $proposal) => $proposal->value ? '₹'.number_format((float) $proposal->value) : 'No value set',
            isLost: fn (Proposal $proposal) => false, // Proposal has no is_lost flag — Lost lives on `outcome` (the card tag), not a lane concept.
            resourceKey: 'proposal',
            urlFor: fn (Proposal $proposal) => ProposalResource::getUrl('view', ['record' => $proposal]),
            outcomeOf: fn (Proposal $proposal) => $proposal->outcome?->value,
        );
    }

    /**
     * Shared shape for the three resources that are pure stage-enum
     * progressions with an `isTerminal()`/is_lost split (Appointment, Lead,
     * Proposal) — Follow-up and Call each have their own method above since
     * neither fits this shape (Follow-up's "stage" is a plain status enum
     * with no isTerminal() method; Call has no stage concept at all).
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     * @param  \Illuminate\Support\Collection<int, TModel>  $records
     * @param  array<int, \App\Enums\AppointmentStage|\App\Enums\LeadStage|\App\Enums\ProposalStage>  $cases
     * @param  \Closure(TModel): (\App\Enums\AppointmentStage|\App\Enums\LeadStage|\App\Enums\ProposalStage)  $stageOf
     * @param  \Closure(TModel): string  $meta
     * @param  \Closure(TModel): bool  $isLost
     * @param  \Closure(TModel): string  $urlFor
     * @param  \Closure(TModel): (?string)  $outcomeOf
     */
    private function stageBasedLane(
        string $label,
        $records,
        array $cases,
        \Closure $stageOf,
        \Closure $meta,
        \Closure $isLost,
        string $resourceKey,
        \Closure $urlFor,
        ?\Closure $outcomeOf = null,
    ): array {
        $grouped = $records->groupBy(fn ($record) => $stageOf($record)->value);

        $stages = collect($cases)->mapWithKeys(function ($case) use ($grouped, $resourceKey, $meta, $isLost, $urlFor, $outcomeOf) {
            $cards = ($grouped[$case->value] ?? collect())->map(fn ($record) => $this->card(
                resource: $resourceKey,
                id: $record->id,
                prospect: $record->prospect,
                meta: $meta($record),
                url: $urlFor($record),
                isLost: $isLost($record),
                outcome: $outcomeOf ? $outcomeOf($record) : null,
            ));

            return [$case->value => [
                'label' => $case->getLabel(),
                'terminal' => $case->isTerminal(),
                'cards' => $cards->all(),
            ]];
        });

        return [
            'label' => $label,
            'stages' => $stages->all(),
        ];
    }

    /**
     * One generic card shape every lane renders identically — the board
     * doesn't care which resource a card came from beyond this shape plus
     * the `resource`/`id` pair a future drag/write phase will need to know
     * which model and row to act on.
     *
     * @return array<string, mixed>
     */
    private function card(
        string $resource,
        int $id,
        ?Prospect $prospect,
        string $meta,
        string $url,
        bool $isLost = false,
        ?string $outcome = null,
    ): array {
        return [
            'resource' => $resource,
            'id' => $id,
            'company' => $prospect?->company_name ?? 'Unknown company',
            'initials' => $this->initials($prospect?->company_name),
            'meta' => $meta,
            'url' => $url,
            'isLost' => $isLost,
            'outcome' => $outcome,
        ];
    }

    private function initials(?string $companyName): string
    {
        if (blank($companyName)) {
            return '—';
        }

        $words = preg_split('/\s+/', trim($companyName));

        return mb_strtoupper(collect($words)->take(2)->map(fn (string $word) => mb_substr($word, 0, 1))->join(''));
    }
}
