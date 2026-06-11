<?php

namespace Tests\Feature;

use App\Domains\Platform\Models\CheckoutQuote;
use App\Domains\Platform\Models\Service;
use App\Domains\Platform\Models\ServicePlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Los planes DE PAGO solo se contratan vía cotización (quote_id): la ruta
 * directa con plan_id calculaba base_price × meses e ignoraba los precios
 * por ciclo (descuentos), pudiendo cobrar de más. Free/trial siguen sin quote.
 */
class QuoteRequiredContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    public function test_paid_plan_without_quote_is_rejected(): void
    {
        $user = User::factory()->create();
        $plan = ServicePlan::factory()->create(['plan_type' => 'paid', 'base_price' => 100]);

        $this->actingAs($user)->postJson('/api/services/contract', [
            'plan_id'           => $plan->slug,
            'billing_cycle'     => 'annually',
            'service_name'      => 'Sin cotización',
            'payment_method_id' => 'pm_fake_123',
        ])->assertStatus(422)
            ->assertJsonPath('error', 'QUOTE_REQUIRED');

        $this->assertSame(0, Service::count());
    }

    public function test_trial_plan_still_contracts_without_quote(): void
    {
        $user = User::factory()->create();
        $plan = ServicePlan::factory()->create([
            'plan_type'   => 'trial',
            'trial_days'  => 14,
            'base_price'  => 100,
            'provisioner' => null,
        ]);

        $this->actingAs($user)->postJson('/api/services/contract', [
            'plan_id'       => $plan->slug,
            'billing_cycle' => 'monthly',
            'service_name'  => 'Trial sin cotización',
        ])->assertCreated();

        $this->assertSame(1, Service::where('user_id', $user->id)->count());
    }

    public function test_unknown_quote_id_is_rejected(): void
    {
        $user = User::factory()->create();

        // La validación del request (exists) rechaza cotizaciones inexistentes.
        $this->actingAs($user)->postJson('/api/services/contract', [
            'quote_id'      => '11111111-1111-1111-1111-111111111111',
            'service_name'  => 'Quote inexistente',
            'billing_cycle' => 'monthly',
        ])->assertStatus(422);

        $this->assertSame(0, Service::count());
    }

    public function test_consumed_quote_cannot_be_reused(): void
    {
        $user  = User::factory()->create();
        $plan  = ServicePlan::factory()->create(['plan_type' => 'paid', 'base_price' => 100]);
        $cycle = \App\Domains\Platform\Models\BillingCycle::firstOrCreate(
            ['slug' => 'monthly'],
            ['uuid' => (string) \Illuminate\Support\Str::uuid(), 'name' => 'Mensual', 'months' => 1, 'discount_percentage' => 0, 'is_active' => true, 'sort_order' => 1]
        );

        $quote = CheckoutQuote::create([
            'user_id'             => $user->id,
            'service_plan_id'     => $plan->id,
            'billing_cycle_id'    => $cycle->id,
            'selected_add_on_ids' => [],
            'request_payload'     => ['plan_id' => $plan->id, 'billing_cycle' => 'monthly', 'add_ons' => []],
            'pricing_snapshot'    => ['total' => 116.0, 'currency' => 'MXN'],
            'quote_hash'          => str_repeat('a', 64),
            'currency'            => 'MXN',
            'subtotal'            => 100,
            'discount'            => 0,
            'tax'                 => 16,
            'total'               => 116,
            'is_free'             => false,
            'is_trial'            => false,
            'trial_days'          => 0,
            'expires_at'          => now()->addMinutes(30),
            'consumed_at'         => now()->subMinute(), // ya usada
        ]);

        $this->actingAs($user)->postJson('/api/services/contract', [
            'quote_id'      => $quote->uuid,
            'service_name'  => 'Reuso de cotización',
            'billing_cycle' => 'monthly',
        ])->assertStatus(409)
            ->assertJsonPath('error', 'QUOTE_ALREADY_USED');

        $this->assertSame(0, Service::count());
    }
}
