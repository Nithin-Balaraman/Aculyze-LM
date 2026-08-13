<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Stale-record thresholds
    |--------------------------------------------------------------------------
    |
    | Number of days a Lead/Proposal can remain in the same active stage
    | before it is flagged as stale on dashboards. See AGENTS.md sections
    | 23 and 27.
    |
    */

    'lead_stale_after_days' => env('ACULYZE_LEAD_STALE_DAYS', 30),

    'proposal_stale_after_days' => env('ACULYZE_PROPOSAL_STALE_DAYS', 20),

    /*
    |--------------------------------------------------------------------------
    | Proposal Hold behavior (UNRESOLVED BUSINESS QUESTION)
    |--------------------------------------------------------------------------
    |
    | It is not yet confirmed whether a Proposal on Hold should continue to
    | be eligible for stale alerts. Default: Hold is treated as an active,
    | non-terminal outcome, so Hold proposals DO continue stale-timing and
    | can appear in stale-alert lists. Flip this to true once the business
    | confirms Hold should be excluded like Won/Lost. See AGENTS.md section
    | 61, Question 6.
    |
    */

    'hold_is_terminal_for_staleness' => env('ACULYZE_HOLD_IS_TERMINAL', false),

    /*
    |--------------------------------------------------------------------------
    | Employee export request validity
    |--------------------------------------------------------------------------
    |
    | Number of days an Approved employee export request stays downloadable
    | before it expires. Import Access + Export Approval batch, Pre-Answered
    | Question 1.
    |
    */

    'export_request_validity_days' => env('ACULYZE_EXPORT_REQUEST_VALIDITY_DAYS', 7),

];
