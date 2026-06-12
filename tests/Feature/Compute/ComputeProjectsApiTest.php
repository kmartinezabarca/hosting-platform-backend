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

    public function test_guest_is_rejected(): void
    {
        $this->getJson('/api/v2/teams')->assertUnauthorized();
    }
}
