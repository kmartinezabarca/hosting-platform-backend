<?php

namespace Tests\Feature\Ai;

use App\Domains\Platform\Ai\Jobs\DiagnoseFailedDeployment;
use App\Domains\Platform\Ai\Models\AiConversation;
use App\Domains\Platform\Compute\Enums\DeploymentStatus;
use App\Domains\Platform\Compute\Enums\DeploymentTrigger;
use App\Domains\Platform\Compute\Models\Environment;
use App\Domains\Platform\Compute\Models\Project;
use App\Domains\Platform\Compute\Models\Resource;
use App\Domains\Platform\Compute\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiAgentTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Resource $resource;

    protected function setUp(): void
    {
        parent::setUp();

        config(['anthropic.api_key' => 'test-key']);

        $this->user = User::factory()->create(['status' => 'active']);
        $team       = Team::factory()->personal()->create(['owner_user_id' => $this->user->id]);
        $project    = Project::factory()->create(['team_id' => $team->id]);
        $env        = Environment::factory()->create(['project_id' => $project->id]);
        $this->resource = Resource::factory()->create(['environment_id' => $env->id, 'name' => 'mi-api']);
    }

    public function test_agent_executes_tool_and_replies(): void
    {
        // 1ª respuesta: el modelo pide get_resource_status; 2ª: texto final.
        Http::fakeSequence('api.anthropic.com/v1/messages')
            ->push([
                'stop_reason' => 'tool_use',
                'content'     => [[
                    'type'  => 'tool_use',
                    'id'    => 'toolu_1',
                    'name'  => 'get_resource_status',
                    'input' => ['resource' => $this->resource->uuid],
                ]],
                'usage'       => ['input_tokens' => 100, 'output_tokens' => 20],
            ])
            ->push([
                'stop_reason' => 'end_turn',
                'content'     => [['type' => 'text', 'text' => 'Tu app mi-api está en estado creating.']],
                'usage'       => ['input_tokens' => 150, 'output_tokens' => 30],
            ]);

        $conversation = $this->actingAs($this->user)
            ->postJson('/api/v2/ai/conversations', ['resource' => $this->resource->uuid])
            ->assertCreated()
            ->json('data.uuid');

        $response = $this->actingAs($this->user)->postJson(
            "/api/v2/ai/conversations/{$conversation}/messages",
            ['message' => '¿Cómo está mi app?'],
        );

        $response->assertOk()
            ->assertJsonPath('data.tool_calls.0.tool', 'get_resource_status')
            ->assertJsonPath('data.tool_calls.0.ok', true);

        $this->assertStringContainsString('mi-api', $response->json('data.reply'));

        // Rastro persistido: user + assistant con tool_calls y tokens acumulados.
        $messages = AiConversation::where('uuid', $conversation)->first()->messages;
        $this->assertSame(['user', 'assistant'], $messages->pluck('role')->all());
        $this->assertSame(250, $messages->last()->tokens_in);  // 100 + 150
        $this->assertSame(50, $messages->last()->tokens_out);  // 20 + 30
    }

    public function test_tools_respect_team_scoping(): void
    {
        $stranger = Resource::factory()->create(); // recurso de otro equipo

        Http::fakeSequence('api.anthropic.com/v1/messages')
            ->push([
                'stop_reason' => 'tool_use',
                'content'     => [[
                    'type'  => 'tool_use',
                    'id'    => 'toolu_1',
                    'name'  => 'get_resource_status',
                    'input' => ['resource' => $stranger->uuid],
                ]],
                'usage'       => ['input_tokens' => 100, 'output_tokens' => 20],
            ])
            ->push([
                'stop_reason' => 'end_turn',
                'content'     => [['type' => 'text', 'text' => 'No tengo acceso a ese recurso.']],
                'usage'       => ['input_tokens' => 120, 'output_tokens' => 15],
            ]);

        $conversation = $this->actingAs($this->user)
            ->postJson('/api/v2/ai/conversations', [])
            ->json('data.uuid');

        $response = $this->actingAs($this->user)->postJson(
            "/api/v2/ai/conversations/{$conversation}/messages",
            ['message' => "Dame el estado del recurso {$stranger->uuid}"],
        );

        // La herramienta corre pero devuelve error de acceso (ok=false).
        $response->assertOk()->assertJsonPath('data.tool_calls.0.ok', false);
    }

    public function test_cannot_use_someone_elses_conversation(): void
    {
        config(['anthropic.api_key' => 'test-key']);

        $other        = User::factory()->create(['status' => 'active']);
        $conversation = AiConversation::create(['user_id' => $other->id]);

        $this->actingAs($this->user)->postJson(
            "/api/v2/ai/conversations/{$conversation->uuid}/messages",
            ['message' => 'hola'],
        )->assertForbidden();
    }

    public function test_context_resource_requires_access(): void
    {
        $stranger = Resource::factory()->create();

        $this->actingAs($this->user)
            ->postJson('/api/v2/ai/conversations', ['resource' => $stranger->uuid])
            ->assertForbidden();
    }

    public function test_returns_503_without_api_key(): void
    {
        config(['anthropic.api_key' => null]);

        $conversation = AiConversation::create(['user_id' => $this->user->id]);

        $this->actingAs($this->user)->postJson(
            "/api/v2/ai/conversations/{$conversation->uuid}/messages",
            ['message' => 'hola'],
        )->assertStatus(503);
    }

    public function test_diagnose_job_replaces_error_summary_with_root_cause(): void
    {
        config(['anthropic.api_key' => null]); // sin LLM → template del taxón

        $deployment = $this->resource->deployments()->create([
            'trigger'       => DeploymentTrigger::Manual,
            'status'        => DeploymentStatus::Failed,
            'error_summary' => 'raw tail',
        ]);
        $deployment->logs()->create([
            'seq'    => 1,
            'stream' => 'build',
            'chunk'  => "npm ERR! code ERESOLVE\nnpm ERR! ERESOLVE unable to resolve dependency tree",
        ]);

        (new DiagnoseFailedDeployment($deployment->id))->handle(
            app(\App\Domains\Platform\Ai\Troubleshooting\DeploymentDiagnosis::class)
        );

        $deployment->refresh();
        $this->assertStringContainsString('dependencias de npm', $deployment->error_summary);
        $this->assertStringContainsString('Fixes sugeridos', $deployment->error_summary);
    }
}
