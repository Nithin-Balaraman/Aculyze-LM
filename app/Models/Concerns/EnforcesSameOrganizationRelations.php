<?php

namespace App\Models\Concerns;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Blocks cross-tenant relationship injection (Phase 1 plan): even though a
 * record's own organization_id is resolved deterministically (see
 * BelongsToOrganization), a crafted/direct request could otherwise still
 * create an otherwise-correct Org-A record whose foreign key (prospect_id,
 * lead_id, user_id, manager_id, etc.) points at an Org-B row — a data leak/
 * corruption path through the relationship itself, not through the row's
 * own organization_id column.
 *
 * Each model using this trait declares organizationScopedRelations(): a
 * column => [table, human label] map of every foreign key that must point
 * within the same organization. Checked on every save (create and update)
 * for both directions of the mismatch.
 *
 * Declared AFTER BelongsToOrganization in every model's `use` statement —
 * Eloquent fires same-event listeners in registration order, so
 * BelongsToOrganization's own saving listener (which resolves
 * organization_id for a new record) always runs first, guaranteeing
 * $model->organization_id is already correct by the time this check reads
 * it.
 */
trait EnforcesSameOrganizationRelations
{
    public static function bootEnforcesSameOrganizationRelations(): void
    {
        static::saving(function ($model): void {
            foreach ($model->organizationScopedRelations() as $column => [$table, $label]) {
                $value = $model->{$column};

                if ($value === null) {
                    continue;
                }

                $relatedOrganizationId = DB::table($table)->where('id', $value)->value('organization_id');

                if ($relatedOrganizationId !== null && $relatedOrganizationId !== $model->organization_id) {
                    throw new RuntimeException(
                        static::class." cannot reference {$label} #{$value}: it belongs to a different organization."
                    );
                }
            }
        });
    }

    /**
     * @return array<string, array{0: string, 1: string}> foreign-key column => [referenced table, human label for the error message]
     */
    protected function organizationScopedRelations(): array
    {
        return [];
    }
}
