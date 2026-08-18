{{--
    "Select Multiple" toggle — registered via AdminPanelProvider on
    TablesRenderHook::TOOLBAR_TOGGLE_COLUMN_TRIGGER_AFTER, scoped to the
    List pages that actually have bulk actions. Flips the `bulkSelect`
    Alpine store that checkbox.blade.php (see the overridden vendor view)
    gates its visibility on. Toggling back off also clears any in-progress
    selection via the table's own public deselectAllTableRecords() method,
    so nothing stays silently selected once select-mode is exited.
--}}

<x-filament::button
    type="button"
    color="gray"
    size="sm"
    outlined
    icon="heroicon-o-check-circle"
    x-on:click="
        $store.bulkSelect.enabled = ! $store.bulkSelect.enabled;
        if (! $store.bulkSelect.enabled) { $wire.deselectAllTableRecords() }
    "
>
    <span x-text="$store.bulkSelect.enabled ? 'Done Selecting' : 'Select Multiple'"></span>
</x-filament::button>
