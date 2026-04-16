<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\PaymentNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class StripeWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK_SECRET = 'whsec_test_secret';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.stripe.webhook_secret' => self::WEBHOOK_SECRET]);

        // Prevent real notification channels (broadcast/DB) from interfering with DB assertions
        Notification::fake();
    }

    // ──────────────────────────────────────────────
    // Signature verification
    // ──────────────────────────────────────────────

    public function test_webhook_rejects_missing_signature(): void
    {
        $response = $this->postJson('/api/stripe/webhook', ['type' => 'payment_intent.succeeded']);

        $response->assertBadRequest()
            ->assertJsonPath('error', 'Invalid signature');
    }

    public function test_webhook_rejects_invalid_signature(): void
    {
        $payload = json_encode(['type' => 'payment_intent.succeeded', 'data' => ['object' => []]]);

        $response = $this->call(
            'POST',
            '/api/stripe/webhook',
            [],
            [],
            [],
            ['HTTP_Stripe-Signature' => 't=12345,v1=invalidsignature', 'CONTENT_TYPE' => 'application/json'],
            $payload
        );

        $response->assertBadRequest()
            ->assertJsonPath('error', 'Invalid signature');
    }

    // ──────────────────────────────────────────────
    // payment_intent.succeeded
    // ──────────────────────────────────────────────

    public function test_payment_intent_succeeded_marks_transaction_completed(): void
    {
        $user = User::factory()->create();

        $invoice = Invoice::factory()->create([
            'user_id' => $user->id,
            'status'  => 'sent',   // valid ENUM: draft|sent|processing|paid|overdue|cancelled|refunded
        ]);

        $transaction = Transaction::factory()->create([
            'user_id'               => $user->id,
            'invoice_id'            => $invoice->id,
            'provider_transaction_id' => 'pi_test_succeeded_001',
            'status'                => 'pending',
        ]);

        $this->sendWebhookEvent('payment_intent.succeeded', [
            'id'       => 'pi_test_succeeded_001',
            'metadata' => (object) [],
        ]);

        $this->assertDatabaseHas('transactions', [
            'id'     => $transaction->id,
            'status' => 'completed',
        ]);

        $this->assertDatabaseHas('invoices', [
            'id'     => $invoice->id,
            'status' => Invoice::STATUS_PAID,
        ]);
    }

    public function test_payment_intent_succeeded_ignores_unknown_payment_intent(): void
    {
        // No transaction exists for this PI — should not throw, should return ok
        $response = $this->sendWebhookEvent('payment_intent.succeeded', [
            'id'       => 'pi_test_unknown_999',
            'metadata' => (object) [],
        ]);

        $response->assertOk()->assertJsonPath('status', 'ok');
    }

    // ──────────────────────────────────────────────
    // payment_intent.payment_failed
    // ──────────────────────────────────────────────

    public function test_payment_intent_failed_marks_transaction_failed(): void
    {
        $user = User::factory()->create();

        $transaction = Transaction::factory()->create([
            'user_id'               => $user->id,
            'provider_transaction_id' => 'pi_test_failed_001',
            'status'                => 'pending',
        ]);

        $this->sendWebhookEvent('payment_intent.payment_failed', [
            'id'                 => 'pi_test_failed_001',
            'last_payment_error' => (object) ['message' => 'Your card was declined.'],
        ]);

        $this->assertDatabaseHas('transactions', [
            'id'             => $transaction->id,
            'status'         => 'failed',
            'failure_reason' => 'Your card was declined.',
        ]);

        // Confirm the failure notification was dispatched to the user
        Notification::assertSentTo($user, PaymentNotification::class);
    }

    // ──────────────────────────────────────────────
    // Unhandled events
    // ──────────────────────────────────────────────

    public function test_unrecognised_event_type_returns_ok(): void
    {
        $response = $this->sendWebhookEvent('charge.refunded', [
            'id' => 'ch_test_refund',
        ]);

        $response->assertOk()->assertJsonPath('status', 'ok');
    }

    // ──────────────────────────────────────────────
    // Helper
    // ──────────────────────────────────────────────

    /**
     * Build and send a Stripe-signed webhook request.
     */
    private function sendWebhookEvent(string $type, array|object $dataObject): \Illuminate\Testing\TestResponse
    {
        $payload = json_encode([
            'id'      => 'evt_' . Str::random(16),
            'type'    => $type,
            'data'    => ['object' => $dataObject],
            'livemode' => false,
        ]);

        $timestamp = time();
        $sigPayload = "{$timestamp}.{$payload}";
        $sig        = hash_hmac('sha256', $sigPayload, self::WEBHOOK_SECRET);
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
