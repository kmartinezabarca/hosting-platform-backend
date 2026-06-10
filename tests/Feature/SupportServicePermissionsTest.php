<?php

namespace Tests\Feature;

use App\Domains\Platform\Models\Service;
use App\Domains\Platform\Models\ServicePlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El rol support asiste a clientes: puede VER servicios pero no crearlos,
 * editarlos, borrarlos, cambiarles el estado ni re-aprovisionarlos.
 * (Regresión del hallazgo de auditoría: support tenía acceso a mutaciones.)
 */
class SupportServicePermissionsTest extends TestCase
{
    use RefreshDatabase;

    private function support(): User
    {
        return User::factory()->create(['role' => 'support', 'status' => 'active']);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'status' => 'active']);
    }

    private function makeService(): Service
    {
        $user = User::factory()->create();
        $plan = ServicePlan::factory()->create();

        return Service::factory()->create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status'  => 'active',
        ]);
    }

    public function test_support_can_view_services(): void
    {
        $service = $this->makeService();

        $this->actingAs($this->support())->getJson('/api/admin/services')->assertOk();
        $this->actingAs($this->support())->getJson("/api/admin/services/{$service->uuid}")->assertOk();
    }

    public function test_support_cannot_create_service(): void
    {
        $this->actingAs($this->support())
            ->postJson('/api/admin/services', [])
            ->assertForbidden();
    }

    public function test_support_cannot_update_service(): void
    {
        $service = $this->makeService();

        $this->actingAs($this->support())
            ->putJson("/api/admin/services/{$service->uuid}", ['name' => 'hijacked'])
            ->assertForbidden();
    }

    public function test_support_cannot_delete_service(): void
    {
        $service = $this->makeService();

        $this->actingAs($this->support())
            ->deleteJson("/api/admin/services/{$service->uuid}")
            ->assertForbidden();

        $this->assertDatabaseHas('services', ['id' => $service->id]);
    }

    public function test_support_cannot_change_service_status_or_reprovision(): void
    {
        $service = $this->makeService();

        $this->actingAs($this->support())
            ->putJson("/api/admin/services/{$service->uuid}/status", ['status' => 'suspended'])
            ->assertForbidden();

        $this->actingAs($this->support())
            ->postJson("/api/admin/services/{$service->uuid}/reprovision")
            ->assertForbidden();
    }

    public function test_admin_can_still_update_service_status(): void
    {
        $service = $this->makeService();

        $this->actingAs($this->admin())
            ->putJson("/api/admin/services/{$service->uuid}/status", ['status' => 'suspended'])
            ->assertOk();

        $this->assertDatabaseHas('services', ['id' => $service->id, 'status' => 'suspended']);
    }
}
