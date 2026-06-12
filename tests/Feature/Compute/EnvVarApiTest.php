<?php

namespace Tests\Feature\Compute;

use App\Domains\Platform\Compute\Enums\TeamRole;
use App\Domains\Platform\Compute\Models\Environment;
use App\Domains\Platform\Compute\Models\Project;
use App\Domains\Platform\Compute\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnvVarApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Environment $environment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['status' => 'active']);
        $team       = Team::factory()->personal()->create(['owner_user_id' => $this->user->id]);
        $project    = Project::factory()->create(['team_id' => $team->id]);
        $this->environment = Environment::factory()->create(['project_id' => $project->id]);
    }

    public function test_upsert_creates_and_updates_vars(): void
    {
        $this->actingAs($this->user)->putJson(
            "/api/v2/environments/{$this->environment->uuid}/env-vars",
            ['vars' => [
                ['key' => 'APP_ENV', 'value' => 'production', 'is_secret' => false],
                ['key' => 'API_TOKEN', 'value' => 'super-secreto'],
            ]],
        )->assertOk()->assertJsonPath('data.applies_on_next_deploy', true);

        // Update de una clave existente no duplica.
        $this->actingAs($this->user)->putJson(
            "/api/v2/environments/{$this->environment->uuid}/env-vars",
            ['vars' => [['key' => 'APP_ENV', 'value' => 'staging', 'is_secret' => false]]],
        )->assertOk();

        $this->assertSame(2, $this->environment->envVars()->count());
        $this->assertSame('staging', (string) $this->environment->envVars()->where('key', 'APP_ENV')->first()->value_encrypted);
    }

    public function test_secret_values_are_never_returned(): void
    {
        $this->environment->envVars()->create([
            'key' => 'DB_PASSWORD', 'value_encrypted' => 'hunter2', 'is_secret' => true,
        ]);
        $this->environment->envVars()->create([
            'key' => 'APP_LOCALE', 'value_encrypted' => 'es_MX', 'is_secret' => false,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/v2/environments/{$this->environment->uuid}/env-vars")
            ->assertOk();

        $vars = collect($response->json('data'))->keyBy('key');

        $this->assertNull($vars['DB_PASSWORD']['value']);
        $this->assertTrue($vars['DB_PASSWORD']['is_secret']);
        $this->assertSame('es_MX', $vars['APP_LOCALE']['value']);
        $this->assertStringNotContainsString('hunter2', $response->getContent());
    }

    public function test_delete_removes_key(): void
    {
        $this->environment->envVars()->create([
            'key' => 'OLD_VAR', 'value_encrypted' => 'x', 'is_secret' => false,
        ]);

        $this->actingAs($this->user)
            ->deleteJson("/api/v2/environments/{$this->environment->uuid}/env-vars/OLD_VAR")
            ->assertOk();

        $this->assertSame(0, $this->environment->envVars()->count());
    }

    public function test_invalid_key_format_is_rejected(): void
    {
        $this->actingAs($this->user)->putJson(
            "/api/v2/environments/{$this->environment->uuid}/env-vars",
            ['vars' => [['key' => '9INVALID-KEY!', 'value' => 'x']]],
        )->assertStatus(422);
    }

    public function test_viewer_cannot_modify_vars(): void
    {
        $viewer = User::factory()->create(['status' => 'active']);
        $this->environment->project->team->members()
            ->attach($viewer->id, ['role' => TeamRole::Viewer->value]);

        $this->actingAs($viewer)->putJson(
            "/api/v2/environments/{$this->environment->uuid}/env-vars",
            ['vars' => [['key' => 'X', 'value' => 'y']]],
        )->assertForbidden();

        // Pero sí puede listarlas (view).
        $this->actingAs($viewer)
            ->getJson("/api/v2/environments/{$this->environment->uuid}/env-vars")
            ->assertOk();
    }

    public function test_non_member_cannot_access(): void
    {
        $outsider = User::factory()->create(['status' => 'active']);

        $this->actingAs($outsider)
            ->getJson("/api/v2/environments/{$this->environment->uuid}/env-vars")
            ->assertForbidden();
    }
}
