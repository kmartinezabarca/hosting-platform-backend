<?php

namespace Tests\Feature;

use App\Domains\Platform\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Impersonation, 2FA reset and password-reset admin tools.
 *
 * NOTE: requires a real DB (MySQL in CI).
 */
class AdminUserSupportToolsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_impersonate_a_client_and_exchange_the_token(): void
    {
        $admin  = User::factory()->admin()->create();
        $client = User::factory()->create(['role' => 'client']);

        $res = $this->actingAs($admin)
            ->postJson("/api/admin/users/{$client->id}/impersonate")
            ->assertOk()
            ->assertJsonPath('success', true);

        $redirect = $res->json('data.redirect_url');
        $this->assertStringContainsString('impersonation_token=', $redirect);

        parse_str(parse_url($redirect, PHP_URL_QUERY), $query);
        $token = $query['impersonation_token'];

        // The audit trail records the impersonation.
        $this->assertDatabaseHas('audit_logs', [
            'action'    => 'user.impersonated',
            'actor_id'  => $admin->id,
            'target_id' => (string) $client->id,
        ]);

        // The client portal exchanges the one-time token for a session.
        $this->postJson('/api/auth/impersonate/exchange', ['token' => $token])
            ->assertOk()
            ->assertJsonPath('data.impersonated', true)
            ->assertJsonPath('data.user.uuid', $client->uuid);

        // Single-use: a second exchange fails.
        $this->postJson('/api/auth/impersonate/exchange', ['token' => $token])
            ->assertStatus(422);
    }

    public function test_only_clients_can_be_impersonated(): void
    {
        $admin = User::factory()->admin()->create();
        $other = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->postJson("/api/admin/users/{$other->id}/impersonate")
            ->assertStatus(422);
    }

    public function test_support_cannot_impersonate(): void
    {
        $support = User::factory()->create(['role' => 'support']);
        $client  = User::factory()->create(['role' => 'client']);

        $this->actingAs($support)
            ->postJson("/api/admin/users/{$client->id}/impersonate")
            ->assertForbidden();
    }

    public function test_leave_restores_the_original_admin_session(): void
    {
        $admin  = User::factory()->admin()->create();
        $client = User::factory()->create(['role' => 'client']);

        // Simulate the impersonation session: a token whose name carries the
        // impersonator id (as ImpersonationController@exchange would mint).
        $plain = $client->createToken('impersonation:' . $admin->id)->plainTextToken;

        $this->withToken($plain)
            ->postJson('/api/auth/impersonate/leave')
            ->assertOk()
            ->assertJsonPath('data.user.uuid', $admin->uuid);

        $this->assertDatabaseHas('audit_logs', [
            'action'    => 'user.impersonation_ended',
            'actor_id'  => $admin->id,
        ]);
    }

    public function test_leave_without_impersonation_session_is_rejected(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $plain  = $client->createToken('auth_token')->plainTextToken;

        $this->withToken($plain)
            ->postJson('/api/auth/impersonate/leave')
            ->assertStatus(422);
    }

    public function test_admin_can_reset_user_two_factor(): void
    {
        $admin = User::factory()->admin()->create();
        $user  = User::factory()->create([
            'role'               => 'client',
            'two_factor_enabled' => true,
            'two_factor_secret'  => encrypt('SECRET'),
        ]);

        $this->actingAs($admin)
            ->postJson("/api/admin/users/{$user->id}/reset-2fa")
            ->assertOk()
            ->assertJsonPath('success', true);

        $user->refresh();
        $this->assertFalse((bool) $user->two_factor_enabled);
        $this->assertNull($user->two_factor_secret);

        $this->assertDatabaseHas('audit_logs', [
            'action'    => 'user.two_factor_reset',
            'target_id' => (string) $user->id,
        ]);
    }

    public function test_admin_can_send_password_reset(): void
    {
        $admin = User::factory()->admin()->create();
        $user  = User::factory()->create(['role' => 'client']);

        $this->actingAs($admin)
            ->postJson("/api/admin/users/{$user->id}/send-password-reset")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('audit_logs', [
            'action'    => 'user.password_reset_sent',
            'target_id' => (string) $user->id,
        ]);
    }

    public function test_password_reset_tool_requires_admin(): void
    {
        $support = User::factory()->create(['role' => 'support']);
        $user    = User::factory()->create(['role' => 'client']);

        $this->actingAs($support)
            ->postJson("/api/admin/users/{$user->id}/send-password-reset")
            ->assertForbidden();
    }
}
