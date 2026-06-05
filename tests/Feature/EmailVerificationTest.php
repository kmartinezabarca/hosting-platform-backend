<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_verified_user_gets_already_verified_response(): void
    {
        Notification::fake();

        $user = User::factory()->create(['email_verified_at' => now()]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/profile/email/verification-notification');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'El correo ya está verificado.')
            ->assertJsonPath('data.already_verified', true);

        Notification::assertNothingSent();
    }

    public function test_unverified_user_can_request_email_verification_notification(): void
    {
        Notification::fake();

        $user = User::factory()->create(['email_verified_at' => null]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/profile/email/verification-notification');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Correo de verificación enviado.')
            ->assertJsonPath('data.sent', true);

        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_verification_notification_is_throttled(): void
    {
        Notification::fake();

        $user = User::factory()->create(['email_verified_at' => null]);

        for ($i = 0; $i < 6; $i++) {
            $this->actingAs($user, 'sanctum')
                ->postJson('/api/profile/email/verification-notification')
                ->assertOk();
        }

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/profile/email/verification-notification')
            ->assertStatus(429)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Demasiados intentos. Espera un minuto antes de solicitar otro correo de verificación.');
    }

    public function test_signed_verification_route_marks_email_as_verified_and_returns_json(): void
    {
        $user = User::factory()->create(['email_verified_at' => null]);

        $url = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->getEmailForVerification())]
        );

        $response = $this->getJson($url);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Correo verificado correctamente.')
            ->assertJsonPath('data.verified', true);

        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_signed_verification_route_redirects_browser_to_frontend(): void
    {
        config(['app.frontend_url' => 'https://frontend.example.test']);

        $user = User::factory()->create(['email_verified_at' => null]);

        $url = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->getEmailForVerification())]
        );

        $this->get($url)
            ->assertRedirect('https://frontend.example.test/profile?email_verified=1');

        $this->assertNotNull($user->fresh()->email_verified_at);
    }
}
