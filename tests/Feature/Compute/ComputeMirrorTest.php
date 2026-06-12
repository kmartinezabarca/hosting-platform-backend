<?php

namespace Tests\Feature\Compute;

use App\Domains\Platform\Compute\Enums\ResourceKind;
use App\Domains\Platform\Compute\Enums\ResourceStatus;
use App\Domains\Platform\Compute\Models\Resource;
use App\Domains\Platform\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ComputeMirrorTest extends TestCase
{
    use RefreshDatabase;

    private function gameServerService(array $overrides = []): Service
    {
        return Service::factory()->create(array_merge([
            'user_id'                 => User::factory()->create()->id,
            'name'                    => 'MC de Kevin',
            'status'                  => 'active',
            'pterodactyl_server_id'   => 4321,
            'pterodactyl_server_uuid' => 'ptero-uuid-1',
            'max_players'             => 20,
            'connection_details'      => ['display' => 'kevin.rokeindustries.com', 'identifier' => 'abc123'],
        ], $overrides));
    }

    public function test_mirror_command_creates_compute_resource(): void
    {
        $service = $this->gameServerService();

        $this->artisan('platform:compute:mirror-game-servers')->assertSuccessful();

        $resource = Resource::where('service_id', $service->id)->firstOrFail();

        $this->assertSame(ResourceKind::GameServer, $resource->kind);
        $this->assertSame(ResourceStatus::Running, $resource->status);
        $this->assertSame('kevin.rokeindustries.com', $resource->spec['address']);
        $this->assertSame('4321', $resource->providerRef('pterodactyl')->external_id);

        // Cuelga del proyecto agrupador del equipo personal del dueño.
        $this->assertSame('game-servers', $resource->environment->project->slug);
        $this->assertTrue($resource->environment->project->team->is_personal);
    }

    public function test_mirror_is_idempotent_and_refreshes_status(): void
    {
        $service = $this->gameServerService();

        $this->artisan('platform:compute:mirror-game-servers')->assertSuccessful();
        $service->update(['status' => 'suspended']);
        $this->artisan('platform:compute:mirror-game-servers')->assertSuccessful();

        $this->assertSame(1, Resource::where('service_id', $service->id)->count());
        $this->assertSame(
            ResourceStatus::Suspended,
            Resource::where('service_id', $service->id)->first()->status,
        );
    }

    public function test_services_without_pterodactyl_server_are_skipped(): void
    {
        Service::factory()->create([
            'user_id'               => User::factory()->create()->id,
            'pterodactyl_server_id' => null,
        ]);

        $this->artisan('platform:compute:mirror-game-servers')->assertSuccessful();

        $this->assertSame(0, Resource::count());
    }
}
