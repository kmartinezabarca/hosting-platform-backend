<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Analytics overview endpoint.
 *
 * NOTE: requires a real DB (MySQL in CI).
 */
class AdminAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_overview_returns_the_expected_shape(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->getJson('/api/admin/analytics/overview?range=30d')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'range', 'currency',
                    'revenue_total', 'revenue_change_pct',
                    'mrr', 'mrr_change_pct', 'arr',
                    'churn_rate', 'new_customers', 'active_subscriptions', 'arpu', 'ltv',
                    'revenue_series', 'customers_series',
                    'plan_distribution', 'revenue_by_category',
                ],
            ])
            ->assertJsonPath('data.range', '30d');
    }

    public function test_overview_defaults_to_30d_and_validates_range(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->getJson('/api/admin/analytics/overview')
            ->assertOk()
            ->assertJsonPath('data.range', '30d');

        $this->actingAs($admin)->getJson('/api/admin/analytics/overview?range=bogus')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['range']);
    }

    public function test_twelve_month_range_produces_twelve_buckets(): void
    {
        $admin = User::factory()->admin()->create();

        $res = $this->actingAs($admin)->getJson('/api/admin/analytics/overview?range=12m')
            ->assertOk();

        $this->assertCount(12, $res->json('data.revenue_series'));
    }

    public function test_support_cannot_view_analytics(): void
    {
        $support = User::factory()->create(['role' => 'support']);

        $this->actingAs($support)->getJson('/api/admin/analytics/overview')
            ->assertForbidden();
    }
}
