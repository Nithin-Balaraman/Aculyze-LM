@php
    $fields = [
        'Contact Person' => $prospect?->contact_person,
        'Designation' => $prospect?->designation,
        'Telephone' => $prospect?->telephone,
        'Mobile' => $prospect?->mobile,
        'Email' => $prospect?->email,
        'Industry' => $prospect?->industry,
        'Assigned To' => $prospect?->assignedEmployee?->name,
    ];
@endphp

<dl class="grid grid-cols-2 gap-x-4 gap-y-3 text-sm sm:grid-cols-4">
    @foreach ($fields as $label => $value)
        <div class="min-w-0">
            <dt class="text-xs font-medium uppercase tracking-wide text-gray-400">{{ $label }}</dt>
            <dd class="break-words text-gray-950 dark:text-white">{{ $value ?: '—' }}</dd>
        </div>
    @endforeach
</dl>
