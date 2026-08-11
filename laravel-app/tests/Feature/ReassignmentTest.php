<?php

namespace Tests\Feature;

use App\Enums\CallOutcome;
use App\Models\CallRecord;
use App\Models\Prospect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AGENTS.md sections 11, 29, 58: reassigning a Prospect updates who is
 * currently responsible without rewriting who created it or who actually
 * made each historical call.
 */
class ReassignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_reassigning_a_prospect_preserves_creator_and_call_history(): void
    {
        $nithin = User::factory()->create(['name' => 'Nithin']);
        $kural = User::factory()->create(['name' => 'Kural']);

        $prospect = Prospect::factory()->create(['assigned_to' => $nithin->id, 'created_by' => $nithin->id]);
        $call = CallRecord::create([
            'prospect_id' => $prospect->id,
            'user_id' => $nithin->id,
            'called_at' => now(),
            'outcome' => CallOutcome::NoAnswer,
        ]);

        // Admin reassigns the prospect to Kural.
        $prospect->update(['assigned_to' => $kural->id]);
        $prospect->refresh();

        $this->assertSame($kural->id, $prospect->assigned_to);
        $this->assertSame($nithin->id, $prospect->created_by, 'Original creator must be preserved.');
        $this->assertSame($nithin->id, $call->fresh()->user_id, 'Historical caller must not change.');
    }
}
