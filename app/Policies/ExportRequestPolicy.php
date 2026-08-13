<?php

namespace App\Policies;

use App\Models\ExportRequest;
use App\Models\User;

/**
 * Import Access + Export Approval batch, Section 2 — the Authorization
 * Matrix in full: any authenticated user may create their own export
 * requests and view their own list; only Admin may see another employee's
 * request or decide (approve/deny) one; only the original requester may
 * ever download the resulting CSV, and only once it is Approved and not
 * expired (see ExportRequest::isDownloadable()).
 */
class ExportRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, ExportRequest $exportRequest): bool
    {
        return $user->isAdmin() || $exportRequest->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Approve/Deny — Admin only, and only while the request is still
     * Pending (ExportRequest::approve()/deny() enforce the latter too).
     */
    public function decide(User $user, ExportRequest $exportRequest): bool
    {
        return $user->isAdmin();
    }

    /**
     * Only the requesting employee may ever download their own approved
     * export — not another employee's, and not an admin's convenience
     * (the Authorization Matrix marks admin download as "not required by
     * this feature," since admins already have immediate export).
     */
    public function download(User $user, ExportRequest $exportRequest): bool
    {
        return $exportRequest->user_id === $user->id && $exportRequest->isDownloadable();
    }
}
