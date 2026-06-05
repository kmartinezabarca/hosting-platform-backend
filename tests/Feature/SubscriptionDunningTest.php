<?php

namespace Tests\Feature;

use App\Domains\Platform\Models\Service;
use App\Domains\Platform\Models\ServicePlan;
use App\Domains\Platform\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Flujo de morosidad (Fase 2): gracia tras pago fallido, suspensión automática
 * al vencer la gracia, y limpieza/reactivación al pagar.
 */
class SubscriptionDunningTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK_SECRET = 'whsec_test_secret';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.stripe.webhook_secret' => self::WEBHOOK_SECRET,
            'billing.grace_period_days'      => 5,
        ]);
        Notification::fake();
    }

    public function test_invoice_payment_failed_opens_grace_without_suspending(): void
    {
        [$user, $service, $subscription] = $this->makeSubscribedService('sub_dun_1', 'active');

        $this->sendWebhookEvent('invoice.payment_failed', [
            'id'           => 'in_fail_1',
            'subscription' => 'sub_dun_1',
        ], 'evt_dun_fail_1')->assertOk();

        $subscription->refresh();
        $service->refresh();

        $this->assertSame('past_due', $subscription->status);
        $this->assertNotNull($subscription->grace_period_ends_at);
        $this->assertTrue($subscription->grace_period_ends_at->isFuture());
        // El servicio NO se suspende durante la gracia.
        $this->assertSame('active', $service->status);
        $this->assertNotNull($service->grace_period_ends_at);
    }

    public function test_process_overdue_suspends_when_grace_expired(): void
    {
        [$user, $service, $subscription] = $this->makeSubscribedService('sub_dun_2', 'past_due');
        $subscription->update([
            'grace_period_ends_at' => now()->subDay(),
            'suspended_at'         => null,
        ]);

        Artisan::call('subscriptions:process-overdue');

        $service->refresh();
        $subscription->refresh();

        $this->assertSame('suspended', $service->status);
        $this->assertSame('payment_overdue', $service->suspension_reason);
        $this->assertNotNull($subscription->suspended_at);
    }

    public function test_invoice_paid_clears_grace_and_reactivates_service(): void
    {
        [$user, $service, $subscription] = $this->makeSubscribedService('sub_dun_3', 'past_due');
        $subscription->update(['grace_period_ends_at' => now()->addDay(), 'suspended_at' => now()]);
        $service->update(['status' => 'suspended', 'suspension_reason' => 'payment_overdue']);

        $this->sendWebhookEvent('invoice.paid', [
            'id'           => 'in_paid_3',
            'subscription' => 'sub_dun_3',
            'lines'        => (object) [
                'data' => [(object) ['period' => (object) ['end' => now()->addMonth()->timestamp]]],
            ],
        ], 'evt_dun_paid_3')->assertOk();

        $subscription->refresh();
        $service->refresh();

        $this->assertSame('active', $subscription->status);
        $this->assertNull($subscription->grace_period_ends_at);
        $this->assertSame('active', $service->status);
        $this->assertNull($service->suspension_reason);
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    /**
     * @return array{0: User, 1: Service, 2: Subscription}
     */
    private function makeSubscribedService(string $stripeSubId, string $subStatus): array
    {
        $user    = User::factory()->create();
        $plan    = ServicePlan::factory()->create(); // sin provisioner → suspend no toca proveedor
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
            'status'                 => $subStatus,
            'amount'                 => 100.00,
            'currency'               => 'MXN',
            'billing_cycle'          => 'monthly',
        ]);

        return [$user, $service, $subscription];
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
