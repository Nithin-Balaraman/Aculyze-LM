{{--
    Overrides vendor/filament/tables/resources/views/components/selection/checkbox.blade.php.
    Shared by every checkbox this table renders (per-row, page-select-all,
    and group-select-all — see cell.blade.php / group-checkbox.blade.php),
    so gating visibility here hides all three at once. Hidden until the
    "Select Multiple" toggle button (see AdminPanelProvider's
    TOOLBAR_TOGGLE_COLUMN_TRIGGER_AFTER render hook) flips the
    `bulkSelect` Alpine store on — otherwise checkboxes sat permanently on
    the left of every row, pushing the row-actions dropdown off-screen.
--}}

@props([
    'label' => null,
])

<label class="flex" x-cloak x-show="$store.bulkSelect.enabled">
    <x-filament::input.checkbox
        :attributes="
            \Filament\Support\prepare_inherited_attributes($attributes)
                ->merge([
                    'wire:loading.attr' => 'disabled',
                    'wire:target' => implode(',', \Filament\Tables\Table::LOADING_TARGETS),
                ], escape: false)
        "
    />

    @if (filled($label))
        <span class="sr-only">
            {{ $label }}
        </span>
    @endif
</label>
