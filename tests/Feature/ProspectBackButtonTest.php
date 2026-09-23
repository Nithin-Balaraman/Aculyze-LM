<?php

namespace Tests\Feature;

use App\Filament\Pages\PipelineBoard;
use App\Filament\Resources\ProspectResource;
use App\Filament\Resources\ProspectResource\Pages\EditProspect;
use App\Filament\Resources\ProspectResource\Pages\ViewProspect;
use App\Models\Prospect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Neither the View nor Edit Prospect page had a "Back" action before —
 * confirmed no existing "return to wherever I came from" pattern exists
 * anywhere in this app (ViewCommercialVersion's own "Back" action is a
 * fixed destination, not history-based — see ProspectResource::
 * getBackUrl()'s own docblock for the full reasoning). Both pages now
 * share that one static helper, built on Laravel's own url()->previous()
 * (Referer header, falling back to the session's own last-GET-request
 * tracking — both real, framework-native signals, not something bespoke).
 *
 * bindRequest() below replaces the app's bound Request with one carrying
 * an explicit URL and Referer header — the same signal a real browser
 * sends on an actual link click. It must be called AFTER Livewire::test()
 * has already mounted the component, not before: Livewire's own test
 * harness dispatches its component update through a synthetic internal
 * request (a "livewire-unit-test-endpoint" URL, no Referer at all),
 * clobbering whatever was bound beforehand. That's harmless for what
 * getHeaderActions() itself does at mount time (label/icon/color are all
 * eager, but the Back action's ->url() is a Closure, only ever evaluated
 * lazily when getUrl() is actually called) — confirmed directly before
 * settling on this order, rather than assumed.
 */
class ProspectBackButtonTest extends TestCase
{
    use RefreshDatabase;

    private function actingAdmin(): User
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        return $admin;
    }

    private function bindRequest(string $url, ?string $referer = null): void
    {
        $server = $referer ? ['HTTP_REFERER' => $referer] : [];
        $this->app->instance('request', Request::create($url, 'GET', [], [], [], $server));
    }

    public function test_view_page_back_button_returns_to_the_database_list(): void
    {
        $this->actingAdmin();
        $prospect = Prospect::factory()->create();
        $viewUrl = ProspectResource::getUrl('view', ['record' => $prospect]);
        $listUrl = ProspectResource::getUrl('index');

        $test = Livewire::test(ViewProspect::class, ['record' => $prospect->getRouteKey()]);
        $this->bindRequest($viewUrl, $listUrl);

        $test->assertActionHasUrl('back', $listUrl);
    }

    public function test_view_page_back_button_returns_to_the_pipeline_board(): void
    {
        $this->actingAdmin();
        $prospect = Prospect::factory()->create();
        $viewUrl = ProspectResource::getUrl('view', ['record' => $prospect]);
        $pipelineBoardUrl = PipelineBoard::getUrl();

        $test = Livewire::test(ViewProspect::class, ['record' => $prospect->getRouteKey()]);
        $this->bindRequest($viewUrl, $pipelineBoardUrl);

        $test->assertActionHasUrl('back', $pipelineBoardUrl);
    }

    /**
     * Third real entry point: global search (ProspectResource::
     * getGlobalSearchResultUrl()) is a link click from whatever page the
     * search was actually invoked on — the Main Dashboard here — not a
     * distinct "search results page" of its own. This proves the
     * mechanism generalizes to that (and, by construction, to any future
     * entry point) without needing special-case code for each one.
     */
    public function test_view_page_back_button_returns_to_wherever_global_search_was_invoked_from(): void
    {
        $this->actingAdmin();
        $prospect = Prospect::factory()->create();
        $viewUrl = ProspectResource::getUrl('view', ['record' => $prospect]);
        $dashboardUrl = url('/admin/main-dashboard');

        $test = Livewire::test(ViewProspect::class, ['record' => $prospect->getRouteKey()]);
        $this->bindRequest($viewUrl, $dashboardUrl);

        $test->assertActionHasUrl('back', $dashboardUrl);
    }

    public function test_edit_page_back_button_uses_the_same_history_based_mechanism(): void
    {
        $this->actingAdmin();
        $prospect = Prospect::factory()->create();
        $editUrl = ProspectResource::getUrl('edit', ['record' => $prospect]);
        // A real entry point in its own right: arriving at Edit via the
        // View page's own Edit header action.
        $viewUrl = ProspectResource::getUrl('view', ['record' => $prospect]);

        $test = Livewire::test(EditProspect::class, ['record' => $prospect->getRouteKey()]);
        $this->bindRequest($editUrl, $viewUrl);

        $test->assertActionHasUrl('back', $viewUrl);
    }

    /**
     * No Referer header (a bookmark, or any direct-URL access) and no
     * prior GET tracked in the session either — url()->previous() would
     * otherwise fall back to the site root; the explicit $fallback
     * argument to previous() in getBackUrl() is what makes it land on the
     * Prospects list instead, exactly the sensible fallback this task
     * asked for.
     */
    public function test_view_page_back_button_falls_back_to_the_list_with_no_referrer_at_all(): void
    {
        $this->actingAdmin();
        $prospect = Prospect::factory()->create();
        $viewUrl = ProspectResource::getUrl('view', ['record' => $prospect]);
        $listUrl = ProspectResource::getUrl('index');

        $test = Livewire::test(ViewProspect::class, ['record' => $prospect->getRouteKey()]);
        $this->bindRequest($viewUrl);

        $test->assertActionHasUrl('back', $listUrl);
    }

    /**
     * A plain page refresh sends no Referer at all, so previous() would
     * otherwise fall through to the session's own last-tracked GET URL —
     * which, on a refresh, is this exact same page (see
     * ProspectResource::getBackUrl()'s own docblock for the full
     * request-sequencing trace). Simulated directly here by setting the
     * Referer to the page's own URL, which is the deterministic
     * equivalent of that same-URL condition getBackUrl() guards against —
     * without it, Back would link to itself instead of somewhere useful.
     */
    public function test_view_page_back_button_falls_back_to_the_list_instead_of_looping_to_itself(): void
    {
        $this->actingAdmin();
        $prospect = Prospect::factory()->create();
        $viewUrl = ProspectResource::getUrl('view', ['record' => $prospect]);
        $listUrl = ProspectResource::getUrl('index');

        $test = Livewire::test(ViewProspect::class, ['record' => $prospect->getRouteKey()]);
        $this->bindRequest($viewUrl, $viewUrl);

        $test->assertActionHasUrl('back', $listUrl)
            ->assertActionDoesNotHaveUrl('back', $viewUrl);
    }

    public function test_edit_page_back_button_also_falls_back_to_the_list_instead_of_looping_to_itself(): void
    {
        $this->actingAdmin();
        $prospect = Prospect::factory()->create();
        $editUrl = ProspectResource::getUrl('edit', ['record' => $prospect]);
        $listUrl = ProspectResource::getUrl('index');

        $test = Livewire::test(EditProspect::class, ['record' => $prospect->getRouteKey()]);
        $this->bindRequest($editUrl, $editUrl);

        $test->assertActionHasUrl('back', $listUrl)
            ->assertActionDoesNotHaveUrl('back', $editUrl);
    }
}
