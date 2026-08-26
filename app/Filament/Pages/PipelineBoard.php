<?php

namespace App\Filament\Pages;

use App\Enums\AppointmentStage;
use App\Enums\FollowUpStatus;
use App\Enums\LeadStage;
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
 * Phase 2 (this file, now): same-lane stage drags for Appointment, Lead,
 * and Proposal — a single real stage mutation on the same record, going
 * through the exact same Eloquent ->update() every Edit form uses, so every
 * existing model `saving()` guard fires identically (see Appointment::
 * booted()/Lead::booted()/Proposal::booted()). Follow-up's Pending ->
 * Completed/Cancelled and the Call lane are deliberately out of scope here
 * (Follow-up's Completed transition creates a real Call Record via the
 * shared FollowUp::completeWithCall() — a different shape — and Call has no
 * stage concept at all); both land in a later phase. Cross-lane drag is
 * also out of scope here.
 *
 * The drop-confirm dialog is a genuine Filament page-level Action (mounted
 * via Alpine's `$wire.mountAction('drop', {...})`, mirroring the exact
 * mechanism ListFollowUps::summaryAction() already uses in this codebase)
 * — not a hand-rolled modal — so its `->form()` schema reuses the same
 * Filament form components (Textarea, FileUpload) with the same
 * validation/config as the matching Resource's own form, and its
 * `->action()` ends in an ordinary Eloquent ->update() call. Authorization
 * is explicit here (auth()->user()->can('update', $record)) rather than
 * automatic the way a real Resource's row action gets it for free.
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
     * The one drop-confirm dialog for every same-lane stage drag in this
     * phase — a single Filament page-level Action (see the class docblock),
     * dispatched via mountAction('drop', ['resource' => ..., 'id' => ...,
     * 'stage' => ...]) from the card/stage-box's Alpine drag handlers in the
     * Blade view. Which fields it asks for, and what the confirm button
     * actually writes, both key off those same $arguments.
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

    private function dropModalHeading(array $arguments): string
    {
        $record = $this->resolveDropRecord($arguments);
        $label = $this->targetStageLabel($arguments);

        return ($record?->prospect?->company_name ?? 'Company').' → '.$label;
    }

    /**
     * @return array<int, Forms\Components\Component>
     */
    private function dropFormSchema(array $arguments): array
    {
        $resource = $arguments['resource'] ?? null;
        $stage = (string) ($arguments['stage'] ?? '');
        $record = $this->resolveDropRecord($arguments);

        return match ($resource) {
            'appointment' => AppointmentStage::tryFrom($stage)?->isTerminal()
                ? [
                    Forms\Components\Textarea::make('outcome_notes')
                        ->label('Outcome Notes')
                        ->rows(3)
                        ->required()
                        ->default($record?->outcome_notes),
                ]
                : [],
            'lead' => $stage === LeadStage::Validated->value
                ? [
                    Forms\Components\Textarea::make('notes')
                        ->label('Notes / Remarks')
                        ->rows(3)
                        ->required()
                        ->default($record?->notes),
                ]
                : [],
            'proposal' => $this->proposalDropFormSchema($stage, $record),
            default => [],
        };
    }

    /**
     * @return array<int, Forms\Components\Component>
     */
    private function proposalDropFormSchema(string $stage, ?Proposal $proposal): array
    {
        if ($stage === ProposalStage::Sent->value) {
            // Same field/config as ProposalResource::form()'s own pdf_path —
            // required the moment the stage is Sent, same disk/visibility/
            // validation, so this dialog can never accept something that
            // Proposal's own Edit form would reject.
            return [
                Forms\Components\FileUpload::make('pdf_path')
                    ->label('Proposal PDF')
                    ->disk('local')
                    ->directory('proposal-pdfs')
                    ->visibility('private')
                    ->acceptedFileTypes(['application/pdf'])
                    ->maxSize(10240)
                    ->previewable(false)
                    ->default($proposal?->pdf_path)
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
                Forms\Components\Placeholder::make('outcome_preview')
                    ->label('Final Outcome')
                    ->content($outcomeLabel),
                Forms\Components\Textarea::make('notes')
                    ->label('Notes')
                    ->rows(3)
                    ->required()
                    ->default($proposal?->notes)
                    ->helperText("Required — Final Outcome {$outcomeLabel} always needs Notes."),
            ];
        }

        return [];
    }

    private function performDrop(array $arguments, array $data): void
    {
        match ($arguments['resource'] ?? null) {
            'appointment' => $this->dropAppointment($arguments, $data),
            'lead' => $this->dropLead($arguments, $data),
            'proposal' => $this->dropProposal($arguments, $data),
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
            default => null,
        };
    }

    private function targetStageLabel(array $arguments): string
    {
        $stage = (string) ($arguments['stage'] ?? '');

        return match ($arguments['resource'] ?? null) {
            'appointment' => AppointmentStage::tryFrom($stage)?->getLabel() ?? $stage,
            'lead' => LeadStage::tryFrom($stage)?->getLabel() ?? $stage,
            'proposal' => ProposalStage::tryFrom($stage)?->getLabel() ?? $stage,
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
