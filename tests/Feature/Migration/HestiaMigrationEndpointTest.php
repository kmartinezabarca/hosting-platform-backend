<?php

namespace Tests\Feature\Migration;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HestiaMigrationEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_plan_endpoint_returns_migration_plan(): void
    {
        $user = User::factory()->create(['status' => 'active']);

        $this->actingAs($user)->postJson('/api/v2/migrations/hestia/plan', [
            'web_domains' => [
                'app.com' => ['BACKEND' => 'PHP-8_1', 'SSL' => 'yes', 'ALIAS' => 'www.app.com', 'SUSPENDED' => 'no'],
            ],
            'databases' => [
                'u_db' => ['DBUSER' => 'u_user', 'TYPE' => 'mysql', 'SUSPENDED' => 'no'],
            ],
        ])
            ->assertStatus(200)
            ->assertJsonPath('data.summary.resources_planned', 2)
            ->assertJsonPath('data.resources.0.kind', 'app')
            ->assertJsonPath('data.resources.0.php_version', '8.1')
            ->assertJsonPath('data.resources.1.kind', 'database')
            ->assertJsonPath('data.resources.1.engine', 'mysql');
    }

    public function test_plan_endpoint_rejects_empty_payload(): void
    {
        $user = User::factory()->create(['status' => 'active']);

        $this->actingAs($user)->postJson('/api/v2/migrations/hestia/plan', [])
            ->assertStatus(422);
    }

    public function test_plan_endpoint_requires_auth(): void
    {
        $this->postJson('/api/v2/migrations/hestia/plan', ['web_domains' => ['a.com' => []]])
            ->assertStatus(401);
    }
}
