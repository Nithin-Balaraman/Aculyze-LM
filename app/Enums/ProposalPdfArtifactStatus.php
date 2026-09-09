<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Phase 4A-3.1: one generation attempt against a ProposalVersion (Master BA
 * Specification Phase 4A-3 addendum). Success and Failed are both permanent,
 * append-only rows — a Failed attempt is never deleted or overwritten, and a
 * Success row is never deleted once superseded (see ProposalPdfArtifact's
 * own `superseded_at`/`superseded_by_artifact_id`, metadata layered on top of
 * this status, never a status value of its own — mirrors
 * ProposalVersionLifecycle's own "supersession is metadata, not a lifecycle
 * value" precedent).
 */
enum ProposalPdfArtifactStatus: string implements HasColor, HasLabel
{
    case Success = 'success';
    case Failed = 'failed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Success => 'Success',
            self::Failed => 'Failed',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Success => 'success',
            self::Failed => 'danger',
        };
    }
}
