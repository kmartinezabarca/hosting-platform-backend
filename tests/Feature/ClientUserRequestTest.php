<?php

namespace Tests\Feature;

use App\Domains\Platform\Models\UserRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Client-side submission and listing of user requests.
 *
 * NOTE: requires a real DB (MySQL in CI).
 */
class ClientUserRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_can_submit_a_request(): void
    {
        $client = User::factory()->create(['role' => 'client']);

        $this->actingAs($client)
            ->postJson('/api/user-requests', [
                'kind'        => UserRequest::KIND_API_DOCUMENTATION,
                'subject'     => 'Acceso a la API',
                'description' => 'Necesito credenciales de API',
            ])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', UserRequest::STATUS_PENDING);

        $this->assertDatabaseHas('user_requests', [
            'user_id' => $client->id,
            'kind'    => UserRequest::KIND_API_DOCUMENTATION,
            'status'  => UserRequest::STATUS_PENDING,
        ]);
    }

    public function test_submission_validates_kind(): void
    {
        $client = User::factory()->create(['role' => 'client']);

        $this->actingAs($client)
            ->postJson('/api/user-requests', ['kind' => 'bogus', 'subject' => 'x'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['kind']);
    }

    public function test_client_only_sees_own_requests(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $other  = User::factory()->create(['role' => 'client']);

        UserRequest::create([
            'user_id' => $client->id,
            'kind'    => UserRequest::KIND_DOCUMENTATION,
            'status'  => UserRequest::STATUS_PENDING,
            'subject' => 'mía',
        ]);
        $foreign = UserRequest::create([
            'user_id' => $other->id,
            'kind'    => UserRequest::KIND_DOCUMENTATION,
            'status'  => UserRequest::STATUS_PENDING,
            'subject' => 'ajena',
        ]);

        $this->actingAs($client)->getJson('/api/user-requests')
            ->assertOk()
            ->assertJsonPath('data.total', 1);

        // Cannot read another user's request.
        $this->actingAs($client)->getJson("/api/user-requests/{$foreign->id}")
            ->assertNotFound();
    }

    public function test_guests_cannot_submit(): void
    {
        $this->postJson('/api/user-requests', [
            'kind'    => UserRequest::KIND_DOCUMENTATION,
            'subject' => 'x',
        ])->assertUnauthorized();
    }
}
