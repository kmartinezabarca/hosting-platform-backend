<?php

namespace Tests\Unit\Compute;

use App\Domains\Platform\Compute\Enums\BillingInterval;
use App\Domains\Platform\Compute\Enums\PlanTier;
use App\Domains\Platform\Compute\Plans\PlanCatalog;
use Tests\TestCase;

class PlanCatalogTest extends TestCase
{
    private PlanCatalog $catalog;

    protected function setUp(): void
    {
        parent::setUp();
        $this->catalog = new PlanCatalog();
    }

    public function test_annual_savings_computed_from_config(): void
    {
        config()->set('compute.billing.pricing.pro.monthly.amount', '100');
        config()->set('compute.billing.pricing.pro.annual.amount', '1000'); // 12×100=1200 → ahorro 200

        $savings = $this->catalog->annualSavings(PlanTier::Pro);

        $this->assertNotNull($savings);
        $this->assertSame(200.0, $savings['amount']);
        $this->assertSame(16.67, $savings['percent']);
    }

    public function test_savings_null_when_a_price_is_missing(): void
    {
        config()->set('compute.billing.pricing.starter.monthly.amount', null);
        config()->set('compute.billing.pricing.starter.annual.amount', '500');

        $this->assertNull($this->catalog->annualSavings(PlanTier::Starter));
    }

    public function test_savings_null_when_monthly_is_zero(): void
    {
        // 'free' tiene monthly 0 → no hay base mensual con qué comparar.
        $this->assertNull($this->catalog->annualSavings(PlanTier::Free));
    }

    public function test_price_casts_env_string_to_float_or_null(): void
    {
        config()->set('compute.billing.pricing.team.monthly.amount', '249.50');
        $this->assertSame(249.5, $this->catalog->price(PlanTier::Team, BillingInterval::Monthly));

        config()->set('compute.billing.pricing.team.annual.amount', '');
        $this->assertNull($this->catalog->price(PlanTier::Team, BillingInterval::Annual));
    }

    public function test_for_tier_has_limits_and_pricing_shape(): void
    {
        $pro = $this->catalog->forTier(PlanTier::Pro);

        $this->assertSame('pro', $pro['tier']);
        $this->assertArrayHasKey('max_resources', $pro['limits']);
        $this->assertArrayHasKey('monthly', $pro['pricing']);
        $this->assertArrayHasKey('annual', $pro['pricing']);
        $this->assertArrayHasKey('savings', $pro['pricing']['annual']);
    }

    public function test_billing_interval_months(): void
    {
        $this->assertSame(1, BillingInterval::Monthly->months());
        $this->assertSame(12, BillingInterval::Annual->months());
    }
}
