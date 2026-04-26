<?php

namespace Tests\Feature;

use App\Models\BillingCycle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class BillingCycleControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_public_can_list_active_billing_cycles(): void
    {
        BillingCycle::factory()->count(3)->create(['is_active' => true]);
        BillingCycle::factory()->create(['is_active' => false]);

        $response = $this->getJson('/api/billing-cycles');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(3, 'data');
    }

    public function test_public_can_get_billing_cycle_by_uuid(): void
    {
        $cycle = BillingCycle::factory()->create();

        $response = $this->getJson("/api/billing-cycles/{$cycle->uuid}");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.uuid', $cycle->uuid);
    }

    public function test_returns_404_for_nonexistent_billing_cycle(): void
    {
        $response = $this->getJson('/api/billing-cycles/99999999-9999-9999-9999-999999999999');

        $response->assertNotFound()
            ->assertJsonPath('success', false);
    }

    public function test_admin_can_create_billing_cycle(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/billing-cycles', [
                'slug' => 'biweekly',
                'name' => 'Bi-Weekly',
                'months' => 2,
                'discount_percentage' => 5,
                'is_active' => true,
            ]);

        $response->assertCreated()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('billing_cycles', [
            'slug' => 'biweekly',
            'name' => 'Bi-Weekly',
        ]);
    }

    public function test_admin_can_update_billing_cycle(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $cycle = BillingCycle::factory()->create(['name' => 'Old Name']);

        $response = $this->actingAs($admin, 'sanctum')
            ->putJson("/api/admin/billing-cycles/{$cycle->uuid}", [
                'name' => 'New Name',
                'discount_percentage' => 10,
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'New Name');
    }

    public function test_admin_can_delete_billing_cycle_without_pricing(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $cycle = BillingCycle::factory()->create();

        $response = $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/admin/billing-cycles/{$cycle->uuid}");

        $response->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('billing_cycles', ['id' => $cycle->id]);
    }

    public function test_cannot_delete_billing_cycle_with_pricing(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $cycle = BillingCycle::factory()->create();

        $category = \App\Models\Category::factory()->create();
        $plan = \App\Models\ServicePlan::factory()->create(['category_id' => $category->id]);
        $plan->pricing()->create([
            'billing_cycle_id' => $cycle->id,
            'price' => 10,
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/admin/billing-cycles/{$cycle->uuid}");

        $response->assertStatus(400)
            ->assertJsonPath('success', false);
    }

    public function test_validation_fails_for_invalid_months(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/billing-cycles', [
                'slug' => 'invalid-months',
                'name' => 'Invalid',
                'months' => 100,
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['months']);
    }

    public function test_validation_fails_for_duplicate_slug(): void
    {
        BillingCycle::factory()->create(['slug' => 'duplicate', 'discount_percentage' => 0]);
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/billing-cycles', [
                'slug' => 'duplicate',
                'name' => 'Test',
                'months' => 1,
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['slug']);
    }
}
