<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    // ──────────────────────────────────────────────
    // Registration
    // ──────────────────────────────────────────────

    public function test_user_can_register_with_valid_data(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'first_name'            => 'Ana',
            'last_name'             => 'García',
            'email'                 => 'ana@example.com',
            'password'              => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);

        $response->assertCreated()
            ->assertJsonStructure(['access_token', 'token_type', 'user' => ['uuid', 'email', 'role']]);

        $this->assertDatabaseHas('users', [
            'email'      => 'ana@example.com',
            'first_name' => 'Ana',
            'role'       => 'client',
        ]);
    }

    public function test_registration_fails_with_duplicate_email(): void
    {
        User::factory()->create(['email' => 'dup@example.com']);

        $response = $this->postJson('/api/auth/register', [
            'first_name'            => 'Other',
            'last_name'             => 'User',
            'email'                 => 'dup@example.com',
            'password'              => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_registration_requires_password_confirmation(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'first_name' => 'Ana',
            'last_name'  => 'García',
            'email'      => 'ana@example.com',
            'password'   => 'Password123!',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);
    }

    public function test_registration_requires_minimum_8_char_password(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'first_name'            => 'Ana',
            'last_name'             => 'García',
            'email'                 => 'ana@example.com',
            'password'              => 'short',
            'password_confirmation' => 'short',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);
    }

    // ──────────────────────────────────────────────
    // Login
    // ──────────────────────────────────────────────

    public function test_user_can_login_with_valid_credentials(): void
    {
        // The password cast ('hashed') will hash the plain text automatically — pass plain text.
        $user = User::factory()->create([
            'email'    => 'test@example.com',
            'password' => 'Password123!',
            'status'   => 'active',
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email'    => 'test@example.com',
            'password' => 'Password123!',
        ]);

        // The token is set as an HTTP-only cookie; the response body contains message + user
        $response->assertOk()
            ->assertJsonStructure(['message', 'user' => ['uuid', 'email', 'role']]);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        User::factory()->create([
            'email'    => 'test@example.com',
            'password' => 'CorrectPassword!',
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email'    => 'test@example.com',
            'password' => 'WrongPassword!',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_login_fails_with_nonexistent_email(): void
    {
        // Ensure at least one user exists so ActivityLog::record() has a valid DB connection
        User::factory()->create();

        $response = $this->postJson('/api/auth/login', [
            'email'    => 'nobody@example.com',
            'password' => 'AnyPassword!',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_login_is_rate_limited_after_5_attempts(): void
    {
        User::factory()->create(['email' => 'victim@example.com']);

        // The array cache persists across postJson() calls within the same test process
        // because RateLimiter is a singleton sharing the same ArrayStore instance.
        // Make 5 failed attempts to exhaust the throttle:5,1 budget...
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login', [
                'email'    => 'victim@example.com',
                'password' => 'WrongPassword!',
            ])->assertUnprocessable(); // 422 — wrong credentials, NOT throttled yet
        }

        // ...then the 6th request within the same minute must be blocked
        $this->postJson('/api/auth/login', [
            'email'    => 'victim@example.com',
            'password' => 'WrongPassword!',
        ])->assertStatus(429);
    }

    // ──────────────────────────────────────────────
    // Authenticated routes
    // ──────────────────────────────────────────────

    public function test_auth_me_returns_current_user(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/auth/me');

        // me() returns {success: true, data: {uuid, email, ...}}
        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.email', $user->email);
    }

    public function test_auth_me_requires_authentication(): void
    {
        $response = $this->getJson('/api/auth/me');

        $response->assertUnauthorized();
    }

    public function test_user_can_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/auth/logout');

        $response->assertOk();
    }
}
