<?php

namespace App\Filament\Resources;

use App\Enums\CallNextAction;
use App\Enums\CallOutcome;
use App\Enums\ProfileSentMode;
use App\Enums\ProfileSentStatus;
use App\Filament\Resources\CallRecordResource\Pages;
use App\Models\CallRecord;
use App\Models\Prospect;
use App\Services\CallRoutingService;
use App\Support\CallDownstreamChain;
use App\Support\DeletionGuard;
use App\Support\TableBulkActions;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;

/**
 * Call Records = the Activity Log (AGENTS.md sections 12, 51). Every call,
 * regardless of outcome, is logged here. Saving a Call Record automatically
 * routes it to Follow-Ups/Appointments/Leads via
 * App\Observers\CallRecordObserver -> App\Services\CallRoutingService.
 */
class CallRecordResource extends Resource
{
    protected static ?string $model = CallRecord::class;

    protected static ?string $navigationIcon = 'heroicon-o-phone';

    protected static ?string $navigationLabel = 'Activity Log';

    protected static ?string $modelLabel = 'Call Record';

    protected static ?string $navigationGroup = 'Sales';

    protected static ?int $navigationSort = 2;

    /**
     * Sentinel Select value for the always-present "+ Create new company…"
     * search-result row (Phase 2 item #4) — a non-numeric string so it can
     * never collide with a real prospects.id.
     */
    private const CREATE_NEW_PROSPECT = '__create_new_prospect__';

    public static function form(Form $form): Form
    {
        return $form->schema(self::formSchema());
    }

    /**
     * Extracted so PipelineBoard's "+ Log a call" / "Record New Call" flow
     * (which needs this exact form, Company selector included) can reuse
     * it directly.
     *
     * Section order: Company (selector + inline create) first — the one
     * decision every other field on this form depends on — then Call
     * Details (including Contact Person/Designation/Phone Called; see
     * callDetailsFieldsSchema()'s own docblock for why they live there
     * rather than in a section of their own), then Profile Sent (only
     * meaningful once an outcome is picked in Call Details), Notes last.
     * "Company Details" (the read-only already-saved-info placeholder)
     * stays immediately under the Company selector it's contextual to,
     * rather than drifting further down the form.
     *
     * @return array<int, Forms\Components\Component>
     */
    public static function formSchema(): array
    {
        return [
            Forms\Components\Section::make('Company')
                ->schema(self::companyFieldSchema()),
            // Phase 2 item #5: once a company is selected, show its
            // already-saved Database details inline so the caller doesn't
            // have to leave this screen to look them up. Only meaningful
            // here (this resource's own create/edit form has the Company
            // select) — PipelineBoard's cross-drop Call dialog never
            // includes this section since the Company is already implied
            // by the dragged card.
            Forms\Components\Section::make('Company Details')
                ->schema([
                    Forms\Components\Placeholder::make('prospect_details')
                        ->label('')
                        ->content(fn (Get $get) => new HtmlString(
                            view('filament.forms.prospect-call-details', [
                                'prospect' => Prospect::find($get('prospect_id')),
                            ])->render()
                        ))
                        ->columnSpanFull(),
                ])
                ->visible(fn (Get $get) => filled($get('prospect_id')) && $get('prospect_id') !== self::CREATE_NEW_PROSPECT),
            Forms\Components\Section::make('Call Details')
                ->columns(2)
                ->schema(self::callDetailsFieldsSchema(includeCompanyField: false)),
            ...self::profileSentFieldsSchema(),
            ...self::notesFieldSchema(),
        ];
    }

    /**
     * The Company selector (search/select an existing Prospect, or the
     * always-present "+ Create new company…" inline-create row) — its own
     * method so formSchema()'s dedicated "Company" section and
     * callDetailsFieldsSchema()'s own $includeCompanyField=true path (kept
     * for any caller that still wants Company folded into Call Details)
     * share the exact same field rather than two copies that could drift.
     *
     * @return array<int, Forms\Components\Component>
     */
    public static function companyFieldSchema(): array
    {
        return [
                        Forms\Components\Select::make('prospect_id')
                            ->label('Company')
                            ->required()
                            // Deliberately NOT ->relationship() and NOT
                            // ->createOptionForm(): createOptionForm's
                            // internals crash without relationship()
                            // (they unconditionally walk
                            // getRelationshipName()), but WITH
                            // relationship() restored, relationship-mode
                            // Select silently rejects a selected value
                            // that has no matching Prospect row before it
                            // ever reaches PHP. registerActions() (see
                            // below) sidesteps both — it's a generic
                            // field-adjacent action with no relationship
                            // coupling at all.
                            ->searchable()
                            ->preload()
                            ->live()
                            // Same blended ranking as the Calls list/
                            // ProspectResource::table() (commit 2c4a8ff):
                            // starts-with matches ranked before contains-
                            // elsewhere ones. Unlike the list's own version,
                            // no ->modifyQueryUsing() workaround is needed
                            // here — this is a plain, freestanding
                            // Prospect::query() built fresh inside this
                            // closure, never merged into a Filament table's
                            // own ->where(function () {...}) nested search
                            // group (the thing that discards ->orderByRaw()
                            // there), so ordering it directly works as
                            // expected. Conditioned on a non-empty $search
                            // so the initial ->preload() call (which passes
                            // an empty string) isn't affected — every
                            // company_name LIKE '%' at that point regardless,
                            // making the CASE WHEN a no-op anyway, but this
                            // keeps the SQL itself clean for that case.
                            // "+ Create new company…" is a plain PHP array
                            // entry ahead of the query results (the `+`
                            // union keeps the left array's keys first), so
                            // it always stays pinned first regardless of
                            // this ranking.
                            ->getSearchResultsUsing(fn (string $search) => [self::CREATE_NEW_PROSPECT => '+ Create new company…']
                                + Prospect::query()
                                    ->visibleTo(auth()->user())
                                    ->where('company_name', 'like', "%{$search}%")
                                    ->when(
                                        filled($search),
                                        fn ($query) => $query->orderByRaw('case when company_name like ? then 0 else 1 end', ["{$search}%"]),
                                    )
                                    ->limit(50)
                                    ->pluck('company_name', 'id')
                                    ->all())
                            ->getOptionLabelUsing(fn ($value) => $value === self::CREATE_NEW_PROSPECT
                                ? '+ Create new company…'
                                : Prospect::find($value)?->company_name)
                            // Pure state normalization only — the sentinel
                            // must never persist as a real prospect_id,
                            // including when state is set programmatically
                            // (e.g. fillForm() in tests, or if JS is
                            // disabled). This does NOT attempt to open the
                            // modal; that trigger lives in
                            // extraAlpineAttributes() below.
                            ->afterStateUpdated(fn ($state, Set $set) => $state === self::CREATE_NEW_PROSPECT
                                && $set('prospect_id', null))
                            // mountFormComponentAction()'s first argument is
                            // this field's absolute statePath, which is NOT
                            // a constant "data.prospect_id" — that's only
                            // true when this schema renders on a Resource's
                            // CreateRecord/EditRecord page (both pages'
                            // getFormStatePath() return 'data'). Reused
                            // as-is inside a page-level Actions\Action (see
                            // PipelineBoard's "+ Log a call" header action,
                            // which calls this same formSchema()), the real
                            // path is "mountedActionsData.{index}.
                            // prospect_id" instead — confirmed by
                            // instrumenting both contexts and inspecting
                            // getFlatComponentsByKey(). $component (bound to
                            // $this via Filament's evaluationIdentifier —
                            // see Forms\Components\Component) exposes
                            // ->getStatePath() so this resolves correctly in
                            // either context rather than hardcoding one of
                            // them. Triggering from genuine client-side
                            // Alpine JS (rather than from inside
                            // afterStateUpdated() above) keeps this a real
                            // top-level $wire call, matching how Filament's
                            // own action buttons trigger it.
                            ->extraAlpineAttributes(fn (Forms\Components\Field $component): array => [
                                'x-init' => sprintf(
                                    <<<'JS'
                                        $watch('state', (value) => {
                                            if (value !== '%s') {
                                                return;
                                            }

                                            state = null;
                                            $wire.mountFormComponentAction('%s', 'createProspect');
                                        })
                                        JS,
                                    self::CREATE_NEW_PROSPECT,
                                    $component->getStatePath(),
                                ),
                            ])
                            // registerActions() (NOT ->suffixAction()) is
                            // the actual root-cause fix: an Action's
                            // isDisabled() is `$this->evaluate($this->
                            // isDisabled) || $this->isHidden()` (see
                            // filament/actions'
                            // Concerns\CanBeDisabled::isDisabled()) — so a
                            // ->suffixAction()->hidden() action is also
                            // implicitly *disabled*, and
                            // mountFormComponentAction() silently refuses
                            // to mount any disabled action (no exception,
                            // no dispatch — exactly the "nothing happens"
                            // symptom seen throughout this investigation).
                            // registerActions() adds the action to the
                            // field's mountable action pool without ever
                            // wiring it into the rendered
                            // prefix/suffix-icon list at all, so it's
                            // never hidden(), never disabled, and never
                            // rendered as a button — the dropdown row is
                            // genuinely the only way to trigger it.
                            // Without this, the mounted action's form model
                            // falls back to this field's *container* model
                            // (CallRecord — this is CallRecordResource's
                            // own create form), since prospect_id has no
                            // ->relationship() of its own. The reused
                            // ProspectResource::formSchema() has an inner
                            // assigned_to field with
                            // ->relationship('assignedEmployee', 'name'),
                            // which crashes when resolved against a
                            // CallRecord instance (no such relation exists,
                            // so Select's internal
                            // RelationshipJoiner::prepareQueryForNoConstraints()
                            // gets passed null where it requires a real
                            // Relation). Declaring the action form's model
                            // explicitly avoids needing prospect_id itself
                            // to carry a ->relationship() — which was tried
                            // earlier and broke selecting the sentinel
                            // value via click.
                            ->actionFormModel(Prospect::class)
                            ->registerActions([
                                Forms\Components\Actions\Action::make('createProspect')
                                    ->modalHeading('Add Company to Database')
                                    // Contact Person/Designation/Email are
                                    // required here specifically — unlike
                                    // the standalone Prospect Create/Edit
                                    // pages — since a rep filling this in
                                    // is already on the phone with the
                                    // contact (see ProspectResource::
                                    // formSchema()'s own docblock).
                                    ->form(ProspectResource::formSchema(requireContactFields: true))
                                    ->action(function (array $data, Set $set) {
                                        $data['created_by'] = auth()->id();
                                        $prospect = Prospect::create($data);

                                        $set('prospect_id', $prospect->getKey());
                                    }),
                            ]),
        ];
    }

    /**
     * The "Call Details" section's own fields (Company excluded by
     * default — see formSchema()'s dedicated "Company" section above and
     * $includeCompanyField below) — extracted so this exact fields/
     * validation/outcome-driven-visibility set (including `next_action`,
     * required whenever outcome is Other — see followUpAtVisible()/
     * appointmentAtVisible() and CallRecord's own `booted()` guard) is
     * available to any caller that needs it, rather than a hand-copied
     * subset that can silently drift out of sync with this one.
     * $includeCompanyField folds companyFieldSchema() in as the section's
     * first field instead, for a caller that wants Company and Call
     * Details combined into one section.
     *
     * @return array<int, Forms\Components\Component>
     */
    public static function callDetailsFieldsSchema(bool $includeCompanyField = true): array
    {
        return [
                ...($includeCompanyField ? self::companyFieldSchema() : []),
                Forms\Components\DateTimePicker::make('called_at')
                    ->required()
                    ->default(now())
                    ->seconds(false),
                Forms\Components\Select::make('outcome')
                    ->options(self::outcomeSelectOptions())
                    ->allowHtml()
                    ->native(false)
                    ->required()
                    ->live()
                    ->helperText('Determines what happens next — see the Follow-Ups, Appointments, and Leads panels.'),
                // Mandatory exactly when the outcome routes to a real next
                // step (CallOutcome::requiresContactDetails() — single
                // source of truth, also enforced model-side by
                // CallRecord::booted()) — you need to know who you
                // actually spoke to when something meaningful results from
                // the call. Reactive off the same live `outcome` field the
                // Follow-Up/Appointment/Profile Sent sections already key
                // off.
                Forms\Components\TextInput::make('contact_person_spoken_to')
                    ->label('Contact Person')
                    ->maxLength(255)
                    ->required(fn (Get $get) => self::resolveOutcome($get('outcome'))?->requiresContactDetails() ?? false),
                Forms\Components\TextInput::make('designation')
                    ->label('Designation')
                    ->placeholder('e.g. Manager, Owner, Procurement Head')
                    ->maxLength(255)
                    ->required(fn (Get $get) => self::resolveOutcome($get('outcome'))?->requiresContactDetails() ?? false),
                Forms\Components\TextInput::make('phone_called')
                    ->label('Phone Called')
                    ->tel()
                    ->maxLength(20)
                    ->required(fn (Get $get) => self::resolveOutcome($get('outcome'))?->requiresContactDetails() ?? false),
                // Visibility is driven by the outcome's own routing
                // rules (CallOutcome::routesToFollowUp()/
                // routesToAppointment()) rather than a manual
                // toggle, so the field can never fall out of sync
                // with what the outcome actually does. Required
                // whenever visible — the caller must know the
                // follow-up/appointment time when logging the call
                // — and read straight into the auto-created
                // Follow-Up/Appointment record by
                // CallRoutingService::createFollowUp()/
                // createAppointment(). Only ever one visible at a
                // time in practice, since no outcome routes to both.
                // The model-level "exempt auto-routed insert"
                // guards on FollowUp/Appointment stay in place —
                // they cover every OTHER write path (tests,
                // seeders, future imports/backfills), not this
                // form, which now never submits a blank value.
                // Phase 3: visible whenever the outcome could
                // possibly create a Follow-Up — either
                // unconditionally (Callback Requested) or as the
                // caller's explicit, intentional decision
                // (Concerned Person Not Available / Profile
                // Requested's optional callback, or Other's
                // CreateFollowUp next action). Required only where
                // the Follow-Up is mandatory, not merely possible —
                // see self::followUpAtRequired().
                Forms\Components\DateTimePicker::make('follow_up_at')
                    ->label('Follow Up At')
                    ->seconds(false)
                    ->visible(fn (Get $get) => self::followUpAtVisible($get('outcome'), $get('next_action')))
                    ->required(fn (Get $get) => self::followUpAtRequired($get('outcome'), $get('next_action'))),
                Forms\Components\DateTimePicker::make('appointment_at')
                    ->label('Appointment At')
                    ->seconds(false)
                    ->visible(fn (Get $get) => self::appointmentAtVisible($get('outcome'), $get('next_action')))
                    ->required(fn (Get $get) => self::appointmentAtVisible($get('outcome'), $get('next_action'))),
                // Phase 3: the explicit, constrained next-action
                // decision — meaningful ONLY for outcome Other (see
                // App\Enums\CallNextAction).
                Forms\Components\Select::make('next_action')
                    ->label('Next Action')
                    ->options(CallNextAction::class)
                    ->live()
                    ->visible(fn (Get $get) => self::resolveOutcome($get('outcome')) === CallOutcome::Others)
                    ->required(fn (Get $get) => self::resolveOutcome($get('outcome')) === CallOutcome::Others),
        ];
    }

    /**
     * Calls Phase 2, item 3: the Outcome dropdown's own routing-badge
     * options — label plus a small badge at the far right indicating
     * where that outcome sends the call (CallOutcome::routingBadge(),
     * which derives it from the existing routesTo*() predicates; nothing
     * here re-maps outcome -> destination itself). Shared by every place
     * this exact Outcome Select appears: this method's own
     * callDetailsFieldsSchema() (reused verbatim by both the main Create/
     * Edit/View form and, via CallRecordResource::formSchema(),
     * PipelineBoard's "+ Log a call"/"Record New Call" dialog) and
     * correctOutcomeAction()'s own "Corrected Outcome" Select.
     *
     * Built as a plain array of pre-rendered HTML labels (`->allowHtml()`)
     * rather than the usual `->options(CallOutcome::class)`, since a
     * label needs its own inline markup to place the badge — the actual
     * badge markup is the real <x-filament::badge> component (via
     * Blade::render(), the same mechanism FollowUpResource::
     * renderCompanyWithOriginTypeBadge() already uses elsewhere in this
     * app), not a hand-rolled substitute, so it uses Filament's own
     * already-shipped classes/color system rather than anything needing
     * a new Tailwind class compiled into this app's own theme.css.
     * ->native(false) is required alongside ->allowHtml(): Select
     * defaults to native=true when not ->searchable() (confirmed
     * directly — Concerns\CanBeNative's own $isNative default), and a
     * real native <option> element never interprets HTML in its own
     * text content regardless of Blade's {!! !!}; only the Alpine/
     * choices.js-backed custom dropdown (forced by ->native(false))
     * actually honors allowHTML, for both the open list AND the closed/
     * selected single-value display (confirmed directly in resources/js/
     * components/select.js: `allowHTML: isHtmlAllowed` is passed straight
     * into the choices.js config, and its own `.choices__list--single`
     * closed-state element is later written via `.innerHTML`, not
     * `.textContent`) — this is also why the selected value keeps
     * reading cleanly once collapsed: it shows the exact same label +
     * badge, not a stripped/escaped version of it.
     *
     * @return array<string, \Illuminate\Support\HtmlString>
     */
    public static function outcomeSelectOptions(): array
    {
        return collect(CallOutcome::cases())->mapWithKeys(fn (CallOutcome $case) => [
            $case->value => new HtmlString(Blade::render(
                <<<'BLADE'
                    <div style="display:flex;align-items:center;justify-content:space-between;gap:0.75rem;">
                        <span>{{ $label }}</span>
                        <x-filament::badge color="gray" size="xs">{{ $badge }}</x-filament::badge>
                    </div>
                    BLADE,
                ['label' => $case->getLabel(), 'badge' => $case->routingBadge()],
            )),
        ])->all();
    }

    /**
     * The "Profile Sent" section — extracted for the same reuse reason as
     * callDetailsFieldsSchema() above (see that method's docblock).
     *
     * @return array<int, Forms\Components\Component>
     */
    public static function profileSentFieldsSchema(): array
    {
        return [
            // Phase 3: structured Profile Sent tracking — visible only
            // for outcome Profile Requested. Follow-Up itself stays
            // optional/intentional only (see follow_up_at above).
            Forms\Components\Section::make('Profile Sent')
                ->columns(2)
                ->visible(fn (Get $get) => self::resolveOutcome($get('outcome')) === CallOutcome::ProfileRequested)
                ->schema([
                    Forms\Components\Select::make('profile_sent_status')
                        ->options(ProfileSentStatus::class)
                        ->live()
                        ->required(fn (Get $get) => self::resolveOutcome($get('outcome')) === CallOutcome::ProfileRequested),
                    Forms\Components\Select::make('profile_sent_mode')
                        ->label('Mode')
                        ->options(ProfileSentMode::class)
                        ->live()
                        ->required(fn (Get $get) => self::resolveProfileSentStatus($get('profile_sent_status')) === ProfileSentStatus::Sent),
                    Forms\Components\DateTimePicker::make('profile_sent_at')
                        ->label('Sent At')
                        ->seconds(false)
                        ->required(fn (Get $get) => self::resolveProfileSentStatus($get('profile_sent_status')) === ProfileSentStatus::Sent),
                    Forms\Components\Textarea::make('profile_sent_notes')
                        ->label('Notes')
                        ->rows(2)
                        ->columnSpanFull()
                        ->required(fn (Get $get) => self::resolveProfileSentMode($get('profile_sent_mode')) === ProfileSentMode::Other)
                        ->helperText('Required when Mode is Other, to explain how the profile was sent.'),
                ]),
        ];
    }

    /**
     * The "Notes" section — extracted for the same reuse reason as
     * callDetailsFieldsSchema() above (see that method's docblock).
     *
     * @return array<int, Forms\Components\Component>
     */
    public static function notesFieldSchema(): array
    {
        return [
            Forms\Components\Section::make('Notes')
                ->schema([
                    // Required for any outcome where a real conversation
                    // actually happened (CallOutcome::requiresNotes() —
                    // every outcome except the three "never connected"
                    // ones), so there's something on record. Was
                    // previously scoped to just Others (the catch-all
                    // with no defined next action). Mirrors the same
                    // required()+rule() pairing LeadResource uses for
                    // "Notes required when Validated" — plain required()
                    // alone would accept a whitespace-only value.
                    Forms\Components\Textarea::make('notes')
                        ->rows(3)
                        ->columnSpanFull()
                        ->required(fn (Get $get) => self::outcomeRequiresNotes($get('outcome')))
                        ->validationMessages([
                            'required' => 'Notes are required for this outcome — only No Answer, Switched Off, and Not Reachable are exempt.',
                        ])
                        ->rule(
                            fn (Get $get) => function (string $attribute, $value, \Closure $fail) use ($get) {
                                if (self::outcomeRequiresNotes($get('outcome')) && blank($value)) {
                                    $fail('Notes are required for this outcome — only No Answer, Switched Off, and Not Reachable are exempt.');
                                }
                            },
                        ),
                ]),
        ];
    }

    /**
     * $get() may hand back either the raw string value or the hydrated
     * CallOutcome case depending on how the form state got there — see the
     * comment above the `notes` field (mirrors LeadResource::
     * stageIsValidated()).
     */
    private static function outcomeRequiresNotes(mixed $outcome): bool
    {
        return self::resolveOutcome($outcome)?->requiresNotes() ?? false;
    }

    private static function followUpAtVisible(mixed $outcome, mixed $nextAction): bool
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

    /** Required only where the Follow-Up is mandatory, not merely possible (Concerned Person Not Available / Profile Requested's callback stays optional). */
    private static function followUpAtRequired(mixed $outcome, mixed $nextAction): bool
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

    private static function appointmentAtVisible(mixed $outcome, mixed $nextAction): bool
    {
        $resolved = self::resolveOutcome($outcome);

        if ($resolved === null) {
            return false;
        }

        if ($resolved->routesToAppointment()) {
            return true;
        }

        return $resolved === CallOutcome::Others && self::resolveNextAction($nextAction) === CallNextAction::CreateAppointment;
    }

    private static function resolveOutcome(mixed $outcome): ?CallOutcome
    {
        return $outcome instanceof CallOutcome ? $outcome : CallOutcome::tryFrom((string) $outcome);
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

    /**
     * Extracted from table() so the Prospect View page's Call Records
     * mini-table (see App\Filament\Widgets\ProspectCallRecordsTable) can
     * reuse the exact same column set rather than duplicating it —
     * matches the ProspectResource::formSchema() precedent for form
     * fields.
     *
     * @return array<int, Tables\Columns\Column>
     */
    public static function columns(): array
    {
        return [
            Tables\Columns\TextColumn::make('called_at')
                ->dateTime('d M Y, h:i A')
                ->sortable(),
            Tables\Columns\TextColumn::make('prospect.company_name')
                ->label('Company')
                ->searchable()
                ->sortable(),
            Tables\Columns\TextColumn::make('outcome')
                ->badge()
                ->sortable(),
            Tables\Columns\TextColumn::make('caller.name')
                ->label('Called By')
                ->badge()
                ->sortable()
                // Employees only ever see their own calls (see
                // CallRecord::scopeVisibleTo()), so this column always
                // just shows their own name for them — redundant, not
                // a toggleable default like the others, just hidden
                // outright for non-admins.
                ->visible(fn () => auth()->user()->isAdmin()),
            Tables\Columns\TextColumn::make('follow_up_at')
                ->dateTime('d M Y, h:i A')
                ->placeholder('—')
                ->toggleable(isToggledHiddenByDefault: true),
            Tables\Columns\TextColumn::make('appointment_at')
                ->dateTime('d M Y, h:i A')
                ->placeholder('—')
                ->toggleable(isToggledHiddenByDefault: true),
            Tables\Columns\TextColumn::make('notes')
                ->limit(40)
                ->toggleable(isToggledHiddenByDefault: true),
            // Flag-as-Incorrect: a simple yes/no indicator — the full
            // reason and downstream-blocker detail live in the "Flag
            // Details" row action/modal (viewFlagDetailsAction()) rather
            // than being crammed into another table column.
            Tables\Columns\IconColumn::make('flagged_incorrect_at')
                ->label('Flagged')
                ->boolean()
                ->getStateUsing(fn (CallRecord $record) => filled($record->flagged_incorrect_at))
                ->toggleable(isToggledHiddenByDefault: true),
        ];
    }

    /**
     * Same blended-ranking mechanism as ProspectResource::table() (see
     * that method's own docblock, commit 2c4a8ff) — starts-with matches
     * ranked before contains-elsewhere matches, in one result set, with
     * the table's own sort (defaultSort('called_at', 'desc') or whatever
     * a user has actively clicked) surviving as the tie-breaker. Company
     * Name here is a RELATIONSHIP column (prospect.company_name), not a
     * plain column on this table, so the ranking can't be a bare
     * `company_name LIKE ...` CASE WHEN the way Prospects' own version
     * is — there is no such column on call_records. It's a scalar
     * correlated subquery against prospects instead, correlated on
     * call_records.prospect_id = prospects.id, which resolves to exactly
     * the same 0-or-1 CASE WHEN per row without needing a JOIN (and
     * without the column-ambiguity/aliasing concerns a JOIN would add on
     * top of this query's own OrganizationScope/visibleTo() wheres).
     *
     * Same reason as Prospects' own version for WHY this can't live
     * inside the column's own ->searchable() (Filament invokes column
     * search from inside a ->where(function ($query) {...}) nested
     * group; Laravel's Query\Builder::whereNested()/forNestedWhere()
     * build that group with a separate, throwaway Builder whose
     * ->orders never gets copied back onto the real query — confirmed
     * directly again here, not assumed, the same way it was for
     * Prospects) — this lives in ->modifyQueryUsing() instead, which
     * Concerns\HasRecords runs before ->applySortingToTableQuery(), so
     * this order-by always ends up primary with defaultSort/a user's
     * active sort surviving as the tie-breaker, and is a no-op with an
     * empty search (confirmed via ->toSql(): no CASE WHEN/subquery
     * appears in the generated SQL at all when the search box is empty).
     */
    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function (Builder $query, $livewire): Builder {
                $search = $livewire->getTableSearch();

                if (filled($search)) {
                    $query->orderByRaw(
                        '(select case when company_name like ? then 0 else 1 end from prospects where prospects.id = call_records.prospect_id) asc',
                        ["{$search}%"],
                    );
                }

                return $query;
            })
            ->columns(static::columns())
            ->filters([
                Tables\Filters\SelectFilter::make('outcome')
                    ->options(CallOutcome::class),
                Tables\Filters\SelectFilter::make('user_id')
                    ->label('Called By')
                    ->relationship('caller', 'name')
                    ->visible(fn () => auth()->user()->isAdmin()),
                Tables\Filters\Filter::make('called_at')
                    ->form([
                        Forms\Components\DatePicker::make('from'),
                        Forms\Components\DatePicker::make('until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('called_at', '>=', $date))
                            ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('called_at', '<=', $date));
                    }),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ViewAction::make(),
                    Tables\Actions\EditAction::make(),
                    self::correctOutcomeAction(),
                    self::flagAsIncorrectAction(),
                    self::viewFlagDetailsAction(),
                    self::deleteCallAndChainAction(),
                    Tables\Actions\DeleteAction::make()
                        ->visible(fn () => auth()->user()->isAdmin())
                        ->before(fn (CallRecord $record) => DeletionGuard::guardRecord($record, 'call record')),
                ]),
            ])
            // Delete/Deselect as standalone toolbar buttons, not nested in a
            // "Bulk actions" dropdown — same placement-only change as
            // ProspectResource::table() (see that method's own comment): a
            // plain array (no BulkActionGroup wrapper) is what makes
            // Filament render each one as its own directly-visible button.
            // Neither action's own config (visibility, confirmation dialog,
            // dehydration, etc.) changes — only how they're grouped for
            // display.
            ->bulkActions([
                TableBulkActions::deselectAll(),
                Tables\Actions\DeleteBulkAction::make()
                    ->visible(fn () => auth()->user()->isAdmin())
                    ->before(fn (Collection $records) => DeletionGuard::guardRecords(
                        $records,
                        'call records',
                        fn (CallRecord $call) => $call->called_at->format('d M Y').' — '.$call->prospect->company_name,
                    )),
            ])
            ->defaultSort('called_at', 'desc')
            ->emptyStateHeading('No calls logged yet.')
            ->emptyStateDescription('Every call you make against a prospect shows up here — successful or not.')
            ->emptyStateIcon('heroicon-o-phone');
    }

    /**
     * Phase 3: the explicit, intentional "Correct Outcome" action —
     * deliberately NOT the generic Edit action, which never reconciles
     * routing. Shows the existing outcome, captures the corrected outcome
     * plus a mandatory correction reason, collects whatever destination-
     * specific data the corrected outcome requires (reusing the exact same
     * conditional fields/visibility as logging a call), and delegates to
     * CallRoutingService::correctOutcome() — which enforces the safety
     * boundary (existing downstream history blocks the correction) and
     * executes routing exactly once. Authorization mirrors ordinary Call
     * edit (auth()->user()->can('update', $record)) — no stricter tier,
     * consistent with how Appointment/Demo outcome recording is gated.
     *
     * Flag-as-Incorrect follow-up: restricted to the "clean" case only —
     * visible ONLY when array_filter($record->deletionBlockers()) is
     * empty (no real downstream Follow-Up/Appointment/Lead yet). Once
     * real history exists, CallRoutingService::correctOutcome() would
     * already reject the write (the same deletionBlockers() check, deeper
     * in the stack) — this just surfaces that same boundary at the UI
     * level too, instead of letting the reviewer open the form, fill it
     * in, and only THEN discover it's rejected. flagAsIncorrectAction()
     * below is the mutually-exclusive counterpart shown in exactly the
     * other case, so an authorized reviewer always sees exactly one of
     * the two, never both, never neither.
     */
    private static function correctOutcomeAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('correctOutcome')
            ->label('Correct Outcome')
            ->icon('heroicon-o-arrow-path')
            ->color('warning')
            ->visible(fn (CallRecord $record) => auth()->user()->can('update', $record)
                && array_filter($record->deletionBlockers()) === [])
            ->form(fn (CallRecord $record) => [
                Forms\Components\Placeholder::make('current_outcome')
                    ->label('Current Outcome')
                    ->content(fn () => $record->outcome->getLabel()),
                Forms\Components\Select::make('outcome')
                    ->label('Corrected Outcome')
                    ->options(self::outcomeSelectOptions())
                    ->allowHtml()
                    ->native(false)
                    ->required()
                    ->live(),
                Forms\Components\Textarea::make('correction_reason')
                    ->label('Correction Reason')
                    ->required()
                    ->rows(2)
                    ->helperText('Why is the previously recorded outcome being changed?'),
                Forms\Components\DateTimePicker::make('follow_up_at')
                    ->label('Follow Up At')
                    ->seconds(false)
                    ->visible(fn (Forms\Get $get) => self::followUpAtVisible($get('outcome'), $get('next_action')))
                    ->required(fn (Forms\Get $get) => self::followUpAtRequired($get('outcome'), $get('next_action'))),
                Forms\Components\DateTimePicker::make('appointment_at')
                    ->label('Appointment At')
                    ->seconds(false)
                    ->visible(fn (Forms\Get $get) => self::appointmentAtVisible($get('outcome'), $get('next_action')))
                    ->required(fn (Forms\Get $get) => self::appointmentAtVisible($get('outcome'), $get('next_action'))),
                // Mandatory exactly when the corrected outcome routes to a
                // real next step (CallOutcome::requiresContactDetails()) —
                // pre-filled from this Call's own existing values (it may
                // already have been logged with the right contact but the
                // wrong outcome), still fully editable.
                Forms\Components\TextInput::make('contact_person_spoken_to')
                    ->label('Contact Person')
                    ->default(fn () => $record->contact_person_spoken_to)
                    ->maxLength(255)
                    ->required(fn (Forms\Get $get) => self::resolveOutcome($get('outcome'))?->requiresContactDetails() ?? false),
                Forms\Components\TextInput::make('designation')
                    ->default(fn () => $record->designation)
                    ->maxLength(255)
                    ->required(fn (Forms\Get $get) => self::resolveOutcome($get('outcome'))?->requiresContactDetails() ?? false),
                Forms\Components\TextInput::make('phone_called')
                    ->label('Phone Called')
                    ->tel()
                    ->default(fn () => $record->phone_called)
                    ->maxLength(20)
                    ->required(fn (Forms\Get $get) => self::resolveOutcome($get('outcome'))?->requiresContactDetails() ?? false),
                Forms\Components\Select::make('next_action')
                    ->label('Next Action')
                    ->options(CallNextAction::class)
                    ->live()
                    ->visible(fn (Forms\Get $get) => self::resolveOutcome($get('outcome')) === CallOutcome::Others)
                    ->required(fn (Forms\Get $get) => self::resolveOutcome($get('outcome')) === CallOutcome::Others),
                Forms\Components\Select::make('profile_sent_status')
                    ->label('Profile Sent Status')
                    ->options(ProfileSentStatus::class)
                    ->live()
                    ->visible(fn (Forms\Get $get) => self::resolveOutcome($get('outcome')) === CallOutcome::ProfileRequested)
                    ->required(fn (Forms\Get $get) => self::resolveOutcome($get('outcome')) === CallOutcome::ProfileRequested),
                Forms\Components\Select::make('profile_sent_mode')
                    ->label('Profile Sent Mode')
                    ->options(ProfileSentMode::class)
                    ->live()
                    ->visible(fn (Forms\Get $get) => self::resolveOutcome($get('outcome')) === CallOutcome::ProfileRequested)
                    ->required(fn (Forms\Get $get) => self::resolveProfileSentStatus($get('profile_sent_status')) === ProfileSentStatus::Sent),
                Forms\Components\DateTimePicker::make('profile_sent_at')
                    ->label('Profile Sent At')
                    ->seconds(false)
                    ->visible(fn (Forms\Get $get) => self::resolveOutcome($get('outcome')) === CallOutcome::ProfileRequested)
                    ->required(fn (Forms\Get $get) => self::resolveProfileSentStatus($get('profile_sent_status')) === ProfileSentStatus::Sent),
                Forms\Components\Textarea::make('profile_sent_notes')
                    ->label('Profile Sent Notes')
                    ->rows(2)
                    ->visible(fn (Forms\Get $get) => self::resolveOutcome($get('outcome')) === CallOutcome::ProfileRequested)
                    ->required(fn (Forms\Get $get) => self::resolveProfileSentMode($get('profile_sent_mode')) === ProfileSentMode::Other),
                Forms\Components\Textarea::make('notes')
                    ->label('Notes')
                    ->rows(2)
                    ->helperText('Optional — updates the Call\'s own Notes alongside the correction.'),
            ])
            ->action(function (CallRecord $record, array $data) {
                try {
                    app(CallRoutingService::class)->correctOutcome(
                        $record,
                        CallOutcome::from($data['outcome']),
                        $data['correction_reason'],
                        array_filter([
                            'follow_up_at' => $data['follow_up_at'] ?? null,
                            'appointment_at' => $data['appointment_at'] ?? null,
                            'contact_person_spoken_to' => $data['contact_person_spoken_to'] ?? null,
                            'designation' => $data['designation'] ?? null,
                            'phone_called' => $data['phone_called'] ?? null,
                            'next_action' => filled($data['next_action'] ?? null) ? CallNextAction::from($data['next_action']) : null,
                            'profile_sent_status' => filled($data['profile_sent_status'] ?? null) ? ProfileSentStatus::from($data['profile_sent_status']) : null,
                            'profile_sent_mode' => filled($data['profile_sent_mode'] ?? null) ? ProfileSentMode::from($data['profile_sent_mode']) : null,
                            'profile_sent_at' => $data['profile_sent_at'] ?? null,
                            'profile_sent_notes' => $data['profile_sent_notes'] ?? null,
                            'notes' => $data['notes'] ?? null,
                        ], fn ($value) => $value !== null),
                    );
                } catch (\LogicException $e) {
                    \Filament\Notifications\Notification::make()
                        ->title("Couldn't correct this Call's outcome")
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                    throw new \Filament\Support\Exceptions\Halt;
                }

                \Filament\Notifications\Notification::make()->title('Outcome corrected')->success()->send();
            });
    }

    /**
     * The counterpart to correctOutcomeAction() above, shown in exactly
     * the opposite case: once a Call's outcome already created real
     * downstream history, its outcome can no longer be silently
     * corrected (see CallRoutingService::correctOutcome()'s own
     * rejection for that case) — instead this marks the Call for Saji's
     * manual review, recording only WHEN and (optionally) WHY, mirroring
     * the existing correction_reason/outcome_corrected_at shape rather
     * than inventing a new one (see the migration adding these columns).
     * Re-flagging (e.g. to update the reason) is allowed — it just
     * re-stamps flagged_incorrect_at, not a special first-time-only
     * action.
     */
    private static function flagAsIncorrectAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('flagAsIncorrect')
            ->label('Flag as Incorrect')
            ->icon('heroicon-o-flag')
            ->color('danger')
            ->modalSubmitActionLabel('Flag as Incorrect')
            ->visible(fn (CallRecord $record) => auth()->user()->can('update', $record)
                && array_filter($record->deletionBlockers()) !== [])
            ->form(fn (CallRecord $record) => [
                Forms\Components\Placeholder::make('downstream_notice')
                    ->label('')
                    ->content("This Call already created a real {$record->downstreamRecordLabel()} — its outcome can no longer be corrected automatically. Flagging it records that for Saji's manual review; it does not delete or change anything on its own."),
                Forms\Components\Textarea::make('flag_reason')
                    ->label('Reason (optional)')
                    ->rows(3)
                    ->helperText("Why do you believe this Call's outcome is incorrect?"),
            ])
            ->action(function (CallRecord $record, array $data) {
                $record->forceFill([
                    'flagged_incorrect_at' => now(),
                    'flag_reason' => $data['flag_reason'] ?? null,
                ])->save();

                \Filament\Notifications\Notification::make()
                    ->title('Call flagged as incorrect')
                    ->body('Saji will review this manually.')
                    ->success()
                    ->send();
            });
    }

    /**
     * Read-only review info for a flagged Call — the piece the locked
     * design specifically called for: surfacing the FULL downstream
     * chain (not just the immediate record — see
     * App\Support\CallDownstreamChain), each link's own
     * deletionBlockers(), and which link is the true end, so a reviewer
     * can tell at a glance whether a clean full-chain delete is still
     * possible, or whether history anywhere along the chain (e.g. a Lead
     * that already has a Proposal, which per AGENTS.md section 59 can
     * never itself be deleted once it has a commercial Version) makes
     * deletion permanently impossible without exceptional intervention.
     * No form, no mutation — purely informational, closed with "Close"
     * rather than a submit action.
     */
    private static function viewFlagDetailsAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('viewFlagDetails')
            ->label('Flag Details')
            ->icon('heroicon-o-information-circle')
            ->color('gray')
            ->visible(fn (CallRecord $record) => filled($record->flagged_incorrect_at))
            ->modalHeading('Flagged Call — Review Details')
            ->modalContent(fn (CallRecord $record) => view('filament.infolists.call-flag-details', [
                'record' => $record,
                'chain' => CallDownstreamChain::walk($record),
            ]))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close');
    }

    /**
     * Locked design Part 3: one-click cleanup for a flagged Call, so
     * Saji doesn't have to manually navigate to a separate resource page
     * per chain link. Admin-gated, same as every other Call/downstream
     * Delete action (AGENTS.md section 37). Always visible on a flagged
     * Call so the modal itself can show WHY it's blocked when it is
     * (Part 3.4) — only the submit button is conditionally withheld
     * (Filament's own ->modalSubmitAction(false) mechanism, evaluated
     * per-record), never the whole action, so a reviewer can always open
     * it to see the chain-position information from Part 2.
     *
     * Never bypasses DeletionGuard/deletionBlockers(): CallDownstreamChain::
     * isClean() checks EVERY link's own deletionBlockers() (see that
     * method's own docblock for why every link, not only the final one),
     * exactly the same check DeletionGuard::guardRecord() already runs
     * per record — this only orchestrates those same individually-
     * permitted deletes in the correct (deepest-first) order, inside one
     * transaction, instead of requiring Saji to do it one resource page
     * at a time.
     */
    private static function deleteCallAndChainAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('deleteCallAndChain')
            ->label('Delete Call + Downstream Chain')
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->visible(fn (CallRecord $record) => auth()->user()->isAdmin() && filled($record->flagged_incorrect_at))
            ->modalHeading('Delete Call + Downstream Chain')
            ->modalContent(fn (CallRecord $record) => view('filament.infolists.call-chain-delete', [
                'record' => $record,
                'chain' => CallDownstreamChain::walk($record),
                'isClean' => CallDownstreamChain::isClean($record),
            ]))
            ->modalSubmitAction(fn (CallRecord $record) => CallDownstreamChain::isClean($record) ? null : false)
            ->modalSubmitActionLabel('Delete Everything')
            ->modalCancelActionLabel('Close')
            ->action(function (CallRecord $record) {
                if (! CallDownstreamChain::isClean($record)) {
                    // Defensive only — the modal already withholds the
                    // submit button in this case; this guards a direct
                    // Livewire call bypassing the UI too.
                    throw new \Filament\Support\Exceptions\Halt;
                }

                $chain = CallDownstreamChain::walk($record);

                DB::transaction(function () use ($record, $chain) {
                    // Deepest link first — the exact reverse of the RESTRICT
                    // FK direction, so every delete in this sequence is
                    // individually valid on its own terms at the moment it runs.
                    foreach (array_reverse($chain) as $node) {
                        $node['record']->delete();
                    }

                    $record->delete();
                });

                \Filament\Notifications\Notification::make()
                    ->title('Call and its downstream chain deleted')
                    ->success()
                    ->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCallRecords::route('/'),
            'create' => Pages\CreateCallRecord::route('/create'),
            'view' => Pages\ViewCallRecord::route('/{record}'),
            'edit' => Pages\EditCallRecord::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->visibleTo(auth()->user());
    }

    /**
     * The fallback destination for View/Edit/Create Call Record's own
     * "Back" header action — used only when there's no real browser
     * history to go back to (a fresh tab/bookmark) or JavaScript is
     * unavailable. Same mechanism as ProspectResource::getBackFallbackUrl()
     * (see ProspectResource::BACK_BUTTON_CLICK_HANDLER's own docblock for
     * why history.back() is the *primary* mechanism) — reused verbatim via
     * ProspectResource::BACK_BUTTON_COLOR/BACK_BUTTON_CLICK_HANDLER in each
     * Call Record page, not a second implementation. Only the fallback
     * destination is resource-specific, by definition — here, the Calls
     * list rather than the Prospects list.
     */
    public static function getBackFallbackUrl(): string
    {
        return static::getUrl('index');
    }
}
