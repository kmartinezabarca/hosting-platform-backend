<?php

namespace Tests\Feature\Compute;

use App\Domains\Platform\Compute\Enums\DeploymentStatus;
use App\Domains\Platform\Compute\Enums\DeploymentTrigger;
use App\Domains\Platform\Compute\Enums\ResourceStatus;
use App\Domains\Platform\Compute\Enums\TeamRole;
use App\Domains\Platform\Compute\Models\Environment;
use App\Domains\Platform\Compute\Models\Project;
use App\Domains\Platform\Compute\Models\Resource;
use App\Domains\Platform\Compute\Models\Team;
use App\Domains\Platform\Compute\Providers\Contracts\AppRuntimeDriver;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeAppRuntimeDriver;
use Tests\TestCase;

class ProvisionDeployFlowTest extends TestCase
{
    use RefreshDatabase;

    private FakeAppRuntimeDriver $driver;
    private User $user;
    private Team $team;
    private Project $project;
    private Environment $environment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->driver = new FakeAppRuntimeDriver();
        $this->app->instance(AppRuntimeDriver::class, $this->driver);

        $this->user = User::factory()->create(['status' => 'active']);
        $this->team = Team::factory()->personal()->create(['owner_user_id' => $this->user->id]);

        // Sin instalación de GitHub → URL pública, RefreshGitCredentials se omite.
        $this->project = Project::factory()->create([
            'team_id'        => $this->team->id,
            'repo_full_name' => 'roke/demo-app',
            'default_branch' => 'main',
            'detected_stack' => [
                'framework' => 'laravel',
                'build'     => ['method' => 'nixpacks'],
                'run'       => ['port' => 8080],
            ],
        ]);

        $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
    }

    public function test_full_provision_flow_ends_with_running_resource(): void
    {
        $response = $this->actingAs($this->user)->postJson(
            "/api/v2/environments/{$this->environment->uuid}/resources",
            ['kind' => 'app', 'name' => 'api'],
        );

        $response->assertStatus(202)->assertJsonPath('success', true);

        $resource = Resource::firstOrFail();

        // Con cola sync la saga ya corrió completa.
        $this->assertSame(ResourceStatus::Running, $resource->status);
        $this->assertSame('cool-app-1', $resource->providerRef('coolify')->external_id);
        $this->assertStringEndsWith('.' . config('compute.app_domain'), $resource->spec['fqdn']);

        $deployment = $resource->deployments()->firstOrFail();
        $this->assertSame(DeploymentStatus::Success, $deployment->status);
        $this->assertGreaterThan(0, $deployment->logs()->count());

        $this->assertTrue($this->driver->called('createApplication'));
        $this->assertTrue($this->driver->called('setDomain'));
        $this->assertTrue($this->driver->called('triggerDeploy'));

        $this->assertDatabaseHas('orchestrations', [
            'resource_id' => $resource->id,
            'flow'        => 'provision_app',
        ]);
    }

    public function test_env_vars_are_synced_to_runtime(): void
    {
        $this->environment->envVars()->create([
            'key'             => 'APP_LOCALE',
            'value_encrypted' => 'es_MX',
            'is_secret'       => false,
        ]);

        $this->actingAs($this->user)->postJson(
            "/api/v2/environments/{$this->environment->uuid}/resources",
            ['kind' => 'app', 'name' => 'api'],
        )->assertStatus(202);

        $syncCall = collect($this->driver->calls)->firstWhere('method', 'syncEnvVars');
        $this->assertNotNull($syncCall);
        // El valor llega DESENCRIPTADO al driver (cast encrypted del modelo).
        $this->assertSame(['APP_LOCALE' => 'es_MX'], $syncCall['args'][1]);
    }

    public function test_import_dotenv_bulk_upserts_with_secret_heuristic(): void
    {
        $blob = "# comentario\n"
            . "export APP_ENV=production\n"
            . "DB_PASSWORD=\"s3cr3t\"\n"
            . "LINEA INVALIDA SIN IGUAL\n"
            . "APP_URL=https://demo.test\n";

        $this->actingAs($this->user)->postJson(
            "/api/v2/environments/{$this->environment->uuid}/env-vars/import",
            ['contents' => $blob],
        )->assertStatus(200)->assertJsonPath('data.count', 3);

        // No-secreto por heurística; valor visible y desencriptado.
        $appEnv = $this->environment->envVars()->where('key', 'APP_ENV')->firstOrFail();
        $this->assertFalse($appEnv->is_secret);
        $this->assertSame('import', $appEnv->source);
        $this->assertSame('production', $appEnv->value_encrypted);

        // Secreto por heurística (PASSWORD); valor desencriptado correcto.
        $pw = $this->environment->envVars()->where('key', 'DB_PASSWORD')->firstOrFail();
        $this->assertTrue($pw->is_secret);
        $this->assertSame('s3cr3t', $pw->value_encrypted);

        // La línea sin '=' se ignora.
        $this->assertDatabaseMissing('env_vars', [
            'environment_id' => $this->environment->id,
            'key'            => 'LINEA',
        ]);
    }

    public function test_failed_build_marks_resource_and_deployment_failed(): void
    {
        $this->driver->failDeployment = true;

        $this->actingAs($this->user)->postJson(
            "/api/v2/environments/{$this->environment->uuid}/resources",
            ['kind' => 'app', 'name' => 'api'],
        )->assertStatus(202);

        $resource = Resource::firstOrFail();
        $this->assertSame(ResourceStatus::Failed, $resource->status);

        $deployment = $resource->deployments()->firstOrFail();
        $this->assertSame(DeploymentStatus::Failed, $deployment->status);
        $this->assertStringContainsString('No se identificó una causa conocida', $deployment->error_summary);
        $this->assertStringContainsString('Fixes sugeridos', $deployment->error_summary);

        $this->assertDatabaseHas('orchestrations', ['flow' => 'provision_app']);
        $this->assertNotNull($resource->orchestrations()->first()->failed_at);
    }

    public function test_manual_deploy_runs_deploy_flow(): void
    {
        $resource = $this->provisionedResource();

        $response = $this->actingAs($this->user)->postJson(
            "/api/v2/resources/{$resource->uuid}/deployments",
            [],
        );

        $response->assertStatus(202);

        $deployment = $resource->deployments()->latest('id')->firstOrFail();
        $this->assertSame(DeploymentStatus::Success, $deployment->status);
        $this->assertSame('cool-dep-1', $deployment->provider_ref);
    }

    public function test_logs_endpoint_paginates_by_seq(): void
    {
        $resource = $this->provisionedResource();

        $this->actingAs($this->user)->postJson("/api/v2/resources/{$resource->uuid}/deployments", []);
        $deployment = $resource->deployments()->latest('id')->firstOrFail();

        $all = $this->actingAs($this->user)
            ->getJson("/api/v2/deployments/{$deployment->uuid}/logs")
            ->assertOk()
            ->json('data.chunks');

        $this->assertNotEmpty($all);

        $afterFirst = $this->actingAs($this->user)
            ->getJson("/api/v2/deployments/{$deployment->uuid}/logs?after_seq={$all[0]['seq']}")
            ->json('data.chunks');

        $this->assertCount(count($all) - 1, $afterFirst);
    }

    public function test_resource_without_repo_is_rejected(): void
    {
        $project     = Project::factory()->create(['team_id' => $this->team->id, 'repo_full_name' => null]);
        $environment = Environment::factory()->create(['project_id' => $project->id]);

        $this->actingAs($this->user)->postJson(
            "/api/v2/environments/{$environment->uuid}/resources",
            ['kind' => 'app', 'name' => 'api'],
        )->assertStatus(422);
    }

    public function test_viewer_cannot_create_resources(): void
    {
        $viewer = User::factory()->create(['status' => 'active']);
        $this->team->members()->attach($viewer->id, ['role' => TeamRole::Viewer->value]);

        $this->actingAs($viewer)->postJson(
            "/api/v2/environments/{$this->environment->uuid}/resources",
            ['kind' => 'app', 'name' => 'api'],
        )->assertForbidden();
    }

    public function test_api_responses_never_leak_provider_ids(): void
    {
        $resource = $this->provisionedResource();

        $body = $this->actingAs($this->user)
            ->getJson("/api/v2/resources/{$resource->uuid}")
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('cool-app-1', $body);
        $this->assertStringNotContainsString('coolify', strtolower($body));
    }

    /** Recurso ya aprovisionado (running + provider ref), sin pasar por la API. */
    public function test_rollback_redeploys_target_commit(): void
    {
        $resource = $this->provisionedResource();

        // Deployment exitoso anterior con un commit conocido.
        $target = $resource->deployments()->create([
            'trigger'        => DeploymentTrigger::Manual,
            'status'         => DeploymentStatus::Success,
            'branch'         => 'main',
            'commit_sha'     => 'abc123',
            'commit_message' => 'release anterior',
        ]);

        $this->driver->calls = [];

        $this->actingAs($this->user)->postJson(
            "/api/v2/resources/{$resource->uuid}/deployments/{$target->uuid}/rollback",
        )->assertStatus(202)->assertJsonPath('data.rolled_back_from', $target->uuid);

        $rollback = $resource->deployments()
            ->where('trigger', DeploymentTrigger::Rollback->value)
            ->firstOrFail();

        $this->assertSame(DeploymentStatus::Success, $rollback->status);
        $this->assertSame('abc123', $rollback->commit_sha);

        // El driver recibió el commit objetivo al desplegar → rollback real, no HEAD.
        $deployCall = collect($this->driver->calls)->firstWhere('method', 'triggerDeploy');
        $this->assertNotNull($deployCall);
        $this->assertSame('abc123', $deployCall['args'][1] ?? null);
    }

    public function test_rollback_rejects_non_successful_target(): void
    {
        $resource = $this->provisionedResource();
        $failed   = $resource->deployments()->create([
            'trigger' => DeploymentTrigger::Manual,
            'status'  => DeploymentStatus::Failed,
            'branch'  => 'main',
        ]);

        $this->actingAs($this->user)->postJson(
            "/api/v2/resources/{$resource->uuid}/deployments/{$failed->uuid}/rollback",
        )->assertStatus(422);
    }

    private function provisionedResource(): Resource
    {
        $resource = Resource::factory()->create([
            'environment_id' => $this->environment->id,
            'status'         => ResourceStatus::Running,
        ]);

        $resource->providerRefs()->create([
            'provider'    => 'coolify',
            'external_id' => 'cool-app-1',
        ]);

        return $resource;
    }
}
