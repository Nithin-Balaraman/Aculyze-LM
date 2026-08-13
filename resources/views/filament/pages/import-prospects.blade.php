@php
    $inputClasses = 'block w-full rounded-lg border-gray-300 shadow-sm text-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white';
@endphp

<x-filament-panels::page>
    <div class="mb-4 flex items-center gap-2">
        @foreach (['upload' => 'Upload', 'mapping' => 'Map Columns', 'duplicates' => 'Resolve Duplicates', 'summary' => 'Summary'] as $key => $label)
            <x-filament::badge :color="$step === $key ? 'primary' : 'gray'">{{ $label }}</x-filament::badge>
            @if (! $loop->last) <span class="text-gray-300 dark:text-gray-600">&rarr;</span> @endif
        @endforeach
    </div>

    @if ($uploadError)
        <div class="mb-4 rounded-lg bg-red-50 p-3 text-sm text-red-700 dark:bg-red-500/10 dark:text-red-400">
            {{ $uploadError }}
        </div>
    @endif

    {{-- Step 1: Upload --}}
    @if ($step === 'upload')
        <x-filament::section heading="Upload a spreadsheet">
            <p class="mb-4 text-sm text-gray-500 dark:text-gray-400">
                Only .xlsx files are supported. The first row must contain column headers.
            </p>
            <input type="file" wire:model="file" accept=".xlsx" class="{{ $inputClasses }}" />
            <div wire:loading wire:target="file" class="mt-2 text-sm text-gray-500">Uploading…</div>
            <div class="mt-4">
                <x-filament::button wire:click="processUpload" wire:loading.attr="disabled" wire:target="processUpload">
                    Continue
                </x-filament::button>
            </div>
        </x-filament::section>
    @endif

    {{-- Step 2: Column mapping --}}
    @if ($step === 'mapping')
        <x-filament::section heading="Map spreadsheet columns to Prospect fields">
            <p class="mb-4 text-sm text-gray-500 dark:text-gray-400">
                {{ count($rows) }} data row(s) detected. Each row below shows the original spreadsheet column on the left (read-only) and where it will be imported on the right. Columns are pre-mapped where the header was recognized — review before continuing.
            </p>

            <div class="mb-4 flex items-center gap-2 rounded-lg p-3 text-sm {{ $this->isCompanyNameMapped() ? 'bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-400' : 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-400' }}">
                <x-filament::icon :icon="$this->isCompanyNameMapped() ? 'heroicon-o-check-circle' : 'heroicon-o-exclamation-triangle'" class="h-5 w-5 shrink-0" />
                @if ($this->isCompanyNameMapped())
                    Company Name is mapped — it's the only required field.
                @else
                    Company Name is not yet mapped. Map one spreadsheet column to it before continuing.
                @endif
            </div>

            @if (count($this->duplicateMappingWarnings()))
                <div class="mb-4 flex items-start gap-2 rounded-lg bg-amber-50 p-3 text-sm text-amber-700 dark:bg-amber-500/10 dark:text-amber-400">
                    <x-filament::icon icon="heroicon-o-exclamation-triangle" class="mt-0.5 h-5 w-5 shrink-0" />
                    <span>More than one column is mapped to the same field, so only the last matching column's value will be kept for each row: <strong>{{ implode(', ', $this->duplicateMappingWarnings()) }}</strong>. Pick distinct fields below to avoid losing data.</span>
                </div>
            @endif

            <div class="space-y-2">
                @foreach ($headers as $header)
                    <div class="grid items-center gap-3 rounded-lg border border-gray-200 p-3 dark:border-white/10 sm:grid-cols-[1fr_auto_1fr]">
                        {{-- Source: the original spreadsheet column, read-only --}}
                        <div class="min-w-0">
                            <div class="text-xs font-semibold uppercase tracking-wide text-gray-400">Spreadsheet column</div>
                            <div class="truncate font-mono text-sm font-medium text-gray-950 dark:text-white" title="{{ $header }}">{{ $header }}</div>
                            <div class="truncate text-xs text-gray-400" title="{{ $rows[0][$header] ?? '' }}">e.g. "{{ $rows[0][$header] ?? '—' }}"</div>
                            @unless (in_array($header, $autoMatchedHeaders, true))
                                <x-filament::badge color="gray" size="sm" class="mt-1">Not auto-matched — review destination</x-filament::badge>
                            @endunless
                        </div>

                        <div class="hidden justify-center text-gray-300 dark:text-gray-600 sm:flex">
                            <x-filament::icon icon="heroicon-o-arrow-right" class="h-5 w-5" />
                        </div>

                        {{-- Destination: the Prospect field this column will import into --}}
                        <div class="min-w-0">
                            <div class="text-xs font-semibold uppercase tracking-wide text-gray-400">Import into</div>
                            <select wire:model="mapping.{{ $header }}" class="{{ $inputClasses }}">
                                @foreach (\App\Filament\Pages\ImportProspects::TARGET_OPTIONS as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            @if (($mapping[$header] ?? null) === 'company_name')
                                <x-filament::badge color="success" size="sm" class="mt-1">Required field</x-filament::badge>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="mt-4 flex gap-2">
                <x-filament::button color="gray" wire:click="backToUpload">Back</x-filament::button>
                <x-filament::button wire:click="processMapping" wire:loading.attr="disabled" wire:target="processMapping">
                    Process Import
                </x-filament::button>
            </div>
        </x-filament::section>
    @endif

    {{-- Step 3: Duplicate resolution --}}
    @if ($step === 'duplicates')
        <x-filament::section heading="Resolve likely duplicates">
            <p class="mb-4 text-sm text-gray-500 dark:text-gray-400">
                {{ count($pendingDuplicates) }} row(s) matched an existing Prospect by company name plus phone or email. Choose what to do with each before finishing.
            </p>

            <div class="mb-4 flex flex-wrap items-center gap-2 rounded-lg bg-gray-50 p-3 dark:bg-white/5">
                <span class="text-sm text-gray-600 dark:text-gray-300">Apply to all unresolved rows:</span>
                <select wire:model="bulkResolution" class="{{ $inputClasses }} w-auto">
                    <option value="">Choose…</option>
                    <option value="update">Update existing record</option>
                    <option value="new">Add as new record anyway</option>
                </select>
                <x-filament::button size="sm" color="gray" wire:click="applyBulkResolution">Apply</x-filament::button>
            </div>

            <div class="space-y-4">
                @foreach ($pendingDuplicates as $index => $duplicate)
                    <div class="rounded-lg border border-gray-200 p-4 dark:border-white/10">
                        <div class="mb-3 text-xs font-medium uppercase tracking-wide text-gray-400">Spreadsheet row {{ $duplicate['rowNumber'] }}</div>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <div class="mb-1 text-xs font-semibold uppercase text-gray-400">Existing record</div>
                                <div class="text-sm text-gray-700 dark:text-gray-300">
                                    <div><strong>{{ $duplicate['existingSnapshot']['company_name'] }}</strong></div>
                                    <div>{{ $duplicate['existingSnapshot']['contact_person'] ?: '—' }}</div>
                                    <div>{{ $duplicate['existingSnapshot']['phone_primary'] ?: '—' }} · {{ $duplicate['existingSnapshot']['email'] ?: '—' }}</div>
                                    <div class="text-xs text-gray-400">Assigned: {{ $duplicate['existingSnapshot']['assigned_to'] ?: '—' }}</div>
                                </div>
                            </div>
                            <div>
                                <div class="mb-1 text-xs font-semibold uppercase text-gray-400">Incoming row</div>
                                <div class="text-sm text-gray-700 dark:text-gray-300">
                                    <div><strong>{{ $duplicate['incoming']['company_name'] }}</strong></div>
                                    <div>{{ $duplicate['incoming']['contact_person'] ?? '—' }}</div>
                                    <div>{{ $duplicate['incoming']['phone_primary'] ?? '—' }} · {{ $duplicate['incoming']['email'] ?? '—' }}</div>
                                </div>
                            </div>
                        </div>
                        <div class="mt-3 flex gap-4 text-sm">
                            <label class="flex items-center gap-2">
                                <input type="radio" wire:model.live="duplicateResolutions.{{ $index }}" value="update" />
                                Update the existing record with this data
                            </label>
                            <label class="flex items-center gap-2">
                                <input type="radio" wire:model.live="duplicateResolutions.{{ $index }}" value="new" />
                                Add as a new record anyway
                            </label>
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="mt-4 flex items-center gap-2">
                <x-filament::button color="gray" wire:click="backToMapping">Back to Mapping</x-filament::button>
                <x-filament::button
                    wire:click="completeImport"
                    wire:loading.attr="disabled"
                    wire:target="completeImport"
                    :disabled="! $this->allDuplicatesResolved()"
                >
                    Finish Import
                </x-filament::button>
                @unless ($this->allDuplicatesResolved())
                    <span class="ms-2 text-xs text-gray-400">Resolve every row above to continue.</span>
                @endunless
            </div>
        </x-filament::section>
    @endif

    {{-- Step 4: Summary --}}
    @if ($step === 'summary')
        <x-filament::section heading="Import complete">
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                <div>
                    <div class="font-mono text-2xl font-semibold text-gray-950 dark:text-white">{{ $summary['total'] }}</div>
                    <div class="text-xs uppercase text-gray-400">Rows Processed</div>
                </div>
                <div>
                    <div class="font-mono text-2xl font-semibold text-gray-950 dark:text-white">{{ $summary['imported'] + $summary['updated'] + $summary['addedDespiteDuplicate'] }}</div>
                    <div class="text-xs uppercase text-gray-400">Imported / Updated</div>
                </div>
                <div>
                    <div class="font-mono text-2xl font-semibold text-brand-coral">{{ count($summary['failed']) }}</div>
                    <div class="text-xs uppercase text-gray-400">Failed</div>
                </div>
                <div>
                    <div class="font-mono text-2xl font-semibold text-gray-950 dark:text-white">{{ $summary['updated'] + $summary['addedDespiteDuplicate'] }}</div>
                    <div class="text-xs uppercase text-gray-400">Duplicates Handled</div>
                </div>
            </div>

            <dl class="mt-4 grid gap-1 text-sm text-gray-600 dark:text-gray-300">
                <div>New records created: <strong>{{ $summary['imported'] }}</strong></div>
                <div>Existing records updated: <strong>{{ $summary['updated'] }}</strong></div>
                <div>Added as new despite matching an existing record: <strong>{{ $summary['addedDespiteDuplicate'] }}</strong></div>
            </dl>

            @if (count($summary['failed']))
                <div class="mt-4">
                    <div class="mb-1 text-sm font-semibold text-gray-950 dark:text-white">Failed rows</div>
                    <ul class="list-inside list-disc text-sm text-brand-coral">
                        @foreach ($summary['failed'] as $failure)
                            <li>Row {{ $failure['row'] }}: {{ $failure['reason'] }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if (count($summary['warnings']))
                <div class="mt-4">
                    <div class="mb-1 text-sm font-semibold text-gray-950 dark:text-white">Warnings</div>
                    <ul class="list-inside list-disc text-sm text-brand-gold">
                        @foreach ($summary['warnings'] as $warning)
                            @foreach ($warning['messages'] as $message)
                                <li>Row {{ $warning['row'] }}: {{ $message }}</li>
                            @endforeach
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="mt-6 flex gap-2">
                <x-filament::button wire:click="startOver" color="gray">Import Another File</x-filament::button>
                <x-filament::button tag="a" href="{{ \App\Filament\Resources\ProspectResource::getUrl() }}">
                    Go to Database
                </x-filament::button>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
