<?php

namespace Tests\Feature;

use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PaymentMethodTest extends TestCase
{
    use RefreshDatabase;

    // ──────────────────────────────────────────────
    // GET /api/payments/methods
    // ──────────────────────────────────────────────

    public function test_unauthenticated_user_cannot_list_payment_methods(): void
    {
        $response = $this->getJson('/api/payments/methods');

        $response->assertUnauthorized();
    }

    public function test_authenticated_user_can_list_their_payment_methods(): void
    {
        $user = User::factory()->create();
        PaymentMethod::factory()->count(2)->create(['user_id' => $user->id, 'is_active' => true]);

        $response = $this->actingAs($user)->getJson('/api/payments/methods');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data');
    }

    public function test_user_cannot_see_other_users_payment_methods(): void
    {
        $user  = User::factory()->create();
        $other = User::factory()->create();
        PaymentMethod::factory()->count(3)->create(['user_id' => $other->id, 'is_active' => true]);

        $response = $this->actingAs($user)->getJson('/api/payments/methods');

        $response->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_inactive_payment_methods_are_not_listed(): void
    {
        $user = User::factory()->create();
        PaymentMethod::factory()->create(['user_id' => $user->id, 'is_active' => true]);
        PaymentMethod::factory()->create(['user_id' => $user->id, 'is_active' => false]);

        $response = $this->actingAs($user)->getJson('/api/payments/methods');

        $response->assertOk()
            ->assertJsonCount(1, 'data');
    }

    // ──────────────────────────────────────────────
    // POST /api/payments/methods — validation
    // ──────────────────────────────────────────────

    public function test_adding_payment_method_requires_authentication(): void
    {
        $response = $this->postJson('/api/payments/methods', [
            'stripe_payment_method_id' => 'pm_test_abc',
        ]);

        $response->assertUnauthorized();
    }

    public function test_stripe_payment_method_id_must_start_with_pm(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/payments/methods', [
            'stripe_payment_method_id' => 'not_a_pm_id',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['stripe_payment_method_id']);
    }

    public function test_stripe_payment_method_id_is_required(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/payments/methods', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['stripe_payment_method_id']);
    }

    public function test_adding_payment_method_delegates_to_payment_service(): void
    {
        $user          = User::factory()->create();
        $paymentMethod = PaymentMethod::factory()->make([
            'user_id'  => $user->id,
            'uuid'     => (string) Str::uuid(),
            'is_active' => true,
        ]);

        $mock = $this->mock(PaymentService::class);
        $mock->shouldReceive('attachPaymentMethod')
            ->once()
            ->with($user, 'pm_test_validtoken', false, null)
            ->andReturn($paymentMethod);

        $response = $this->actingAs($user)->postJson('/api/payments/methods', [
            'stripe_payment_method_id' => 'pm_test_validtoken',
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true);
    }

    public function test_duplicate_payment_method_returns_422_with_friendly_message(): void
    {
        $user = User::factory()->create();

        $mock = $this->mock(PaymentService::class);
        $mock->shouldReceive('attachPaymentMethod')
            ->once()
            ->andThrow(new \RuntimeException('payment_method_already_saved'));

        $response = $this->actingAs($user)->postJson('/api/payments/methods', [
            'stripe_payment_method_id' => 'pm_test_dup',
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Este método de pago ya está registrado en tu cuenta.');
    }

    // ──────────────────────────────────────────────
    // DELETE /api/payments/methods/{id}
    // ──────────────────────────────────────────────

    public function test_user_can_delete_their_own_payment_method(): void
    {
        $user   = User::factory()->create();
        $method = PaymentMethod::factory()->create(['user_id' => $user->id]);

        $mock = $this->mock(PaymentService::class);
        $mock->shouldReceive('detachPaymentMethod')->once()->andReturnNull();

        $response = $this->actingAs($user)->deleteJson("/api/payments/methods/{$method->uuid}");

        $response->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_user_cannot_delete_another_users_payment_method(): void
    {
        $user   = User::factory()->create();
        $other  = User::factory()->create();
        $method = PaymentMethod::factory()->create(['user_id' => $other->id]);

        $response = $this->actingAs($user)->deleteJson("/api/payments/methods/{$method->uuid}");

        $response->assertNotFound();
    }
}
