<?php

namespace Tests\Feature\Compute;

use App\Domains\Platform\Compute\Enums\DeploymentTrigger;
use App\Domains\Platform\Compute\Enums\EnvironmentType;
use App\Domains\Platform\Compute\Enums\ResourceStatus;
use App\Domains\Platform\Compute\Models\Environment;
use App\Domains\Platform\Compute\Models\GithubInstallation;
use App\Domains\Platform\Compute\Models\Project;
use App\Domains\Platform\Compute\Models\Resource;
use App\Domains\Platform\Compute\Models\Team;
use App\Domains\Platform\Compute\Previews\PreviewEnvironmentService;
use App\Domains\Platform\Compute\Providers\Contracts\AppRuntimeDriver;
use App\Domains\Platform\Git\GitHubAppClient;
use App\Domains\Platform\Git\Jobs\HandlePullRequestEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeAppRuntimeDriver;
use Tests\Support\FakeGitHubAppClient;
use Tests\TestCase;

/**
 * Ambientes preview de PR (mes 2 #1): un PR crea/redespliega un ambiente
 * efímero `pr-{n}` y comenta la URL; al cerrarlo se destruye todo.
 */
class PrPreviewTest extends TestCase
{
    use RefreshDatabase;

    private FakeAppRuntimeDriver $driver;
    private FakeGitHubAppClient $github;
    private GithubInstallation $installation;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->driver = new FakeAppRuntimeDriver();
        $this->github = new FakeGitHubAppClient();
        $this->app->instance(AppRuntimeDriver::class, $this->driver);
        $this->app->instance(GitHubAppClient::class, $this->github);

        $user = User::factory()->create(['status' => 'active']);
        $team = Team::factory()->personal()->create(['owner_user_id' => $user->id, 'plan_tier' => 'pro']);

        $this->installation = GithubInstallation::create([
            'team_id'         => $team->id,
            'installation_id' => 555,
            'account_login'   => 'roke',
        ]);

        $this->project = Project::factory()->create([
            'team_id'                => $team->id,
            'github_installation_id' => $this->installation->id,
            'repo_full_name'         => 'roke/demo',
            'slug'                   => 'demo',
            'default_branch'         => 'main',
            'detected_stack'         => ['framework' => 'laravel', 'build' => ['method' => 'nixpacks'], 'run' => ['port' => 8080]],
        ]);

        // App base en un ambiente de producción → plantilla del preview.
        $prodEnv = Environment::factory()->create([
            'project_id' => $this->project->id,
            'slug'       => 'production',
            'type'       => EnvironmentType::Production->value,
        ]);
        $template = Resource::factory()->create([
            'environment_id' => $prodEnv->id,
            'status'         => ResourceStatus::Running,
            'spec'           => ['ram_mb' => 1024, 'cpu' => 1.0, 'fqdn' => 'demo.example.com'],
        ]);
        $template->providerRefs()->create(['provider' => 'coolify', 'external_id' => 'cool-prod-1']);
    }

    private function fire(string $action, int $pr = 7, string $branch = 'feature/x', ?string $sha = 'deadbeef'): void
    {
        (new HandlePullRequestEvent(555, 'roke/demo', $action, $pr, $branch, $sha, 'Mi PR'))
            ->handle(app(PreviewEnvironmentService::class));
    }

    public function test_pr_opened_creates_preview_environment_and_comments(): void
    {
        $this->fire('opened');

        $env = Environment::where('project_id', $this->project->id)->where('slug', 'pr-7')->first();
        $this->assertNotNull($env);
        $this->assertTrue($env->ephemeral);
        $this->assertFalse($env->auto_deploy);
        $this->assertSame(EnvironmentType::Preview, $env->type);
        $this->assertSame(7, $env->pr_number);
        $this->assertSame('feature/x', $env->branch);

        $resource = $env->resources()->firstOrFail();
        $this->assertSame(ResourceStatus::Running, $resource->status);
        $this->assertSame('demo-pr-7', $resource->name);
        $this->assertStringContainsString('demo-pr-7-', $resource->spec['fqdn']);
        // Hereda cpu/ram de la plantilla.
        $this->assertSame(1024, $resource->spec['ram_mb']);

        $deployment = $resource->deployments()->firstOrFail();
        $this->assertSame(DeploymentTrigger::PrOpen, $deployment->trigger);
        $this->assertSame(7, $deployment->pr_number);

        // Comentario creado una vez con la URL del preview.
        $this->assertCount(1, $this->github->comments);
        $this->assertSame('create', $this->github->comments[0]['method']);
        $this->assertStringContainsString($resource->spec['fqdn'], $this->github->comments[0]['body']);
        $this->assertSame(9001, $env->fresh()->pr_comment_id);
    }

    public function test_pr_synchronize_redeploys_and_updates_same_comment(): void
    {
        $this->fire('opened');
        $this->fire('synchronize', sha: 'cafe1234');

        $env      = Environment::where('slug', 'pr-7')->firstOrFail();
        $resource = $env->resources()->firstOrFail();

        // Un solo recurso; dos deployments (open + sync).
        $this->assertSame(1, $env->resources()->count());
        $this->assertTrue($resource->deployments()->where('trigger', DeploymentTrigger::PrSync->value)->exists());

        // El comentario se actualiza, no se duplica.
        $this->assertSame(1, collect($this->github->comments)->where('method', 'create')->count());
        $this->assertTrue($this->github->called('update'));
    }

    public function test_pr_closed_tears_down_preview(): void
    {
        $this->fire('opened');
        $env      = Environment::where('slug', 'pr-7')->firstOrFail();
        $resource = $env->resources()->firstOrFail();

        $this->driver->calls = [];
        $this->fire('closed');

        // App borrada del runtime; el ambiente se elimina y su FK cascade
        // hard-borra el recurso preview (efímero, sin historial que conservar).
        $this->assertTrue($this->driver->called('deleteApplication'));
        $this->assertNull(Environment::where('slug', 'pr-7')->first());
        $this->assertNull(Resource::withTrashed()->find($resource->id));

        // Último comentario: preview eliminado.
        $last = end($this->github->comments);
        $this->assertSame('update', $last['method']);
        $this->assertStringContainsString('eliminado', $last['body']);
    }

    public function test_preview_skipped_when_project_has_no_base_app(): void
    {
        // Proyecto sin app base.
        $bare = Project::factory()->create([
            'team_id'                => $this->project->team_id,
            'github_installation_id' => $this->installation->id,
            'repo_full_name'         => 'roke/bare',
            'default_branch'         => 'main',
        ]);

        (new HandlePullRequestEvent(555, 'roke/bare', 'opened', 3, 'feat', 'sha', 'PR'))
            ->handle(app(PreviewEnvironmentService::class));

        $this->assertSame(0, $bare->environments()->count());
        $this->assertEmpty($this->github->comments);
    }
}
