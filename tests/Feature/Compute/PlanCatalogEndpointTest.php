<?php

namespace Tests\Feature\Compute;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanCatalogEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_plans_endpoint_returns_catalog_with_annual_savings(): void
    {
        config()->set('compute.billing.currency', 'MXN');
        config()->set('compute.billing.pricing.pro.monthly.amount', '100');
        config()->set('compute.billing.pricing.pro.annual.amount', '1000');

        $user = User::factory()->create(['status' => 'active']);

        $response = $this->actingAs($user)->getJson('/api/v2/plans');

        $response->assertStatus(200)
            ->assertJsonPath('data.currency', 'MXN')
            // PlanTier::cases() = free, starter, pro, team, agency → índice 2 = pro.
            ->assertJsonPath('data.plans.2.tier', 'pro')
            ->assertJsonPath('data.plans.2.pricing.monthly.amount', 100)
            ->assertJsonPath('data.plans.2.pricing.annual.savings.amount', 200)
            ->assertJsonPath('data.plans.2.pricing.annual.savings.percent', 16.67)
            // 'free' se expone con precio 0, no null.
            ->assertJsonPath('data.plans.0.tier', 'free')
            ->assertJsonPath('data.plans.0.pricing.monthly.amount', 0);
    }

    public function test_plans_endpoint_requires_auth(): void
    {
        $this->getJson('/api/v2/plans')->assertStatus(401);
    }
}
