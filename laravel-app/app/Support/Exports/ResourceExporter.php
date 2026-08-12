<?php

namespace App\Support\Exports;

use App\Models\User;
use App\Support\CsvExporter;
use Filament\Forms\Components\Component;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * One reusable implementation per exportable resource (Lead/Appointment/
 * Proposal/FollowUp), used identically by both the admin-immediate export
 * action and the employee approved-request download (Import Access +
 * Export Approval batch, Section 2.23) — the query scoping, filter
 * criteria, CSV columns, and filename all live in exactly one place.
 *
 * scopedQuery() must always be the resource's normal visibleTo($user)
 * query: unrestricted for an admin, restricted to that user's own records
 * for an employee. Criteria are then applied on top of that, never
 * instead of it — this is what guarantees an employee's export can never
 * contain another employee's rows, however the request was built.
 */
abstract class ResourceExporter
{
    /**
     * @return Builder<Model>
     */
    abstract public function scopedQuery(User $user): Builder;

    /**
     * @param  Builder<Model>  $query
     * @param  array<string, mixed>  $criteria
     * @return Builder<Model>
     */
    abstract public function applyCriteria(Builder $query, array $criteria): Builder;

    /**
     * @return array<int, string>
     */
    abstract public function headers(): array;

    /**
     * @return array<int, mixed>
     */
    abstract public function mapRow(Model $record): array;

    /**
     * @param  array<string, mixed>  $criteria
     */
    abstract public function filename(array $criteria): string;

    /**
     * Human-readable summary of the criteria, shown to the admin reviewing
     * a request and to the employee on their request history — e.g.
     * "Stage: Demo Scheduled" or "All stages".
     *
     * @param  array<string, mixed>  $criteria
     */
    abstract public function summarizeCriteria(array $criteria): string;

    /**
     * The Filament form fields used to collect criteria, reused by both the
     * admin-immediate export action's form and the employee Request Export
     * action's form, so both always offer the exact same filter options.
     *
     * @return array<int, Component>
     */
    abstract public function criteriaFormSchema(): array;

    /**
     * Strips form data down to only the whitelisted keys this resource
     * accepts, so nothing arbitrary from request input ever reaches
     * storage or a later query. The result is also what's compared for
     * duplicate-request detection, so key order doesn't matter as long as
     * it's produced consistently here.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    abstract public function normalizeCriteria(array $data): array;

    /**
     * @param  array<string, mixed>  $criteria
     */
    final public function stream(User $user, array $criteria): StreamedResponse
    {
        $query = $this->applyCriteria($this->scopedQuery($user), $criteria);

        $rows = $query->get()->map(fn (Model $record) => $this->mapRow($record));

        return CsvExporter::stream($this->filename($criteria), $this->headers(), $rows);
    }
}
