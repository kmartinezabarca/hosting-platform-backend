<?php

namespace Tests\Feature;

use App\Models\BillingCycle;
use App\Models\Category;
use App\Models\ServicePlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ServicePlanControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_public_can_list_active_service_plans(): void
    {
        $category = Category::factory()->create();
        ServicePlan::factory()->count(3)->create(['category_id' => $category->id, 'is_active' => true]);
        ServicePlan::factory()->create(['category_id' => $category->id, 'is_active' => false]);

        $response = $this->getJson('/api/service-plans');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(3, 'data');
    }

    public function test_public_can_get_service_plans_by_category_slug(): void
    {
        $category = Category::factory()->create(['slug' => 'hosting']);
        ServicePlan::factory()->count(2)->create(['category_id' => $category->id, 'is_active' => true]);

        $response = $this->getJson('/api/service-plans/category/hosting');

        $response->assertOk();
    }

    public function test_public_can_get_service_plan_by_uuid(): void
    {
        $plan = ServicePlan::factory()->create();

        $response = $this->getJson("/api/service-plans/{$plan->uuid}");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.uuid', $plan->uuid);
    }

    public function test_returns_404_for_nonexistent_service_plan(): void
    {
        $response = $this->getJson('/api/service-plans/99999999-9999-9999-9999-999999999999');

        $response->assertNotFound()
            ->assertJsonPath('success', false);
    }

    public function test_admin_can_list_with_pagination(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $category = Category::factory()->create();

        ServicePlan::factory()->count(20)->create(['category_id' => $category->id]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/service-plans?page=1&per_page=5');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(5, 'data');
    }

    public function test_admin_can_create_service_plan(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $category = Category::factory()->create();
        $billingCycle = BillingCycle::factory()->create();

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/service-plans', [
                'category_id' => $category->id,
                'slug' => 'new-plan',
                'name' => 'New Plan',
                'base_price' => 15.00,
                'setup_fee' => 5.00,
                'is_active' => true,
                'pricing' => [
                    [
                        'billing_cycle_id' => $billingCycle->id,
                        'price' => 15.00,
                    ],
                ],
            ]);

        $response->assertCreated()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('service_plans', [
            'slug' => 'new-plan',
            'name' => 'New Plan',
        ]);
    }

    public function test_admin_can_update_service_plan(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $plan = ServicePlan::factory()->create(['name' => 'Old Name']);

        $response = $this->actingAs($admin, 'sanctum')
            ->putJson("/api/admin/service-plans/{$plan->uuid}", [
                'name' => 'Updated Name',
                'base_price' => 20.00,
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Updated Name');
    }

    public function test_admin_can_delete_service_plan_without_services(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $plan = ServicePlan::factory()->create();

        $response = $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/admin/service-plans/{$plan->uuid}");

        $response->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('service_plans', ['id' => $plan->id]);
    }

    public function test_cannot_delete_service_plan_with_services(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $plan = ServicePlan::factory()->create();

        $user = User::factory()->create();
        \App\Models\Service::factory()->create([
            'plan_id' => $plan->id,
            'user_id' => $user->id,
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/admin/service-plans/{$plan->uuid}");

        $response->assertStatus(400)
            ->assertJsonPath('success', false);
    }

    public function test_validation_fails_for_duplicate_slug(): void
    {
        ServicePlan::factory()->create(['slug' => 'duplicate']);
        $admin = User::factory()->create(['role' => 'admin']);
        $category = Category::factory()->create();

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/service-plans', [
                'category_id' => $category->id,
                'slug' => 'duplicate',
                'name' => 'Test',
                'base_price' => 10,
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['slug']);
    }

    public function test_validation_fails_for_invalid_price(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $category = Category::factory()->create();

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/service-plans', [
                'category_id' => $category->id,
                'slug' => 'test-plan',
                'name' => 'Test',
                'base_price' => -10,
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['base_price']);
    }
}
