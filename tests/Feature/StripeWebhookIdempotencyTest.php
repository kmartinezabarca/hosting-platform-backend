<?php

namespace Tests\Feature;

use App\Domains\Platform\Models\StripeEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Idempotencia del webhook por event_id (tabla stripe_events).
 */
class StripeWebhookIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK_SECRET = 'whsec_test_secret';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.stripe.webhook_secret' => self::WEBHOOK_SECRET]);
        Notification::fake();
    }

    public function test_first_delivery_is_processed_and_recorded(): void
    {
        $eventId = 'evt_idem_001';

        $response = $this->sendWebhookEvent('payment_intent.succeeded', [
            'id'       => 'pi_idem_unknown',
            'metadata' => (object) [],
        ], $eventId);

        $response->assertOk()->assertJsonPath('status', 'ok');

        $this->assertDatabaseHas('stripe_events', [
            'event_id' => $eventId,
            'status'   => StripeEvent::STATUS_PROCESSED,
        ]);
    }

    public function test_duplicate_delivery_is_ignored(): void
    {
        $eventId = 'evt_idem_dup_001';
        $object  = ['id' => 'pi_idem_unknown_2', 'metadata' => (object) []];

        $first  = $this->sendWebhookEvent('payment_intent.succeeded', $object, $eventId);
        $second = $this->sendWebhookEvent('payment_intent.succeeded', $object, $eventId);

        $first->assertOk()->assertJsonPath('status', 'ok');
        $second->assertOk()->assertJsonPath('status', 'duplicate');

        // Sólo existe una fila para ese event_id (procesado una sola vez).
        $this->assertSame(1, StripeEvent::where('event_id', $eventId)->count());
    }

    /**
     * Envía un webhook firmado con un event_id explícito (para probar duplicados).
     */
    private function sendWebhookEvent(string $type, array|object $dataObject, string $eventId): \Illuminate\Testing\TestResponse
    {
        $payload = json_encode([
            'id'       => $eventId,
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
