<?php

namespace App\Filament\Resources;

use App\Enums\CallNextAction;
use App\Enums\CallOutcome;
use App\Enums\ContactMode;
use App\Enums\DemoMode;
use App\Enums\DemoStatus;
use App\Enums\FollowUpStatus;
use App\Enums\ProfileSentMode;
use App\Enums\ProfileSentStatus;
use App\Filament\Resources\FollowUpResource\Pages;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Services\RescheduleService;
use App\Services\WorkflowTransitionService;
use App\Support\DeletionGuard;
use App\Support\TableBulkActions;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Exceptions\Halt;
use Filament\Tables;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use LogicException;

/**
 * Follow-Ups panel (AGENTS.md section 17). Mostly populated automatically
 * by App\Services\CallRoutingService from No Answer / Switched Off / Not
 * Reachable / Callback Requested calls, but can also be created directly.
 *
 * Two distinct resolutions, per the Change Request "Decisions 3 & 4":
 * - Completed: the retry call finally reached the prospect. Logs a real
 *   new Call Record and routes it through the exact same
 *   App\Services\CallRoutingService every other call uses — no separate
 *   routing path.
 * - Close: giving up on this one. Just archives it (no new activity).
 * Regular users no longer see Delete here at all (Admin still does).
 */
class FollowUpResource extends Resource
{
    protected static ?string $model = FollowUp::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-path';

    protected static ?string $navigationLabel = 'Follow-Ups';

    protected static ?string $navigationGroup = 'Sales';

    protected static ?int $navigationSort = 3;

    /**
     * Shared with ListFollowUps::updatedActiveTab() so the two stay in
     * sync — one place defining which column History/Lost group by.
     */
    public const GROUP_BY_COMPANY = 'prospect.company_name';

    public static function form(Form $form): Form
    {
        return $form->schema(self::formSchema());
    }

    /**
     * Extracted so PipelineBoard's in-board Edit/View actions (which reuse
     * every resource's real form for full parity — see PipelineBoard's
     * editRecordAction()/viewRecordAction()) can call this directly instead
     * of duplicating these fields — matches the ProspectResource::
     * formSchema() precedent.
     *
     * @return array<int, Forms\Components\Component>
     */
    public static function formSchema(): array
    {
        return [
                Forms\Components\Section::make()
                    ->columns(2)
                    ->schema([
                        Forms\Components\Select::make('prospect_id')
                            ->label('Company')
                            ->relationship(
                                'prospect',
                                'company_name',
                                modifyQueryUsing: fn (Builder $query) => $query->visibleTo(auth()->user()),
                            )
                            ->required()
                            ->searchable()
                            ->preload(),
                        Forms\Components\Grid::make(3)
                            ->schema([
                                // In practice this records when the
                                // interaction actually happened, not a
                                // future schedule — defaulted to right now
                                // on every fresh form load (including "Create
                                // & create another", which re-fills the form
                                // via $this->form->fill(), re-running this
                                // closure — confirmed against Filament's own
                                // CreateRecord::fillForm() source) rather
                                // than requiring manual entry or carrying
                                // over the previous record's value.
                                Forms\Components\DateTimePicker::make('follow_up_at')
                                    ->label('Followed Up At')
                                    ->required()
                                    ->seconds(false)
                                    ->default(fn () => now())
                                    // Phase 2: normal Edit must never
                                    // silently change an ALREADY-SET
                                    // schedule — the dedicated
                                    // "Reschedule" row action is the only
                                    // way (see table()). A still-NULL
                                    // schedule (e.g. a "No Answer" call's
                                    // auto-routed Follow-Up with no
                                    // callback time yet) is not a
                                    // reschedule and stays editable here —
                                    // this is filling it in for the first
                                    // time, not replacing an existing one.
                                    ->disabled(fn (?FollowUp $record) => $record?->follow_up_at !== null)
                                    ->dehydrated(fn (?FollowUp $record) => $record?->follow_up_at === null),
                                // Optional, always visible, always persists
                                // on this same FollowUp record — unrelated to
                                // `new_follow_up_at` below, which is
                                // ephemeral, only appears inside the
                                // Completed flow, and spawns a whole new,
                                // separate FollowUp row via
                                // CallRoutingService rather than scheduling
                                // this one.
                                Forms\Components\DateTimePicker::make('next_follow_up_at')
                                    ->label('Next Follow-up Date')
                                    ->seconds(false),
                                Forms\Components\Select::make('contact_mode')
                                    ->label('Contact Mode')
                                    ->options(ContactMode::class),
                            ]),
                        Forms\Components\TextInput::make('reason')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\Select::make('status')
                            ->options(FollowUpStatus::class)
                            ->required()
                            ->default(FollowUpStatus::Pending)
                            ->live(),
                        // Neither of these persists to the FollowUp record
                        // itself — EditFollowUp::handleRecordUpdate() /
                        // CreateFollowUp::handleRecordCreation() pull them
                        // back out of the form data and use them to create a
                        // new Call Record, exactly like the row-action
                        // "Completed" modal's own fields (FollowUpResource::
                        // table()) do. Required only when Status is being set
                        // to Completed here — matching the modal's
                        // enforcement — so this is both the reactive UI
                        // validation and, since Livewire validates
                        // server-side regardless of JS, the actual guard
                        // against submitting Completed with no outcome
                        // captured.
                        Forms\Components\Select::make('outcome')
                            ->label('Call Outcome')
                            ->options(CallOutcome::class)
                            ->live()
                            ->visible(fn (Forms\Get $get) => self::statusIsCompleting($get('status')))
                            ->required(fn (Forms\Get $get) => self::statusIsCompleting($get('status')))
                            ->helperText('You reached them — log what happened on this call, same as the row-action Completed modal.'),
                        // Mirrors CallRecordResource::form()'s identical
                        // appointment_at/follow_up_at pair exactly — same
                        // routing rules (CallOutcome::routesToAppointment()/
                        // routesToFollowUp()), same visible-whenever-required
                        // pairing. "Next Follow-Up At" (not "Follow up at",
                        // already taken above by *this* Follow-Up's own
                        // field) since an outcome like No Answer here creates
                        // a brand new Follow-Up, distinct from the one being
                        // completed.
                        Forms\Components\DateTimePicker::make('appointment_at')
                            ->label('Appointment At')
                            ->seconds(false)
                            ->visible(fn (Forms\Get $get) => self::statusIsCompleting($get('status')) && self::outcomeRoutesToAppointment($get('outcome')))
                            ->required(fn (Forms\Get $get) => self::statusIsCompleting($get('status')) && self::outcomeRoutesToAppointment($get('outcome'))),
                        Forms\Components\DateTimePicker::make('new_follow_up_at')
                            ->label('Next Follow-Up At')
                            ->seconds(false)
                            ->visible(fn (Forms\Get $get) => self::statusIsCompleting($get('status')) && self::outcomeRoutesToFollowUp($get('outcome'), $get('next_action')))
                            ->required(fn (Forms\Get $get) => self::statusIsCompleting($get('status')) && self::followUpAtRequired($get('outcome'), $get('next_action'))),
                        ...self::otherAndProfileSentFields(
                            outcomeGetter: fn (Forms\Get $get) => $get('outcome'),
                            activeWhen: fn (Forms\Get $get) => self::statusIsCompleting($get('status')),
                        ),
                        Forms\Components\Textarea::make('call_notes')
                            ->label('Call Notes')
                            ->rows(3)
                            ->visible(fn (Forms\Get $get) => self::statusIsCompleting($get('status')))
                            ->required(fn (Forms\Get $get) => self::statusIsCompleting($get('status'))),
                        Forms\Components\Textarea::make('notes')
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),
        ];
    }

    /**
     * $get()/form data may hand back either the raw string value or the
     * hydrated enum case depending on how the form state got there (a
     * record-hydrated Edit form's initial value is already a plain string —
     * Eloquent's attributesToArray() normalizes backed enums on the way out
     * — but a *live* Select-with-enum-options interaction, e.g. picking a
     * new option in the browser, stores the actual enum instance instead;
     * confirmed live — a naive `$get('status') === FollowUpStatus::
     * Completed->value` string comparison silently never matched on the
     * Create form, whose Status field starts from ->default() rather than a
     * hydrated record, once the user actually changed it). Mirrors
     * CallRecordResource::resolveOutcome() / LeadResource::
     * stageIsValidated() exactly, the same fix already established there.
     */
    public static function resolveStatus(mixed $status): ?FollowUpStatus
    {
        return $status instanceof FollowUpStatus ? $status : FollowUpStatus::tryFrom((string) $status);
    }

    private static function resolveOutcome(mixed $outcome): ?CallOutcome
    {
        return $outcome instanceof CallOutcome ? $outcome : CallOutcome::tryFrom((string) $outcome);
    }

    public static function statusIsCompleting(mixed $status): bool
    {
        return self::resolveStatus($status) === FollowUpStatus::Completed;
    }

    private static function outcomeRoutesToAppointment(mixed $outcome): bool
    {
        return self::resolveOutcome($outcome)?->routesToAppointment() ?? false;
    }

    /**
     * Phase 3: visible whenever the outcome could possibly create a
     * Follow-Up — unconditionally (Callback Requested) or as an explicit,
     * intentional decision (Concerned Person Not Available / Profile
     * Requested's optional callback, or Other's CreateFollowUp next
     * action) — mirrors CallRecordResource::followUpAtVisible() exactly,
     * since this form creates a Call Record through the identical model
     * guards/routing service.
     */
    private static function outcomeRoutesToFollowUp(mixed $outcome, mixed $nextAction = null): bool
    {
        $resolved = self::resolveOutcome($outcome);

        if ($resolved === null) {
            return false;
        }

        if ($resolved->routesToFollowUp() || $resolved->routesToConditionalFollowUp()) {
            return true;
        }

        return $resolved === CallOutcome::Others && self::resolveNextAction($nextAction) === CallNextAction::CreateFollowUp;
    }

    /** Required only where the Follow-Up is mandatory, not merely possible. */
    private static function followUpAtRequired(mixed $outcome, mixed $nextAction = null): bool
    {
        $resolved = self::resolveOutcome($outcome);

        if ($resolved === null) {
            return false;
        }

        if ($resolved->routesToFollowUp()) {
            return true;
        }

        return $resolved === CallOutcome::Others && self::resolveNextAction($nextAction) === CallNextAction::CreateFollowUp;
    }

    private static function resolveNextAction(mixed $nextAction): ?CallNextAction
    {
        return $nextAction instanceof CallNextAction ? $nextAction : CallNextAction::tryFrom((string) $nextAction);
    }

    private static function resolveProfileSentStatus(mixed $status): ?ProfileSentStatus
    {
        return $status instanceof ProfileSentStatus ? $status : ProfileSentStatus::tryFrom((string) $status);
    }

    private static function resolveProfileSentMode(mixed $mode): ?ProfileSentMode
    {
        return $mode instanceof ProfileSentMode ? $mode : ProfileSentMode::tryFrom((string) $mode);
    }

    private static function resolveDemoMode(mixed $mode): ?DemoMode
    {
        return $mode instanceof DemoMode ? $mode : DemoMode::tryFrom((string) $mode);
    }

    /**
     * The Company column's small type indicator (see columns()) — driven
     * solely by the existing `origin_type` morph-map value, the only field
     * that records why this Follow-Up exists. `null` (never set by the
     * ordinary call-routed path — see CallRoutingService::createFollowUp())
     * means General; any value outside the three real origins this app
     * ever writes (WorkflowTransitionService::createFollowUpFromOrigin())
     * omits the badge instead of guessing at a label.
     */
    private static function resolveOriginTypeLabel(?string $originType): ?string
    {
        return match ($originType) {
            null => 'General',
            'appointment' => 'Appointment',
            'demo' => 'Demo',
            'proposal' => 'Proposal',
            default => null,
        };
    }

    /**
     * Company name plus the origin-type badge directly beneath it, in the
     * same cell. Renders the real <x-filament::badge> component (the exact
     * one the Status column's ->badge() uses) at 'gray' — visually
     * identical shape/size/pill styling to every other badge in this app,
     * just a subtler, neutral color so it never competes with the Status
     * badge. Company name is HTML-escaped explicitly since the column
     * itself is ->html() to allow the badge markup through.
     */
    private static function renderCompanyWithOriginTypeBadge(string $companyName, ?string $originType): HtmlString
    {
        $label = self::resolveOriginTypeLabel($originType);

        if ($label === null) {
            return new HtmlString(e($companyName));
        }

        $badge = Blade::render(
            '<x-filament::badge color="gray">{{ $label }}</x-filament::badge>',
            ['label' => $label],
        );

        return new HtmlString(e($companyName).'<div class="mt-1">'.$badge.'</div>');
    }

    /**
     * Phase 3 correction: Follow-Up -> Demo was one of the four approved
     * routes into Demo (WorkflowTransitionService::transitionToDemo()'s own
     * docblock: "Follow-Up/Appointment/Lead/Proposal -> Demo per the Master
     * BA") that had no user-facing entry point at all — Lead/Proposal/
     * Appointment each already had one. Mirrors LeadResource's own
     * "Schedule Demo" action, except a Follow-Up has no lead_id of its own
     * (unlike Demo), so the rep picks the existing Lead (matched by the
     * same Prospect) this Demo belongs to — transitionToDemo() itself
     * rejects a mismatched Lead/Prospect pair.
     */
    private static function scheduleDemoAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('scheduleDemo')
            ->label('Schedule Demo')
            ->icon('heroicon-o-presentation-chart-bar')
            ->color('info')
            ->visible(fn (FollowUp $record) => $record->status === FollowUpStatus::Pending && auth()->user()->can('update', $record))
            ->form([
                Forms\Components\Select::make('lead_id')
                    ->label('Lead')
                    ->helperText('The existing Lead (requirement) this Demo belongs to.')
                    ->options(fn (FollowUp $record) => Lead::query()
                        ->where('prospect_id', $record->prospect_id)
                        ->get()
                        ->mapWithKeys(fn (Lead $lead) => [$lead->id => $lead->stage->getLabel().' — '.$lead->created_at->format('d M Y')]))
                    ->searchable()
                    ->required(),
                Forms\Components\DateTimePicker::make('demo_at')
                    ->label('Demo At')
                    ->required()
                    ->seconds(false),
                Forms\Components\Select::make('mode')
                    ->options(DemoMode::class)
                    ->required()
                    ->live(),
                Forms\Components\TextInput::make('location')
                    ->visible(fn (Get $get) => self::resolveDemoMode($get('mode')) === DemoMode::OnSite)
                    ->required(fn (Get $get) => self::resolveDemoMode($get('mode')) === DemoMode::OnSite),
                Forms\Components\TextInput::make('meeting_link')
                    ->label('Meeting Link')
                    ->url()
                    ->visible(fn (Get $get) => self::resolveDemoMode($get('mode')) === DemoMode::Online)
                    ->required(fn (Get $get) => self::resolveDemoMode($get('mode')) === DemoMode::Online),
                Forms\Components\TextInput::make('attendees'),
                Forms\Components\TextInput::make('product_service')
                    ->label('Product / Service'),
                Forms\Components\TextInput::make('purpose'),
            ])
            ->action(function (FollowUp $record, array $data) {
                $lead = Lead::query()->find($data['lead_id']);

                if ($lead === null || $lead->prospect_id !== $record->prospect_id) {
                    Notification::make()->title("Couldn't schedule the Demo")->body('The selected Lead is not valid for this Follow-Up\'s Company.')->danger()->send();
                    throw new Halt;
                }

                if ($lead->demos()->where('status', DemoStatus::Scheduled)->exists()) {
                    Notification::make()->title("Couldn't schedule the Demo")->body('This Lead already has a Scheduled Demo.')->danger()->send();
                    throw new Halt;
                }

                try {
                    app(WorkflowTransitionService::class)->transitionToDemo($lead, $record, 'follow_up', $data);
                } catch (LogicException $e) {
                    Notification::make()->title("Couldn't schedule the Demo")->body($e->getMessage())->danger()->send();
                    throw new Halt;
                }

                Notification::make()->title('Demo scheduled')->success()->send();
            });
    }

    /**
     * The shared Other/Profile Sent field set appended after the outcome-
     * dependent appointment/follow-up date pair — reused by both the Edit
     * page's inline Completed section and the row-action Completed modal,
     * so the two never diverge. $outcomeGetter/$nextActionGetter read the
     * respective statePath, which differs between the two contexts.
     *
     * @return array<int, Forms\Components\Component>
     */
    private static function otherAndProfileSentFields(\Closure $outcomeGetter, \Closure $activeWhen): array
    {
        $isOther = fn (Forms\Get $get) => $activeWhen($get) && self::resolveOutcome($outcomeGetter($get)) === CallOutcome::Others;
        $isProfileRequested = fn (Forms\Get $get) => $activeWhen($get) && self::resolveOutcome($outcomeGetter($get)) === CallOutcome::ProfileRequested;

        return [
            Forms\Components\Select::make('next_action')
                ->label('Next Action')
                ->options(CallNextAction::class)
                ->live()
                ->visible($isOther)
                ->required($isOther),
            Forms\Components\Select::make('profile_sent_status')
                ->label('Profile Sent Status')
                ->options(ProfileSentStatus::class)
                ->live()
                ->visible($isProfileRequested)
                ->required($isProfileRequested),
            Forms\Components\Select::make('profile_sent_mode')
                ->label('Profile Sent Mode')
                ->options(ProfileSentMode::class)
                ->live()
                ->visible($isProfileRequested)
                ->required(fn (Forms\Get $get) => $isProfileRequested($get) && self::resolveProfileSentStatus($get('profile_sent_status')) === ProfileSentStatus::Sent),
            Forms\Components\DateTimePicker::make('profile_sent_at')
                ->label('Profile Sent At')
                ->seconds(false)
                ->visible($isProfileRequested)
                ->required(fn (Forms\Get $get) => $isProfileRequested($get) && self::resolveProfileSentStatus($get('profile_sent_status')) === ProfileSentStatus::Sent),
            Forms\Components\Textarea::make('profile_sent_notes')
                ->label('Profile Sent Notes')
                ->rows(2)
                ->visible($isProfileRequested)
                ->required(fn (Forms\Get $get) => $isProfileRequested($get) && self::resolveProfileSentMode($get('profile_sent_mode')) === ProfileSentMode::Other),
        ];
    }

    /**
     * Extracted from table() so the Prospect View page's Follow-Ups
     * mini-table (see App\Filament\Widgets\ProspectFollowUpsTable) can
     * reuse the exact same column set rather than duplicating it —
     * matches the ProspectResource::formSchema() precedent for form
     * fields.
     *
     * @return array<int, Tables\Columns\Column>
     */
    public static function columns(): array
    {
        return [
            // UI-only: a small type indicator under the Company name so
            // multiple Follow-Ups for the same Company (a common case) are
            // distinguishable at a glance. Driven entirely by the existing
            // `origin_type` column (never a new field, never inferred from
            // legacy stage) — not a second table column. Rendered as the
            // exact same <x-filament::badge> pill the Status column's own
            // ->badge() uses (same shape/size), just colored 'gray' so it
            // never visually competes with Status. An unrecognized
            // origin_type (shouldn't occur) omits the badge rather than
            // fabricating a label.
            Tables\Columns\TextColumn::make('prospect.company_name')
                ->label('Company')
                ->searchable()
                ->sortable()
                ->html()
                ->formatStateUsing(fn (string $state, FollowUp $record) => self::renderCompanyWithOriginTypeBadge($state, $record->origin_type)),
            Tables\Columns\TextColumn::make('reason')
                ->searchable()
                ->toggleable(isToggledHiddenByDefault: true),
            Tables\Columns\TextColumn::make('follow_up_at')
                ->dateTime('d M Y, h:i A')
                ->sortable()
                ->placeholder('Not scheduled'),
            Tables\Columns\TextColumn::make('status')
                ->badge()
                ->sortable(),
            Tables\Columns\TextColumn::make('responsibleEmployee.name')
                ->label('Employee')
                ->badge()
                ->sortable(),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns(static::columns())
            ->filters([
                // Pending vs. History is now owned by the page's tabs (UX
                // Fixes Batch Issue 3) rather than this filter, so there is
                // only ever one place that decides what "pending" means.
                Tables\Filters\SelectFilter::make('user_id')
                    ->label('Employee')
                    ->relationship('responsibleEmployee', 'name')
                    ->visible(fn () => auth()->user()->isAdmin()),
            ])
            // Phase 2 item #3: History and Lost are look-back/audit views —
            // grouping by company collapses noise (one row's worth of
            // follow-ups against a company that's been called 20 times)
            // without hiding anything, since the underlying records are
            // still there, just collapsed until clicked. Pending stays flat
            // — it's an actionable queue, not something to browse by
            // company. Only one grouping dimension is offered, so
            // Filament's built-in "Groups" picker is hidden rather than
            // left visible with nothing else to switch to.
            ->groups([
                Group::make(self::GROUP_BY_COMPANY)
                    ->titlePrefixedWithLabel(false)
                    ->collapsible()
                    ->getDescriptionFromRecordUsing(fn (FollowUp $record, $livewire) => static::groupSummary($record, $livewire)),
            ])
            ->defaultGroup(fn ($livewire) => in_array($livewire->activeTab ?? 'pending', ['history', 'lost'], true)
                ? self::GROUP_BY_COMPANY
                : null)
            ->groupingSettingsHidden()
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ViewAction::make(),
                    // Carries the tab the user is currently on through to
                    // the Edit page as ?activeTab=..., so getRedirectUrl()
                    // on EditFollowUp can send them back to the same tab
                    // (History/Lost) instead of always Pending — see that
                    // page for the other half of this.
                    Tables\Actions\EditAction::make()
                        ->url(fn (FollowUp $record, $livewire): string => static::getUrl('edit', [
                            'record' => $record,
                            'activeTab' => $livewire->activeTab,
                        ])),
                    Tables\Actions\Action::make('completed')
                        ->label('Completed')
                        ->icon('heroicon-o-phone')
                        ->color('success')
                        ->visible(fn (FollowUp $record) => $record->status === FollowUpStatus::Pending && auth()->user()->can('update', $record))
                        ->form([
                            Forms\Components\Select::make('outcome')
                                ->label('Call Outcome')
                                ->options(CallOutcome::class)
                                ->required()
                                ->live()
                                ->helperText('You reached them — log what happened on this call, same as logging any other call.'),
                            // Mirrors CallRecordResource::form()'s identical
                            // pair — an outcome here can route to an
                            // Appointment and/or a new Follow-Up exactly like
                            // any other logged call (App\Services\
                            // CallRoutingService doesn't treat this call any
                            // differently), so the same date/time it needs
                            // has to be collected here too, not left blank.
                            Forms\Components\DateTimePicker::make('appointment_at')
                                ->label('Appointment At')
                                ->seconds(false)
                                ->visible(fn (Forms\Get $get) => static::outcomeRoutesToAppointment($get('outcome')))
                                ->required(fn (Forms\Get $get) => static::outcomeRoutesToAppointment($get('outcome'))),
                            Forms\Components\DateTimePicker::make('new_follow_up_at')
                                ->label('Follow Up At')
                                ->seconds(false)
                                ->visible(fn (Forms\Get $get) => static::outcomeRoutesToFollowUp($get('outcome'), $get('next_action')))
                                ->required(fn (Forms\Get $get) => static::followUpAtRequired($get('outcome'), $get('next_action'))),
                            ...self::otherAndProfileSentFields(
                                outcomeGetter: fn (Forms\Get $get) => $get('outcome'),
                                activeWhen: fn (Forms\Get $get) => true,
                            ),
                            Forms\Components\Textarea::make('notes')
                                ->label('Notes')
                                ->required()
                                ->rows(3),
                        ])
                        ->action(fn (FollowUp $record, array $data) => $record->completeWithCall([
                            'outcome' => $data['outcome'],
                            'notes' => $data['notes'],
                            'appointment_at' => $data['appointment_at'] ?? null,
                            'follow_up_at' => $data['new_follow_up_at'] ?? null,
                            'next_action' => $data['next_action'] ?? null,
                            'profile_sent_status' => $data['profile_sent_status'] ?? null,
                            'profile_sent_at' => $data['profile_sent_at'] ?? null,
                            'profile_sent_mode' => $data['profile_sent_mode'] ?? null,
                            'profile_sent_notes' => $data['profile_sent_notes'] ?? null,
                        ])),
                    self::scheduleDemoAction(),
                    // Phase 2: the ONLY way to change follow_up_at on an
                    // active Follow-Up — normal Edit shows it read-only
                    // (see formSchema()) so the history-preserving
                    // reschedule behavior can never be silently bypassed.
                    // This Follow-Up becomes Rescheduled/history; a brand
                    // new Follow-Up is created as the active Pending
                    // record — never the same row mutated in place.
                    Tables\Actions\Action::make('reschedule')
                        ->label('Reschedule')
                        ->icon('heroicon-o-calendar-days')
                        ->color('warning')
                        ->visible(fn (FollowUp $record) => $record->status === FollowUpStatus::Pending && auth()->user()->can('update', $record))
                        ->form([
                            Forms\Components\DateTimePicker::make('follow_up_at')
                                ->label('New Follow Up At')
                                ->required()
                                ->seconds(false),
                            Forms\Components\Textarea::make('reason')
                                ->label('Reason for Reschedule')
                                ->rows(2),
                        ])
                        ->action(fn (FollowUp $record, array $data) => app(RescheduleService::class)->reschedule(
                            $record,
                            ['follow_up_at' => $data['follow_up_at']],
                            $data['reason'] ?? null,
                        )),
                    Tables\Actions\Action::make('close')
                        ->label('Close')
                        ->icon('heroicon-o-archive-box-x-mark')
                        ->color('gray')
                        ->requiresConfirmation()
                        ->modalDescription('This follow-up will be archived and removed from your default list. It is not deleted — history is retained.')
                        ->visible(fn (FollowUp $record) => $record->status === FollowUpStatus::Pending && auth()->user()->can('update', $record))
                        ->form([
                            Forms\Components\Textarea::make('notes')
                                ->label('Notes')
                                ->required()
                                ->rows(3)
                                ->helperText('Required — why this follow-up is being closed.'),
                        ])
                        ->action(fn (FollowUp $record, array $data) => $record->update([
                            'status' => FollowUpStatus::Cancelled,
                            'notes' => $data['notes'],
                        ])),
                    Tables\Actions\DeleteAction::make()
                        ->visible(fn () => auth()->user()->isAdmin())
                        ->before(function (FollowUp $record) {
                            $record->deleteHarmlessGeneratedCallRecord();
                            DeletionGuard::guardRecord($record, 'follow-up');
                        }),
                ]),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    TableBulkActions::deselectAll(),
                    Tables\Actions\DeleteBulkAction::make()
                        ->visible(fn () => auth()->user()->isAdmin())
                        ->before(fn (Collection $records) => DeletionGuard::guardRecords(
                            $records,
                            'follow-ups',
                            fn (FollowUp $followUp) => $followUp->prospect->company_name,
                        )),
                ]),
            ])
            ->defaultSort('follow_up_at', 'asc')
            ->emptyStateHeading(fn ($livewire) => match ($livewire->activeTab ?? 'pending') {
                'history' => 'No follow-up history available.',
                'lost' => 'No lost follow-ups.',
                default => 'No pending follow-ups.',
            })
            ->emptyStateDescription(fn ($livewire) => match ($livewire->activeTab ?? 'pending') {
                'history' => 'Completed and closed follow-ups will show up here.',
                'lost' => 'Follow-ups closed without a positive outcome will show up here.',
                default => 'Follow-ups land here automatically when a call needs one.',
            })
            ->emptyStateIcon('heroicon-o-arrow-path');
    }

    /**
     * The group header's subtitle: how many follow-ups against this
     * company are in the *currently active tab's* filtered set (History =
     * Completed + Cancelled, Lost = Cancelled only) and when the most
     * recent one was — mirrors ListFollowUps::getTabs()'s own status
     * filtering rather than a raw all-time count, so it matches what's
     * actually on screen.
     *
     * Also carries the "Summary" button. Filament's group header partial
     * (vendor/filament/tables/.../components/group/header.blade.php) has
     * no slot for extra actions, and this app has already deliberately
     * chosen not to eject/override that view once before (see
     * list-follow-ups-footer.blade.php's comment) since it's shared by
     * every grouped table in the app, not just this one. Blade's `{{ }}`
     * calls e(), which passes Htmlable values through unescaped — so
     * returning an HtmlString here (instead of a plain string) lets a real
     * <button wire:click="mountAction(...)"> ride along inside the
     * description `<p>` Filament already renders, with zero vendor files
     * touched. $countLabel/$recentLabel are both derived from a count and
     * a formatted date, never free text, so this is safe without a
     * separate e() pass over them.
     *
     * This calls the page-level mountAction() (see ListFollowUps::
     * summaryAction()), not mountTableAction() — a *table row* action
     * bound ->visible(false) also comes back isDisabled() === true in
     * this Filament version (isDisabled() folds in isHidden()), so a
     * hidden table action can never actually be mounted, only rendered
     * or not. A page-level action sidesteps that entirely: it's simply
     * never placed in any auto-rendering slot (getHeaderActions() etc.),
     * so it needs no ->visible(false) and stays mountable. It carries the
     * target Follow-Up as an argument rather than a bound $record.
     */
    private static function groupSummary(FollowUp $record, $livewire): HtmlString
    {
        $statuses = ($livewire->activeTab ?? 'pending') === 'lost'
            ? [FollowUpStatus::Cancelled]
            : [FollowUpStatus::Completed, FollowUpStatus::Cancelled];

        $matching = FollowUp::query()
            ->visibleTo(auth()->user())
            ->where('prospect_id', $record->prospect_id)
            ->whereIn('status', $statuses);

        $count = $matching->count();
        $mostRecent = $matching->max('follow_up_at');

        $countLabel = $count === 1 ? '1 follow-up' : "{$count} follow-ups";
        $recentLabel = $mostRecent ? Carbon::parse($mostRecent)->format('d M Y') : '—';

        // Single-quoted HTML attribute since the JSON arguments payload
        // itself only ever contains double quotes.
        $summaryButton = sprintf(
            '<button type="button" wire:click=\'mountAction("summary", %s)\' class="follow-up-summary-button ms-2 text-sm font-medium text-primary-600 hover:underline dark:text-primary-400">Summary</button>',
            json_encode(['followUpId' => $record->id]),
        );

        return new HtmlString("{$countLabel} · most recent {$recentLabel}{$summaryButton}");
    }

    /**
     * Every Completed/Cancelled Follow-Up against this record's company,
     * most recent first, for the "Summary" modal (see ListFollowUps::
     * summaryAction(), which is what actually calls this). A Completed
     * Follow-Up's date/notes live on the Call Record its "Completed"
     * action generated (generatedCallRecord — see App\Models\FollowUp); a
     * Cancelled one has no dedicated cancelled_at column, so updated_at
     * (set the moment the "Close" action flips the status) and its own
     * notes column are all there is. Two different date sources per
     * status means "most recent first" has to be resolved in PHP rather
     * than a single ORDER BY.
     *
     * map() downgrades to a plain \Illuminate\Support\Collection here (not
     * Eloquent's) since the mapped items are arrays, not Models.
     *
     * @return \Illuminate\Support\Collection<int, array{status: FollowUpStatus, occurred_at: ?Carbon, notes: ?string}>
     */
    public static function companyFollowUpHistory(FollowUp $record): \Illuminate\Support\Collection
    {
        return FollowUp::query()
            ->visibleTo(auth()->user())
            ->where('prospect_id', $record->prospect_id)
            ->whereIn('status', [FollowUpStatus::Completed, FollowUpStatus::Cancelled])
            ->with('generatedCallRecord')
            ->get()
            ->map(fn (FollowUp $followUp) => [
                'status' => $followUp->status,
                'occurred_at' => $followUp->status === FollowUpStatus::Completed
                    ? $followUp->generatedCallRecord?->called_at
                    : $followUp->updated_at,
                'notes' => $followUp->status === FollowUpStatus::Completed
                    ? $followUp->generatedCallRecord?->notes
                    : $followUp->notes,
            ])
            ->sortByDesc('occurred_at')
            ->values();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFollowUps::route('/'),
            'create' => Pages\CreateFollowUp::route('/create'),
            'view' => Pages\ViewFollowUp::route('/{record}'),
            'edit' => Pages\EditFollowUp::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->visibleTo(auth()->user());
    }
}
