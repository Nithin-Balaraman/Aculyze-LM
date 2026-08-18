{{--
    Registers the `bulkSelect` Alpine store once, panel-wide, via
    AdminPanelProvider on PanelsRenderHook::SCRIPTS_AFTER. `enabled`
    starts false so bulk-select checkboxes (see the overridden
    checkbox.blade.php) stay hidden until "Select Multiple" is clicked.
    Registering inside an `alpine:init` listener is the standard safe
    pattern — it works regardless of script load order, since Alpine
    itself dispatches that event only once it has finished loading.
--}}

<script>
    document.addEventListener('alpine:init', () => {
        Alpine.store('bulkSelect', {
            enabled: false,
        });
    });
</script>
