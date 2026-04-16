<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verifies that the UserPolicy gates admin user-management actions correctly.
 */
class AdminUserPolicyTest extends TestCase
{
    use RefreshDatabase;

    // ──────────────────────────────────────────────
    // Access control — non-admin users
    // ──────────────────────────────────────────────

    public function test_client_cannot_access_admin_user_list(): void
    {
        $client = User::factory()->create(['role' => 'client']);

        $this->actingAs($client)->getJson('/api/admin/users')
            ->assertForbidden();
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/admin/users')->assertUnauthorized();
    }

    // ──────────────────────────────────────────────
    // Admin can manage users
    // ──────────────────────────────────────────────

    public function test_admin_can_list_users(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->count(3)->create();

        $response = $this->actingAs($admin)->getJson('/api/admin/users');

        $response->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_admin_can_create_a_client_user(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->postJson('/api/admin/users', [
            'first_name' => 'New',
            'last_name'  => 'Client',
            'email'      => 'newclient@example.com',
            'password'   => 'Password123!',
            'role'       => 'client',
            'status'     => 'active',
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('users', ['email' => 'newclient@example.com']);
    }

    public function test_admin_can_update_a_client_user(): void
    {
        $admin  = User::factory()->admin()->create();
        $client = User::factory()->create(['role' => 'client']);

        $response = $this->actingAs($admin)->putJson("/api/admin/users/{$client->id}", [
            'first_name' => 'Updated',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('users', [
            'id'         => $client->id,
            'first_name' => 'Updated',
        ]);
    }

    public function test_admin_can_delete_a_client_user(): void
    {
        $admin  = User::factory()->admin()->create();
        $client = User::factory()->create(['role' => 'client']);

        $response = $this->actingAs($admin)->deleteJson("/api/admin/users/{$client->id}");

        $response->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSoftDeleted('users', ['id' => $client->id]);
    }

    // ──────────────────────────────────────────────
    // Policy constraints
    // ──────────────────────────────────────────────

    public function test_admin_cannot_update_super_admin(): void
    {
        $admin      = User::factory()->admin()->create();
        $superAdmin = User::factory()->superAdmin()->create();

        $response = $this->actingAs($admin)->putJson("/api/admin/users/{$superAdmin->id}", [
            'first_name' => 'Hacked',
        ]);

        $response->assertForbidden();
    }

    public function test_admin_cannot_delete_super_admin(): void
    {
        $admin      = User::factory()->admin()->create();
        $superAdmin = User::factory()->superAdmin()->create();

        $response = $this->actingAs($admin)->deleteJson("/api/admin/users/{$superAdmin->id}");

        $response->assertForbidden();
    }

    public function test_admin_cannot_delete_themselves(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->deleteJson("/api/admin/users/{$admin->id}");

        $response->assertForbidden();
    }

    public function test_super_admin_can_update_another_admin(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $admin      = User::factory()->admin()->create();

        $response = $this->actingAs($superAdmin)->putJson("/api/admin/users/{$admin->id}", [
            'first_name' => 'Modified',
        ]);

        $response->assertOk();
    }

    // ──────────────────────────────────────────────
    // Validation
    // ──────────────────────────────────────────────

    public function test_create_user_requires_valid_role(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->postJson('/api/admin/users', [
            'first_name' => 'Bad',
            'last_name'  => 'Role',
            'email'      => 'badrole@example.com',
            'password'   => 'Password123!',
            'role'       => 'hacker',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['role']);
    }

    public function test_create_user_rejects_duplicate_email(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->create(['email' => 'taken@example.com']);

        $response = $this->actingAs($admin)->postJson('/api/admin/users', [
            'first_name' => 'Dup',
            'last_name'  => 'Email',
            'email'      => 'taken@example.com',
            'password'   => 'Password123!',
            'role'       => 'client',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }
}
