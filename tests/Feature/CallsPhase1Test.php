<?php

namespace Tests\Feature;

use App\Filament\Resources\CallRecordResource;
use App\Filament\Resources\CallRecordResource\Pages\CreateCallRecord;
use App\Filament\Resources\CallRecordResource\Pages\EditCallRecord;
use App\Filament\Resources\CallRecordResource\Pages\ListCallRecords;
use App\Filament\Resources\CallRecordResource\Pages\ViewCallRecord;
use App\Filament\Resources\ProspectResource;
use App\Filament\Resources\ProspectResource\Pages\CreateProspect;
use App\Filament\Resources\ProspectResource\Pages\EditProspect;
use App\Filament\Resources\ProspectResource\Pages\ListProspects;
use App\Filament\Resources\ProspectResource\Pages\ViewProspect;
use App\Models\CallRecord;
use App\Models\Prospect;
use App\Models\User;
use App\Support\Exports\ExportActions;
use Filament\Forms\Components\Section;
use Filament\Tables\Actions\ActionGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Calls Phase 1: Back button, bulk actions, button colors, form order.
 * Mirrors the exact mechanisms/tests already established for Prospects
 * (ProspectBackButtonTest, BulkSelectToggleTest's standalone-bulk-actions
 * coverage) rather than a second implementation for Calls.
 */
class CallsPhase1Test extends TestCase
{
    use RefreshDatabase;

    private function actingAdmin(): User
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        return $admin;
    }

    // --- 1. Back button --------------------------------------------------

    public function test_view_page_back_button_falls_back_to_the_calls_list(): void
    {
        $this->actingAdmin();
        $prospect = Prospect::factory()->create();
        $call = CallRecord::create([
            'prospect_id' => $prospect->id,
            'user_id' => $prospect->assigned_to,
            'called_at' => now(),
            'outcome' => 'no_answer',
        ]);
        $listUrl = CallRecordResource::getUrl('index');

        Livewire::test(ViewCallRecord::class, ['record' => $call->getRouteKey()])
            ->assertActionHasUrl('back', $listUrl)
            ->assertActionHasColor('back', ProspectResource::BACK_BUTTON_COLOR);
    }

    public function test_edit_page_back_button_falls_back_to_the_calls_list(): void
    {
        $this->actingAdmin();
        $prospect = Prospect::factory()->create();
        $call = CallRecord::create([
            'prospect_id' => $prospect->id,
            'user_id' => $prospect->assigned_to,
            'called_at' => now(),
            'outcome' => 'no_answer',
        ]);
        $listUrl = CallRecordResource::getUrl('index');

        Livewire::test(EditCallRecord::class, ['record' => $call->getRouteKey()])
            ->assertActionHasUrl('back', $listUrl)
            ->assertActionHasColor('back', ProspectResource::BACK_BUTTON_COLOR);
    }

    public function test_create_page_back_button_falls_back_to_the_calls_list(): void
    {
        $this->actingAdmin();
        $listUrl = CallRecordResource::getUrl('index');

        Livewire::test(CreateCallRecord::class)
            ->assertActionHasUrl('back', $listUrl)
            ->assertActionHasColor('back', ProspectResource::BACK_BUTTON_COLOR);
    }

    public function test_view_page_back_button_has_the_history_back_click_handler(): void
    {
        $this->actingAdmin();
        $prospect = Prospect::factory()->create();
        $call = CallRecord::create([
            'prospect_id' => $prospect->id,
            'user_id' => $prospect->assigned_to,
            'called_at' => now(),
            'outcome' => 'no_answer',
        ]);

        $action = Livewire::test(ViewCallRecord::class, ['record' => $call->getRouteKey()])
            ->instance()
            ->getAction('back');

        $handler = $action->getExtraAttributes()['x-on:click'] ?? '';
        $this->assertStringContainsString('window.history.length', $handler);
        $this->assertStringContainsString('window.history.back();', $handler);
    }

    public function test_edit_page_back_button_has_the_history_back_click_handler(): void
    {
        $this->actingAdmin();
        $prospect = Prospect::factory()->create();
        $call = CallRecord::create([
            'prospect_id' => $prospect->id,
            'user_id' => $prospect->assigned_to,
            'called_at' => now(),
            'outcome' => 'no_answer',
        ]);

        $action = Livewire::test(EditCallRecord::class, ['record' => $call->getRouteKey()])
            ->instance()
            ->getAction('back');

        $handler = $action->getExtraAttributes()['x-on:click'] ?? '';
        $this->assertStringContainsString('window.history.length', $handler);
        $this->assertStringContainsString('window.history.back();', $handler);
    }

    public function test_create_page_back_button_has_the_history_back_click_handler(): void
    {
        $this->actingAdmin();

        $action = Livewire::test(CreateCallRecord::class)->instance()->getAction('back');

        $handler = $action->getExtraAttributes()['x-on:click'] ?? '';
        $this->assertStringContainsString('window.history.length', $handler);
        $this->assertStringContainsString('window.history.back();', $handler);
    }

    // --- 2. Bulk actions ---------------------------------------------------

    /**
     * Same layout-only mechanism as Prospects (see
     * BulkSelectToggleTest::test_prospects_bulk_actions_are_standalone_not_grouped_in_a_dropdown()):
     * a top-level array with no BulkActionGroup wrapper is what renders
     * each bulk action as its own standalone toolbar button.
     */
    public function test_calls_bulk_actions_are_standalone_not_grouped_in_a_dropdown(): void
    {
        $this->actingAdmin();

        $bulkActions = Livewire::test(ListCallRecords::class)
            ->instance()
            ->getTable()
            ->getBulkActions();

        $this->assertCount(2, $bulkActions);

        foreach ($bulkActions as $bulkAction) {
            $this->assertNotInstanceOf(ActionGroup::class, $bulkAction);
        }

        $names = collect($bulkActions)->map(fn ($action) => $action->getName())->all();
        $this->assertEqualsCanonicalizing(['deselectAll', 'delete'], $names);
    }

    public function test_employee_does_not_have_the_calls_deselect_all_bulk_action(): void
    {
        $employee = User::factory()->create();
        $this->actingAs($employee);

        Livewire::test(ListCallRecords::class)
            ->assertTableBulkActionHidden('deselectAll');
    }

    // --- 3. Button colors ---------------------------------------------------

    public function test_calls_create_page_footer_actions_use_the_three_distinct_colors(): void
    {
        $this->actingAdmin();

        $page = Livewire::test(CreateCallRecord::class)->instance();

        $this->assertSame(CreateCallRecord::CREATE_ACTION_COLOR, $this->createFormAction($page)->getColor());
        $this->assertSame(CreateCallRecord::CREATE_ANOTHER_ACTION_COLOR, $this->createAnotherFormAction($page)->getColor());
        $this->assertSame(CreateCallRecord::CANCEL_ACTION_COLOR, $this->cancelFormAction($page)->getColor());

        $colors = [
            $this->createFormAction($page)->getColor(),
            $this->createAnotherFormAction($page)->getColor(),
            $this->cancelFormAction($page)->getColor(),
        ];
        $this->assertSame(3, count(array_unique($colors)), 'The three footer actions must all be distinct colors.');
    }

    public function test_prospect_create_page_footer_actions_use_the_three_distinct_colors(): void
    {
        $this->actingAdmin();

        $page = Livewire::test(CreateProspect::class)->instance();

        $this->assertSame(CreateProspect::CREATE_ACTION_COLOR, $this->createFormAction($page)->getColor());
        $this->assertSame(CreateProspect::CREATE_ANOTHER_ACTION_COLOR, $this->createAnotherFormAction($page)->getColor());
        $this->assertSame(CreateProspect::CANCEL_ACTION_COLOR, $this->cancelFormAction($page)->getColor());
    }

    public function test_prospects_import_button_has_a_deliberate_color_distinct_from_create(): void
    {
        $this->actingAdmin();

        $test = Livewire::test(ListProspects::class);
        $importColor = $test->instance()->getAction('importExcel')->getColor();
        $createColor = $test->instance()->getAction('create')->getColor();

        $this->assertSame(ExportActions::COLOR, $importColor);
        $this->assertNotSame($createColor, $importColor);
    }

    /**
     * Calls Phase 1 follow-up: Export CSV (admin) and Request Export
     * (everyone else) must match Import from Excel's own color — one
     * shared constant (ExportActions::COLOR), not two colors that happen
     * to look alike today and could drift apart tomorrow. Both variants
     * checked directly rather than only the one visible to whichever role
     * is acting, since ->getColor() reads the action's configured color
     * regardless of its current visibility.
     */
    public function test_calls_export_actions_match_import_from_excels_color(): void
    {
        $this->actingAdmin();

        $test = Livewire::test(ListCallRecords::class);
        $exportColor = $test->instance()->getAction('exportCsv')->getColor();
        $requestExportColor = $test->instance()->getAction('requestExport')->getColor();

        $this->assertSame(ExportActions::COLOR, $exportColor);
        $this->assertSame(ExportActions::COLOR, $requestExportColor);
        $this->assertSame($exportColor, $requestExportColor);
    }

    // --- Edit page button colors --------------------------------------------

    public function test_calls_edit_page_save_and_cancel_use_the_same_colors_as_create(): void
    {
        $this->actingAdmin();
        $prospect = Prospect::factory()->create();
        $call = CallRecord::create([
            'prospect_id' => $prospect->id,
            'user_id' => $prospect->assigned_to,
            'called_at' => now(),
            'outcome' => 'no_answer',
        ]);

        $page = Livewire::test(EditCallRecord::class, ['record' => $call->getRouteKey()])->instance();

        $this->assertSame(EditCallRecord::SAVE_ACTION_COLOR, $this->saveFormAction($page)->getColor());
        $this->assertSame(EditCallRecord::CANCEL_ACTION_COLOR, $this->cancelFormAction($page)->getColor());
        $this->assertSame(CreateCallRecord::CREATE_ACTION_COLOR, EditCallRecord::SAVE_ACTION_COLOR);
        $this->assertSame(CreateCallRecord::CANCEL_ACTION_COLOR, EditCallRecord::CANCEL_ACTION_COLOR);
    }

    public function test_prospect_edit_page_save_and_cancel_use_the_same_colors_as_create(): void
    {
        $this->actingAdmin();
        $prospect = Prospect::factory()->create();

        $page = Livewire::test(EditProspect::class, ['record' => $prospect->getRouteKey()])->instance();

        $this->assertSame(EditProspect::SAVE_ACTION_COLOR, $this->saveFormAction($page)->getColor());
        $this->assertSame(EditProspect::CANCEL_ACTION_COLOR, $this->cancelFormAction($page)->getColor());
        $this->assertSame(CreateProspect::CREATE_ACTION_COLOR, EditProspect::SAVE_ACTION_COLOR);
        $this->assertSame(CreateProspect::CANCEL_ACTION_COLOR, EditProspect::CANCEL_ACTION_COLOR);
    }

    /**
     * The twin View page's own Edit button is genuinely left unset
     * (->getColor() === null — confirmed directly: Filament's HasColor
     * never resolves null to a named color at the PHP level, only Blade's
     * button component falls it through to 'primary' at render time), so
     * a direct getColor()-to-getColor() equality check against it isn't
     * meaningful. What's actually checked: View-on-Edit-page resolves to
     * the explicit 'primary' token, AND Edit-on-View-page really is still
     * the untouched null this task didn't ask to change — together those
     * two facts are what make the pair render identically.
     */
    public function test_calls_edit_pages_view_button_matches_view_pages_edit_button_color(): void
    {
        $this->actingAdmin();
        $prospect = Prospect::factory()->create();
        $call = CallRecord::create([
            'prospect_id' => $prospect->id,
            'user_id' => $prospect->assigned_to,
            'called_at' => now(),
            'outcome' => 'no_answer',
        ]);

        $viewColorOnEditPage = Livewire::test(EditCallRecord::class, ['record' => $call->getRouteKey()])
            ->instance()->getAction('view')->getColor();
        $editColorOnViewPage = Livewire::test(ViewCallRecord::class, ['record' => $call->getRouteKey()])
            ->instance()->getAction('edit')->getColor();

        $this->assertSame(EditCallRecord::VIEW_ACTION_COLOR, $viewColorOnEditPage);
        $this->assertSame('primary', $viewColorOnEditPage);
        $this->assertNull($editColorOnViewPage);
    }

    public function test_prospect_edit_pages_view_button_matches_view_pages_edit_button_color(): void
    {
        $this->actingAdmin();
        $prospect = Prospect::factory()->create();

        $viewColorOnEditPage = Livewire::test(EditProspect::class, ['record' => $prospect->getRouteKey()])
            ->instance()->getAction('view')->getColor();
        $editColorOnViewPage = Livewire::test(ViewProspect::class, ['record' => $prospect->getRouteKey()])
            ->instance()->getAction('edit')->getColor();

        $this->assertSame(EditProspect::VIEW_ACTION_COLOR, $viewColorOnEditPage);
        $this->assertSame('primary', $viewColorOnEditPage);
        $this->assertNull($editColorOnViewPage);
    }

    /**
     * Item 3: Delete stays exactly as it was — DeleteAction's own baked-in
     * 'danger' default, never touched by this task.
     */
    public function test_calls_and_prospect_edit_pages_delete_action_is_unchanged(): void
    {
        $this->actingAdmin();
        $prospect = Prospect::factory()->create();
        $call = CallRecord::create([
            'prospect_id' => $prospect->id,
            'user_id' => $prospect->assigned_to,
            'called_at' => now(),
            'outcome' => 'no_answer',
        ]);

        $callDeleteColor = Livewire::test(EditCallRecord::class, ['record' => $call->getRouteKey()])
            ->instance()->getAction('delete')->getColor();
        $prospectDeleteColor = Livewire::test(EditProspect::class, ['record' => $prospect->getRouteKey()])
            ->instance()->getAction('delete')->getColor();

        $this->assertSame('danger', $callDeleteColor);
        $this->assertSame('danger', $prospectDeleteColor);
    }

    private function saveFormAction(object $page): object
    {
        $method = new \ReflectionMethod($page, 'getSaveFormAction');
        $method->setAccessible(true);

        return $method->invoke($page);
    }

    private function createFormAction(object $page): object
    {
        $method = new \ReflectionMethod($page, 'getCreateFormAction');
        $method->setAccessible(true);

        return $method->invoke($page);
    }

    private function createAnotherFormAction(object $page): object
    {
        $method = new \ReflectionMethod($page, 'getCreateAnotherFormAction');
        $method->setAccessible(true);

        return $method->invoke($page);
    }

    private function cancelFormAction(object $page): object
    {
        $method = new \ReflectionMethod($page, 'getCancelFormAction');
        $method->setAccessible(true);

        return $method->invoke($page);
    }

    // --- 4. Form reorder -----------------------------------------------------

    /**
     * New order: Company (selector + inline create) first, Company Details
     * (the read-only already-saved-info placeholder) immediately under it,
     * Call Details third, Profile Sent fourth (conditional on outcome,
     * itself picked in Call Details), Notes last.
     */
    public function test_call_form_sections_are_ordered_company_then_call_details_then_notes(): void
    {
        $sections = collect(CallRecordResource::formSchema())
            ->filter(fn ($component) => $component instanceof Section)
            ->map(fn (Section $section) => $section->getHeading())
            ->values()
            ->all();

        $this->assertSame(
            ['Company', 'Company Details', 'Call Details', 'Profile Sent', 'Notes'],
            $sections,
        );
    }

    /**
     * Contact Person/Designation/Phone Called are grouped under Call
     * Details (not split into their own section) — asserted explicitly so
     * this grouping choice is easy to revisit.
     */
    public function test_contact_person_designation_and_phone_called_live_under_call_details(): void
    {
        $callDetails = collect(CallRecordResource::formSchema())
            ->first(fn ($component) => $component instanceof Section && $component->getHeading() === 'Call Details');

        $fieldNames = collect($callDetails->getChildComponents())
            ->map(fn ($field) => method_exists($field, 'getName') ? $field->getName() : null)
            ->filter()
            ->values()
            ->all();

        $this->assertContains('contact_person_spoken_to', $fieldNames);
        $this->assertContains('designation', $fieldNames);
        $this->assertContains('phone_called', $fieldNames);
    }

    /**
     * PipelineBoard's "+ Log a call" header action and "Record New Call"
     * quick action both call CallRecordResource::formSchema() directly
     * (confirmed by reading PipelineBoard.php — out of scope to modify or
     * mount for this task per the hard constraint), with no
     * PipelineBoard-side reordering of the result. That means this
     * section-order change automatically applies there too — the only way
     * it could NOT is if PipelineBoard.php stopped calling formSchema()
     * verbatim, which this test guards against without touching that file.
     * The three V2 destination-specific dialogs (callToFollowUpFormSchema/
     * callToAppointmentFormSchema/callToLeadFormSchema) hand-roll their own
     * narrower field lists and never call formSchema() or
     * callDetailsFieldsSchema() at all, so their own field order is
     * entirely unaffected by this change.
     */
    public function test_pipeline_board_generic_dialog_calls_form_schema_verbatim(): void
    {
        $source = file_get_contents(app_path('Filament/Pages/PipelineBoard.php'));

        $this->assertStringContainsString("->form(CallRecordResource::formSchema())", $source);
    }
}
