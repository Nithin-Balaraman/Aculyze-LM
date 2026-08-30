<?php

namespace App\Models;

use App\Enums\ExportableResource;
use App\Enums\ExportRequestStatus;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\EnforcesSameOrganizationRelations;
use App\Models\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * Import Access + Export Approval batch, Section 2: a single, shared
 * record of one employee's request to export a CSV of Leads/Appointments/
 * Proposals/Follow-Ups. Doubles as the approval audit trail (Section
 * 2.24) — who requested what, when, who decided it, and when.
 *
 * The CSV itself is never generated or stored here (Section 2.8) — this
 * only ever holds the *criteria* needed to regenerate it, on demand, at
 * download time. See App\Support\Exports\ResourceExporter.
 */
class ExportRequest extends Model
{
    use BelongsToOrganization, EnforcesSameOrganizationRelations, HasFactory;

    protected $fillable = [
        'user_id',
        'resource',
        'filters',
        'status',
        'decided_by',
        'decided_at',
        'denial_reason',
        'expires_at',
        'downloaded_at',
    ];

    protected function casts(): array
    {
        return [
            'resource' => ExportableResource::class,
            'filters' => 'array',
            'status' => ExportRequestStatus::class,
            'decided_at' => 'datetime',
            'expires_at' => 'datetime',
            'downloaded_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new OrganizationScope);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /**
     * An Approved request whose validity window has passed. Deliberately
     * not a stored status (see ExportRequestStatus) — computed here so
     * every caller (download authorization, admin list, employee list)
     * agrees on the same instant-in-time answer without needing a
     * scheduled job to flip a column.
     */
    public function isExpired(): bool
    {
        return $this->status === ExportRequestStatus::Approved
            && $this->expires_at !== null
            && $this->expires_at->isPast();
    }

    /**
     * One admin approval authorizes exactly one successful CSV download
     * (One-Time Approved Export Download batch, Section 2.1) — downloaded_at
     * already existed on this model (previously written on download but
     * never actually consulted here), so reusing it as the consumed marker
     * needed no new schema.
     */
    public function isDownloadable(): bool
    {
        return $this->status === ExportRequestStatus::Approved
            && ! $this->isExpired()
            && $this->downloaded_at === null;
    }

    public function wasDownloaded(): bool
    {
        return $this->downloaded_at !== null;
    }

    public function effectiveStatusLabel(): string
    {
        if ($this->isExpired()) {
            return 'Expired';
        }

        if ($this->status === ExportRequestStatus::Approved && $this->wasDownloaded()) {
            return 'Downloaded';
        }

        return $this->status->getLabel();
    }

    public function effectiveStatusColor(): string
    {
        if ($this->isExpired()) {
            return 'gray';
        }

        if ($this->status === ExportRequestStatus::Approved && $this->wasDownloaded()) {
            return 'gray';
        }

        return $this->status->getColor();
    }

    /**
     * Atomically claims this request for its one permitted download. The
     * database itself — not the in-memory $this state — is the source of
     * truth for whether it's already been consumed: two nearly-simultaneous
     * requests both operating on their own (possibly stale) in-memory copy
     * will still only let one of these UPDATEs actually flip downloaded_at
     * from NULL, since MySQL locks the row for the duration of each UPDATE
     * and the second one's WHERE clause (whereNull('downloaded_at')) then
     * no longer matches. No explicit transaction is needed — a single
     * conditional UPDATE is already atomic at the row level.
     */
    public function claimForDownload(): bool
    {
        $now = now();

        $claimed = static::query()
            ->whereKey($this->id)
            ->where('status', ExportRequestStatus::Approved)
            ->where(fn (Builder $query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', $now))
            ->whereNull('downloaded_at')
            ->update(['downloaded_at' => $now]);

        if ($claimed === 1) {
            $this->forceFill(['downloaded_at' => $now]);
        }

        return $claimed === 1;
    }

    /**
     * @throws \LogicException if the request is not Pending
     */
    public function approve(User $admin): void
    {
        $this->assertPending();

        $this->forceFill([
            'status' => ExportRequestStatus::Approved,
            'decided_by' => $admin->id,
            'decided_at' => now(),
            'expires_at' => now()->addDays((int) config('aculyze.export_request_validity_days')),
        ])->save();
    }

    /**
     * @throws \LogicException if the request is not Pending
     */
    public function deny(User $admin, ?string $reason): void
    {
        $this->assertPending();

        $this->forceFill([
            'status' => ExportRequestStatus::Denied,
            'decided_by' => $admin->id,
            'decided_at' => now(),
            'denial_reason' => filled($reason) ? $reason : null,
        ])->save();
    }

    /**
     * Once a request has left Pending, it must never be silently
     * re-decided (Section 2.14: "prevent accidental duplicate decisions").
     */
    private function assertPending(): void
    {
        if ($this->status !== ExportRequestStatus::Pending) {
            throw new \LogicException('This export request has already been decided.');
        }
    }

    /**
     * Senior Managers see every export request in their organization;
     * Managers see their own + their direct reports'; Employees see only
     * their own.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return \App\Support\Authorization\HierarchyVisibility::scopeFor($query, $user, 'user_id');
    }

    /**
     * An existing Pending request for the exact same user/resource/filters
     * (Section 2.19). Only Pending requests are deduplicated — an Approved
     * request (even unexpired) never blocks a new one, since approved CSVs
     * are generated from live data at download time (Section 2.4 of the
     * "Employee Prospect Visibility, Export Request Deduplication, and
     * Follow-Up Lost Tab" batch): an employee may legitimately want a fresh
     * export after new records have been created since the last approval.
     * Denied and Expired requests never block a new one either, and were
     * never included here.
     *
     * Compared in PHP rather than as a raw JSON-column equality query,
     * since key ordering inside the stored JSON must never produce a false
     * "different" result — normalizeCriteria() on each Exporter already
     * guarantees a consistent key set, but comparing the decoded arrays
     * here is the simplest way to also be resilient to ordering.
     *
     * @param  array<string, mixed>  $filters
     */
    public static function findEquivalentPending(User $user, ExportableResource $resource, array $filters): ?self
    {
        return static::query()
            ->where('user_id', $user->id)
            ->where('resource', $resource)
            ->where('status', ExportRequestStatus::Pending)
            ->get()
            ->first(fn (self $request) => $request->filters === $filters);
    }

    /** Inherits organization_id from the requesting User, never a value later re-derived from whoever is currently logged in. */
    protected function inheritedOrganizationId(): ?int
    {
        if (! $this->user_id) {
            return null;
        }

        return DB::table('users')->where('id', $this->user_id)->value('organization_id');
    }

    /** @return array<string, array{0: string, 1: string}> */
    protected function organizationScopedRelations(): array
    {
        return [
            'user_id' => ['users', 'requesting User'],
            'decided_by' => ['users', 'deciding User'],
        ];
    }
}
