<?php

namespace Tests\Feature;

use App\Domains\Platform\Models\Category;
use App\Domains\Platform\Models\Domain;
use App\Domains\Platform\Models\Service;
use App\Domains\Platform\Models\ServicePlan;
use App\Domains\Platform\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientCriticalFlowsTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_dashboard_services_returns_only_authenticated_users_services(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $plan = $this->servicePlan();

        $owned = Service::factory()->active()->create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'name' => 'Servidor Java',
            'price' => 189.00,
            'billing_cycle' => 'monthly',
            'connection_details' => [
                'display' => 'aldairmartinez-11.rokeindustries.com',
                'server_ip' => '100.94.93.51',
                'server_port' => 25576,
            ],
        ]);

        Service::factory()->active()->create([
            'user_id' => $other->id,
            'plan_id' => $plan->id,
            'name' => 'No debe aparecer',
        ]);

        $response = $this->actingAs($user)->getJson('/api/dashboard/services');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.uuid', $owned->uuid)
            ->assertJsonPath('data.0.domain', 'aldairmartinez-11.rokeindustries.com')
            ->assertJsonMissing(['name' => 'No debe aparecer']);
    }

    public function test_client_services_are_isolated_by_owner(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $plan = $this->servicePlan();

        $owned = Service::factory()->active()->create(['user_id' => $user->id, 'plan_id' => $plan->id]);
        $foreign = Service::factory()->active()->create(['user_id' => $other->id, 'plan_id' => $plan->id]);

        $this->actingAs($user)
            ->getJson('/api/services/user')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonFragment(['uuid' => $owned->uuid])
            ->assertJsonMissing(['uuid' => $foreign->uuid]);

        $this->actingAs($user)
            ->getJson("/api/services/{$foreign->uuid}")
            ->assertNotFound()
            ->assertJsonPath('success', false);
    }

    public function test_client_can_update_service_auto_renew_configuration(): void
    {
        $user = User::factory()->create();
        $service = Service::factory()->active()->create([
            'user_id' => $user->id,
            'configuration' => ['auto_renew' => false],
        ]);

        $this->actingAs($user)
            ->patchJson("/api/services/{$service->uuid}/configuration", ['auto_renew' => true])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertTrue($service->fresh()->configuration['auto_renew']);
    }

    public function test_client_domain_import_flow_validates_duplicates_and_ownership_token(): void
    {
        $user = User::factory()->create();

        $payload = [
            'domain_name' => 'cliente-ejemplo.com',
            'registrar' => 'Cloudflare',
            'expiration_years' => 2,
            'auto_renew' => true,
            'nameservers' => ['amy.ns.cloudflare.com', 'bob.ns.cloudflare.com'],
        ];

        $created = $this->actingAs($user)->postJson('/api/domains', $payload);

        $created->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.domain_name', 'cliente-ejemplo.com')
            ->assertJsonPath('data.auto_renew', true);

        $this->actingAs($user)
            ->postJson('/api/domains', $payload)
            ->assertStatus(409)
            ->assertJsonPath('success', false);

        $domain = Domain::where('domain_name', 'cliente-ejemplo.com')->firstOrFail();

        $this->actingAs($user)
            ->postJson("/api/domains/{$domain->uuid}/verify-ownership")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['txt_record' => ['type', 'name', 'value', 'ttl'], 'instructions']]);

        $this->assertNotNull($domain->fresh()->ownership_token);
    }

    public function test_client_tickets_create_reply_and_cannot_cross_account_access(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/tickets', [
            'subject' => 'Mi servidor no conecta',
            'message' => 'El dominio resuelve pero el puerto rechaza conexion.',
            'priority' => 'high',
            'department' => 'technical',
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.subject', 'Mi servidor no conecta')
            ->assertJsonCount(1, 'data.replies');

        $ticketUuid = $response->json('data.uuid');

        $this->actingAs($user)
            ->postJson("/api/tickets/{$ticketUuid}/reply", ['message' => 'Adjunto mas informacion.'])
            ->assertCreated()
            ->assertJsonPath('success', true);

        $this->assertSame(2, Ticket::where('uuid', $ticketUuid)->firstOrFail()->replies()->count());

        $this->actingAs($other)
            ->getJson("/api/tickets/{$ticketUuid}")
            ->assertNotFound()
            ->assertJsonPath('success', false);
    }

    private function servicePlan(): ServicePlan
    {
        $category = Category::factory()->create([
            'slug' => 'gameserver',
            'name' => 'Servidores de Juegos',
        ]);

        return ServicePlan::factory()->pterodactyl()->create([
            'category_id' => $category->id,
            'name' => 'Minecraft Starter',
            'slug' => 'minecraft-starter',
        ]);
    }
}
