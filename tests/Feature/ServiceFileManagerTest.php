<?php

namespace Tests\Feature;

use App\Exceptions\PterodactylApiException;
use App\Models\Service;
use App\Models\User;
use App\Services\Pterodactyl\PterodactylService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceFileManagerTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_list_files_for_their_pterodactyl_service(): void
    {
        $user = User::factory()->create();
        $service = $this->createPterodactylService($user);

        $this->mock(PterodactylService::class)
            ->shouldReceive('listFiles')
            ->once()
            ->with('abc12345', '/mods')
            ->andReturn([
                [
                    'name' => 'essentials.jar',
                    'size' => 2048576,
                    'modified_at' => '2026-04-28T10:30:00Z',
                    'is_file' => true,
                    'mimetype' => 'application/java-archive',
                ],
            ]);

        $response = $this->actingAs($user)
            ->getJson("/api/services/{$service->uuid}/files/list?directory=/mods");

        $response->assertOk()
            ->assertJsonPath('data.0.name', 'essentials.jar')
            ->assertJsonPath('data.0.is_file', true);
    }

    public function test_user_cannot_access_another_users_service_files(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $service = $this->createPterodactylService($other);

        $response = $this->actingAs($user)
            ->getJson("/api/services/{$service->uuid}/files/list?directory=/mods");

        $response->assertNotFound();
    }

    public function test_user_can_get_upload_url_for_their_service(): void
    {
        $user = User::factory()->create();
        $service = $this->createPterodactylService($user);

        $this->mock(PterodactylService::class)
            ->shouldReceive('getUploadUrl')
            ->once()
            ->with('abc12345')
            ->andReturn('https://panel.example.com/upload/signed-url');

        $response = $this->actingAs($user)
            ->getJson("/api/services/{$service->uuid}/files/upload");

        $response->assertOk()
            ->assertJsonPath('data.url', 'https://panel.example.com/upload/signed-url');
    }

    public function test_user_can_delete_files_for_their_service(): void
    {
        $user = User::factory()->create();
        $service = $this->createPterodactylService($user);

        $this->mock(PterodactylService::class)
            ->shouldReceive('deleteFiles')
            ->once()
            ->with('abc12345', '/mods', ['essentials.jar'])
            ->andReturnNull();

        $response = $this->actingAs($user)
            ->postJson("/api/services/{$service->uuid}/files/delete", [
                'root' => '/mods',
                'files' => ['essentials.jar'],
            ]);

        $response->assertNoContent();
    }

    public function test_panel_errors_are_returned_with_panel_status(): void
    {
        $user = User::factory()->create();
        $service = $this->createPterodactylService($user);

        $this->mock(PterodactylService::class)
            ->shouldReceive('getDownloadUrl')
            ->once()
            ->with('abc12345', '/mods/missing.jar')
            ->andThrow(new PterodactylApiException('File not found.', 404));

        $response = $this->actingAs($user)
            ->getJson("/api/services/{$service->uuid}/files/download?file=/mods/missing.jar");

        $response->assertNotFound()
            ->assertJsonPath('message', 'File not found.');
    }

    private function createPterodactylService(User $user): Service
    {
        return Service::factory()->active()->create([
            'user_id' => $user->id,
            'pterodactyl_server_id' => 123,
            'connection_details' => [
                'identifier' => 'abc12345',
            ],
        ]);
    }
}
