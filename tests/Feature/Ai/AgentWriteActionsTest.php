<?php

namespace Tests\Feature\Ai;

use App\Domains\Platform\Compute\Enums\DeploymentStatus;
use App\Domains\Platform\Compute\Enums\DeploymentTrigger;
use App\Domains\Platform\Compute\Enums\ResourceStatus;
use App\Domains\Platform\Compute\Models\Environment;
use App\Domains\Platform\Compute\Models\Project;
use App\Domains\Platform\Compute\Models\Resource;
use App\Domains\Platform\Compute\Models\Team;
use App\Domains\Platform\Compute\Providers\Contracts\AppRuntimeDriver;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeAppRuntimeDriver;
use Tests\TestCase;

/**
 * Gate de confirmación del agente (mes 2): las herramientas de escritura se
 * PROPONEN y solo corren cuando el usuario confirma. Cubre set_env_var,
 * redeploy_resource y apply_fix, más la autorización del confirm/reject.
 */
class AgentWriteActionsTest extends TestCase
{
    use RefreshDatabase;

    private FakeAppRuntimeDriver $driver;
    private User $user;
    private Environment $environment;

    protected function setUp(): void
    {
        parent::setUp();

        config(['anthropic.api_key' => 'test-key']);

        $this->driver = new FakeAppRuntimeDriver();
        $this->app->instance(AppRuntimeDriver::class, $this->driver);

        $this->user = User::factory()->create(['status' => 'active']);
        $team       = Team::factory()->personal()->create(['owner_user_id' => $this->user->id]);
        $project    = Project::factory()->create([
            'team_id'        => $team->id,
            'repo_full_name' => 'roke/demo-app',
            'default_branch' => 'main',
            'detected_stack' => [
                'framework' => 'laravel',
                'build'     => ['method' => 'nixpacks'],
                'run'       => ['port' => 8080],
            ],
        ]);
        $this->environment = Environment::factory()->create(['project_id' => $project->id]);
    }

    /** El modelo pide una sola tool de escritura y luego cierra con texto. */
    private function fakeWriteThenText(string $tool, array $input, string $text = 'Listo, lo propuse.'): void
    {
        Http::fakeSequence('api.anthropic.com/v1/messages')
            ->push([
                'stop_reason' => 'tool_use',
                'content'     => [[
                    'type'  => 'tool_use',
                    'id'    => 'toolu_1',
                    'name'  => $tool,
                    'input' => $input,
                ]],
                'usage'       => ['input_tokens' => 100, 'output_tokens' => 20],
            ])
            ->push([
                'stop_reason' => 'end_turn',
                'content'     => [['type' => 'text', 'text' => $text]],
                'usage'       => ['input_tokens' => 120, 'output_tokens' => 25],
            ]);
    }

    private function startConversation(): string
    {
        return $this->actingAs($this->user)
            ->postJson('/api/v2/ai/conversations', [])
            ->json('data.uuid');
    }

    private function provisionedResource(): Resource
    {
        $resource = Resource::factory()->create([
            'environment_id' => $this->environment->id,
            'status'         => ResourceStatus::Running,
            'name'           => 'mi-api',
        ]);
        $resource->providerRefs()->create(['provider' => 'coolify', 'external_id' => 'cool-app-1']);

        return $resource;
    }

    public function test_write_tool_is_proposed_not_executed(): void
    {
        $resource = $this->provisionedResource();
        $this->fakeWriteThenText('set_env_var', [
            'resource' => $resource->uuid, 'key' => 'APP_DEBUG', 'value' => 'false', 'is_secret' => false,
        ]);

        $conversation = $this->startConversation();
        $response = $this->actingAs($this->user)->postJson(
            "/api/v2/ai/conversations/{$conversation}/messages",
            ['message' => 'Pon APP_DEBUG en false'],
        );

        $response->assertOk()
            ->assertJsonPath('data.actions.0.tool', 'set_env_var')
            ->assertJsonPath('data.actions.0.status', 'proposed')
            ->assertJsonPath('data.actions.0.risk', 'safe_write');
        $this->assertStringContainsString('APP_DEBUG', $response->json('data.actions.0.summary'));

        // No se ejecutó: la variable NO existe todavía.
        $this->assertFalse($resource->environment->envVars()->where('key', 'APP_DEBUG')->exists());
        $this->assertDatabaseHas('ai_actions', ['tool' => 'set_env_var', 'status' => 'proposed']);
    }

    public function test_confirm_executes_the_action(): void
    {
        $resource = $this->provisionedResource();
        $this->fakeWriteThenText('set_env_var', [
            'resource' => $resource->uuid, 'key' => 'APP_DEBUG', 'value' => 'false', 'is_secret' => false,
        ]);

        $conversation = $this->startConversation();
        $actionUuid = $this->actingAs($this->user)->postJson(
            "/api/v2/ai/conversations/{$conversation}/messages",
            ['message' => 'Pon APP_DEBUG en false'],
        )->json('data.actions.0.uuid');

        $this->actingAs($this->user)->postJson(
            "/api/v2/ai/conversations/{$conversation}/actions/{$actionUuid}/confirm",
        )->assertOk()->assertJsonPath('data.status', 'executed');

        $var = $resource->environment->envVars()->where('key', 'APP_DEBUG')->first();
        $this->assertNotNull($var);
        $this->assertSame('false', $var->value_encrypted);
        $this->assertSame('ai', $var->source);
        $this->assertDatabaseHas('ai_actions', ['uuid' => $actionUuid, 'status' => 'executed']);
    }

    public function test_reject_does_not_execute(): void
    {
        $resource = $this->provisionedResource();
        $this->fakeWriteThenText('set_env_var', [
            'resource' => $resource->uuid, 'key' => 'APP_DEBUG', 'value' => 'false', 'is_secret' => false,
        ]);

        $conversation = $this->startConversation();
        $actionUuid = $this->actingAs($this->user)->postJson(
            "/api/v2/ai/conversations/{$conversation}/messages",
            ['message' => 'Pon APP_DEBUG en false'],
        )->json('data.actions.0.uuid');

        $this->actingAs($this->user)->postJson(
            "/api/v2/ai/conversations/{$conversation}/actions/{$actionUuid}/reject",
        )->assertOk()->assertJsonPath('data.status', 'rejected');

        $this->assertFalse($resource->environment->envVars()->where('key', 'APP_DEBUG')->exists());
    }

    public function test_cannot_confirm_action_of_another_user(): void
    {
        $resource = $this->provisionedResource();
        $this->fakeWriteThenText('set_env_var', [
            'resource' => $resource->uuid, 'key' => 'APP_DEBUG', 'value' => 'false', 'is_secret' => false,
        ]);

        $conversation = $this->startConversation();
        $actionUuid = $this->actingAs($this->user)->postJson(
            "/api/v2/ai/conversations/{$conversation}/messages",
            ['message' => 'Pon APP_DEBUG en false'],
        )->json('data.actions.0.uuid');

        $stranger = User::factory()->create(['status' => 'active']);
        $this->actingAs($stranger)->postJson(
            "/api/v2/ai/conversations/{$conversation}/actions/{$actionUuid}/confirm",
        )->assertForbidden();

        $this->assertDatabaseHas('ai_actions', ['uuid' => $actionUuid, 'status' => 'proposed']);
    }

    public function test_confirmed_redeploy_creates_ai_deployment(): void
    {
        $resource = $this->provisionedResource();
        $this->fakeWriteThenText('redeploy_resource', ['resource' => $resource->uuid]);

        $conversation = $this->startConversation();
        $actionUuid = $this->actingAs($this->user)->postJson(
            "/api/v2/ai/conversations/{$conversation}/messages",
            ['message' => 'Vuelve a desplegar mi-api'],
        )->json('data.actions.0.uuid');

        $this->driver->calls = [];

        $this->actingAs($this->user)->postJson(
            "/api/v2/ai/conversations/{$conversation}/actions/{$actionUuid}/confirm",
        )->assertOk()->assertJsonPath('data.status', 'executed');

        $deployment = $resource->deployments()
            ->where('trigger', DeploymentTrigger::Ai->value)
            ->firstOrFail();

        $this->assertTrue($deployment->initiated_by_ai);
        $this->assertSame(DeploymentStatus::Success, $deployment->status);
        $this->assertTrue($this->driver->called('triggerDeploy'));
    }

    public function test_apply_fix_generates_app_key_and_redeploys(): void
    {
        config(['anthropic.api_key' => 'test-key']);
        $resource = $this->provisionedResource();

        // Deployment fallido con la firma de APP_KEY ausente (auto-fixable).
        $failed = $resource->deployments()->create([
            'trigger' => DeploymentTrigger::Manual,
            'status'  => DeploymentStatus::Failed,
            'branch'  => 'main',
        ]);
        $failed->logs()->create([
            'seq'    => 1,
            'stream' => 'build',
            'chunk'  => 'RuntimeException: No application encryption key has been specified.',
        ]);

        $this->fakeWriteThenText('apply_fix', ['deployment' => $failed->uuid]);

        $conversation = $this->startConversation();
        $response = $this->actingAs($this->user)->postJson(
            "/api/v2/ai/conversations/{$conversation}/messages",
            ['message' => '¿Puedes arreglar el deploy?'],
        );

        $actionUuid = $response->json('data.actions.0.uuid');
        $this->assertStringContainsString('APP_KEY', $response->json('data.actions.0.summary'));

        $this->driver->calls = [];
        $this->actingAs($this->user)->postJson(
            "/api/v2/ai/conversations/{$conversation}/actions/{$actionUuid}/confirm",
        )->assertOk();

        // Se generó la APP_KEY (secreta, source ai) y se relanzó el deploy.
        $appKey = $resource->environment->envVars()->where('key', 'APP_KEY')->first();
        $this->assertNotNull($appKey);
        $this->assertTrue($appKey->is_secret);
        $this->assertStringStartsWith('base64:', $appKey->value_encrypted);
        $this->assertTrue(
            $resource->deployments()->where('trigger', DeploymentTrigger::Ai->value)->exists(),
        );
    }

    public function test_proposal_against_inaccessible_resource_is_not_persisted(): void
    {
        $stranger = Resource::factory()->create(); // otro equipo
        $this->fakeWriteThenText('set_env_var', [
            'resource' => $stranger->uuid, 'key' => 'FOO', 'value' => 'bar',
        ]);

        $conversation = $this->startConversation();
        $response = $this->actingAs($this->user)->postJson(
            "/api/v2/ai/conversations/{$conversation}/messages",
            ['message' => "Pon FOO en el recurso {$stranger->uuid}"],
        );

        $response->assertOk()
            ->assertJsonPath('data.tool_calls.0.ok', false)
            ->assertJsonCount(0, 'data.actions');
        $this->assertDatabaseCount('ai_actions', 0);
    }
}
