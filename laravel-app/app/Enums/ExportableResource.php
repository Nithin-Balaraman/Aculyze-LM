<?php

namespace App\Enums;

use App\Support\Exports\AppointmentExporter;
use App\Support\Exports\FollowUpExporter;
use App\Support\Exports\LeadExporter;
use App\Support\Exports\ProposalExporter;
use App\Support\Exports\ResourceExporter;
use Filament\Support\Contracts\HasLabel;

/**
 * The controlled, whitelisted set of resources an ExportRequest can target
 * — stored on the request instead of a raw/untrusted class name from
 * request input (Import Access + Export Approval batch, Section 2.2).
 */
enum ExportableResource: string implements HasLabel
{
    case Lead = 'lead';
    case Appointment = 'appointment';
    case Proposal = 'proposal';
    case FollowUp = 'follow_up';

    public function getLabel(): string
    {
        return match ($this) {
            self::Lead => 'Leads',
            self::Appointment => 'Appointments',
            self::Proposal => 'Proposals',
            self::FollowUp => 'Follow-Ups',
        };
    }

    public function exporter(): ResourceExporter
    {
        return app(match ($this) {
            self::Lead => LeadExporter::class,
            self::Appointment => AppointmentExporter::class,
            self::Proposal => ProposalExporter::class,
            self::FollowUp => FollowUpExporter::class,
        });
    }
}
