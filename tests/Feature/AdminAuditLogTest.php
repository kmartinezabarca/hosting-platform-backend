<?php

namespace Tests\Feature;

use App\Domains\Platform\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Audit log read API (super_admin only).
 *
 * NOTE: requires a real DB (MySQL in CI).
 */
class AdminAuditLogTest extends TestCase
{
    use RefreshDatabase;

    private function seedEntry(array $overrides = []): AuditLog
    {
        return AuditLog::create(array_merge([
            'actor_id'    => null,
            'actor_name'  => 'Kevin M.',
            'actor_email' => 'k@example.com',
            'actor_role'  => 'admin',
            'action'      => 'invoice.refunded',
            'target_type' => 'Invoice',
            'target_id'   => '42',
            'description' => 'Reembolso de prueba',
        ], $overrides));
    }

    public function test_super_admin_can_list_audit_logs(): void
    {
        $super = User::factory()->superAdmin()->create();
        $this->seedEntry();
        $this->seedEntry(['action' => 'user.impersonated']);

        $this->actingAs($super)->getJson('/api/admin/audit-logs')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.total', 2);
    }

    public function test_audit_logs_can_be_filtered_by_action(): void
    {
        $super = User::factory()->superAdmin()->create();
        $this->seedEntry();
        $this->seedEntry(['action' => 'user.impersonated']);

        $this->actingAs($super)->getJson('/api/admin/audit-logs?action=user.impersonated')
            ->assertOk()
            ->assertJsonPath('data.total', 1);
    }

    public function test_actions_endpoint_returns_distinct_actions(): void
    {
        $super = User::factory()->superAdmin()->create();
        $this->seedEntry();
        $this->seedEntry();
        $this->seedEntry(['action' => 'user.impersonated']);

        $res = $this->actingAs($super)->getJson('/api/admin/audit-logs/actions')->assertOk();

        $this->assertEqualsCanonicalizing(
            ['invoice.refunded', 'user.impersonated'],
            $res->json('data')
        );
    }

    public function test_admin_cannot_view_audit_logs(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->getJson('/api/admin/audit-logs')->assertForbidden();
    }
}
