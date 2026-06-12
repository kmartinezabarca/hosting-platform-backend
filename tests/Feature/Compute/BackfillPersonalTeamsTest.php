<?php

namespace Tests\Feature\Compute;

use App\Domains\Platform\Compute\Enums\TeamRole;
use App\Domains\Platform\Compute\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackfillPersonalTeamsTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_personal_team_for_users_without_one(): void
    {
        $users = User::factory()->count(3)->create();

        $this->artisan('platform:compute:backfill-teams')->assertSuccessful();

        foreach ($users as $user) {
            $team = $user->fresh()->personalTeam();
            $this->assertNotNull($team, "Usuario {$user->email} sin equipo personal");
            $this->assertTrue($team->is_personal);
            $this->assertSame(TeamRole::Owner, $team->roleFor($user));
        }
    }

    public function test_is_idempotent(): void
    {
        User::factory()->count(2)->create();

        $this->artisan('platform:compute:backfill-teams')->assertSuccessful();
        $this->artisan('platform:compute:backfill-teams')->assertSuccessful();

        $this->assertSame(2, Team::where('is_personal', true)->count());
    }

    public function test_dry_run_writes_nothing(): void
    {
        User::factory()->create();

        $this->artisan('platform:compute:backfill-teams', ['--dry-run' => true])
            ->assertSuccessful();

        $this->assertSame(0, Team::count());
    }

    public function test_duplicate_usernames_get_unique_slugs(): void
    {
        User::factory()->count(2)->create(['username' => null, 'first_name' => 'Kevin']);

        $this->artisan('platform:compute:backfill-teams')->assertSuccessful();

        $this->assertSame(2, Team::where('is_personal', true)->count());
        $this->assertSame(2, Team::distinct('slug')->count('slug'));
    }
}
