<?php

namespace Tests\Unit;

use App\Models\Invoice;
use App\Models\User;
use App\Services\DashboardStatsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DashboardStatsServiceTest extends TestCase
{
    use RefreshDatabase;

    private DashboardStatsService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DashboardStatsService();
    }

    // ──────────────────────────────────────────────
    // growthRate() via usersStats()
    // ──────────────────────────────────────────────

    public function test_growth_rate_is_zero_when_no_users_either_month(): void
    {
        $stats = $this->service->usersStats();

        $this->assertSame(0.0, $stats['growth_rate']);
    }

    public function test_growth_rate_is_100_when_previous_month_was_zero(): void
    {
        // Create a user only this month — previous month is empty
        User::factory()->create(['created_at' => now()]);

        $stats = $this->service->usersStats();

        $this->assertSame(100.0, $stats['growth_rate']);
    }

    public function test_growth_rate_is_positive_when_this_month_exceeds_last(): void
    {
        $lastMonth = Carbon::now()->subMonth();

        User::factory()->count(2)->create(['created_at' => $lastMonth]); // 2 last month
        User::factory()->count(4)->create(['created_at' => now()]);      // 4 this month

        $stats = $this->service->usersStats();

        // Growth = ((4-2)/2) * 100 = 100.0
        $this->assertSame(100.0, $stats['growth_rate']);
    }

    public function test_growth_rate_is_negative_when_this_month_is_lower(): void
    {
        $lastMonth = Carbon::now()->subMonth();

        User::factory()->count(4)->create(['created_at' => $lastMonth]); // 4 last month
        User::factory()->count(2)->create(['created_at' => now()]);      // 2 this month

        $stats = $this->service->usersStats();

        // Growth = ((2-4)/4) * 100 = -50.0
        $this->assertSame(-50.0, $stats['growth_rate']);
    }

    public function test_growth_rate_does_not_cross_year_boundary(): void
    {
        // Create users in January of LAST year (not last month)
        $lastYear = Carbon::now()->subYear()->startOfYear();
        User::factory()->count(10)->create(['created_at' => $lastYear]);

        // No users this month or last month
        $stats = $this->service->usersStats();

        // Last month is 0, this month is 0 → growth_rate = 0.0
        $this->assertSame(0.0, $stats['growth_rate']);
    }

    // ──────────────────────────────────────────────
    // usersStats() — counts
    // ──────────────────────────────────────────────

    public function test_users_stats_returns_correct_totals(): void
    {
        User::factory()->count(3)->create(['status' => 'active']);
        User::factory()->count(2)->create(['status' => 'suspended']);
        User::factory()->count(1)->create(['status' => 'pending_verification']);

        $stats = $this->service->usersStats();

        $this->assertSame(6, $stats['total']);
        $this->assertSame(3, $stats['active']);
        $this->assertSame(2, $stats['suspended']);
        $this->assertSame(1, $stats['pending']);
    }

    public function test_new_this_month_only_counts_current_month(): void
    {
        User::factory()->count(2)->create(['created_at' => now()]);
        User::factory()->count(5)->create(['created_at' => now()->subMonth()]);

        $stats = $this->service->usersStats();

        $this->assertSame(2, $stats['new_this_month']);
    }

    // ──────────────────────────────────────────────
    // revenueStats()
    // ──────────────────────────────────────────────

    public function test_revenue_stats_only_counts_paid_invoices(): void
    {
        // Paid this month
        Invoice::factory()->create(['status' => 'paid', 'total' => 100.00, 'created_at' => now()]);
        // Unpaid — should not count
        Invoice::factory()->create(['status' => 'sent', 'total' => 200.00, 'created_at' => now()]);

        $stats = $this->service->revenueStats();

        $this->assertEquals(100.00, $stats['monthly']);
    }

    public function test_revenue_stats_yearly_sums_all_paid_this_year(): void
    {
        Invoice::factory()->create(['status' => 'paid', 'total' => 300.00, 'created_at' => now()]);
        Invoice::factory()->create(['status' => 'paid', 'total' => 200.00, 'created_at' => now()->subMonths(3)]);
        // Last year — should not count in yearly total
        Invoice::factory()->create(['status' => 'paid', 'total' => 999.00, 'created_at' => now()->subYear()]);

        $stats = $this->service->revenueStats();

        $this->assertEquals(500.00, $stats['yearly']);
    }

    // ──────────────────────────────────────────────
    // getAll() — structure
    // ──────────────────────────────────────────────

    public function test_get_all_returns_expected_keys(): void
    {
        $result = $this->service->getAll();

        $this->assertArrayHasKey('users',           $result);
        $this->assertArrayHasKey('services',        $result);
        $this->assertArrayHasKey('revenue',         $result);
        $this->assertArrayHasKey('invoices',        $result);
        $this->assertArrayHasKey('tickets',         $result);
        $this->assertArrayHasKey('plans',           $result);
        $this->assertArrayHasKey('recent_activity', $result);
    }
}
