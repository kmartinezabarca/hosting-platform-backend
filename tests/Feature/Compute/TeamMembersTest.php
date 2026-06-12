<?php

namespace Tests\Feature\Compute;

use App\Domains\Platform\Compute\Enums\TeamRole;
use App\Domains\Platform\Compute\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Miembros de equipo (mes 3, "team plans"): alta/baja/rol con guardrails
 * (owner intocable, equipo personal sin miembros) y cupo por plan.
 */
class TeamMembersTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Team $team; // plan team → max_members 10 (holgura para tests de roles)

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['status' => 'active']);
        $this->team  = Team::factory()->create([
            'owner_user_id' => $this->owner->id,
            'plan_tier'     => 'team', // max_members 10
        ]);
    }

    private function makeUser(string $email): User
    {
        return User::factory()->create(['status' => 'active', 'email' => $email]);
    }

    public function test_owner_adds_a_member_by_email(): void
    {
        $invitee = $this->makeUser('dev@acme.test');

        $this->actingAs($this->owner)->postJson("/api/v2/teams/{$this->team->uuid}/members", [
            'email' => 'dev@acme.test',
            'role'  => 'developer',
        ])
            ->assertCreated()
            ->assertJsonPath('data.email', 'dev@acme.test')
            ->assertJsonPath('data.role', 'developer')
            ->assertJsonPath('data.is_owner', false);

        $this->assertSame(TeamRole::Developer, $this->team->roleFor($invitee));
    }

    public function test_cannot_add_member_to_personal_team(): void
    {
        $personal = Team::factory()->personal()->create([
            'owner_user_id' => $this->owner->id,
            'plan_tier'     => 'pro',
        ]);
        $this->makeUser('x@acme.test');

        $this->actingAs($this->owner)->postJson("/api/v2/teams/{$personal->uuid}/members", [
            'email' => 'x@acme.test', 'role' => 'developer',
        ])->assertStatus(422);
    }

    public function test_cannot_add_unknown_email(): void
    {
        $this->actingAs($this->owner)->postJson("/api/v2/teams/{$this->team->uuid}/members", [
            'email' => 'ghost@acme.test', 'role' => 'developer',
        ])->assertStatus(422);
    }

    public function test_cannot_add_same_member_twice(): void
    {
        $this->makeUser('dup@acme.test');
        $url = "/api/v2/teams/{$this->team->uuid}/members";

        $this->actingAs($this->owner)->postJson($url, ['email' => 'dup@acme.test', 'role' => 'viewer'])->assertCreated();
        $this->actingAs($this->owner)->postJson($url, ['email' => 'dup@acme.test', 'role' => 'viewer'])->assertStatus(422);
    }

    public function test_owner_role_cannot_be_assigned(): void
    {
        $this->makeUser('boss@acme.test');

        $this->actingAs($this->owner)->postJson("/api/v2/teams/{$this->team->uuid}/members", [
            'email' => 'boss@acme.test', 'role' => 'owner',
        ])->assertStatus(422)->assertJsonValidationErrors('role');
    }

    public function test_free_plan_blocks_members(): void
    {
        $free = Team::factory()->create(['owner_user_id' => $this->owner->id, 'plan_tier' => 'free']);
        $this->makeUser('nope@acme.test');

        $this->actingAs($this->owner)->postJson("/api/v2/teams/{$free->uuid}/members", [
            'email' => 'nope@acme.test', 'role' => 'developer',
        ])->assertStatus(422);
    }

    public function test_admin_can_manage_but_viewer_cannot(): void
    {
        $admin  = $this->makeUser('admin@acme.test');
        $viewer = $this->makeUser('viewer@acme.test');
        $this->team->members()->attach($admin->id, ['role' => TeamRole::Admin->value]);
        $this->team->members()->attach($viewer->id, ['role' => TeamRole::Viewer->value]);
        $target = $this->makeUser('target@acme.test');

        // Viewer no puede gestionar.
        $this->actingAs($viewer)->postJson("/api/v2/teams/{$this->team->uuid}/members", [
            'email' => 'target@acme.test', 'role' => 'viewer',
        ])->assertForbidden();

        // Admin sí.
        $this->actingAs($admin)->postJson("/api/v2/teams/{$this->team->uuid}/members", [
            'email' => 'target@acme.test', 'role' => 'viewer',
        ])->assertCreated();
    }

    public function test_update_member_role(): void
    {
        $member = $this->makeUser('m@acme.test');
        $this->team->members()->attach($member->id, ['role' => TeamRole::Viewer->value]);

        $this->actingAs($this->owner)->patchJson("/api/v2/teams/{$this->team->uuid}/members/{$member->uuid}", [
            'role' => 'admin',
        ])->assertOk()->assertJsonPath('data.role', 'admin');

        $this->assertSame(TeamRole::Admin, $this->team->roleFor($member));
    }

    public function test_cannot_change_owner_role(): void
    {
        $this->actingAs($this->owner)->patchJson(
            "/api/v2/teams/{$this->team->uuid}/members/{$this->owner->uuid}",
            ['role' => 'viewer'],
        )->assertStatus(422);
    }

    public function test_remove_member_but_not_owner(): void
    {
        $member = $this->makeUser('bye@acme.test');
        $this->team->members()->attach($member->id, ['role' => TeamRole::Developer->value]);

        $this->actingAs($this->owner)
            ->deleteJson("/api/v2/teams/{$this->team->uuid}/members/{$member->uuid}")
            ->assertOk();
        $this->assertFalse($this->team->fresh()->hasMember($member));

        // El owner no se puede quitar.
        $this->actingAs($this->owner)
            ->deleteJson("/api/v2/teams/{$this->team->uuid}/members/{$this->owner->uuid}")
            ->assertStatus(422);
    }

    public function test_index_lists_members_with_owner_flag(): void
    {
        $member = $this->makeUser('list@acme.test');
        $this->team->members()->attach($member->id, ['role' => TeamRole::Billing->value]);

        $data = $this->actingAs($this->owner)
            ->getJson("/api/v2/teams/{$this->team->uuid}/members")
            ->assertOk()
            ->json('data');

        $this->assertCount(2, $data); // owner + 1
        $owner = collect($data)->firstWhere('is_owner', true);
        $this->assertSame('owner', $owner['role']);
    }

    public function test_teams_endpoint_exposes_member_usage(): void
    {
        $this->actingAs($this->owner)->getJson('/api/v2/teams')
            ->assertOk()
            ->assertJsonPath('data.0.usage.max_members', 10)
            ->assertJsonPath('data.0.usage.members_used', 1); // solo el owner
    }

    public function test_member_cap_is_enforced_when_full(): void
    {
        // Plan pro = 3 miembros. Llenamos: owner + 2 → cupo lleno.
        $pro = Team::factory()->create(['owner_user_id' => $this->owner->id, 'plan_tier' => 'pro']);
        $pro->members()->attach($this->makeUser('a@acme.test')->id, ['role' => TeamRole::Viewer->value]);
        $pro->members()->attach($this->makeUser('b@acme.test')->id, ['role' => TeamRole::Viewer->value]);
        $this->makeUser('c@acme.test');

        $this->actingAs($this->owner)->postJson("/api/v2/teams/{$pro->uuid}/members", [
            'email' => 'c@acme.test', 'role' => 'viewer',
        ])->assertStatus(422);
    }
}
