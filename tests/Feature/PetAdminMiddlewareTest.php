<?php

namespace Tests\Feature;

use App\Domains\Pet\Models\AppAdmin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Las rutas /api/rp/admin/* (excepto /admin/check) están protegidas por el
 * middleware pet.admin además de los checks por método del controlador.
 */
class PetAdminMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // RefreshDatabase solo migra la conexión por defecto; las tablas del
        // dominio Pet viven en la conexión roke_pet (en CI apunta a la misma BD).
        if (! \Illuminate\Support\Facades\Schema::connection('roke_pet')->hasTable('app_admins')) {
            \Illuminate\Support\Facades\Artisan::call('migrate', [
                '--path'     => 'database/migrations/roke_pet',
                '--database' => 'roke_pet',
                '--force'    => true,
            ]);
        }

        // Limpiar admins de corridas anteriores (la conexión roke_pet no
        // participa del rollback transaccional de RefreshDatabase).
        AppAdmin::query()->delete();
    }

    public function test_non_admin_user_is_rejected_at_route_level(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/rp/admin/overview')->assertForbidden();
        $this->actingAs($user)->getJson('/api/rp/admin/moderation-queue')->assertForbidden();
        $this->actingAs($user)->getJson('/api/rp/admin/plans')->assertForbidden();
    }

    public function test_admin_check_remains_accessible_to_any_authenticated_user(): void
    {
        $user = User::factory()->create(['role' => 'client']);

        $this->actingAs($user)
            ->getJson('/api/rp/admin/check')
            ->assertOk()
            ->assertJsonPath('isAdmin', false);
    }

    public function test_app_admin_passes_route_guard(): void
    {
        $user = User::factory()->create();

        // app_admins.user_id referencia owners.id (el uuid del usuario plataforma).
        \App\Domains\Pet\Models\Owner::firstOrCreate(
            ['id' => $user->uuid],
            ['display_name' => 'Admin Test', 'email' => $user->email]
        );
        AppAdmin::firstOrCreate(['user_id' => $user->uuid]);

        $this->actingAs($user)->getJson('/api/rp/admin/moderation-queue')->assertOk();
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/rp/admin/overview')->assertUnauthorized();
    }
}
