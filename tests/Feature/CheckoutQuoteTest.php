<?php

namespace Tests\Feature;

use App\Models\AddOn;
use App\Models\BillingCycle;
use App\Models\Category;
use App\Models\PlanPricing;
use App\Models\ServicePlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckoutQuoteTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('billing.currency', 'MXN');
        config()->set('billing.tax_rate_percent', 16.00);

        $this->user = User::factory()->create();
    }

    public function test_quote_with_monthly_plan(): void
    {
        [$plan, $cycle] = $this->planWithCycle(price: 80);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/checkout/quote', [
                'plan_id' => $plan->id,
                'billing_cycle' => $cycle->slug,
            ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.currency', 'MXN')
            ->assertJsonPath('data.subtotal', 80)
            ->assertJsonPath('data.tax', 12.8)
            ->assertJsonPath('data.total', 92.8);
    }

    public function test_quote_with_add_ons(): void
    {
        [$plan, $cycle] = $this->planWithCycle(price: 80);
        $addOn = AddOn::create([
            'slug' => 'backups',
            'name' => 'Backups diarios',
            'price' => 20,
            'currency' => 'MXN',
            'is_active' => true,
        ]);
        $plan->addOns()->attach($addOn->id);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/checkout/quote', [
                'plan_id' => $plan->id,
                'billing_cycle' => $cycle->slug,
                'add_ons' => [$addOn->id],
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.subtotal', 100)
            ->assertJsonPath('data.tax', 16)
            ->assertJsonPath('data.total', 116)
            ->assertJsonPath('data.selected_add_ons.0.id', $addOn->id);
    }

    public function test_quote_with_discount(): void
    {
        [$plan, $cycle] = $this->planWithCycle(
            cycleSlug: 'quarterly',
            cycleName: 'Trimestral',
            months: 3,
            basePrice: 80,
            price: 72,
            discount: 10,
        );

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/checkout/quote', [
                'plan_id' => $plan->id,
                'billing_cycle' => $cycle->slug,
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.subtotal', 216)
            ->assertJsonPath('data.discount', 24)
            ->assertJsonPath('data.billing_cycle.discount_percent', 10)
            ->assertJsonPath('data.total', 250.56);
    }

    public function test_quote_calculates_iva(): void
    {
        [$plan, $cycle] = $this->planWithCycle(price: 100);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/checkout/quote', [
                'plan_id' => $plan->id,
                'billing_cycle' => $cycle->slug,
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.tax_rate', 16)
            ->assertJsonPath('data.tax', 16)
            ->assertJsonPath('data.lines.1.label', 'IVA 16%');
    }

    public function test_quote_for_trial_plan_is_free(): void
    {
        [$plan, $cycle] = $this->planWithCycle(
            cycleSlug: 'trial',
            cycleName: 'Prueba Gratuita',
            months: 0,
            basePrice: 0,
            price: 0,
            planType: ServicePlan::TYPE_TRIAL,
            trialDays: 7,
            discount: 100,
        );

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/checkout/quote', [
                'plan_id' => $plan->id,
                'billing_cycle' => $cycle->slug,
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.is_trial', true)
            ->assertJsonPath('data.trial_days', 7)
            ->assertJsonPath('data.subtotal', 0)
            ->assertJsonPath('data.tax', 0)
            ->assertJsonPath('data.total', 0);
    }

    public function test_inactive_plan_is_rejected(): void
    {
        [$plan, $cycle] = $this->planWithCycle(price: 80, active: false);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/checkout/quote', [
                'plan_id' => $plan->id,
                'billing_cycle' => $cycle->slug,
            ]);

        $response->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('error', 'PLAN_UNAVAILABLE');
    }

    public function test_expired_quote_is_rejected(): void
    {
        [$plan, $cycle] = $this->planWithCycle(price: 80);

        $quoteId = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/checkout/quote', [
                'plan_id' => $plan->id,
                'billing_cycle' => $cycle->slug,
            ])
            ->json('data.quote_id');

        \App\Models\CheckoutQuote::where('uuid', $quoteId)->update(['expires_at' => now()->subMinute()]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/services/contract', [
                'quote_id' => $quoteId,
                'service_name' => 'Hosting de prueba',
            ]);

        $response->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error', 'QUOTE_EXPIRED');
    }

    private function planWithCycle(
        string $cycleSlug = 'monthly',
        string $cycleName = 'Mensual',
        int $months = 1,
        float $basePrice = 80,
        float $price = 80,
        string $planType = ServicePlan::TYPE_PAID,
        ?int $trialDays = null,
        float $discount = 0,
        bool $active = true,
    ): array {
        $category = Category::factory()->create(['is_active' => true]);
        $plan = ServicePlan::factory()->create([
            'category_id' => $category->id,
            'base_price' => $basePrice,
            'setup_fee' => 0,
            'is_active' => $active,
            'plan_type' => $planType,
            'trial_days' => $trialDays,
        ]);
        $cycle = BillingCycle::factory()->create([
            'slug' => $cycleSlug,
            'name' => $cycleName,
            'months' => $months,
            'discount_percentage' => $discount,
            'is_active' => true,
        ]);

        PlanPricing::create([
            'service_plan_id' => $plan->id,
            'billing_cycle_id' => $cycle->id,
            'price' => $price,
        ]);

        return [$plan->fresh(['pricing.billingCycle', 'addOns']), $cycle];
    }
}
