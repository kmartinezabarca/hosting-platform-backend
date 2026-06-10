<?php

namespace Tests\Feature;

use App\Domains\Platform\Models\ProvisioningJob;
use App\Domains\Platform\Models\Service;
use App\Domains\Platform\Models\ServicePlan;
use App\Domains\Platform\Models\Subscription;
use App\Domains\Platform\Services\ProvisioningService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Regresiones del audit 2026-06-10:
 *
 *  1. services.status no incluía 'cancelled' en el ENUM → la cancelación
 *     inmediata del cliente y el webhook customer.subscription.deleted
 *     lanzaban QueryException (data truncated) en strict mode.
 *  2. El webhook escribía subscriptions.status='cancelled' (doble L) cuando
 *     el ENUM define 'canceled' (una L).
 *  3. ProvisioningService::runJob marcaba el job como succeeded vía la guarda
 *     de idempotencia pero dejaba el servicio en 'failed' para siempre cuando
 *     un paso tardío (FRP/DNS) había fallado tras crear el servidor.
 */
class ServiceCancellationAndRepairTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK_SECRET = 'whsec_test_secret';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.stripe.webhook_secret' => self::WEBHOOK_SECRET]);
        Notification::fake();
    }

    public function test_subscription_deleted_webhook_cancels_subscription_and_service(): void
    {
        $user    = User::factory()->create();
        $plan    = ServicePlan::factory()->create();
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status'  => 'active',
        ]);

        Subscription::create([
            'uuid'                   => (string) Str::uuid(),
            'user_id'                => $user->id,
            'service_id'             => $service->id,
            'stripe_subscription_id' => 'sub_del_test_1',
            'stripe_customer_id'     => 'cus_test',
            'stripe_price_id'        => 'price_test',
            'name'                   => $plan->name,
            'status'                 => 'active',
            'amount'                 => 100.00,
            'currency'               => 'MXN',
            'billing_cycle'          => 'monthly',
        ]);

        $this->sendWebhookEvent('customer.subscription.deleted', [
            'id' => 'sub_del_test_1',
        ])->assertOk();

        $this->assertDatabaseHas('subscriptions', [
            'stripe_subscription_id' => 'sub_del_test_1',
            'status'                 => 'canceled',
        ]);
        $this->assertDatabaseHas('services', [
            'id'     => $service->id,
            'status' => 'cancelled',
        ]);
    }

    public function test_client_immediate_cancel_marks_service_cancelled(): void
    {
        $user    = User::factory()->create();
        $plan    = ServicePlan::factory()->create();
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status'  => 'active',
        ]);

        // Sin suscripción recurrente → cancelación inmediata pura de BD.
        $this->actingAs($user)
            ->postJson("/api/services/{$service->uuid}/cancel", ['immediate' => true])
            ->assertOk();

        $this->assertDatabaseHas('services', [
            'id'     => $service->id,
            'status' => 'cancelled',
        ]);
    }

    public function test_provisioning_retry_repairs_failed_service_already_provisioned(): void
    {
        $user    = User::factory()->create();
        $plan    = ServicePlan::factory()->create();
        $service = Service::factory()->create([
            'user_id'               => $user->id,
            'plan_id'               => $plan->id,
            // Escenario real: el servidor SÍ se creó en Pterodactyl, pero un paso
            // tardío falló y provision() dejó el servicio en 'failed'.
            'status'                => 'failed',
            'pterodactyl_server_id' => 4242,
        ]);

        $job = ProvisioningJob::create([
            'service_id'   => $service->id,
            'provider'     => ProvisioningJob::PROVIDER_PTERODACTYL,
            'status'       => ProvisioningJob::STATUS_PENDING,
            'available_at' => now(),
        ]);

        $ok = app(ProvisioningService::class)->runJob($job);

        $this->assertTrue($ok);
        $this->assertDatabaseHas('provisioning_jobs', [
            'id'     => $job->id,
            'status' => ProvisioningJob::STATUS_SUCCEEDED,
        ]);
        $this->assertDatabaseHas('services', [
            'id'                  => $service->id,
            'status'              => 'active',
            'provisioning_status' => 'succeeded',
        ]);
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function sendWebhookEvent(string $type, array|object $dataObject): \Illuminate\Testing\TestResponse
    {
        $payload = json_encode([
            'id'       => 'evt_' . Str::random(16),
            'type'     => $type,
            'data'     => ['object' => $dataObject],
            'livemode' => false,
        ]);

        $timestamp  = time();
        $sig        = hash_hmac('sha256', "{$timestamp}.{$payload}", self::WEBHOOK_SECRET);
        $sigHeader  = "t={$timestamp},v1={$sig}";

        return $this->call(
            'POST',
            '/api/stripe/webhook',
            [],
            [],
            [],
            ['HTTP_Stripe-Signature' => $sigHeader, 'CONTENT_TYPE' => 'application/json'],
            $payload
        );
    }
}
