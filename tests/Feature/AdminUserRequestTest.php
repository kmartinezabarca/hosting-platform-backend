<?php

namespace Tests\Feature;

use App\Domains\Platform\Models\UserRequest;
use App\Domains\Platform\Notifications\UserRequestStatusNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Approve / reject of user-submitted requests.
 *
 * NOTE: requires a real DB (MySQL in CI).
 */
class AdminUserRequestTest extends TestCase
{
    use RefreshDatabase;

    private function pendingRequest(User $client): UserRequest
    {
        return UserRequest::create([
            'user_id'     => $client->id,
            'kind'        => UserRequest::KIND_DOCUMENTATION,
            'status'      => UserRequest::STATUS_PENDING,
            'subject'     => 'Necesito documentación de la API',
            'description' => 'Detalle de la solicitud',
        ]);
    }

    public function test_admin_can_list_user_requests(): void
    {
        $admin  = User::factory()->admin()->create();
        $client = User::factory()->create(['role' => 'client']);
        $this->pendingRequest($client);

        $this->actingAs($admin)->getJson('/api/admin/user-requests')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.total', 1);
    }

    public function test_admin_can_approve_request_and_requester_is_notified(): void
    {
        Notification::fake();

        $admin  = User::factory()->admin()->create();
        $client = User::factory()->create(['role' => 'client']);
        $req    = $this->pendingRequest($client);

        $this->actingAs($admin)
            ->postJson("/api/admin/user-requests/{$req->id}/approve", ['note' => 'Aprobada'])
            ->assertOk()
            ->assertJsonPath('data.status', UserRequest::STATUS_APPROVED);

        $req->refresh();
        $this->assertSame(UserRequest::STATUS_APPROVED, $req->status);
        $this->assertSame($admin->id, $req->resolved_by);
        $this->assertNotNull($req->resolved_at);

        Notification::assertSentTo($client, UserRequestStatusNotification::class);

        $this->assertDatabaseHas('audit_logs', [
            'action'    => 'user_request.approved',
            'target_id' => (string) $req->id,
        ]);
    }

    public function test_reject_requires_a_reason(): void
    {
        $admin  = User::factory()->admin()->create();
        $client = User::factory()->create(['role' => 'client']);
        $req    = $this->pendingRequest($client);

        $this->actingAs($admin)
            ->postJson("/api/admin/user-requests/{$req->id}/reject", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reason']);
    }

    public function test_admin_can_reject_request(): void
    {
        Notification::fake();

        $admin  = User::factory()->admin()->create();
        $client = User::factory()->create(['role' => 'client']);
        $req    = $this->pendingRequest($client);

        $this->actingAs($admin)
            ->postJson("/api/admin/user-requests/{$req->id}/reject", ['reason' => 'Datos incompletos'])
            ->assertOk()
            ->assertJsonPath('data.status', UserRequest::STATUS_REJECTED);

        $this->assertDatabaseHas('audit_logs', ['action' => 'user_request.rejected']);
    }

    public function test_cannot_resolve_an_already_resolved_request(): void
    {
        $admin  = User::factory()->admin()->create();
        $client = User::factory()->create(['role' => 'client']);
        $req    = $this->pendingRequest($client);
        $req->update(['status' => UserRequest::STATUS_APPROVED]);

        $this->actingAs($admin)
            ->postJson("/api/admin/user-requests/{$req->id}/reject", ['reason' => 'x'])
            ->assertStatus(422);
    }

    public function test_support_cannot_approve_requests(): void
    {
        $support = User::factory()->create(['role' => 'support']);
        $client  = User::factory()->create(['role' => 'client']);
        $req     = $this->pendingRequest($client);

        $this->actingAs($support)
            ->postJson("/api/admin/user-requests/{$req->id}/approve")
            ->assertForbidden();
    }
}
