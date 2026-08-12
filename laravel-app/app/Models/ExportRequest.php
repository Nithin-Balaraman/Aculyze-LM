<?php

namespace App\Models;

use App\Enums\ExportableResource;
use App\Enums\ExportRequestStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
    use HasFactory;

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

    public function isDownloadable(): bool
    {
        return $this->status === ExportRequestStatus::Approved && ! $this->isExpired();
    }

    public function effectiveStatusLabel(): string
    {
        return $this->isExpired() ? 'Expired' : $this->status->getLabel();
    }

    public function effectiveStatusColor(): string
    {
        return $this->isExpired() ? 'gray' : $this->status->getColor();
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

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $user->isAdmin() ? $query : $query->where('user_id', $user->id);
    }

    /**
     * An existing Pending request, or an Approved-and-unexpired one, for
     * the exact same user/resource/filters (Section 2.19). Compared in PHP
     * rather than as a raw JSON-column equality query, since key ordering
     * inside the stored JSON must never produce a false "different"
     * result — normalizeCriteria() on each Exporter already guarantees a
     * consistent key set, but comparing the decoded arrays here is the
     * simplest way to also be resilient to ordering.
     *
     * @param  array<string, mixed>  $filters
     */
    public static function findEquivalentPendingOrApproved(User $user, ExportableResource $resource, array $filters): ?self
    {
        return static::query()
            ->where('user_id', $user->id)
            ->where('resource', $resource)
            ->where(fn (Builder $query) => $query
                ->where('status', ExportRequestStatus::Pending)
                ->orWhere(fn (Builder $q) => $q
                    ->where('status', ExportRequestStatus::Approved)
                    ->where(fn (Builder $q2) => $q2->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                )
            )
            ->get()
            ->first(fn (self $request) => $request->filters === $filters);
    }
}
