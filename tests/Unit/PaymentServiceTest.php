<?php

namespace Tests\Unit;

use App\Models\Invoice;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
    }

    public function test_get_or_create_stripe_customer_returns_existing_id(): void
    {
        $user = User::factory()->create([
            'stripe_customer_id' => 'cus_existing123',
        ]);

        $service = app(PaymentService::class);
        $customerId = $service->getOrCreateStripeCustomer($user);

        $this->assertEquals('cus_existing123', $customerId);
    }

    public function test_get_or_create_stripe_customer_creates_new_customer(): void
    {
        $user = User::factory()->create([
            'stripe_customer_id' => null,
            'email' => 'new@example.com',
        ]);

        $service = app(PaymentService::class);
        $customerId = $service->getOrCreateStripeCustomer($user);

        $this->assertNotNull($customerId);
        $this->assertStringStartsWith('cus_', $customerId);

        $user->refresh();
        $this->assertEquals($customerId, $user->stripe_customer_id);
    }

    public function test_create_payment_intent_creates_valid_intent(): void
    {
        $user = User::factory()->create([
            'stripe_customer_id' => 'cus_test123',
        ]);

        $service = app(PaymentService::class);
        $intent = $service->createPaymentIntent(
            $user,
            5000,
            'MXN',
            ['invoice_id' => '123']
        );

        $this->assertNotNull($intent->id);
        $this->assertEquals(5000, $intent->amount);
        $this->assertEquals('mxn', $intent->currency);
    }

    public function test_record_transaction_creates_valid_record(): void
    {
        $user = User::factory()->create();
        $invoice = Invoice::factory()->create(['user_id' => $user->id]);

        $paymentMethod = PaymentMethod::factory()->create(['user_id' => $user->id]);

        $service = app(PaymentService::class);
        $transaction = $service->recordTransaction(
            $user,
            $invoice,
            'pi_test123',
            100.00,
            'MXN',
            $paymentMethod->id,
            ['card' => 'last4']
        );

        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id,
            'invoice_id' => $invoice->id,
            'provider_transaction_id' => 'pi_test123',
            'amount' => 100.00,
            'status' => 'completed',
        ]);
    }

    public function test_get_user_stats_returns_valid_stats(): void
    {
        $user = User::factory()->create();

        PaymentMethod::factory()->count(2)->create(['user_id' => $user->id, 'is_active' => true]);

        $invoice = Invoice::factory()->create([
            'user_id' => $user->id,
            'status' => 'sent',
            'total' => 150.00,
        ]);

        $service = app(PaymentService::class);
        $stats = $service->getUserStats($user);

        $this->assertArrayHasKey('total_spent', $stats);
        $this->assertArrayHasKey('transactions_count', $stats);
        $this->assertArrayHasKey('payment_methods_count', $stats);
        $this->assertEquals(2, $stats['payment_methods_count']);
    }

    public function test_attach_payment_method_throws_for_duplicate(): void
    {
        $user = User::factory()->create();
        PaymentMethod::factory()->create([
            'user_id' => $user->id,
            'stripe_payment_method_id' => 'pm_dupe123',
        ]);

        $service = app(PaymentService::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('payment_method_already_saved');

        $service->attachPaymentMethod($user, 'pm_dupe123', false, null);
    }
}
