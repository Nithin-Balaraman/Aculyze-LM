@php
    $labels = [
        'prospects' => 'Database records',
        'callRecords' => 'Call Records',
        'followUps' => 'Follow-Ups',
        'appointments' => 'Appointments',
        'demos' => 'Demos',
        'leads' => 'Leads',
        'proposals' => 'Proposals',
        'directReports' => 'Direct Reports',
        // Permanent commercial evidence, not cleanup — a replacement can
        // never absorb these (see App\Services\EmployeeDeletionService).
        'versionsSubmitted' => 'Versions they submitted',
        'versionsApproved' => 'Versions they approved',
        'versionsReturned' => 'Versions they returned',
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
