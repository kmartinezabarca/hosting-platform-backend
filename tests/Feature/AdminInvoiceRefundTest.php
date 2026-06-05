<?php

namespace Tests\Feature;

use App\Domains\Platform\Models\Receipt;
use App\Domains\Platform\Models\Transaction;
use App\Domains\Platform\Services\PaymentService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Invoice (Receipt) refunds. The Stripe call is isolated behind PaymentService,
 * which is swapped for a fake in the happy-path test so no network is hit.
 *
 * NOTE: requires a real DB (MySQL in CI).
 */
class AdminInvoiceRefundTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_refund_a_paid_invoice(): void
    {
        $admin   = User::factory()->admin()->create();
        $client  = User::factory()->create(['role' => 'client']);
        $receipt = Receipt::factory()->paid()->create(['user_id' => $client->id]);

        // Swap the Stripe-backed service for a fake.
        $this->mock(PaymentService::class, function ($mock) {
            $mock->shouldReceive('refundReceipt')->once()->andReturn([
                'refund'      => (object) ['id' => 're_test_123'],
                'transaction' => new Transaction(),
                'amount'      => 116.00,
                'currency'    => 'MXN',
            ]);
        });

        $this->actingAs($admin)
            ->postJson("/api/admin/invoices/{$receipt->id}/refund", ['reason' => 'Cobro duplicado'])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('audit_logs', [
            'action'    => 'invoice.refunded',
            'target_id' => (string) $receipt->id,
        ]);
    }

    public function test_refund_requires_a_reason(): void
    {
        $admin   = User::factory()->admin()->create();
        $receipt = Receipt::factory()->paid()->create();

        $this->actingAs($admin)
            ->postJson("/api/admin/invoices/{$receipt->id}/refund", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reason']);
    }

    public function test_cannot_refund_an_unpaid_invoice(): void
    {
        $admin   = User::factory()->admin()->create();
        $receipt = Receipt::factory()->create(['status' => Receipt::STATUS_SENT]);

        $this->actingAs($admin)
            ->postJson("/api/admin/invoices/{$receipt->id}/refund", ['reason' => 'x'])
            ->assertStatus(422);
    }

    public function test_cannot_refund_twice(): void
    {
        $admin   = User::factory()->admin()->create();
        $receipt = Receipt::factory()->create(['status' => Receipt::STATUS_REFUNDED]);

        $this->actingAs($admin)
            ->postJson("/api/admin/invoices/{$receipt->id}/refund", ['reason' => 'x'])
            ->assertStatus(422);
    }

    public function test_support_cannot_refund(): void
    {
        $support = User::factory()->create(['role' => 'support']);
        $receipt = Receipt::factory()->paid()->create();

        $this->actingAs($support)
            ->postJson("/api/admin/invoices/{$receipt->id}/refund", ['reason' => 'x'])
            ->assertForbidden();
    }
}
