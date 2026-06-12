<?php

namespace Tests\Feature\Compute;

use App\Domains\Platform\Compute\Enums\ResourceStatus;
use App\Domains\Platform\Compute\Models\Environment;
use App\Domains\Platform\Compute\Models\Project;
use App\Domains\Platform\Compute\Models\Resource;
use App\Domains\Platform\Compute\Models\Team;
use App\Domains\Platform\Compute\Providers\Contracts\AppRuntimeDriver;
use App\Domains\Platform\Compute\Providers\Contracts\DatabaseDriver;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeAppRuntimeDriver;
use Tests\Support\FakeDatabaseDriver;
use Tests\TestCase;

/**
 * Self-service de bases de datos (mes 2 #4) + detection bindings (#3): un data
 * store se aprovisiona sin admin y sus credenciales se inyectan solas en las
 * apps del mismo ambiente vía el env_template del stack detectado.
 */
class DatabaseResourceTest extends TestCase
{
    use RefreshDatabase;

    private FakeAppRuntimeDriver $appDriver;
    private FakeDatabaseDriver $dbDriver;
    private User $user;
    private Project $project;
    private Environment $environment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->appDriver = new FakeAppRuntimeDriver();
        $this->dbDriver  = new FakeDatabaseDriver();
        $this->app->instance(AppRuntimeDriver::class, $this->appDriver);
        $this->app->instance(DatabaseDriver::class, $this->dbDriver);

        $this->user    = User::factory()->create(['status' => 'active']);
        $team          = Team::factory()->personal()->create(['owner_user_id' => $this->user->id]);
        $this->project = Project::factory()->create([
            'team_id'        => $team->id,
            'repo_full_name' => 'roke/demo-app',
            'default_branch' => 'main',
            'detected_stack' => [
                'framework'    => 'laravel',
                'build'        => ['method' => 'nixpacks'],
                'run'          => ['port' => 8080],
                'env_template' => [
                    ['key' => 'APP_KEY', 'generate' => 'laravel_key'],
                    ['key' => 'APP_ENV', 'value' => 'production'],
                    ['key' => 'DB_HOST', 'bind' => 'database.host'],
                    ['key' => 'DB_DATABASE', 'bind' => 'database.name'],
                    ['key' => 'DB_USERNAME', 'bind' => 'database.username'],
                    ['key' => 'DB_PASSWORD', 'bind' => 'database.password'],
                ],
            ],
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

    public function test_provisions_a_mysql_database_end_to_end(): void
    {
        $this->createResource(['kind' => 'database', 'name' => 'main-db', 'spec' => ['engine' => 'mysql']])
            ->assertStatus(202);

        $resource = Resource::where('kind', 'database')->firstOrFail();

        // Cola sync → la saga ya corrió completa.
        $this->assertSame(ResourceStatus::Running, $resource->status);
        $this->assertSame('cool-db-1', $resource->providerRef('coolify')->external_id);
        $this->assertTrue($this->dbDriver->called('createDatabase'));
        $this->assertTrue($this->dbDriver->called('startDatabase'));

        // La conexión quedó cifrada en reposo.
        $this->assertSame('app_db', $resource->fresh()->connection()['database']);
        $this->assertSame('s3cr3t-pass', $resource->fresh()->connection()['password']);

        // El engine pasó al driver.
        $createCall = collect($this->dbDriver->calls)->firstWhere('method', 'createDatabase');
        $this->assertSame('mysql', $createCall['args'][1]['engine']);
    }

    public function test_redis_does_not_require_engine(): void
    {
        $this->createResource(['kind' => 'redis', 'name' => 'cache'])->assertStatus(202);

        $createCall = collect($this->dbDriver->calls)->firstWhere('method', 'createDatabase');
        $this->assertSame('redis', $createCall['args'][1]['engine']);
    }

    public function test_database_requires_engine(): void
    {
        $this->createResource(['kind' => 'database', 'name' => 'main-db'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('spec.engine');
    }

    public function test_connection_detail_never_exposes_password(): void
    {
        $this->createResource(['kind' => 'database', 'name' => 'main-db', 'spec' => ['engine' => 'mysql']]);
        $resource = Resource::where('kind', 'database')->firstOrFail();

        $body = $this->actingAs($this->user)
            ->getJson("/api/v2/resources/{$resource->uuid}")
            ->assertOk();

        $body->assertJsonPath('data.connection.host', 'db-internal-host')
            ->assertJsonPath('data.connection.database', 'app_db')
            ->assertJsonPath('data.connection.password', null);
        $this->assertStringNotContainsString('s3cr3t-pass', $body->getContent());
    }

    public function test_app_provisioning_binds_database_credentials_and_generates_app_key(): void
    {
        // 1) Data store listo en el ambiente.
        $this->createResource(['kind' => 'database', 'name' => 'main-db', 'spec' => ['engine' => 'mysql']])
            ->assertStatus(202);

        // 2) App: ApplyDetectionBindings resuelve el env_template.
        $this->createResource(['kind' => 'app', 'name' => 'api'])->assertStatus(202);

        $vars = $this->environment->envVars()->pluck('value_encrypted', 'key');

        $this->assertSame('db-internal-host', $vars['DB_HOST']);
        $this->assertSame('app_db', $vars['DB_DATABASE']);
        $this->assertSame('app_user', $vars['DB_USERNAME']);
        $this->assertSame('s3cr3t-pass', $vars['DB_PASSWORD']);
        $this->assertSame('production', $vars['APP_ENV']);
        $this->assertStringStartsWith('base64:', $vars['APP_KEY']);

        // Las credenciales del binding son marcadas como secretas (la password).
        $this->assertTrue($this->environment->envVars()->where('key', 'DB_PASSWORD')->first()->is_secret);
        $this->assertSame('binding', $this->environment->envVars()->where('key', 'DB_HOST')->first()->source);

        // Y llegaron al runtime en el sync.
        $syncCall = collect($this->appDriver->calls)->firstWhere('method', 'syncEnvVars');
        $this->assertSame('db-internal-host', $syncCall['args'][1]['DB_HOST']);
    }

    public function test_binding_never_overwrites_a_user_defined_var(): void
    {
        $this->environment->envVars()->create([
            'key' => 'DB_DATABASE', 'value_encrypted' => 'mi_db_custom', 'is_secret' => false, 'source' => 'user',
        ]);

        $this->createResource(['kind' => 'database', 'name' => 'main-db', 'spec' => ['engine' => 'mysql']]);
        $this->createResource(['kind' => 'app', 'name' => 'api'])->assertStatus(202);

        $var = $this->environment->envVars()->where('key', 'DB_DATABASE')->first();
        $this->assertSame('mi_db_custom', $var->value_encrypted);
        $this->assertSame('user', $var->source);
    }
}
