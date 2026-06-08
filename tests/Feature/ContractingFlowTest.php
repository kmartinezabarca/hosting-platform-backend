<?php

namespace Tests\Feature;

use App\Domains\Platform\Models\Category;
use App\Domains\Platform\Models\ProvisioningJob;
use App\Domains\Platform\Models\PterodactylEgg;
use App\Domains\Platform\Models\Service;
use App\Domains\Platform\Models\ServicePlan;
use App\Domains\Platform\Services\Coolify\HostingProvisioningService;
use App\Domains\Platform\Services\Pterodactyl\GameServerProvisioningService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Prueba E2E del flujo de contratación: registro de usuario → contratar un
 * game server (Pterodactyl) y un hosting (Coolify) → verificar persistencia,
 * aprovisionamiento e idempotencia, más controles de seguridad/validación.
 *
 * El aprovisionamiento real (Pterodactyl/Coolify) está mockeado: aquí probamos
 * la lógica de negocio del backend, no la integración con proveedores externos.
 *
 * Se usan planes trial/free para ejercitar el flujo sin Stripe.
 */
class ContractingFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Nunca tocar proveedores externos durante el aprovisionamiento síncrono.
        $this->mock(
            GameServerProvisioningService::class,
            fn ($m) => $m->shouldReceive('provision')->andReturnNull()
        );
        $this->mock(
            HostingProvisioningService::class,
            fn ($m) => $m->shouldReceive('provision')->andReturnNull()
        );
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Flujo feliz E2E
    // ──────────────────────────────────────────────────────────────────────────

    public function test_user_registers_then_contracts_game_server_and_hosting(): void
    {
        // 1) Registro del usuario
        $this->postJson('/api/auth/register', [
            'first_name'            => 'Aldair',
            'last_name'             => 'Martínez',
            'email'                 => 'aldair@example.com',
            'password'              => 'Password123!',
            'password_confirmation' => 'Password123!',
        ])->assertCreated();

        $user = User::where('email', 'aldair@example.com')->firstOrFail();

        // 2) Catálogo
        $gamePlan = $this->gameServerTrialPlan();
        $egg      = $this->activeEgg(nestId: 1);
        $hostPlan = $this->hostingTrialPlan();

        // 3) Contratar el game server
        $gameRes = $this->actingAs($user)->postJson('/api/services/contract', [
            'plan_id'       => $gamePlan->slug,
            'billing_cycle' => 'monthly',
            'service_name'  => 'Mi Servidor Minecraft',
            'egg_id'        => $egg->id,
        ]);

        $gameRes->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Mi Servidor Minecraft');

        $gameService = Service::where('user_id', $user->id)
            ->where('plan_id', $gamePlan->id)
            ->firstOrFail();

        $this->assertSame('active', $gameService->status);
        $this->assertSame($egg->id, $gameService->selected_egg_id, 'El egg elegido debe quedar guardado.');
        $this->assertSame(0.0, (float) $gameService->price, 'Un plan trial no debe cobrar.');
        $this->assertNotNull($gameService->trial_ends_at, 'Un trial debe fijar trial_ends_at.');
        $this->assertGreaterThan(0, $gameService->max_players, 'max_players debe resolverse.');

        // Aprovisionamiento encolado y ejecutado (mockeado) → job succeeded.
        $this->assertDatabaseHas('provisioning_jobs', [
            'service_id' => $gameService->id,
            'provider'   => ProvisioningJob::PROVIDER_PTERODACTYL,
            'status'     => ProvisioningJob::STATUS_SUCCEEDED,
        ]);

        // 4) Contratar el hosting (Coolify)
        $hostRes = $this->actingAs($user)->postJson('/api/services/contract', [
            'plan_id'       => $hostPlan->slug,
            'billing_cycle' => 'monthly',
            'service_name'  => 'micliente.com',
            'domain'        => 'micliente.com',
        ]);

        $hostRes->assertCreated()->assertJsonPath('success', true);

        $hostService = Service::where('user_id', $user->id)
            ->where('plan_id', $hostPlan->id)
            ->firstOrFail();

        $this->assertSame('active', $hostService->status);
        $this->assertDatabaseHas('provisioning_jobs', [
            'service_id' => $hostService->id,
            'provider'   => ProvisioningJob::PROVIDER_COOLIFY,
            'status'     => ProvisioningJob::STATUS_SUCCEEDED,
        ]);

        // El usuario terminó con exactamente 2 servicios.
        $this->assertSame(2, Service::where('user_id', $user->id)->count());
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Seguridad y validación
    // ──────────────────────────────────────────────────────────────────────────

    public function test_contract_requires_authentication(): void
    {
        $plan = $this->gameServerTrialPlan();

        $this->postJson('/api/services/contract', [
            'plan_id'       => $plan->slug,
            'billing_cycle' => 'monthly',
            'service_name'  => 'Anónimo',
        ])->assertUnauthorized();

        $this->assertSame(0, Service::count());
    }

    public function test_game_server_contract_requires_egg_selection(): void
    {
        $user = User::factory()->create();
        $plan = $this->gameServerTrialPlan();

        $this->actingAs($user)->postJson('/api/services/contract', [
            'plan_id'       => $plan->slug,
            'billing_cycle' => 'monthly',
            'service_name'  => 'Sin juego',
            // egg_id ausente a propósito
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['egg_id']);

        $this->assertSame(0, Service::count());
    }

    public function test_contract_accepts_constancia_as_object_without_422(): void
    {
        // El frontend manda la constancia como objeto {filename, mime, content_b64}.
        // Antes daba 422 (la regla era nullable|string). Ahora se acepta.
        $user = User::factory()->create();
        $plan = $this->hostingTrialPlan();

        $this->actingAs($user)->postJson('/api/services/contract', [
            'plan_id'       => $plan->slug,
            'billing_cycle' => 'monthly',
            'service_name'  => 'Con constancia',
            'invoice' => [
                'rfc'           => 'XAXX010101000',
                'name'          => 'CLIENTE DEMO',
                'zip'           => '99999',
                'regimen'       => '616',
                'cfdi_use_code' => 'G03',
                'constancia'    => [
                    'filename'    => 'csf.pdf',
                    'mime'        => 'application/pdf',
                    'content_b64' => base64_encode('PDF-FAKE'),
                ],
            ],
        ])->assertCreated();
    }

    public function test_game_server_rejects_egg_from_a_nest_not_allowed_by_plan(): void
    {
        $user = User::factory()->create();
        $plan = $this->gameServerTrialPlan(); // allowed_nest_ids = [1]
        $egg  = $this->activeEgg(nestId: 99); // egg de un nest NO permitido

        $res = $this->actingAs($user)->postJson('/api/services/contract', [
            'plan_id'       => $plan->slug,
            'billing_cycle' => 'monthly',
            'service_name'  => 'Egg prohibido',
            'egg_id'        => $egg->id,
        ]);

        $res->assertStatus(422)->assertJsonPath('success', false);
        $this->assertSame(0, Service::count(), 'No debe crearse servicio con un egg no permitido.');
    }

    public function test_contract_rejects_unknown_addons(): void
    {
        $user = User::factory()->create();
        $plan = $this->hostingTrialPlan();

        $res = $this->actingAs($user)->postJson('/api/services/contract', [
            'plan_id'       => $plan->slug,
            'billing_cycle' => 'monthly',
            'service_name'  => 'Con add-on falso',
            'add_ons'       => ['11111111-1111-1111-1111-111111111111'],
        ]);

        $res->assertStatus(422)->assertJsonPath('success', false);
        $this->assertSame(0, Service::count());
    }

    public function test_invalid_billing_cycle_is_rejected(): void
    {
        $user = User::factory()->create();
        $plan = $this->hostingTrialPlan();

        $this->actingAs($user)->postJson('/api/services/contract', [
            'plan_id'       => $plan->slug,
            'billing_cycle' => 'every_decade',
            'service_name'  => 'Ciclo inválido',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['billing_cycle']);
    }

    public function test_service_is_owned_by_authenticated_user_and_not_spoofable(): void
    {
        $caller = User::factory()->create();
        $victim = User::factory()->create();
        $plan   = $this->hostingTrialPlan();

        $this->actingAs($caller)->postJson('/api/services/contract', [
            'plan_id'       => $plan->slug,
            'billing_cycle' => 'monthly',
            'service_name'  => 'Intento de spoof',
            // Intento de inyectar otro dueño — debe ignorarse (se usa Auth::user()).
            'user_id'       => $victim->id,
        ])->assertCreated();

        $this->assertSame(0, Service::where('user_id', $victim->id)->count(), 'No debe poder asignarse a otro usuario.');
        $this->assertSame(1, Service::where('user_id', $caller->id)->count());
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Caracterización de bugs/riesgos detectados (documentan el comportamiento
    // ACTUAL para que, al corregirse, estos tests se actualicen explícitamente).
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * El flujo free/trial es idempotente: un doble-submit / refresh / retry del
     * mismo plan devuelve el servicio existente en lugar de crear un duplicado
     * (anti-abuso de trials). Antes contractFree() no tenía guarda alguna.
     */
    public function test_free_plan_double_submit_is_idempotent(): void
    {
        $user = User::factory()->create();
        $plan = $this->hostingTrialPlan();

        $payload = [
            'plan_id'       => $plan->slug,
            'billing_cycle' => 'monthly',
            'service_name'  => 'Doble submit',
        ];

        $first  = $this->actingAs($user)->postJson('/api/services/contract', $payload)->assertCreated();
        $second = $this->actingAs($user)->postJson('/api/services/contract', $payload)->assertCreated();

        // Un solo servicio (sole() falla si hay 0 o >1).
        $service = Service::where('user_id', $user->id)->sole();

        // Ambas respuestas referencian el MISMO servicio.
        $first->assertJsonPath('data.uuid', $service->uuid);
        $second->assertJsonPath('data.uuid', $service->uuid);
    }

    /**
     * El límite también aplica entre intentos no concurrentes: contratar dos veces
     * el mismo plan trial no acumula servicios para el mismo usuario.
     */
    public function test_user_cannot_stack_multiple_trials_of_same_plan(): void
    {
        $user = User::factory()->create();
        $plan = $this->hostingTrialPlan();

        foreach (range(1, 3) as $i) {
            $this->actingAs($user)->postJson('/api/services/contract', [
                'plan_id'       => $plan->slug,
                'billing_cycle' => 'monthly',
                'service_name'  => "Intento {$i}",
            ])->assertCreated();
        }

        $this->assertSame(1, Service::where('user_id', $user->id)->where('plan_id', $plan->id)->count());
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────────

    private function gameServerTrialPlan(): ServicePlan
    {
        $category = Category::factory()->create(['slug' => 'gameserver', 'name' => 'Game Servers']);

        return ServicePlan::factory()->pterodactyl()->create([
            'category_id'   => $category->id,
            'name'          => 'Minecraft Trial',
            'slug'          => 'minecraft-trial',
            'plan_type'     => 'trial',
            'trial_days'    => 14,
            'base_price'    => 0,
            'specifications'=> ['players' => '40 Jugadores'],
        ]);
    }

    private function hostingTrialPlan(): ServicePlan
    {
        $category = Category::factory()->create(['slug' => 'hosting-coolify', 'name' => 'Hosting']);

        return ServicePlan::factory()->create([
            'category_id' => $category->id,
            'name'        => 'Hosting Trial',
            'slug'        => 'hosting-trial-coolify',
            'provisioner' => 'coolify',
            'plan_type'   => 'trial',
            'trial_days'  => 14,
            'base_price'  => 0,
        ]);
    }

    private function activeEgg(int $nestId = 1): PterodactylEgg
    {
        return PterodactylEgg::create([
            'ptero_nest_id' => $nestId,
            'ptero_egg_id'  => $nestId * 10 + 1,
            'nest_name'     => 'Minecraft',
            'egg_name'      => 'Paper',
            'docker_image'  => 'ghcr.io/pterodactyl/yolks:java_21',
            'startup'       => 'java -jar server.jar',
            'is_active'     => true,
        ]);
    }
}
