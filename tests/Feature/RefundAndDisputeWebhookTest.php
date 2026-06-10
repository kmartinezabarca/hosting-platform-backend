<?php

namespace Tests\Feature;

use App\Domains\Platform\Models\Receipt;
use App\Domains\Platform\Models\Transaction;
use App\Domains\Platform\Services\PaymentService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class RefundAndDisputeWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK_SECRET = 'whsec_test_secret';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.stripe.webhook_secret' => self::WEBHOOK_SECRET]);
        Notification::fake();
    }

    /** @return array{0: User, 1: Receipt} */
    private function makePaidReceipt(string $paymentIntentId, float $total = 232.00): array
    {
        $user    = User::factory()->create();
        $receipt = Receipt::factory()->create([
            'user_id'           => $user->id,
            'status'            => Receipt::STATUS_PAID,
            'payment_reference' => $paymentIntentId,
            'currency'          => 'MXN',
            'subtotal'          => round($total / 1.16, 2),
            'tax_amount'        => round($total - $total / 1.16, 2),
            'total'             => $total,
        ]);

        Transaction::factory()->completed()->create([
            'user_id'                 => $user->id,
            'receipt_id'              => $receipt->id,
            'provider_transaction_id' => $paymentIntentId,
            'type'                    => 'payment',
            'amount'                  => $total,
        ]);

        return [$user, $receipt];
    }

    // ── charge.refunded ────────────────────────────────────────────────────────

    public function test_full_refund_webhook_marks_receipt_refunded_and_records_transaction(): void
    {
        [, $receipt] = $this->makePaidReceipt('pi_ref_1');

        $this->sendWebhookEvent('charge.refunded', [
            'id'              => 'ch_ref_1',
            'payment_intent'  => 'pi_ref_1',
            'currency'        => 'mxn',
            'amount_refunded' => 23200,
            'refunds'         => (object) ['data' => [(object) ['id' => 're_ref_1']]],
        ], 'evt_ref_1')->assertOk();

        $this->assertDatabaseHas('receipts', ['id' => $receipt->id, 'status' => Receipt::STATUS_REFUNDED]);
        $this->assertDatabaseHas('transactions', [
            'receipt_id'              => $receipt->id,
            'type'                    => 'refund',
            'provider_transaction_id' => 're_ref_1',
            'amount'                  => 232.00,
            'status'                  => 'completed',
        ]);
    }

    public function test_duplicate_refund_webhook_is_idempotent(): void
    {
        [, $receipt] = $this->makePaidReceipt('pi_ref_2');

        $payload = [
            'id'              => 'ch_ref_2',
            'payment_intent'  => 'pi_ref_2',
            'currency'        => 'mxn',
            'amount_refunded' => 23200,
            'refunds'         => (object) ['data' => [(object) ['id' => 're_ref_2']]],
        ];

        // Mismo charge entregado dos veces con event ids distintos.
        $this->sendWebhookEvent('charge.refunded', $payload, 'evt_ref_2a')->assertOk();
        $this->sendWebhookEvent('charge.refunded', $payload, 'evt_ref_2b')->assertOk();

        $this->assertSame(1, Transaction::where('receipt_id', $receipt->id)->where('type', 'refund')->count());
    }

    public function test_partial_refund_keeps_receipt_paid_and_records_delta(): void
    {
        [, $receipt] = $this->makePaidReceipt('pi_ref_3');

        // Primer reembolso parcial de 100.00.
        $this->sendWebhookEvent('charge.refunded', [
            'id'              => 'ch_ref_3',
            'payment_intent'  => 'pi_ref_3',
            'currency'        => 'mxn',
            'amount_refunded' => 10000,
        ], 'evt_ref_3a')->assertOk();

        $this->assertDatabaseHas('receipts', ['id' => $receipt->id, 'status' => Receipt::STATUS_PAID]);

        // Segundo reembolso parcial: amount_refunded acumulado 232.00 → delta 132.00.
        $this->sendWebhookEvent('charge.refunded', [
            'id'              => 'ch_ref_3',
            'payment_intent'  => 'pi_ref_3',
            'currency'        => 'mxn',
            'amount_refunded' => 23200,
        ], 'evt_ref_3b')->assertOk();

        $refunds = Transaction::where('receipt_id', $receipt->id)->where('type', 'refund')->pluck('amount');
        $this->assertEqualsCanonicalizing([100.00, 132.00], $refunds->map(fn ($a) => (float) $a)->all());
        $this->assertDatabaseHas('receipts', ['id' => $receipt->id, 'status' => Receipt::STATUS_REFUNDED]);
    }

    // ── charge.dispute.* ───────────────────────────────────────────────────────

    public function test_dispute_created_marks_receipt_and_transaction_disputed(): void
    {
        [, $receipt] = $this->makePaidReceipt('pi_disp_1');

        $this->sendWebhookEvent('charge.dispute.created', [
            'id'             => 'dp_1',
            'charge'         => 'ch_disp_1',
            'payment_intent' => 'pi_disp_1',
            'amount'         => 23200,
            'currency'       => 'mxn',
            'status'         => 'needs_response',
        ], 'evt_disp_1')->assertOk();

        $this->assertDatabaseHas('receipts', ['id' => $receipt->id, 'status' => 'disputed']);
        $this->assertDatabaseHas('transactions', [
            'receipt_id' => $receipt->id,
            'type'       => 'payment',
            'status'     => 'disputed',
        ]);
    }

    public function test_dispute_won_restores_paid_state(): void
    {
        [, $receipt] = $this->makePaidReceipt('pi_disp_2');

        $this->sendWebhookEvent('charge.dispute.created', [
            'id' => 'dp_2', 'charge' => 'ch_disp_2', 'payment_intent' => 'pi_disp_2',
            'amount' => 23200, 'currency' => 'mxn', 'status' => 'under_review',
        ], 'evt_disp_2a')->assertOk();

        $this->sendWebhookEvent('charge.dispute.closed', [
            'id' => 'dp_2', 'charge' => 'ch_disp_2', 'payment_intent' => 'pi_disp_2',
            'amount' => 23200, 'currency' => 'mxn', 'status' => 'won',
        ], 'evt_disp_2b')->assertOk();

        $this->assertDatabaseHas('receipts', ['id' => $receipt->id, 'status' => Receipt::STATUS_PAID]);
        $this->assertDatabaseHas('transactions', [
            'receipt_id' => $receipt->id, 'type' => 'payment', 'status' => 'completed',
        ]);
    }

    public function test_dispute_lost_records_chargeback_and_marks_refunded(): void
    {
        [, $receipt] = $this->makePaidReceipt('pi_disp_3');

        $this->sendWebhookEvent('charge.dispute.created', [
            'id' => 'dp_3', 'charge' => 'ch_disp_3', 'payment_intent' => 'pi_disp_3',
            'amount' => 23200, 'currency' => 'mxn', 'status' => 'under_review',
        ], 'evt_disp_3a')->assertOk();

        $this->sendWebhookEvent('charge.dispute.closed', [
            'id' => 'dp_3', 'charge' => 'ch_disp_3', 'payment_intent' => 'pi_disp_3',
            'amount' => 23200, 'currency' => 'mxn', 'status' => 'lost',
        ], 'evt_disp_3b')->assertOk();

        $this->assertDatabaseHas('receipts', ['id' => $receipt->id, 'status' => Receipt::STATUS_REFUNDED]);
        $this->assertDatabaseHas('transactions', [
            'receipt_id'              => $receipt->id,
            'type'                    => 'chargeback',
            'provider_transaction_id' => 'dp_3',
            'amount'                  => 232.00,
        ]);
    }

    // ── Guard de doble-reembolso en el servicio (admin double-click) ───────────

    public function test_refund_service_rejects_already_refunded_receipt(): void
    {
        [, $receipt] = $this->makePaidReceipt('pi_guard_1');
        $receipt->update(['status' => Receipt::STATUS_REFUNDED]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ya fue reembolsada');

        app(PaymentService::class)->refundReceipt($receipt->fresh());
    }

    public function test_refund_service_rejects_amount_over_remaining(): void
    {
        [$user, $receipt] = $this->makePaidReceipt('pi_guard_2');

        // Ya hay un reembolso parcial de 200.00 registrado (p. ej. por webhook).
        Transaction::factory()->completed()->create([
            'user_id'                 => $user->id,
            'receipt_id'              => $receipt->id,
            'provider_transaction_id' => 're_guard_2',
            'type'                    => 'refund',
            'amount'                  => 200.00,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('excede lo reembolsable');

        // Quedan 32.00 reembolsables; pedir 100.00 debe rechazarse ANTES de llamar a Stripe.
        app(PaymentService::class)->refundReceipt($receipt->fresh(), 100.00);
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

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
