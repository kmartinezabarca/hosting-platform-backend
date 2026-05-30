<?php

namespace Tests\Feature;

use App\Exceptions\CheckoutQuoteException;
use App\Models\BillingCycle;
use App\Models\CheckoutQuote;
use App\Models\ServicePlan;
use App\Services\CheckoutQuoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Reclamo atómico de la cotización (Fase 0): impide contratar dos veces la misma
 * quote (doble click / refresh / retry) y permite reintentar si el pago falla.
 */
class CheckoutQuoteClaimTest extends TestCase
{
    use RefreshDatabase;

    private function makeQuote(): CheckoutQuote
    {
        $plan  = ServicePlan::factory()->create();
        $cycle = BillingCycle::factory()->create();

        return CheckoutQuote::create([
            'service_plan_id'  => $plan->id,
            'billing_cycle_id' => $cycle->id,
            'request_payload'  => ['plan_id' => $plan->id, 'billing_cycle' => $cycle->slug],
            'pricing_snapshot' => ['total' => 100, 'currency' => 'MXN'],
            'quote_hash'       => str_repeat('a', 64),
            'currency'         => 'MXN',
            'total'            => 100,
            'expires_at'       => now()->addMinutes(30),
        ]);
    }

    public function test_claim_marks_quote_consumed(): void
    {
        $quote = $this->makeQuote();
        $svc   = app(CheckoutQuoteService::class);

        $svc->claim($quote);

        $this->assertNotNull($quote->fresh()->consumed_at);
    }

    public function test_second_claim_is_rejected(): void
    {
        $quote = $this->makeQuote();
        $svc   = app(CheckoutQuoteService::class);

        $svc->claim($quote);

        $this->expectException(CheckoutQuoteException::class);
        $this->expectExceptionMessage('ya fue utilizada');

        // Simula una segunda petición concurrente con una instancia fresca.
        $svc->claim($quote->fresh());
    }

    public function test_release_allows_reclaim(): void
    {
        $quote = $this->makeQuote();
        $svc   = app(CheckoutQuoteService::class);

        $svc->claim($quote);
        $svc->release($quote);

        $this->assertNull($quote->fresh()->consumed_at);

        // Tras liberar, se puede volver a reclamar sin excepción.
        $svc->claim($quote->fresh());
        $this->assertNotNull($quote->fresh()->consumed_at);
    }
}
