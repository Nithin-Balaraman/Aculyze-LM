<?php

namespace App\Observers;

use App\Models\CallRecord;
use App\Services\CallRoutingService;

class CallRecordObserver
{
    public function __construct(private readonly CallRoutingService $callRoutingService) {}

    /**
     * Route the call the moment it is created. Editing an existing Call
     * Record later never re-triggers routing (AGENTS.md section 16) — only
     * `created` is observed here, not `updated`.
     */
    public function created(CallRecord $callRecord): void
    {
        $this->callRoutingService->route($callRecord);
    }
}
