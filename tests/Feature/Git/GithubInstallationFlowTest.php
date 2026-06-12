<?php

namespace Tests\Feature\Git;

use App\Domains\Platform\Compute\Enums\TeamRole;
use App\Domains\Platform\Compute\Models\GithubInstallation;
use App\Domains\Platform\Compute\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GithubInstallationFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Llave RSA efímera para que appJwt() pueda firmar; las llamadas a
        // GitHub se interceptan con Http::fake.
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);
        config(['github.private_key_base64' => base64_encode($pem)]);
    }

    public function test_admin_gets_install_url_with_state(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $team = Team::factory()->personal()->create(['owner_user_id' => $user->id]);

        $response = $this->actingAs($user)->getJson("/api/v2/github/install-url?team={$team->uuid}");

        $response->assertOk();
        $url = $response->json('data.install_url');
        $this->assertStringContainsString('github.com/apps/roke-platform-test/installations/new', $url);
        $this->assertStringContainsString('state=', $url);
    }

    public function test_developer_cannot_get_install_url(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $team = Team::factory()->create();
        $team->members()->attach($user->id, ['role' => TeamRole::Developer->value]);

        $this->actingAs($user)
            ->getJson("/api/v2/github/install-url?team={$team->uuid}")
            ->assertForbidden();
    }

    public function test_claim_binds_installation_to_team(): void
    {
        Http::fake([
            'api.github.com/app/installations/4242' => Http::response([
                'id'      => 4242,
                'account' => ['login' => 'roke-industries'],
            ]),
        ]);

        $user = User::factory()->create(['status' => 'active']);
        $team = Team::factory()->personal()->create(['owner_user_id' => $user->id]);

        $state = Crypt::encryptString(json_encode([
            'team' => $team->uuid,
            'user' => $user->id,
            'exp'  => now()->addMinutes(30)->timestamp,
        ]));

        $response = $this->actingAs($user)->postJson('/api/v2/github/installations/claim', [
            'installation_id' => 4242,
            'state'           => $state,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.account_login', 'roke-industries');

        $this->assertDatabaseHas('github_installations', [
            'team_id'         => $team->id,
            'installation_id' => 4242,
        ]);
    }

    public function test_claim_with_tampered_state_fails(): void
    {
        $user = User::factory()->create(['status' => 'active']);

        $this->actingAs($user)->postJson('/api/v2/github/installations/claim', [
            'installation_id' => 1,
            'state'           => 'estado-falso',
        ])->assertStatus(422);
    }

    public function test_claim_with_expired_state_fails(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $team = Team::factory()->personal()->create(['owner_user_id' => $user->id]);

        $state = Crypt::encryptString(json_encode([
            'team' => $team->uuid,
            'user' => $user->id,
            'exp'  => now()->subMinute()->timestamp,
        ]));

        $this->actingAs($user)->postJson('/api/v2/github/installations/claim', [
            'installation_id' => 1,
            'state'           => $state,
        ])->assertStatus(422);
    }

    public function test_claim_with_foreign_state_fails(): void
    {
        $user  = User::factory()->create(['status' => 'active']);
        $other = User::factory()->create(['status' => 'active']);
        $team  = Team::factory()->personal()->create(['owner_user_id' => $other->id]);

        $state = Crypt::encryptString(json_encode([
            'team' => $team->uuid,
            'user' => $other->id, // emitido para otro usuario
            'exp'  => now()->addMinutes(30)->timestamp,
        ]));

        $this->actingAs($user)->postJson('/api/v2/github/installations/claim', [
            'installation_id' => 1,
            'state'           => $state,
        ])->assertForbidden();
    }

    public function test_member_can_list_repos(): void
    {
        Http::fake([
            'api.github.com/app/installations/4242/access_tokens' => Http::response(['token' => 'ghs_test'], 201),
            'api.github.com/installation/repositories*'            => Http::response([
                'total_count'  => 2,
                'repositories' => [
                    ['full_name' => 'roke/api', 'private' => true, 'default_branch' => 'main', 'language' => 'PHP', 'pushed_at' => '2026-06-10T00:00:00Z'],
                    ['full_name' => 'roke/web', 'private' => true, 'default_branch' => 'main', 'language' => 'JS', 'pushed_at' => '2026-06-09T00:00:00Z'],
                ],
            ]),
        ]);

        $user = User::factory()->create(['status' => 'active']);
        $team = Team::factory()->personal()->create(['owner_user_id' => $user->id]);

        $installation = GithubInstallation::create([
            'team_id'         => $team->id,
            'installation_id' => 4242,
            'account_login'   => 'roke',
        ]);

        $response = $this->actingAs($user)
            ->getJson("/api/v2/github/installations/{$installation->id}/repos?search=api");

        $response->assertOk()
            ->assertJsonPath('data.repositories.0.full_name', 'roke/api')
            ->assertJsonCount(1, 'data.repositories'); // filtro client-side
    }

    public function test_non_member_cannot_list_repos(): void
    {
        $outsider     = User::factory()->create(['status' => 'active']);
        $installation = GithubInstallation::create([
            'team_id'         => Team::factory()->create()->id,
            'installation_id' => 4243,
            'account_login'   => 'roke',
        ]);

        $this->actingAs($outsider)
            ->getJson("/api/v2/github/installations/{$installation->id}/repos")
            ->assertForbidden();
    }
}
