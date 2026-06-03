<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verifies the role-scoped gating of the admin module:
 *   • support             → operational scope (users[read], services, tickets…)
 *   • admin + super_admin  → business scope (analytics, finance, catalog…)
 *   • super_admin          → backups & audit log
 *
 * NOTE: requires a real DB (MySQL in CI). The pre-existing migration set is not
 * SQLite-compatible, so this cannot be executed in the sandbox.
 */
class AdminRoleAccessTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        return User::factory()->create(['role' => $role, 'status' => 'active']);
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/admin/users')->assertUnauthorized();
        $this->getJson('/api/admin/analytics/overview')->assertUnauthorized();
        $this->getJson('/api/admin/audit-logs')->assertUnauthorized();
    }

    public function test_client_cannot_reach_admin_area(): void
    {
        $client = $this->user('client');

        $this->actingAs($client)->getJson('/api/admin/users')->assertForbidden();
        $this->actingAs($client)->getJson('/api/admin/services')->assertForbidden();
        $this->actingAs($client)->getJson('/api/admin/analytics/overview')->assertForbidden();
    }

    public function test_support_has_operational_scope_only(): void
    {
        $support = $this->user('support');

        // Allowed (operational + analytics dashboard per spec §0)
        $this->actingAs($support)->getJson('/api/admin/users')->assertOk();
        $this->actingAs($support)->getJson('/api/admin/services')->assertOk();
        $this->actingAs($support)->getJson('/api/admin/analytics/overview')->assertOk();

        // Denied (finance management / catalog / super-admin areas)
        $this->actingAs($support)->postJson('/api/admin/users', [])->assertForbidden();
        $this->actingAs($support)->getJson('/api/admin/invoices')->assertForbidden();
        $this->actingAs($support)->getJson('/api/admin/audit-logs')->assertForbidden();
        $this->actingAs($support)->getJson('/api/admin/backups')->assertForbidden();
    }

    public function test_admin_has_business_scope_but_not_audit_or_backups(): void
    {
        $admin = $this->user('admin');

        $this->actingAs($admin)->getJson('/api/admin/analytics/overview')->assertOk();
        $this->actingAs($admin)->getJson('/api/admin/users')->assertOk();

        $this->actingAs($admin)->getJson('/api/admin/audit-logs')->assertForbidden();
        $this->actingAs($admin)->getJson('/api/admin/backups')->assertForbidden();
    }

    public function test_super_admin_can_reach_audit_log(): void
    {
        $super = $this->user('super_admin');

        $this->actingAs($super)->getJson('/api/admin/audit-logs')->assertOk();
        $this->actingAs($super)->getJson('/api/admin/analytics/overview')->assertOk();
    }

    public function test_inactive_staff_is_blocked(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'suspended']);

        $this->actingAs($admin)->getJson('/api/admin/users')->assertForbidden();
    }
}
