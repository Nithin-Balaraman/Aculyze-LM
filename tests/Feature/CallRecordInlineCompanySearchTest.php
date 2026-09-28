<?php

namespace Tests\Feature;

use App\Filament\Resources\CallRecordResource;
use App\Models\Prospect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Calls Phase 2, item 2: same blended search ranking as the Calls list/
 * Prospects (see CallRecordTableSearchTest, ProspectResource::table()) —
 * applied inside the Company Select's own ->getSearchResultsUsing()
 * closure (CallRecordResource::companyFieldSchema()), reused verbatim by
 * both the main Create/Edit form and (unmodified, per this task's hard
 * constraint) PipelineBoard's "+ Log a call"/"Record New Call" dialog,
 * which calls CallRecordResource::formSchema() directly.
 *
 * Unlike the table's own version, no ->modifyQueryUsing() workaround is
 * needed here: this closure builds a plain, freestanding Prospect::query()
 * from scratch every time it runs — never merged into a Filament table's
 * own ->where(function () {...}) nested search group (the thing that
 * silently discards ->orderByRaw() there) — so ordering it directly
 * works exactly as written, confirmed here via Select::getSearchResults(),
 * which evaluates the exact same closure Filament's own Livewire endpoint
 * (getFormSelectSearchResults()) calls.
 */
class CallRecordInlineCompanySearchTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        return $admin;
    }

    private function companySelect(): \Filament\Forms\Components\Select
    {
        return CallRecordResource::companyFieldSchema()[0];
    }

    public function test_starts_with_match_ranks_before_contains_only_match(): void
    {
        $admin = $this->admin();
        $containsOnly = Prospect::factory()->create(['assigned_to' => $admin->id, 'created_by' => $admin->id, 'company_name' => 'Acme Corp Industries']);
        $startsWith = Prospect::factory()->create(['assigned_to' => $admin->id, 'created_by' => $admin->id, 'company_name' => 'Corp Solutions Ltd']);

        $results = $this->companySelect()->getSearchResults('corp');
        $ids = array_keys(array_filter($results, fn ($k) => $k !== '__create_new_prospect__', ARRAY_FILTER_USE_KEY));

        $this->assertSame([$startsWith->id, $containsOnly->id], $ids);
    }

    /** The sentinel "+ Create new company…" row must stay pinned first regardless of ranking. */
    public function test_create_new_company_option_stays_first(): void
    {
        $admin = $this->admin();
        Prospect::factory()->create(['assigned_to' => $admin->id, 'created_by' => $admin->id, 'company_name' => 'Corp Solutions Ltd']);

        $results = $this->companySelect()->getSearchResults('corp');

        $this->assertSame('__create_new_prospect__', array_key_first($results));
        $this->assertSame('+ Create new company…', reset($results));
    }

    public function test_term_appearing_only_mid_name_still_matches(): void
    {
        $admin = $this->admin();
        $prospect = Prospect::factory()->create(['assigned_to' => $admin->id, 'created_by' => $admin->id, 'company_name' => 'Precision Engineering Co']);

        $results = $this->companySelect()->getSearchResults('cision');

        $this->assertArrayHasKey($prospect->id, $results);
    }

    public function test_empty_search_does_not_error_and_still_returns_the_sentinel(): void
    {
        $admin = $this->admin();
        Prospect::factory()->create(['assigned_to' => $admin->id, 'created_by' => $admin->id, 'company_name' => 'Corp Solutions Ltd']);

        $results = $this->companySelect()->getSearchResults('');

        $this->assertSame('__create_new_prospect__', array_key_first($results));
    }
}
