<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Phase 2: cross-organization guard for `origin_type`/`origin_id` — the
 * lineage pair recording which prior workflow activity (Follow-Up,
 * Appointment, Lead, Demo, or Proposal — never a raw class name, always
 * one of the stable aliases registered via Relation::enforceMorphMap() in
 * App\Providers\AppServiceProvider) caused this record to be created as
 * the next business action.
 *
 * Deliberately separate from App\Models\Concerns\
 * EnforcesSameOrganizationRelations: that trait's column => [table, label]
 * map assumes a fixed target table per column, which doesn't hold for a
 * polymorphic origin whose target table varies by `origin_type`. A
 * self-referencing `rescheduled_from_id` (a different concept entirely —
 * see each model's own docblock) still uses
 * EnforcesSameOrganizationRelations normally, since its target table never
 * varies.
 */
trait ValidatesOriginLineage
{
    public static function bootValidatesOriginLineage(): void
    {
        static::saving(function ($model): void {
            if ($model->origin_type === null || $model->origin_id === null) {
                return;
            }

            $originClass = Relation::getMorphedModel($model->origin_type);

            if ($originClass === null) {
                throw new RuntimeException(
                    static::class." has an unmapped origin_type '{$model->origin_type}' — every lineage ".
                    'origin must use a stable alias registered in Relation::enforceMorphMap().'
                );
            }

            $relatedOrganizationId = DB::table((new $originClass)->getTable())
                ->where('id', $model->origin_id)
                ->value('organization_id');

            if ($relatedOrganizationId !== null && $relatedOrganizationId !== $model->organization_id) {
                throw new RuntimeException(
                    static::class." cannot reference origin {$model->origin_type} #{$model->origin_id}: it belongs to a different organization."
                );
            }
        });
    }
}
