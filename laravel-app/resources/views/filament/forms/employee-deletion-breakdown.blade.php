@php
    $labels = [
        'prospects' => 'Database records',
        'callRecords' => 'Call Records',
        'followUps' => 'Follow-Ups',
        'appointments' => 'Appointments',
        'leads' => 'Leads',
        'proposals' => 'Proposals',
    ];
@endphp

<ul class="grid grid-cols-2 gap-x-4 gap-y-1 text-sm text-gray-700 dark:text-gray-300 sm:grid-cols-3">
    @foreach ($labels as $key => $label)
        <li class="flex items-baseline gap-1.5">
            <span class="font-mono font-semibold tabular-nums text-gray-950 dark:text-white">{{ $breakdown[$key] ?? 0 }}</span>
            <span>{{ $label }}</span>
        </li>
    @endforeach
</ul>
