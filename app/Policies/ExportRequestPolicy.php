<?php

namespace App\Policies;

use App\Models\ExportRequest;
use App\Models\User;
use App\Support\Authorization\HierarchyVisibility;

/**
 * Import Access + Export Approval batch, Section 2 — the Authorization
 * Matrix in full: any authenticated user may create their own export
 * requests and view their own list. Manager may additionally see/decide
 * their direct reports' requests, and Senior Manager may see/decide any
 * (Phase 1 hierarchy — Master BA permission matrix row 56). Only the
 * original requester may ever download the resulting CSV, and only once it
 * is Approved and not expired (see ExportRequest::isDownloadable()) —
 * downloading is never delegated up the hierarchy.
 */
class ExportRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, ExportRequest $exportRequest): bool
    {
        return HierarchyVisibility::canAccess($user, $exportRequest, 'user_id');
    }

    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Approve/Deny — Manager (for their direct reports') and Senior Manager
     * (for anyone in the organization), and only while the request is still
     * Pending (ExportRequest::approve()/deny() enforce the latter too).
     */
    public function decide(User $user, ExportRequest $exportRequest): bool
    {
        return ($user->isManager() || $user->isSeniorManager())
            && HierarchyVisibility::canAccess($user, $exportRequest, 'user_id');
    }

    /**
     * Only the requesting employee may ever download their own approved
     * export — not another employee's, and not a Manager's/Senior
     * Manager's convenience (Senior Manager already has immediate export).
     */
    public function download(User $user, ExportRequest $exportRequest): bool
    {
        return $exportRequest->user_id === $user->id && $exportRequest->isDownloadable();
    }
}
