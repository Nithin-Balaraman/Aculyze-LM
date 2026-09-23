<?php

namespace App\Filament\Resources\ProspectResource\Pages;

use App\Filament\Pages\ImportProspects;
use App\Filament\Resources\ProspectResource;
use App\Models\ProspectTableColumnPreference;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Arr;

class ListProspects extends ListRecords
{
    protected static string $resource = ProspectResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('importExcel')
                ->label('Import from Excel')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('gray')
                ->url(fn () => ImportProspects::getUrl()),
            Actions\CreateAction::make(),
        ];
    }

    /**
     * Filament's own Concerns\CanToggleColumns::bootedInteractsWithTable()
     * (called via parent::bootedInteractsWithTable() below) fills
     * $toggledTableColumns on first load, but only from the PHP session —
     * which doesn't survive logout (invalidated) or a different browser/
     * device. DB is the real source of truth instead: the saved
     * ProspectTableColumnPreference if one exists, or the same true
     * default state Filament's own getDefaultTableColumnToggleState()
     * would compute if not (see below for why that method itself can't be
     * called here).
     *
     * This MUST run and populate $toggledTableColumns BEFORE calling
     * parent, not after (tried and rejected — confirmed directly, not
     * assumed): parent's own bootedInteractsWithTable() caches the filters
     * dropdown form (cacheForm('tableFiltersForm', ...), used by every
     * toggle-following ->visible() filter in ProspectResource::table())
     * BEFORE it ever touches $toggledTableColumns itself. On a fresh
     * mount, $toggledTableColumns is still `[]` at that exact moment, so
     * isTableColumnToggledHidden() — Arr::has([], $col) is false —
     * returns false ("not hidden") for every single toggleable column
     * regardless of its true state, caching every toggle-following filter
     * as visible. That stale, over-inclusive cached form then survives
     * for the rest of the page's lifetime (every later Livewire round-trip
     * reuses the cached form via hasCachedForm()) — reproduced directly in
     * a real browser: every toggle-following filter appeared regardless of
     * actual toggle state, confirmed by checking the real DB-stored
     * preference at the same moment. Populating the real value first means
     * parent's own `if (! count($this->toggledTableColumns))` guard sees
     * it's already set and skips its session-based fill entirely, so the
     * filters form gets cached correctly from the very first request.
     *
     * The "no preference yet" fallback uses ProspectResource::
     * TOGGLEABLE_COLUMNS directly (every one false/hidden) rather than
     * Filament's own getDefaultTableColumnToggleState() — that method
     * needs $this->getTable(), and $this->table is a typed property not
     * yet initialized this early (parent::bootedInteractsWithTable() is
     * what constructs it, on the very first line of its own body) —
     * confirmed directly: calling it here throws "must not be accessed
     * before initialization".
     *
     * Queried directly (ProspectTableColumnPreference::query()), not via
     * auth()->user()->prospectTableColumnPreference: the latter is a
     * lazy-loaded relation Eloquent caches ON the User model instance —
     * harmless normally (a real request always resolves a fresh User from
     * the session), but confirmed directly to go stale within one shared
     * PHP process/object graph if that same cached-null relation is read
     * again after the row starts existing. Querying directly has no such
     * cache to go stale.
     */
    public function bootedInteractsWithTable(): void
    {
        if ($this->shouldMountInteractsWithTable && (! count($this->toggledTableColumns))) {
            $userId = auth()->id();

            $preference = $userId
                ? ProspectTableColumnPreference::query()->where('user_id', $userId)->first()
                : null;

            $this->toggledTableColumns = $preference
                ? $preference->toggled_columns
                : array_fill_keys(ProspectResource::TOGGLEABLE_COLUMNS, false);
        }

        parent::bootedInteractsWithTable();

        if ($this->shouldMountInteractsWithTable) {
            $this->getTableColumnToggleForm()->fill($this->toggledTableColumns);
        }
    }

    /**
     * Fires whenever the toggle-columns form changes. Three
     * responsibilities: persist the new state per-user in the DB
     * (surviving logout/device changes, unlike Filament's own
     * session-only default), force the filters dropdown to rebuild so a
     * newly-shown/hidden filter actually appears/disappears within the
     * SAME live page (not just after a refresh — see below), and clear
     * any filter value belonging to a column that just became hidden — a
     * locked decision for this task: a hidden-but-still-active filter
     * would otherwise keep silently affecting results with the control
     * gone, no visible explanation why.
     *
     * The rebuild is necessary because Concerns\HasForms::cacheForm()
     * caches the filters FORM (i.e. which filter fields exist, not just
     * their values) once, and getTableFiltersForm() reuses that cached
     * form on every later call within the same live Livewire session —
     * confirmed directly in a real browser: toggling a column correctly
     * persisted to the DB immediately, but its filter didn't appear until
     * a full page reload rebuilt the form from scratch. Passing a Closure
     * (not the already-evaluated Form) matters: cacheForm() only skips
     * the "already cached" short-circuit inside getTableFiltersForm()
     * while $isCachingForms is true, which it sets AFTER receiving its
     * $form argument — an eager, non-Closure value would already have hit
     * that short-circuit before cacheForm() ever ran.
     *
     * $this->tableFilters ??= [] guards a Livewire edge case found while
     * testing this exact rebuild, not a cosmetic touch: Filament leaves
     * $tableFilters as literal null (not []) until a filter is first
     * touched. Rebuilding the filters form while it's still null makes
     * Livewire's synthesizer unable to write a NEW nested path (e.g.
     * tableFilters.city.value, for a filter that has just started
     * existing) into a null property — it throws "Property type not
     * supported ... [null]" — confirmed directly by bisecting each set()
     * call in a failing test. An existing, even empty, array is something
     * Livewire can create nested keys inside; null is not.
     */
    public function updatedToggledTableColumns(): void
    {
        parent::updatedToggledTableColumns();

        $userId = auth()->id();

        if ($userId) {
            ProspectTableColumnPreference::query()->updateOrCreate(
                ['user_id' => $userId],
                ['toggled_columns' => $this->toggledTableColumns],
            );
        }

        $this->tableFilters ??= [];
        $this->cacheForm('tableFiltersForm', fn () => $this->getTableFiltersForm());

        foreach ($this->getTable()->getColumns() as $column) {
            if (! $column->isToggleable()) {
                continue;
            }

            if ($this->tableFilters !== null && $this->isTableColumnToggledHidden($column->getName())) {
                Arr::forget($this->tableFilters, $column->getName());
            }
        }
    }
}
