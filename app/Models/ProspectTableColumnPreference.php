<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per user: which toggleable columns they have visible on the
 * Prospects table. See this table's own migration docblock for why this
 * exists (DB-backed, per-user, survives logout/device changes — unlike
 * Filament's own built-in session-based toggle persistence) and why it's
 * scoped to exactly this one table rather than a generic preference store.
 */
class ProspectTableColumnPreference extends Model
{
    protected $fillable = [
        'user_id',
        'toggled_columns',
    ];

    protected function casts(): array
    {
        return [
            'toggled_columns' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
