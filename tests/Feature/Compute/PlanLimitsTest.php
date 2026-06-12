<?php

namespace Tests\Feature\Compute;

use App\Domains\Platform\Compute\Models\Environment;
use App\Domains\Platform\Compute\Models\Project;
use App\Domains\Platform\Compute\Models\Resource;
use App\Domains\Platform\Compute\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Enforcement de límites por plan (mes 2 #7): conteo de recursos y tope de RAM
 * por recurso, según config('compute.plans') y el tier del equipo.
 */
class PlanLimitsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Team $team;
    private Project $project;
    private Environment $environment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user        = User::factory()->create(['status' => 'active']);
        $this->team        = Team::factory()->personal()->create([
            'owner_user_id' => $this->user->id,
            'plan_tier'     => 'free', // max_resources=2, ram_mb_max=512
        ]);
        $this->project     = Project::factory()->create([
            'team_id'        => $this->team->id,
            'repo_full_name' => 'roke/demo-app',
        ]);
        $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
    }

    private function createResource(array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->user)->postJson(
            "/api/v2/environments/{$this->environment->uuid}/resources",
            $payload,
        );
    }

    public function test_free_plan_blocks_resource_beyond_quota(): void
    {
        // Llena el cupo (2) sin pasar por el orquestador.
        Resource::factory()->count(2)->create(['environment_id' => $this->environment->id]);

        $this->createResource(['kind' => 'app', 'name' => 'tercera'])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Alcanzaste el límite de 2 recursos de tu plan «free». Mejora tu plan para crear más.']);

        // No se creó la tercera.
        $this->assertSame(2, $this->team->activeResourceCount());
    }

    public function test_soft_deleted_resources_do_not_count(): void
    {
        $resources = Resource::factory()->count(2)->create(['environment_id' => $this->environment->id]);
        $resources->first()->delete(); // soft delete libera cupo

        $this->assertSame(1, $this->team->activeResourceCount());
    }

    public function test_ram_above_plan_cap_is_rejected(): void
    {
        $this->createResource(['kind' => 'app', 'name' => 'api', 'spec' => ['ram_mb' => 1024]])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Tu plan «free» permite hasta 512 MB de RAM por recurso (pediste 1024 MB).']);

        $this->assertSame(0, $this->team->activeResourceCount());
    }

    public function test_higher_tier_raises_the_quota(): void
    {
        $this->team->update(['plan_tier' => 'pro']); // max_resources=15, ram_mb_max=2048
        Resource::factory()->count(2)->create(['environment_id' => $this->environment->id]);

        // En free fallaría; en pro 1024 MB y un 3er recurso están permitidos
        // (no toca el orquestador porque solo verificamos que pase el gate).
        $error = app(\App\Domains\Platform\Compute\Plans\PlanLimits::class)->check($this->team, 1024);
        $this->assertNull($error);
    }

    public function test_teams_endpoint_exposes_usage(): void
    {
        Resource::factory()->create(['environment_id' => $this->environment->id]);

        $this->actingAs($this->user)->getJson('/api/v2/teams')
            ->assertOk()
            ->assertJsonPath('data.0.usage.plan', 'free')
            ->assertJsonPath('data.0.usage.max_resources', 2)
            ->assertJsonPath('data.0.usage.ram_mb_max', 512)
            ->assertJsonPath('data.0.usage.resources_used', 1);
    }
}
