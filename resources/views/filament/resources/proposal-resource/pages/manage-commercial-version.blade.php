{{--
    Read-only Commercial Version Summary + Version History always render
    first (Employee visibility included — see ProposalVersionPolicy::edit()
    being what actually gates the editor below, not this infolist). The
    Draft editor form only renders additionally when the current Version is
    Draft and the acting Manager-or-above is authorized to edit it.
--}}
<x-filament-panels::page>
    <div wire:key="{{ $this->getId() }}.infolist">
        {{ $this->infolist }}
    </div>

    @if ($this->isDraftEditable())
        <div class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <h2 class="text-base font-semibold mb-4">Edit Commercial Draft</h2>
            {{ $this->getDraftForm() }}
        </div>
    @endif
</x-filament-panels::page>
