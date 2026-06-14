<?php

namespace Tests\Feature\Compute;

use App\Domains\Platform\Compute\Enums\TeamRole;
use App\Domains\Platform\Compute\Models\Project;
use App\Domains\Platform\Compute\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ComputeProjectsApiTest extends TestCase
{
    use RefreshDatabase;

    private function actingUser(): User
    {
        return User::factory()->create(['status' => 'active']);
    }

    public function test_user_can_list_own_teams(): void
    {
        $user = $this->actingUser();
        Team::factory()->personal()->create(['owner_user_id' => $user->id]);
        Team::factory()->create(); // equipo ajeno — no debe aparecer

        $response = $this->actingAs($user)->getJson('/api/v2/teams');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.role', TeamRole::Owner->value);
    }

    public function test_member_can_create_project_with_default_environment(): void
    {
        $user = $this->actingUser();
        $team = Team::factory()->personal()->create(['owner_user_id' => $user->id]);

        $response = $this->actingAs($user)->postJson('/api/v2/projects', [
            'team'           => $team->uuid,
            'name'           => 'Mi API Laravel',
            'repo_full_name' => 'roke/mi-api',
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.slug', 'mi-api-laravel');

        $project = Project::where('team_id', $team->id)->first();
        $this->assertNotNull($project);
        $this->assertSame('production', $project->environments()->first()->slug);
    }

    public function test_viewer_cannot_create_project(): void
    {
        $user = $this->actingUser();
        $team = Team::factory()->create();
        $team->members()->attach($user->id, ['role' => TeamRole::Viewer->value]);

        $response = $this->actingAs($user)->postJson('/api/v2/projects', [
            'team' => $team->uuid,
            'name' => 'No debería existir',
        ]);

        $response->assertForbidden();
    }

    public function test_non_member_cannot_view_project(): void
    {
        $outsider = $this->actingUser();
        $project  = Project::factory()->create();

        $response = $this->actingAs($outsider)->getJson("/api/v2/projects/{$project->uuid}");

        $response->assertForbidden();
    }

    public function test_project_listing_is_scoped_to_membership(): void
    {
        $user = $this->actingUser();
        $team = Team::factory()->personal()->create(['owner_user_id' => $user->id]);
        Project::factory()->create(['team_id' => $team->id]);
        Project::factory()->count(2)->create(); // de otros equipos

        $response = $this->actingAs($user)->getJson('/api/v2/projects');

        $response->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_admin_can_archive_project(): void
    {
        $user = $this->actingUser();
        $team = Team::factory()->personal()->create(['owner_user_id' => $user->id]);
        $project = Project::factory()->create(['team_id' => $team->id]);

        $this->actingAs($user)->deleteJson("/api/v2/projects/{$project->uuid}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['archived_at']]);

        $this->assertNotNull($project->fresh()->archived_at);

        $this->actingAs($user)->getJson('/api/v2/projects')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_developer_can_update_project_settings(): void
    {
        $user = $this->actingUser();
        $team = Team::factory()->create();
        $team->members()->attach($user->id, ['role' => TeamRole::Developer->value]);
        $project = Project::factory()->create([
            'team_id'        => $team->id,
            'name'           => 'Nombre viejo',
            'default_branch' => 'main',
        ]);

        $this->actingAs($user)->patchJson("/api/v2/projects/{$project->uuid}", [
            'name'           => 'Nombre nuevo',
            'default_branch' => 'develop',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Nombre nuevo')
            ->assertJsonPath('data.default_branch', 'develop');

        $fresh = $project->fresh();
        $this->assertSame('Nombre nuevo', $fresh->name);
        $this->assertSame('develop', $fresh->default_branch);
    }

    public function test_viewer_cannot_update_project_settings(): void
    {
        $user = $this->actingUser();
        $team = Team::factory()->create();
        $team->members()->attach($user->id, ['role' => TeamRole::Viewer->value]);
        $project = Project::factory()->create(['team_id' => $team->id, 'name' => 'Intacto']);

        $this->actingAs($user)->patchJson("/api/v2/projects/{$project->uuid}", [
            'name' => 'Hackeado',
        ])->assertForbidden();

        $this->assertSame('Intacto', $project->fresh()->name);
    }

    public function test_developer_cannot_archive_project(): void
    {
        $user = $this->actingUser();
        $team = Team::factory()->create();
        $team->members()->attach($user->id, ['role' => TeamRole::Developer->value]);
        $project = Project::factory()->create(['team_id' => $team->id]);

        $this->actingAs($user)->deleteJson("/api/v2/projects/{$project->uuid}")
            ->assertForbidden();

        $this->assertNull($project->fresh()->archived_at);
    }

    public function test_game_server_mirror_is_excluded_from_projects(): void
    {
        $user = $this->actingUser();
        $team = Team::factory()->personal()->create(['owner_user_id' => $user->id]);

        // App real (con repo) → sí aparece.
        Project::factory()->create(['team_id' => $team->id, 'repo_full_name' => 'roke/app']);
        // Espejo de game servers (slug 'game-servers' sin repo) → NO es app, se excluye.
        Project::factory()->create([
            'team_id'        => $team->id,
            'slug'           => 'game-servers',
            'repo_full_name' => null,
        ]);

        $this->actingAs($user)->getJson('/api/v2/projects')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_guest_is_rejected(): void
    {
        $this->getJson('/api/v2/teams')->assertUnauthorized();
    }
}
