<?php

namespace Tests\Feature;

use App\Domains\Platform\Models\NewsletterSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ContactAndNewsletterTurnstileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.turnstile.secret', 'test-secret');
        Http::preventStrayRequests();
    }

    public function test_contact_request_requires_valid_turnstile_token(): void
    {
        Http::fake([
            'https://challenges.cloudflare.com/turnstile/v0/siteverify' => Http::response([
                'success' => false,
            ]),
        ]);

        $response = $this->postJson('/api/contact', [
            'name' => 'Ana Lopez',
            'email' => 'ana@example.com',
            'message' => 'Necesito ayuda con hosting web.',
            'cf-turnstile-response' => 'invalid-token',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['cf-turnstile-response']);

        $this->assertDatabaseCount('contact_requests', 0);
    }

    public function test_contact_request_is_stored_after_turnstile_verification(): void
    {
        Http::fake([
            'https://challenges.cloudflare.com/turnstile/v0/siteverify' => Http::response([
                'success' => true,
            ]),
        ]);

        $response = $this->postJson('/api/contact', [
            'name' => 'Ana Lopez',
            'email' => ' ANA@EXAMPLE.COM ',
            'phone' => '5551234567',
            'company' => 'Roke Test',
            'service' => 'Hosting Web',
            'message' => 'Necesito ayuda con hosting web.',
            'cf-turnstile-response' => 'valid-token',
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('contact_requests', [
            'email' => 'ana@example.com',
            'service' => 'Hosting Web',
        ]);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://challenges.cloudflare.com/turnstile/v0/siteverify'
                && $request['secret'] === 'test-secret'
                && $request['response'] === 'valid-token'
                && $request['remoteip'] !== null;
        });
    }

    public function test_newsletter_subscription_is_idempotent(): void
    {
        Http::fake([
            'https://challenges.cloudflare.com/turnstile/v0/siteverify' => Http::response([
                'success' => true,
            ]),
        ]);

        NewsletterSubscription::create([
            'email' => 'ana@example.com',
            'is_active' => false,
        ]);

        $payload = [
            'email' => ' ANA@EXAMPLE.COM ',
            'cf-turnstile-response' => 'valid-token',
        ];

        $this->postJson('/api/newsletter/subscribe', $payload)
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->postJson('/api/newsletter/subscribe', $payload)
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseCount('newsletter_subscriptions', 1);
        $this->assertDatabaseHas('newsletter_subscriptions', [
            'email' => 'ana@example.com',
            'is_active' => true,
        ]);
    }
}
