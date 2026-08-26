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
 * created by logging a real call, see CallRecordResource's own form; and
 * never resolved further afterward — a Call has no stage of its own to
 * advance, see isAlreadyResolved()'s default case). Every Call Record
 * already auto-creates whichever of those three its own outcome routes to
 * the instant it's saved (see CallRoutingService, keyed off
 * CallOutcome::routesToFollowUp()/routesToAppointment()/routesToLead()), so
 * dragging one is only eligible into a destination type that auto-routing
 * left empty for it (checked via CallRecord's own followUp()/appointment()/
 * lead() relations) — otherwise it would create a second, duplicate linked
 * record for the same call. In practice this matters for the two outcomes
 * that route nowhere at all (No Current Requirement/Future Opportunity,
 * Others), letting a rep manually create the linked record later after all,
 * but the eligibility check itself is generic, not hardcoded to those two.
 * The `RequirementIdentified` dual-routing case (one Call outcome touching
 * both Appointment AND Lead) is unaffected by this — both auto-created at
 * Call-creation time already, so cross-dropping that Call again is simply
 * blocked for both (already linked), same as any other already-routed
 * outcome.
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
        $destLabel = $this->resourceLabel($arguments['destResource'] ?? '');

        return "{$company} → New {$destLabel}";
    }

    /**
     * @return array<int, Forms\Components\Component>
     */
    private function dropFormSchema(array $arguments): array
    {
        $resource = $arguments['resource'] ?? null;
        $stage = (string) ($arguments['stage'] ?? '');
        $record = $this->resolveDropRecord($arguments);

        return $this->stageFields($resource, $stage, '', $record);
    }

    private function isCrossDropEligible(array $arguments): bool
    {
        $source = $this->resolveDropRecord(['resource' => $arguments['sourceResource'] ?? null, 'id' => $arguments['sourceId'] ?? null]);

        return $this->crossDropSupported($arguments['sourceResource'] ?? null, $arguments['destResource'] ?? null, $source);
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

        if (! $this->crossDropSupported($sourceResource, $destResource, $source)) {
            return [
                Forms\Components\Placeholder::make('unsupported')
                    ->label('Not available yet')
                    ->content($this->unsupportedCrossDropReason($sourceResource, $destResource, $source)),
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
        if (! (AppointmentStage::tryFrom((string) $stage)?->isTerminal() ?? false)) {
            return [];
        }

        return [
            Forms\Components\Textarea::make("{$prefix}outcome_notes")
                ->label('Outcome Notes')
                ->rows(3)
                ->required()
                ->default($record?->outcome_notes),
        ];
    }

    /**
     * @return array<int, Forms\Components\Component>
     */
    private function leadStageFields(?string $stage, ?Lead $record, string $prefix): array
    {
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
        if ($stage === ProposalStage::Sent->value) {
            // Same field/config as ProposalResource::form()'s own pdf_path —
            // required the moment the stage is Sent, same disk/visibility/
            // validation, so this dialog can never accept something that
            // Proposal's own Edit form would reject.
            return [
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
            ];
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

        return [];
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

        $update = ['stage' => $resolved];

        if ($resolved->isTerminal()) {
            $update['outcome_notes'] = $data['outcome_notes'] ?? null;
        }

        $this->applyDrop($appointment, $update, $resolved->getLabel());
    }

    private function dropLead(array $arguments, array $data): void
    {
        $resolved = LeadStage::tryFrom((string) ($arguments['stage'] ?? ''));
        /** @var ?Lead $lead */
        $lead = $this->resolveDropRecord($arguments);

        if (! $lead || ! $resolved || $lead->stage === $resolved) {
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
        } elseif ($resolved === ProposalStage::CustomerAccepted) {
            $update['outcome'] = ProposalOutcome::Won;
            $update['notes'] = $data['notes'] ?? null;
        } elseif ($resolved === ProposalStage::CustomerRejected) {
            $update['outcome'] = ProposalOutcome::Lost;
            $update['notes'] = $data['notes'] ?? null;
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

        if (! $source || ! $this->crossDropSupported($sourceResource, $destResource, $source)) {
            return;
        }

        $this->authorizeUpdate($source);

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

    private function createCrossDropDestination(string $destResource, string $destStage, Model $source, array $data): void
    {
        $prospect = $source->prospect;
        $assignedTo = $prospect?->assigned_to ?? auth()->id();

        // Stamping call_record_id when the dragged source is itself a Call
        // marks the new record as "the one this call routed to" — exactly
        // like CallRoutingService's own auto-created ones — so the
        // crossDropSupported() eligibility check (keyed off CallRecord's
        // own followUp()/appointment()/lead() relations) correctly sees this
        // as no longer empty and blocks a second, duplicate drag next time.
        $callRecordId = $source instanceof CallRecord ? $source->id : null;

        match ($destResource) {
            'follow_up' => FollowUp::create([
                'prospect_id' => $prospect->id,
                'call_record_id' => $callRecordId,
                'user_id' => $assignedTo,
                'reason' => $data['destination_reason'] ?? null,
                'follow_up_at' => $data['destination_follow_up_at'] ?? null,
                // Always Pending regardless of which box was dropped onto —
                // see the class docblock.
                'status' => FollowUpStatus::Pending,
            ]),
            'appointment' => Appointment::create([
                'prospect_id' => $prospect->id,
                'call_record_id' => $callRecordId,
                'assigned_to' => $assignedTo,
                'created_by' => auth()->id(),
                'appointment_at' => $data['destination_appointment_at'] ?? null,
                'stage' => $destStage,
                'outcome_notes' => $data['destination_outcome_notes'] ?? null,
            ]),
            'lead' => Lead::create([
                'prospect_id' => $prospect->id,
                'call_record_id' => $callRecordId,
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
    private function crossDropSupported(?string $sourceResource, ?string $destResource, ?Model $source): bool
    {
        $validSourceResources = ['follow_up', 'appointment', 'lead', 'proposal', 'call'];
        $validDestResources = ['follow_up', 'appointment', 'lead', 'proposal'];

        if (! in_array($sourceResource, $validSourceResources, true) || ! in_array($destResource, $validDestResources, true)) {
            return false;
        }

        if ($sourceResource === $destResource) {
            return false;
        }

        if ($destResource === 'proposal') {
            return $sourceResource === 'lead'
                && $source instanceof Lead
                && $source->stage->isEligibleForProposal()
                && $source->proposal === null;
        }

        // A Call Record already auto-creates whichever of Follow-up/
        // Appointment/Lead its own outcome routes to the moment it's saved
        // (see CallRoutingService) — so dragging one across is only ever
        // useful for the destination type(s) that auto-routing left empty
        // (e.g. a "No Current Requirement" or "Others" call, which routes
        // nowhere), not to create a second, duplicate linked record.
        if ($sourceResource === 'call') {
            return $source instanceof CallRecord && match ($destResource) {
                'follow_up' => $source->followUp === null,
                'appointment' => $source->appointment === null,
                'lead' => $source->lead === null,
                default => false,
            };
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
    private function unsupportedCrossDropReason(?string $sourceResource, ?string $destResource, ?Model $source): string
    {
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

        if ($sourceResource === 'call' && $source instanceof CallRecord) {
            $existing = match ($destResource) {
                'follow_up' => $source->followUp,
                'appointment' => $source->appointment,
                'lead' => $source->lead,
                default => null,
            };

            if ($existing !== null) {
                return 'This call already has a linked '.$this->resourceLabel((string) $destResource).' — open it directly instead of creating a new one.';
            }
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
        return $this->stageBasedLane(
            label: 'Lead',
            records: LeadResource::getEloquentQuery()->with('prospect')->latest('created_at')->get(),
            cases: LeadStage::cases(),
            stageOf: fn (Lead $lead) => $lead->stage,
            meta: fn (Lead $lead) => $lead->temperature->getLabel().' · since '.$lead->stage_changed_at?->format('d M'),
            isLost: fn (Lead $lead) => $lead->is_lost,
            resourceKey: 'lead',
            urlFor: fn (Lead $lead) => LeadResource::getUrl('view', ['record' => $lead]),
        );
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
