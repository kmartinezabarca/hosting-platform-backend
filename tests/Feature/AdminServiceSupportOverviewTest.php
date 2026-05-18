<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\PterodactylEgg;
use App\Models\Receipt;
use App\Models\ServerNode;
use App\Models\Service;
use App\Models\ServicePlan;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Pterodactyl\PterodactylService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminServiceSupportOverviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_get_support_overview_structure(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create(['stripe_customer_id' => 'cus_test_123']);
        $service = $this->createPterodactylService($user);

        Receipt::create([
            'user_id' => $user->id,
            'service_id' => $service->id,
            'status' => Receipt::STATUS_PAID,
            'subtotal' => 100,
            'tax_rate' => 16,
            'tax_amount' => 16,
            'total' => 116,
            'currency' => 'MXN',
            'due_date' => now()->addDays(5),
            'paid_at' => now()->subDay(),
            'gateway' => 'stripe',
        ]);

        Ticket::create([
            'user_id' => $user->id,
            'service_id' => $service->id,
            'ticket_number' => 'TKT-202605-0001',
            'subject' => 'Connection issue',
            'description' => 'Cannot connect.',
            'priority' => 'high',
            'status' => 'open',
            'department' => 'technical',
        ]);

        $this->mock(PterodactylService::class, function ($mock): void {
            $mock->shouldReceive('getServer')->once()->with(321)->andReturn([
                'attributes' => [
                    'id' => 321,
                    'identifier' => 'abc12345',
                    'status' => null,
                    'suspended' => false,
                    'node' => 7,
                    'limits' => ['memory' => 2048, 'disk' => 10240, 'cpu' => 100],
                    'feature_limits' => ['databases' => 1, 'backups' => 3, 'allocations' => 1],
                ],
            ]);
            $mock->shouldReceive('getServerResources')->once()->with('abc12345')->andReturn([
                'current_state' => 'running',
                'is_suspended' => false,
                'resources' => [
                    'cpu_absolute' => 12.5,
                    'memory_bytes' => 536870912,
                    'disk_bytes' => 1073741824,
                    'network_rx_bytes' => 1000,
                    'network_tx_bytes' => 2000,
                    'uptime' => 60000,
                ],
            ]);
        });

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/admin/services/{$service->uuid}/support-overview");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'service' => ['id', 'uuid', 'user', 'plan', 'selected_egg', 'server_node'],
                    'game_server' => [
                        'pterodactyl_status' => [
                            'status',
                            'suspended',
                            'suspension_reason',
                            'node',
                            'node_name',
                            'node_location',
                            'panel_url',
                            'limits',
                            'feature_limits',
                        ],
                        'usage' => [
                            'state',
                            'cpu_percent',
                            'memory_bytes',
                            'disk_bytes',
                            'network_rx_bytes',
                            'network_tx_bytes',
                            'uptime_ms',
                            'checked_at',
                        ],
                        'connection_health' => [
                            'frp_enabled',
                            'frp_status',
                            'dns_status',
                            'hostname_resolves',
                            'srv_record_ok',
                            'cname_record_ok',
                            'last_checked_at',
                        ],
                        'software',
                        'backups',
                        'provisioning',
                    ],
                    'billing' => ['latest_invoice', 'invoices', 'stats', 'subscription'],
                    'support' => ['open_tickets_count', 'latest_tickets', 'recent_events', 'risk_flags'],
                ],
            ])
            ->assertJsonPath('data.game_server.pterodactyl_status.status', 'running')
            ->assertJsonPath('data.game_server.usage.cpu_percent', 12.5)
            ->assertJsonPath('data.game_server.backups.backups_limit', 3)
            ->assertJsonPath('data.billing.stats.total_invoices', 1)
            ->assertJsonPath('data.billing.stats.total_paid', '116.00')
            ->assertJsonPath('data.billing.subscription.gateway', 'stripe')
            ->assertJsonPath('data.support.open_tickets_count', 1);
    }

    public function test_support_overview_includes_null_defaults_without_pterodactyl_server_or_invoices(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $service = $this->createPterodactylService($user, [
            'pterodactyl_server_id' => null,
            'pterodactyl_server_uuid' => null,
            'connection_details' => [],
        ]);

        $this->mock(PterodactylService::class, function ($mock): void {
            $mock->shouldReceive('getServer')->never();
            $mock->shouldReceive('getServerResources')->never();
        });

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/admin/services/{$service->uuid}/support-overview");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.game_server.pterodactyl_status.status', 'unknown')
            ->assertJsonPath('data.game_server.usage.state', 'unknown')
            ->assertJsonPath('data.game_server.usage.checked_at', null)
            ->assertJsonPath('data.game_server.software.installed_version', null)
            ->assertJsonPath('data.billing.latest_invoice', null)
            ->assertJsonPath('data.billing.invoices', [])
            ->assertJsonPath('data.billing.stats.total_invoices', 0)
            ->assertJsonPath('data.billing.stats.total_paid', '0.00')
            ->assertJsonPath('data.billing.stats.total_pending', '0.00');

        $codes = collect($response->json('data.support.risk_flags'))->pluck('code');
        $this->assertTrue($codes->contains('missing_pterodactyl_server'));
    }

    public function test_support_overview_marks_suspended_pterodactyl_server(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $service = $this->createPterodactylService($user, [
            'status' => 'suspended',
            'notes' => 'billing_hold',
        ]);

        $this->mock(PterodactylService::class, function ($mock): void {
            $mock->shouldReceive('getServer')->once()->with(321)->andReturn([
                'attributes' => [
                    'id' => 321,
                    'identifier' => 'abc12345',
                    'status' => null,
                    'suspended' => true,
                    'suspension_reason' => 'billing_hold',
                    'node' => 7,
                    'limits' => ['memory' => 2048],
                    'feature_limits' => ['backups' => 2],
                ],
            ]);
            $mock->shouldReceive('getServerResources')->once()->with('abc12345')->andReturn([
                'current_state' => 'offline',
                'is_suspended' => true,
                'resources' => [
                    'cpu_absolute' => 0,
                    'memory_bytes' => 0,
                    'disk_bytes' => 1048576,
                    'uptime' => 0,
                ],
            ]);
        });

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/admin/services/{$service->uuid}/support-overview");

        $response->assertOk()
            ->assertJsonPath('data.game_server.pterodactyl_status.suspended', true)
            ->assertJsonPath('data.game_server.pterodactyl_status.suspension_reason', 'billing_hold')
            ->assertJsonPath('data.game_server.usage.state', 'offline')
            ->assertJsonPath('data.game_server.provisioning.status', 'completed');

        $flags = collect($response->json('data.support.risk_flags'));
        $this->assertTrue($flags->contains(fn (array $flag) => $flag['code'] === 'server_suspended' && $flag['severity'] === 'critical'));
    }

    private function createPterodactylService(User $user, array $overrides = []): Service
    {
        $category = Category::factory()->create(['slug' => 'gameserver']);
        $plan = ServicePlan::factory()->create([
            'category_id' => $category->id,
            'provisioner' => 'pterodactyl',
            'game_type' => 'minecraft',
            'pterodactyl_node_id' => 7,
            'pterodactyl_limits' => ['memory' => 2048, 'disk' => 10240, 'cpu' => 100],
            'pterodactyl_feature_limits' => ['databases' => 1, 'backups' => 3, 'allocations' => 1],
            'pterodactyl_docker_image' => 'ghcr.io/pterodactyl/yolks:java_21',
        ]);
        $node = ServerNode::forceCreate([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'name' => 'Node MX-1',
            'hostname' => 'node1.example.test',
            'ip_address' => '10.0.0.10',
            'location' => 'MX',
            'node_type' => 'pterodactyl',
            'specifications' => json_encode(['memory' => '64 GB']),
            'api_credentials' => json_encode([]),
            'status' => 'active',
            'max_services' => 100,
            'current_services' => 1,
        ]);
        $egg = PterodactylEgg::create([
            'ptero_nest_id' => 1,
            'ptero_egg_id' => 2,
            'nest_name' => 'Minecraft',
            'nest_identifier' => 'minecraft',
            'egg_name' => 'Paper',
            'display_name' => 'Minecraft Paper',
            'docker_image' => 'ghcr.io/pterodactyl/yolks:java_21',
            'startup' => 'java -jar server.jar',
            'is_active' => true,
            'game_protocol' => 'java',
        ]);

        return Service::factory()->create(array_merge([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'server_node_id' => $node->id,
            'selected_egg_id' => $egg->id,
            'status' => 'active',
            'name' => 'Minecraft Server',
            'billing_cycle' => 'monthly',
            'next_due_date' => now()->addMonth(),
            'pterodactyl_server_id' => 321,
            'pterodactyl_server_uuid' => '00000000-0000-0000-0000-000000000321',
            'connection_details' => [
                'identifier' => 'abc12345',
                'hostname' => 'player.example.test',
                'panel_url' => 'https://panel.example.test/server/abc12345',
                'frp_enabled' => true,
                'dns_record_ids' => [
                    'cname' => 'dns-cname',
                    'srv' => 'dns-srv',
                ],
            ],
            'configuration' => [],
        ], $overrides));
    }
}
