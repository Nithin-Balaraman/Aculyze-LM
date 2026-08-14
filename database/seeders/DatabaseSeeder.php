<?php

namespace Database\Seeders;

use App\Enums\CallOutcome;
use App\Enums\LeadStage;
use App\Enums\LeadTemperature;
use App\Enums\ProposalStage;
use App\Enums\UserRole;
use App\Models\CallRecord;
use App\Models\Lead;
use App\Models\Proposal;
use App\Models\Prospect;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Date;

/**
 * Development-only sample data (AGENTS.md section 52). NOT real production
 * data or real passwords — see README for the seeded login credentials.
 *
 * Call Records are created through normal Eloquent create() calls so that
 * App\Observers\CallRecordObserver / App\Services\CallRoutingService run
 * exactly as they would in the real app, exercising the same routing logic
 * that the automated tests check.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $saji = User::create([
            'name' => 'Saji',
            'email' => 'saji@aculyze.test',
            'password' => 'password',
            'role' => UserRole::Admin,
        ]);

        $nithin = User::create([
            'name' => 'Nithin',
            'email' => 'nithin@aculyze.test',
            'password' => 'password',
            'role' => UserRole::Employee,
        ]);

        $kural = User::create([
            'name' => 'Kural',
            'email' => 'kural@aculyze.test',
            'password' => 'password',
            'role' => UserRole::Employee,
        ]);

        $ilaya = User::create([
            'name' => 'Ilaya Bharathi',
            'email' => 'ilaya@aculyze.test',
            'password' => 'password',
            'role' => UserRole::Employee,
        ]);

        // --- 1. Plain prospect with a couple of "still working it" calls,
        // routed automatically to the Follow-Ups panel. ---
        $chennaiPrecision = $this->prospect('Chennai Precision Engineering Works', $nithin, [
            'contact_person' => 'R. Suresh Kumar',
            'designation' => 'Plant Manager',
            'telephone' => '+91 98400 11223',
            'email' => 'suresh@chennaiprecision.example',
            'industry' => 'Precision Engineering',
            'city' => 'Coimbatore',
            'source' => 'Trade Directory',
        ]);
        $this->logCall($chennaiPrecision, $nithin, CallOutcome::NoAnswer, Date::now()->subDays(6));
        $this->logCall($chennaiPrecision, $nithin, CallOutcome::CallbackRequested, Date::now()->subDays(2), [
            'callback_required' => true,
            'callback_at' => Date::now()->addDays(2),
        ]);

        // --- 2. Appointment Set -> auto-creates an Appointment. ---
        $coimbatoreTextiles = $this->prospect('Coimbatore Textile Mills', $kural, [
            'contact_person' => 'Meena Rangaswamy',
            'designation' => 'Operations Head',
            'telephone' => '+91 98430 55667',
            'industry' => 'Textiles',
            'city' => 'Coimbatore',
            'source' => 'Referral',
        ]);
        $this->logCall($coimbatoreTextiles, $kural, CallOutcome::AppointmentSet, Date::now()->subDays(3));

        // --- 3. Requirement Identified -> auto-creates Appointment + Lead.
        // Advanced further and marked Hot. ---
        $novaIndustrial = $this->prospect('Nova Industrial Systems', $ilaya, [
            'contact_person' => 'Arvind Menon',
            'designation' => 'Director',
            'telephone' => '+91 90000 12121',
            'email' => 'arvind@novaindustrial.example',
            'industry' => 'Industrial Automation',
            'city' => 'Coimbatore',
            'source' => 'Website Enquiry',
        ]);
        $novaCall = $this->logCall($novaIndustrial, $ilaya, CallOutcome::RequirementIdentified, Date::now()->subDays(10), [
            'notes' => 'Interested in ERP + BI rollout across two plants.',
        ]);
        $hotLead = $novaCall->lead;
        $hotLead->update(['stage' => LeadStage::DemoScheduledOrDone, 'temperature' => LeadTemperature::Hot]);

        // --- 4. Another Requirement Identified -> Warm Lead, validated and
        // taken all the way to an in-progress Proposal. ---
        $metroAuto = $this->prospect('Metro Auto Components', $nithin, [
            'contact_person' => 'Divya Prakash',
            'designation' => 'Purchase Manager',
            'telephone' => '+91 98940 33445',
            'industry' => 'Automotive Components',
            'city' => 'Coimbatore',
            'source' => 'Cold Outreach',
        ]);
        $metroCall = $this->logCall($metroAuto, $nithin, CallOutcome::RequirementIdentified, Date::now()->subDays(15), [
            'notes' => 'Needs Microsoft 365 migration for 80 users.',
        ]);
        $warmLead = $metroCall->lead;
        $warmLead->update(['stage' => LeadStage::Validated, 'temperature' => LeadTemperature::Warm]);

        Proposal::create([
            'lead_id' => $warmLead->id,
            'prospect_id' => $metroAuto->id,
            'assigned_to' => $nithin->id,
            'created_by' => $nithin->id,
            'stage' => ProposalStage::Sent,
            'value' => 450000,
            'sent_at' => Date::now()->subDays(5),
            'notes' => 'M365 E3 licensing + migration services for 80 users.',
        ]);

        // --- 5. Requirement Identified -> Cold Lead. ---
        $globalFasteners = $this->prospect('Global Fasteners Pvt Ltd', $kural, [
            'contact_person' => 'K. Rajendran',
            'designation' => 'Owner',
            'telephone' => '+91 98650 77889',
            'industry' => 'Industrial Fasteners',
            'city' => 'Coimbatore',
            'source' => 'Exhibition',
        ]);
        $globalCall = $this->logCall($globalFasteners, $kural, CallOutcome::RequirementIdentified, Date::now()->subDays(20), [
            'notes' => 'Mentioned a possible cybersecurity audit next year, nothing urgent now.',
        ]);
        $globalCall->lead->update(['temperature' => LeadTemperature::Cold]);

        // --- 6. Stale Lead: stuck in Requirement Collection for 45 days. ---
        $sunrisePlastics = $this->prospect('Sunrise Plastics', $ilaya, [
            'contact_person' => 'Geetha Subramaniam',
            'designation' => 'Admin Manager',
            'telephone' => '+91 90420 66778',
            'industry' => 'Plastics Manufacturing',
            'city' => 'Tiruppur',
            'source' => 'Trade Directory',
        ]);
        $sunriseCall = $this->logCall($sunrisePlastics, $ilaya, CallOutcome::RequirementIdentified, Date::now()->subDays(45), [
            'notes' => 'Wants a quote for a basic cloud backup solution.',
        ]);
        $staleLead = $sunriseCall->lead;
        Lead::withoutEvents(fn () => $staleLead->forceFill(['stage_changed_at' => Date::now()->subDays(45)])->save());

        // --- 7. Stale Proposal: sent 30 days ago, no movement since. ---
        $apexEngineering = $this->prospect('Apex Engineering Solutions', $nithin, [
            'contact_person' => 'Vignesh Iyer',
            'designation' => 'General Manager',
            'telephone' => '+91 90470 22334',
            'industry' => 'Precision Engineering',
            'city' => 'Coimbatore',
            'source' => 'Referral',
        ]);
        $apexCall = $this->logCall($apexEngineering, $nithin, CallOutcome::RequirementIdentified, Date::now()->subDays(35), [
            'notes' => 'Wants a full workflow automation proposal for their quality inspection process.',
        ]);
        $apexLead = $apexCall->lead;
        $apexLead->update(['stage' => LeadStage::Validated, 'temperature' => LeadTemperature::Hot]);

        $staleProposal = Proposal::create([
            'lead_id' => $apexLead->id,
            'prospect_id' => $apexEngineering->id,
            'assigned_to' => $nithin->id,
            'created_by' => $nithin->id,
            'stage' => ProposalStage::Sent,
            'value' => 620000,
            'sent_at' => Date::now()->subDays(30),
            'notes' => 'Workflow automation for quality inspection — awaiting customer response.',
        ]);
        Proposal::withoutEvents(fn () => $staleProposal->forceFill(['stage_changed_at' => Date::now()->subDays(30)])->save());

        // --- 8. A "no requirement yet" call for variety — routes nowhere
        // (no Follow-Up/Appointment/Lead); it only shows up in the Call
        // Records "History" tab. See CallOutcome::routesToAppointment(). ---
        $millenniumSteel = $this->prospect('Millennium Steel Traders', $kural, [
            'contact_person' => 'Bala Murugan',
            'designation' => 'Proprietor',
            'telephone' => '+91 90930 44556',
            'industry' => 'Steel Trading',
            'city' => 'Coimbatore',
            'source' => 'Cold Outreach',
        ]);
        $this->logCall($millenniumSteel, $kural, CallOutcome::FutureOpportunity, Date::now()->subDays(1), [
            'notes' => 'No budget this year, revisit after their next financial year starts.',
        ]);

        $this->command?->info('Seeded 4 users and 8 prospects with a full spread of workflow states.');
        $this->command?->info('Admin login: saji@aculyze.test / password');
        $this->command?->info('Employee logins: nithin@aculyze.test, kural@aculyze.test, ilaya@aculyze.test / password');
    }

    private function prospect(string $companyName, User $owner, array $attributes = []): Prospect
    {
        return Prospect::create(array_merge([
            'company_name' => $companyName,
            'assigned_to' => $owner->id,
            'created_by' => $owner->id,
        ], $attributes));
    }

    private function logCall(Prospect $prospect, User $caller, CallOutcome $outcome, $calledAt, array $attributes = []): CallRecord
    {
        return CallRecord::create(array_merge([
            'prospect_id' => $prospect->id,
            'user_id' => $caller->id,
            'called_at' => $calledAt,
            'outcome' => $outcome,
        ], $attributes));
    }
}
