<?php

namespace App\Support;

use Filament\Tables\Actions\BulkAction;

/**
 * "Deselect all" as a real entry inside each resource's "Bulk actions"
 * dropdown, alongside Delete — rather than Filament's separate standalone
 * link in the selection-indicator bar, which visually dominated the
 * toolbar (UI fix). Built on BulkAction's own deselectRecordsAfterCompletion()
 * — the action itself is a no-op; the built-in "after completion" hook is
 * what actually clears the selection.
 *
 * Gated to admin, matching the exact condition every resource's real bulk
 * actions already use. This must stay in sync with that condition:
 * Table::isSelectionEnabled() (which decides whether row checkboxes render
 * at all) is true if ANY bulk action is visible, so an unrestricted
 * "Deselect all" here would silently turn checkboxes back on for a role
 * that shouldn't see them.
 */
class TableBulkActions
{
    public static function deselectAll(): BulkAction
    {
        return BulkAction::make('deselectAll')
            ->label('Deselect all')
            ->icon('heroicon-o-x-mark')
            ->color('gray')
            ->deselectRecordsAfterCompletion()
            ->action(fn () => null)
            ->visible(fn () => auth()->user()?->isAdmin() ?? false);
    }
}
