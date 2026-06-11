<?php

namespace Tests\Feature;

use App\Domains\Platform\Models\Receipt;
use App\Domains\Platform\Models\Service;
use App\Domains\Platform\Models\ServicePlan;
use App\Domains\Platform\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Las renovaciones de Stripe deben generar contabilidad interna:
 * Receipt + Transaction + Invoice(CFDI), exactamente una vez por invoice.
 */
class RenewalAccountingTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK_SECRET = 'whsec_test_secret';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.stripe.webhook_secret' => self::WEBHOOK_SECRET,
            'billing.tax_rate_percent'       => 16.00,
        ]);
        Notification::fake();

        // El PDF del comprobante se genera post-commit con dompdf (pesado en
        // memoria); aquí solo validamos los registros contables.
        $this->mock(\App\Domains\Platform\Services\PaymentReceiptService::class)
            ->shouldReceive('generate')->andReturnNull();
    }

    public function test_renewal_webhook_creates_receipt_transaction_and_cfdi(): void
    {
        [$user, $service, $subscription] = $this->makeSubscribedService('sub_renew_1');

        $this->sendWebhookEvent('invoice.payment_succeeded', $this->renewalInvoice('in_renew_1', 'sub_renew_1'), 'evt_renew_1')
            ->assertOk();

        // Receipt vinculado al servicio/usuario correctos, pagado, con totales de Stripe.
        $this->assertDatabaseHas('receipts', [
            'provider_invoice_id' => 'in_renew_1',
            'gateway'             => 'stripe',
            'user_id'             => $user->id,
            'service_id'          => $service->id,
            'status'              => Receipt::STATUS_PAID,
            'total'               => 232.00,
            'subtotal'            => 200.00,
            'tax_amount'          => 32.00,
            'currency'            => 'MXN',
        ]);

        $receipt = Receipt::where('provider_invoice_id', 'in_renew_1')->first();

        // Transaction completada apuntando al PaymentIntent de la renovación.
        $this->assertDatabaseHas('transactions', [
            'receipt_id'              => $receipt->id,
            'provider_transaction_id' => 'pi_renew_1',
            'type'                    => 'payment',
            'status'                  => 'completed',
        ]);

        // CFDI: sin perfil fiscal → Público en General programado.
        $this->assertDatabaseHas('invoices', [
            'receipt_id'         => $receipt->id,
            'service_id'         => $service->id,
            'is_publico_general' => true,
            'cfdi_status'        => 'scheduled',
        ]);
    }

    public function test_duplicate_renewal_events_create_single_receipt(): void
    {
        $this->makeSubscribedService('sub_renew_2');

        // invoice.payment_succeeded e invoice.paid llegan como eventos DISTINTOS
        // (IDs de evento diferentes) para la MISMA invoice.
        $this->sendWebhookEvent('invoice.payment_succeeded', $this->renewalInvoice('in_renew_2', 'sub_renew_2'), 'evt_renew_2a')
            ->assertOk();
        $this->sendWebhookEvent('invoice.paid', $this->renewalInvoice('in_renew_2', 'sub_renew_2'), 'evt_renew_2b')
            ->assertOk();

        $this->assertSame(1, Receipt::where('provider_invoice_id', 'in_renew_2')->count());
        $this->assertSame(
            1,
            \App\Domains\Platform\Models\Transaction::where('provider_transaction_id', 'pi_renew_2')->count()
        );
    }

    public function test_zero_amount_anchor_invoice_creates_no_receipt(): void
    {
        $this->makeSubscribedService('sub_renew_3');

        // Primera invoice $0 de la suscripción anclada con trial_end.
        $invoice = $this->renewalInvoice('in_renew_3', 'sub_renew_3');
        $invoice['amount_paid'] = 0;
        $invoice['total']       = 0;
        $invoice['subtotal']    = 0;
        $invoice['tax']         = 0;

        $this->sendWebhookEvent('invoice.paid', $invoice, 'evt_renew_3')->assertOk();

        $this->assertDatabaseMissing('receipts', ['provider_invoice_id' => 'in_renew_3']);
    }

    public function test_initial_invoice_matching_contract_payment_intent_is_skipped(): void
    {
        [$user, $service] = $this->makeSubscribedService('sub_renew_4');

        // El flujo de contratación ya generó un Receipt con este PI.
        Receipt::factory()->create([
            'user_id'           => $user->id,
            'service_id'        => $service->id,
            'status'            => 'paid',
            'payment_reference' => 'pi_contract_4',
        ]);

        $invoice = $this->renewalInvoice('in_renew_4', 'sub_renew_4');
        $invoice['payment_intent'] = 'pi_contract_4';

        $this->sendWebhookEvent('invoice.paid', $invoice, 'evt_renew_4')->assertOk();

        $this->assertDatabaseMissing('receipts', ['provider_invoice_id' => 'in_renew_4']);
    }

    public function test_renewal_with_default_fiscal_profile_creates_pending_stamp_cfdi(): void
    {
        // El timbrado real (Facturama) se difiere a afterCommit; aquí solo
        // validamos que el registro CFDI se crea con los datos del perfil.
        $this->mock(\App\Domains\Platform\Services\Factura\CfdiService::class)
            ->shouldReceive('stamp')->andReturnNull();

        [$user, $service] = $this->makeSubscribedService('sub_renew_5');

        \App\Domains\Platform\Models\CustomerFiscalProfile::create([
            'uuid'               => (string) Str::uuid(),
            'user_id'            => $user->id,
            'alias'              => 'Principal',
            'rfc'                => 'XAXX010101000',
            'business_name'      => 'AUDIT FISCAL SA DE CV',
            'postal_code'        => '06600',
            'fiscal_regime_code' => '601',
            'cfdi_use_code'      => 'G03',
            'is_default'         => true,
        ]);

        $this->sendWebhookEvent('invoice.paid', $this->renewalInvoice('in_renew_5', 'sub_renew_5'), 'evt_renew_5')
            ->assertOk();

        $receipt = Receipt::where('provider_invoice_id', 'in_renew_5')->first();
        $this->assertNotNull($receipt);

        $this->assertDatabaseHas('invoices', [
            'receipt_id'         => $receipt->id,
            'is_publico_general' => false,
            'rfc'                => 'XAXX010101000',
            'cfdi_status'        => 'pending_stamp',
        ]);
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    /** @return array{0: User, 1: Service, 2: Subscription} */
    private function makeSubscribedService(string $stripeSubId): array
    {
        $user    = User::factory()->create();
        $plan    = ServicePlan::factory()->create();
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status'  => 'active',
        ]);

        $subscription = Subscription::create([
            'uuid'                   => (string) Str::uuid(),
            'user_id'                => $user->id,
            'service_id'             => $service->id,
            'stripe_subscription_id' => $stripeSubId,
            'stripe_customer_id'     => 'cus_test',
            'stripe_price_id'        => 'price_test',
            'name'                   => $plan->name,
            'status'                 => 'active',
            'amount'                 => 232.00,
            'currency'               => 'MXN',
            'billing_cycle'          => 'monthly',
        ]);

        return [$user, $service, $subscription];
    }

    private function renewalInvoice(string $invoiceId, string $subscriptionId): array
    {
        return [
            'id'             => $invoiceId,
            'subscription'   => $subscriptionId,
            'payment_intent' => 'pi_' . substr($invoiceId, 3),
            'charge'         => 'ch_' . substr($invoiceId, 3),
            'currency'       => 'mxn',
            'amount_paid'    => 23200,
            'total'          => 23200,
            'subtotal'       => 20000,
            'tax'            => 3200,
            'billing_reason' => 'subscription_cycle',
            'lines'          => (object) [
                'data' => [(object) [
                    'period' => (object) [
                        'start' => now()->timestamp,
                        'end'   => now()->addMonth()->timestamp,
                    ],
                ]],
            ],
        ];
    }

    private function sendWebhookEvent(string $type, array|object $dataObject, string $eventId): \Illuminate\Testing\TestResponse
    {
        $payload = json_encode([
            'id'       => $eventId,
            'type'     => $type,
            'data'     => ['object' => $dataObject],
            'livemode' => false,
        ]);

        $timestamp = time();
        $sig       = hash_hmac('sha256', "{$timestamp}.{$payload}", self::WEBHOOK_SECRET);

        return $this->call(
            'POST',
            '/api/stripe/webhook',
            [],
            [],
            [],
            ['HTTP_Stripe-Signature' => "t={$timestamp},v1={$sig}", 'CONTENT_TYPE' => 'application/json'],
            $payload
        );
    }
}
