<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * The stored lifecycle states of an ExportRequest. "Expired" is
 * deliberately not a stored state here — it's a point-in-time fact about
 * an Approved request (status stays Approved, expires_at is in the past),
 * computed on demand via ExportRequest::isExpired()/effectiveStatusLabel()
 * rather than requiring a scheduled job to flip a column (this app has no
 * queue/scheduler dependency and this batch shouldn't introduce one).
 */
enum ExportRequestStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Denied = 'denied';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Approved => 'Approved',
            self::Denied => 'Denied',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Approved => 'success',
            self::Denied => 'danger',
        };
    }
}
