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

    /*
    |--------------------------------------------------------------------------
    | Organization legal identity / letterhead fallback (Phase 4A-3.2)
    |--------------------------------------------------------------------------
    |
    | Locked Decision 2: the final Proposal PDF's letterhead is read from
    | `organizations.settings['identity']` first; this config array is only
    | the FALLBACK used when an organization has not configured its own
    | identity there (there is no identity-management UI in 4A-3 — see
    | App\Support\Proposal\OrganizationIdentityResolver). legal_name,
    | registered_address and gstin are mandatory before any final PDF can be
    | generated (from either source); phone/email/website/logo_path are
    | optional. Nothing here is guessed or defaulted to a fabricated value —
    | every key below is null unless a real environment value is set, and
    | generation fails clearly rather than inventing an identity.
    |
    */

    'organization_identity' => [
        'legal_name' => env('ACULYZE_ORG_LEGAL_NAME'),
        'registered_address' => env('ACULYZE_ORG_REGISTERED_ADDRESS'),
        'gstin' => env('ACULYZE_ORG_GSTIN'),
        'phone' => env('ACULYZE_ORG_PHONE'),
        'email' => env('ACULYZE_ORG_EMAIL'),
        'website' => env('ACULYZE_ORG_WEBSITE'),
        'logo_path' => env('ACULYZE_ORG_LOGO_PATH'),
    ],

];
