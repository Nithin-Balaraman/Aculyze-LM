<?php

namespace App\Support\Exports;

use App\Enums\ExportableResource;
use App\Enums\ExportRequestStatus;
use App\Models\ExportRequest;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;

/**
 * Import Access + Export Approval batch, Section 2.5/2.11: builds the two
 * header actions every exportable resource's List page needs — the
 * unchanged admin-immediate download, and the new employee Request Export
 * flow. Both are backed by the exact same ResourceExporter (see
 * ExportableResource::exporter()), so criteria options and CSV formatting
 * can never drift between the two paths.
 *
 * Visibility hides the "wrong" action per role, but authorization is
 * re-checked inside each action() closure too — a hidden button is never
 * the only thing standing between an employee and an immediate download.
 */
class ExportActions
{
    public static function immediate(ExportableResource $resource): Action
    {
        $exporter = $resource->exporter();

        return Action::make('exportCsv')
            ->label('Export CSV')
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->visible(fn () => auth()->user()?->isAdmin() ?? false)
            ->form($exporter->criteriaFormSchema())
            ->action(function (array $data) use ($exporter) {
                if (! (auth()->user()?->isAdmin() ?? false)) {
                    throw new Halt;
                }

                return $exporter->stream(auth()->user(), $exporter->normalizeCriteria($data));
            });
    }

    public static function request(ExportableResource $resource): Action
    {
        $exporter = $resource->exporter();

        return Action::make('requestExport')
            ->label('Request Export')
            ->icon('heroicon-o-clock')
            ->color('gray')
            ->visible(fn () => ! (auth()->user()?->isAdmin() ?? true))
            ->form($exporter->criteriaFormSchema())
            ->action(function (array $data) use ($resource, $exporter) {
                $user = auth()->user();

                if (! $user || $user->isAdmin()) {
                    throw new Halt;
                }

                $filters = $exporter->normalizeCriteria($data);
                $existing = ExportRequest::findEquivalentPending($user, $resource, $filters);

                if ($existing) {
                    self::notifyDuplicatePending();

                    return;
                }

                ExportRequest::create([
                    'user_id' => $user->id,
                    'resource' => $resource,
                    'filters' => $filters,
                    'status' => ExportRequestStatus::Pending,
                ]);

                Notification::make()
                    ->title('Export requested')
                    ->body('An admin will review your request. Check My Export Requests for updates.')
                    ->success()
                    ->send();
            });
    }

    /**
     * Only a Pending duplicate is ever blocked (see
     * ExportRequest::findEquivalentPending()) — an existing Approved
     * request is never a reason to refuse a new one.
     */
    private static function notifyDuplicatePending(): void
    {
        Notification::make()
            ->title('You already have a pending request for this export.')
            ->body('Wait for an admin to review it, or check My Export Requests.')
            ->warning()
            ->send();
    }
}
