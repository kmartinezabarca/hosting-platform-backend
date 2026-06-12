<?php

namespace Tests\Feature\Git;

use App\Domains\Platform\Compute\Enums\DeploymentStatus;
use App\Domains\Platform\Compute\Models\Environment;
use App\Domains\Platform\Compute\Models\GithubInstallation;
use App\Domains\Platform\Compute\Models\Project;
use App\Domains\Platform\Compute\Models\Resource;
use App\Domains\Platform\Compute\Models\Team;
use App\Domains\Platform\Compute\Providers\Contracts\AppRuntimeDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\FakeAppRuntimeDriver;
use Tests\TestCase;

class GithubWebhookTest extends TestCase
{
    use RefreshDatabase;

    private FakeAppRuntimeDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        // El push arranca DeployFlow inline (cola sync): driver fake y token
        // de instalación pre-cacheado para que RefreshGitCredentials no
        // firme JWT ni toque la API de GitHub.
        $this->driver = new FakeAppRuntimeDriver();
        $this->app->instance(AppRuntimeDriver::class, $this->driver);
    }

    private function postWebhook(array $payload, string $event, ?string $secret = 'test-webhook-secret')
    {
        $body = json_encode($payload);

        $headers = ['X-GitHub-Event' => $event, 'Content-Type' => 'application/json'];
        if ($secret !== null) {
            $headers['X-Hub-Signature-256'] = 'sha256=' . hash_hmac('sha256', $body, $secret);
        }

        return $this->call('POST', '/api/webhooks/github', [], [], [], $this->transformHeadersToServerVars($headers), $body);
    }

    public function test_rejects_missing_signature(): void
    {
        $this->postWebhook(['zen' => 'hi'], 'ping', secret: null)->assertUnauthorized();
    }

    public function test_rejects_invalid_signature(): void
    {
        $this->postWebhook(['zen' => 'hi'], 'ping', secret: 'wrong-secret')->assertUnauthorized();
    }

    public function test_accepts_ping_with_valid_signature(): void
    {
        $this->postWebhook(['zen' => 'hi'], 'ping')->assertStatus(202);
    }

    public function test_installation_deleted_removes_claimed_row(): void
    {
        $installation = GithubInstallation::create([
            'team_id'         => Team::factory()->create()->id,
            'installation_id' => 777,
            'account_login'   => 'roke',
        ]);

        $this->postWebhook([
            'action'       => 'deleted',
            'installation' => ['id' => 777],
        ], 'installation')->assertStatus(202);

        $this->assertDatabaseMissing('github_installations', ['id' => $installation->id]);
    }

    public function test_installation_suspend_and_unsuspend(): void
    {
        $installation = GithubInstallation::create([
            'team_id'         => Team::factory()->create()->id,
            'installation_id' => 778,
            'account_login'   => 'roke',
        ]);

        $this->postWebhook(['action' => 'suspend', 'installation' => ['id' => 778]], 'installation');
        $this->assertNotNull($installation->fresh()->suspended_at);

        $this->postWebhook(['action' => 'unsuspend', 'installation' => ['id' => 778]], 'installation');
        $this->assertNull($installation->fresh()->suspended_at);
    }

    public function test_unclaimed_installation_event_is_noop(): void
    {
        $this->postWebhook([
            'action'       => 'created',
            'installation' => ['id' => 999],
        ], 'installation')->assertStatus(202);

        $this->assertDatabaseCount('github_installations', 0);
    }

    public function test_push_to_tracked_branch_creates_deployment_and_runs_flow(): void
    {
        $team         = Team::factory()->create();
        $installation = GithubInstallation::create([
            'team_id'         => $team->id,
            'installation_id' => 555,
            'account_login'   => 'roke',
        ]);
        $project = Project::factory()->create([
            'team_id'                => $team->id,
            'github_installation_id' => $installation->id,
            'repo_full_name'         => 'roke/mi-api',
            'default_branch'         => 'main',
        ]);
        $environment = Environment::factory()->create(['project_id' => $project->id]);
        $resource    = Resource::factory()->create(['environment_id' => $environment->id]);
        $resource->providerRefs()->create(['provider' => 'coolify', 'external_id' => 'cool-app-1']);

        Cache::put('github:installation_token:555', 'ghs_fake', 300);

        $this->postWebhook([
            'installation' => ['id' => 555],
            'repository'   => ['full_name' => 'roke/mi-api'],
            'ref'          => 'refs/heads/main',
            'after'        => 'abc123def',
            'head_commit'  => ['message' => 'fix: bug'],
        ], 'push')->assertStatus(202);

        // Con cola sync, el DeployFlow ya corrió completo.
        $this->assertDatabaseHas('deployments', [
            'resource_id' => $resource->id,
            'status'      => DeploymentStatus::Success->value,
            'commit_sha'  => 'abc123def',
            'branch'      => 'main',
        ]);
        $this->assertDatabaseHas('orchestrations', ['flow' => 'deploy']);
        $this->assertTrue($this->driver->called('updateGitRepository')); // URL tokenizada renovada
        $this->assertTrue($this->driver->called('triggerDeploy'));
    }

    public function test_push_to_untracked_branch_is_noop(): void
    {
        $team         = Team::factory()->create();
        $installation = GithubInstallation::create([
            'team_id'         => $team->id,
            'installation_id' => 556,
            'account_login'   => 'roke',
        ]);
        $project = Project::factory()->create([
            'team_id'                => $team->id,
            'github_installation_id' => $installation->id,
            'repo_full_name'         => 'roke/mi-api',
            'default_branch'         => 'main',
        ]);
        $environment = Environment::factory()->create(['project_id' => $project->id]);
        Resource::factory()->create(['environment_id' => $environment->id]);

        $this->postWebhook([
            'installation' => ['id' => 556],
            'repository'   => ['full_name' => 'roke/mi-api'],
            'ref'          => 'refs/heads/feature/x',
            'after'        => 'abc',
        ], 'push')->assertStatus(202);

        $this->assertDatabaseCount('deployments', 0);
    }

    public function test_push_from_suspended_installation_is_noop(): void
    {
        $team         = Team::factory()->create();
        $installation = GithubInstallation::create([
            'team_id'         => $team->id,
            'installation_id' => 557,
            'account_login'   => 'roke',
            'suspended_at'    => now(),
        ]);
        $project = Project::factory()->create([
            'team_id'                => $team->id,
            'github_installation_id' => $installation->id,
            'repo_full_name'         => 'roke/mi-api',
            'default_branch'         => 'main',
        ]);
        $environment = Environment::factory()->create(['project_id' => $project->id]);
        Resource::factory()->create(['environment_id' => $environment->id]);

        $this->postWebhook([
            'installation' => ['id' => 557],
            'repository'   => ['full_name' => 'roke/mi-api'],
            'ref'          => 'refs/heads/main',
            'after'        => 'abc',
        ], 'push')->assertStatus(202);

        $this->assertDatabaseCount('deployments', 0);
    }
}
