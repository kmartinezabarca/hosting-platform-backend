<?php

namespace Tests\Feature\Compute;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GamePresetEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_game_presets_endpoint_returns_catalog(): void
    {
        $user = User::factory()->create(['status' => 'active']);

        $this->actingAs($user)->getJson('/api/v2/game-presets')
            ->assertStatus(200)
            // El orden = orden de config; minecraft es el primero.
            ->assertJsonPath('data.0.slug', 'minecraft')
            ->assertJsonPath('data.0.default_port', 25565)
            // Los ids de proveedor nunca llegan al cliente.
            ->assertJsonMissingPath('data.0.egg_id');
    }

    public function test_game_presets_requires_auth(): void
    {
        $this->getJson('/api/v2/game-presets')->assertStatus(401);
    }
}
