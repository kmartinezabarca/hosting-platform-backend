<?php

namespace App\Domains\Platform\Services\Admin;

use App\Domains\Platform\Models\Backup;
use App\Domains\Platform\Models\Receipt;
use App\Domains\Platform\Models\ServerNode;
use App\Domains\Platform\Models\Service;
use App\Domains\Platform\Models\Ticket;
use App\Domains\Platform\Services\Coolify\CoolifyService;
use App\Domains\Platform\Services\Pterodactyl\PterodactylService;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class ServiceSupportOverviewService
{
    public function __construct(
        private readonly PterodactylService $pterodactyl,
        private readonly CoolifyService $coolify,
    ) {
    }

    public function build(string $uuid): array
    {
        $service = Service::with([
            'user',
            'plan.category',
            'plan.pricing.billingCycle',
            'selectedEgg',
            'serverNode',
        ])->where('uuid', $uuid)->firstOrFail();

        $receipts = Receipt::with(['items'])
            ->where('service_id', $service->id)
            ->orderByDesc('created_at')
            ->get();

        $tickets = Ticket::where('service_id', $service->id)
            ->orderByDesc('updated_at')
            ->limit(5)
            ->get();

        $provider = $service->plan?->provisioner ?: null;
        $isPterodactyl = $provider === 'pterodactyl';
        $isCoolify = $provider === 'coolify';

        // Solo consultamos al proveedor que corresponde al plan.
        $runtime = $isPterodactyl ? $this->runtimeSnapshot($service) : $this->emptyRuntime();
        $hosting = $isCoolify ? $this->coolifySnapshot($service) : null;

        $billing = $this->billing($service, $receipts);
        $support = $this->support($service, $tickets, $receipts, $runtime, $billing, $hosting);

        return [
            'provider' => $provider,
            'service' => $this->servicePayload($service),
            // game_server solo es relevante para planes Pterodactyl.
            'game_server' => $isPterodactyl ? $this->gameServer($service, $runtime) : null,
            'hosting' => $hosting,
            'billing' => $billing,
            'support' => $support,
        ];
    }

    private function emptyRuntime(): array
    {
        return [
            'server' => null,
            'server_error' => null,
            'resources' => null,
            'resources_error' => null,
            'checked_at' => null,
        ];
    }

    /**
     * Estado real de la aplicación de hosting en Coolify.
     * Consulta la API de Coolify para reflejar si la app está realmente
     * corriendo/saludable (el deploy puede fallar aunque el alta haya tenido éxito).
     */
    private function coolifySnapshot(Service $service): array
    {
        $cd = $service->connection_details ?? [];
        $appUuid = $cd['coolify_app_uuid'] ?? $service->external_id ?: null;

        $snapshot = [
            'app_uuid'      => $appUuid,
            'project_uuid'  => $cd['coolify_project_uuid'] ?? null,
            'fqdn'          => $cd['fqdn'] ?? null,
            'domain'        => $cd['domain'] ?? $service->domain,
            'subdomain'     => $cd['subdomain'] ?? null,
            'panel_url'     => $cd['panel_url'] ?? config('coolify.base_url'),
            'database'      => [
                'type' => $cd['db_type'] ?? null,
                'name' => $cd['db_name'] ?? null,
                'host' => $cd['db_host'] ?? null,
            ],
            'status'        => 'unknown',   // running | stopped | starting | degraded | not_provisioned | unknown
            'status_raw'    => null,
            'health'        => null,        // healthy | unhealthy | null
            'unreachable'   => false,
            'last_error'    => $service->provisioning_error
                ?? ($service->status === 'failed' ? $service->notes : null),
            'checked_at'    => now()->toISOString(),
        ];

        if (! $appUuid) {
            $snapshot['status'] = 'not_provisioned';
            return $snapshot;
        }

        try {
            $app = $this->coolify->getApplication($appUuid);
            $raw = $app['status'] ?? null; // p.ej. "running:healthy", "exited:unhealthy"
            $snapshot['status_raw'] = $raw;

            [$runState, $health] = array_pad(explode(':', (string) $raw, 2), 2, null);
            $snapshot['status'] = $this->normalizeCoolifyState($runState);
            $snapshot['health'] = $health ?: null;
        } catch (\Throwable $e) {
            $snapshot['unreachable'] = true;
            $snapshot['status'] = 'unknown';
            $snapshot['last_error'] = $snapshot['last_error'] ?? $e->getMessage();
            Log::warning('Support overview could not fetch Coolify application', [
                'service_id' => $service->id,
                'app_uuid'   => $appUuid,
                'error'      => $e->getMessage(),
            ]);
        }

        return $snapshot;
    }

    private function normalizeCoolifyState(?string $state): string
    {
        $state = strtolower(trim((string) $state));

        return match (true) {
            $state === 'running'                          => 'running',
            in_array($state, ['exited', 'stopped'], true) => 'stopped',
            in_array($state, ['restarting', 'starting'], true) => 'starting',
            $state === 'degraded'                         => 'degraded',
            $state === ''                                 => 'unknown',
            default                                       => 'unknown',
        };
    }

    private function runtimeSnapshot(Service $service): array
    {
        $snapshot = [
            'server' => null,
            'server_error' => null,
            'resources' => null,
            'resources_error' => null,
            'checked_at' => null,
        ];

        if (! $service->pterodactyl_server_id) {
            return $snapshot;
        }

        $snapshot['checked_at'] = now()->toISOString();

        try {
            $snapshot['server'] = $this->pterodactyl->getServer((int) $service->pterodactyl_server_id);
        } catch (\Throwable $e) {
            $snapshot['server_error'] = $e->getMessage();
            Log::warning('Support overview could not fetch Pterodactyl server', [
                'service_id' => $service->id,
                'error' => $e->getMessage(),
            ]);
        }

        $identifier = $service->connection_details['identifier'] ?? null;
        if ($identifier) {
            try {
                $snapshot['resources'] = $this->pterodactyl->getServerResources($identifier);
            } catch (\Throwable $e) {
                $snapshot['resources_error'] = $e->getMessage();
                Log::warning('Support overview could not fetch Pterodactyl resources', [
                    'service_id' => $service->id,
                    'identifier' => $identifier,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $snapshot;
    }

    private function gameServer(Service $service, array $runtime): array
    {
        $base = $service->toArray();
        $serverAttributes = $runtime['server']['attributes'] ?? [];
        $resources = $runtime['resources'] ?? [];
        $resourceValues = $resources['resources'] ?? [];
        $connection = $service->connection_details ?? [];
        $selectedEgg = $service->selectedEgg;
        $node = $this->resolvePterodactylNode($service, $serverAttributes);
        $nodeId = $serverAttributes['node']
            ?? data_get($serverAttributes, 'relationships.node.attributes.id')
            ?? $node?->pterodactyl_node_id
            ?? $service->plan?->pterodactyl_node_id
            ?? null;
        $dockerImage = data_get($serverAttributes, 'container.image')
            ?? data_get($service->configuration, 'game_server.runtime.docker_image')
            ?? $service->plan?->pterodactyl_docker_image
            ?? $selectedEgg?->docker_image;
        $limits = $serverAttributes['limits'] ?? $service->plan?->resolvedLimits() ?? [];
        $featureLimits = $serverAttributes['feature_limits'] ?? $service->plan?->resolvedFeatureLimits() ?? [];
        $backupCount = Backup::where('service_id', $service->id)->count();
        $latestBackup = Backup::where('service_id', $service->id)
            ->orderByDesc('completed_at')
            ->orderByDesc('created_at')
            ->first();
        $dnsHealth = $this->dnsHealth($connection);

        return array_merge($base, [
            'pterodactyl_status' => [
                'status' => $this->normalizePowerState(
                    $resources['current_state'] ?? $serverAttributes['status'] ?? null
                ),
                'suspended' => (bool) ($serverAttributes['suspended'] ?? $resources['is_suspended'] ?? $service->status === 'suspended'),
                'suspension_reason' => $this->suspensionReason($service, $serverAttributes),
                'node' => $nodeId,
                'node_id' => $node?->id,
                'node_name' => $serverAttributes['node_name'] ?? data_get($serverAttributes, 'relationships.node.attributes.name') ?? $node?->name,
                'node_location' => $serverAttributes['node_location'] ?? data_get($serverAttributes, 'relationships.node.attributes.location') ?? $node?->location,
                'node_hostname' => $node?->hostname,
                'node_ip' => $node?->ip_address,
                'node_status' => $node?->status,
                'panel_url' => $connection['panel_url']
                    ?? (! empty($serverAttributes['identifier']) ? rtrim(config('pterodactyl.base_url'), '/') . '/server/' . $serverAttributes['identifier'] : null),
                'limits' => $limits,
                'feature_limits' => $featureLimits,
            ],
            'usage' => [
                'state' => $this->normalizePowerState($resources['current_state'] ?? null),
                'cpu_percent' => $this->nullableFloat($resourceValues['cpu_absolute'] ?? null),
                'memory_bytes' => $this->nullableInt($resourceValues['memory_bytes'] ?? null),
                'disk_bytes' => $this->nullableInt($resourceValues['disk_bytes'] ?? null),
                'network_rx_bytes' => $this->nullableInt($resourceValues['network_rx_bytes'] ?? data_get($resourceValues, 'network.rx_bytes')),
                'network_tx_bytes' => $this->nullableInt($resourceValues['network_tx_bytes'] ?? data_get($resourceValues, 'network.tx_bytes')),
                'uptime_ms' => $this->nullableInt($resourceValues['uptime'] ?? null),
                'checked_at' => $runtime['resources'] ? $runtime['checked_at'] : null,
            ],
            'connection_health' => [
                'frp_enabled' => (bool) ($connection['frp_enabled'] ?? false),
                'frp_status' => $this->frpStatus($service, $runtime),
                'dns_status' => $dnsHealth['status'],
                'hostname_resolves' => $dnsHealth['hostname_resolves'],
                'srv_record_ok' => $dnsHealth['srv_record_ok'],
                'cname_record_ok' => $dnsHealth['cname_record_ok'],
                'last_checked_at' => $runtime['checked_at'],
            ],
            'software' => [
                'egg_name' => $selectedEgg?->egg_name,
                'display_name' => $selectedEgg?->display_name,
                'docker_image' => $dockerImage,
                'java_version' => $this->javaVersion($dockerImage),
                'installed_version' => $this->installedVersion($service, $serverAttributes),
            ],
            'backups' => [
                'latest_backup_at' => $this->date($latestBackup?->completed_at ?? $latestBackup?->started_at ?? $latestBackup?->created_at),
                'latest_backup_status' => $latestBackup?->status,
                'backups_used' => $backupCount,
                'backups_limit' => $featureLimits['backups'] ?? null,
            ],
            'provisioning' => [
                'status' => $this->provisioningStatus($service),
                'last_error' => $service->status === 'failed' ? $service->notes : null,
                'last_attempt_at' => $this->date($service->updated_at),
            ],
        ]);
    }

    private function billing(Service $service, Collection $receipts): array
    {
        $paidStatuses = [Receipt::STATUS_PAID];
        $pendingStatuses = [Receipt::STATUS_DRAFT, Receipt::STATUS_SENT, Receipt::STATUS_PROCESS];
        $latest = $receipts->first();
        $currency = $latest?->currency ?? 'MXN';
        $overdue = $receipts->filter(fn (Receipt $receipt) => $receipt->status === Receipt::STATUS_OVERDUE || $receipt->isOverdue());

        return [
            'latest_invoice' => $latest?->toArray(),
            'invoices' => $receipts->values()->toArray(),
            'stats' => [
                'total_invoices' => $receipts->count(),
                'paid_invoices' => $receipts->whereIn('status', $paidStatuses)->count(),
                'pending_invoices' => $receipts->whereIn('status', $pendingStatuses)->count(),
                'overdue_invoices' => $overdue->count(),
                'total_paid' => $this->money($receipts->whereIn('status', $paidStatuses)->sum(fn (Receipt $receipt) => (float) $receipt->total)),
                'total_pending' => $this->money($receipts->whereIn('status', $pendingStatuses)->sum(fn (Receipt $receipt) => (float) $receipt->total)),
                'currency' => $currency,
            ],
            'subscription' => [
                'gateway' => $this->gateway($service, $latest),
                'stripe_customer_id' => $service->user?->stripe_customer_id,
                'payment_intent_id' => $service->payment_intent_id,
                'subscription_status' => $this->subscriptionStatus($service, $overdue->isNotEmpty()),
                'next_due_date' => $this->date($service->next_due_date),
                'auto_renew' => ! in_array($service->billing_cycle, ['one_time'], true)
                    && ! in_array($service->status, ['terminated', 'cancelled'], true),
            ],
        ];
    }

    private function support(Service $service, Collection $tickets, Collection $receipts, array $runtime, array $billing, ?array $hosting = null): array
    {
        $openStatuses = ['open', 'in_progress', 'waiting_customer'];
        $events = collect()
            ->merge($this->paymentEvents($receipts))
            ->merge($this->ticketEvents($tickets))
            ->merge($this->serviceEvents($service, $runtime))
            ->sortByDesc('created_at')
            ->take(10)
            ->values()
            ->all();

        return [
            'open_tickets_count' => Ticket::where('service_id', $service->id)->whereIn('status', $openStatuses)->count(),
            'latest_tickets' => $tickets->map(fn (Ticket $ticket) => [
                'id' => $ticket->id,
                'uuid' => $ticket->uuid,
                'ticket_number' => $ticket->ticket_number,
                'subject' => $ticket->subject,
                'status' => $ticket->status,
                'priority' => $ticket->priority,
                'updated_at' => $this->date($ticket->updated_at),
            ])->values()->all(),
            'recent_events' => $events,
            'risk_flags' => $this->riskFlags($service, $runtime, $billing, $hosting),
        ];
    }

    private function paymentEvents(Collection $receipts): array
    {
        return $receipts->take(5)->map(fn (Receipt $receipt) => [
            'type' => 'payment',
            'title' => $this->eventTitle('invoice_' . $receipt->status),
            'description' => $receipt->receipt_number,
            'created_at' => $this->date($receipt->paid_at ?? $receipt->created_at),
        ])->all();
    }

    private function ticketEvents(Collection $tickets): array
    {
        return $tickets->map(fn (Ticket $ticket) => [
            'type' => 'ticket',
            'title' => $this->eventTitle('ticket_' . $ticket->status),
            'description' => $ticket->subject,
            'created_at' => $this->date($ticket->updated_at),
        ])->all();
    }

    private function serviceEvents(Service $service, array $runtime): array
    {
        $events = [[
            'type' => $service->status === 'suspended' ? 'suspension' : 'provisioning',
            'title' => $this->eventTitle('service_' . $service->status),
            'description' => $service->notes,
            'created_at' => $this->date($service->updated_at),
        ]];

        if ($runtime['resources']) {
            $events[] = [
                'type' => 'power',
                'title' => $this->eventTitle('power_' . $this->normalizePowerState($runtime['resources']['current_state'] ?? null)),
                'description' => null,
                'created_at' => $runtime['checked_at'],
            ];
        }

        return $events;
    }

    private function riskFlags(Service $service, array $runtime, array $billing, ?array $hosting = null): array
    {
        $flags = [];

        if ($service->status === 'failed') {
            $flags[] = [
                'severity' => 'critical',
                'code' => 'provisioning_failed',
                'message' => 'El aprovisionamiento del servicio falló.',
            ];
        }

        // ── Alertas específicas de Coolify (hosting) ─────────────────────────
        if ($hosting !== null) {
            if (! empty($hosting['unreachable'])) {
                $flags[] = [
                    'severity' => 'warning',
                    'code' => 'coolify_unreachable',
                    'message' => 'No se pudo consultar el estado de la aplicación en Coolify.',
                ];
            } elseif ($service->status === 'active' && in_array($hosting['status'] ?? null, ['stopped', 'degraded'], true)) {
                // El alta tuvo éxito pero la app no está corriendo (p. ej. deploy falló).
                $flags[] = [
                    'severity' => 'critical',
                    'code' => 'coolify_not_running',
                    'message' => 'La aplicación de hosting no está en ejecución en Coolify (revisa el último deploy).',
                ];
            } elseif (($hosting['health'] ?? null) === 'unhealthy') {
                $flags[] = [
                    'severity' => 'warning',
                    'code' => 'coolify_unhealthy',
                    'message' => 'La aplicación de hosting responde pero su healthcheck está en estado unhealthy.',
                ];
            }

            if (empty($hosting['app_uuid'])) {
                $flags[] = [
                    'severity' => 'warning',
                    'code' => 'missing_coolify_app',
                    'message' => 'El plan es de hosting (Coolify) pero no hay una aplicación vinculada.',
                ];
            }
        }

        if ($service->status === 'suspended' || ($runtime['server']['attributes']['suspended'] ?? false)) {
            $flags[] = [
                'severity' => 'critical',
                'code' => 'server_suspended',
                'message' => 'El servicio o el servidor de Pterodactyl está suspendido.',
            ];
        }

        if (($billing['stats']['overdue_invoices'] ?? 0) > 0) {
            $flags[] = [
                'severity' => 'warning',
                'code' => 'billing_overdue',
                'message' => 'El servicio tiene facturas vencidas.',
            ];
        }

        if ($service->plan?->isPterodactylManaged() && ! $service->pterodactyl_server_id) {
            $flags[] = [
                'severity' => 'warning',
                'code' => 'missing_pterodactyl_server',
                'message' => 'El plan requiere Pterodactyl, pero no hay servidor vinculado.',
            ];
        }

        if ($runtime['server_error'] || $runtime['resources_error']) {
            $flags[] = [
                'severity' => 'warning',
                'code' => 'pterodactyl_unreachable',
                'message' => 'No se pudo actualizar la información desde Pterodactyl.',
            ];
        }

        return $flags;
    }

    private function normalizePowerState(?string $state): string
    {
        return in_array($state, ['running', 'offline', 'starting', 'stopping'], true) ? $state : 'unknown';
    }

    private function frpStatus(Service $service, array $runtime): string
    {
        if (! (bool) ($service->connection_details['frp_enabled'] ?? false)) {
            return 'unknown';
        }

        if ($runtime['resources_error'] || $runtime['server_error']) {
            return 'degraded';
        }

        return $runtime['resources'] ? 'healthy' : 'unknown';
    }

    private function dnsStatus(array $connection): string
    {
        if (empty($connection['hostname'])) {
            return 'unknown';
        }

        $recordIds = is_array($connection['dns_record_ids'] ?? null) ? $connection['dns_record_ids'] : [];

        return ! empty($recordIds) ? 'healthy' : 'degraded';
    }

    private function dnsHealth(array $connection): array
    {
        $hostname = trim((string) ($connection['hostname'] ?? ''));

        if ($hostname === '') {
            return [
                'status' => 'unknown',
                'hostname_resolves' => null,
                'srv_record_ok' => null,
                'cname_record_ok' => null,
            ];
        }

        $recordIds = $connection['dns_record_ids'] ?? [];
        $hostnameResolves = $this->hostnameResolves($hostname);
        $srvRecordOk = array_key_exists('srv', $recordIds)
            ? $this->recordExists("_minecraft._tcp.{$hostname}", 'SRV')
            : null;
        $cnameRecordOk = array_key_exists('cname', $recordIds)
            ? $this->recordExists($hostname, 'CNAME')
            : null;
        $knownChecks = array_filter([$hostnameResolves, $srvRecordOk, $cnameRecordOk], static fn ($value) => $value !== null);
        $hasFailure = in_array(false, $knownChecks, true);
        $hasSuccess = in_array(true, $knownChecks, true);

        return [
            'status' => $hasFailure ? 'degraded' : ($hasSuccess ? 'healthy' : $this->dnsStatus($connection)),
            'hostname_resolves' => $hostnameResolves,
            'srv_record_ok' => $srvRecordOk,
            'cname_record_ok' => $cnameRecordOk,
        ];
    }

    private function hostnameResolves(string $hostname): bool
    {
        return $this->recordExists($hostname, 'A')
            || $this->recordExists($hostname, 'AAAA')
            || $this->recordExists($hostname, 'CNAME');
    }

    private function recordExists(string $hostname, string $type): bool
    {
        return checkdnsrr($hostname, $type);
    }

    private function suspensionReason(Service $service, array $serverAttributes): ?string
    {
        if (! ($serverAttributes['suspended'] ?? false) && $service->status !== 'suspended') {
            return null;
        }

        return $serverAttributes['suspension_reason'] ?? $service->notes;
    }

    private function provisioningStatus(Service $service): string
    {
        return match ($service->status) {
            'active', 'suspended' => 'completed',
            'pending' => 'pending',
            'failed' => 'failed',
            default => 'unknown',
        };
    }

    private function subscriptionStatus(Service $service, bool $hasOverdueInvoices): string
    {
        if ($service->status === 'terminated') {
            return 'canceled';
        }

        if ($hasOverdueInvoices) {
            return 'past_due';
        }

        return match ($service->status) {
            'active' => 'active',
            'suspended' => 'unpaid',
            default => 'unknown',
        };
    }

    private function gateway(Service $service, ?Receipt $latest): ?string
    {
        if (($latest?->gateway ?? null) === 'stripe' || $service->user?->stripe_customer_id || $service->payment_intent_id) {
            return 'stripe';
        }

        return null;
    }

    private function servicePayload(Service $service): array
    {
        $payload = $service->toArray();
        $payload['user'] = $this->userPayload($service);
        $payload['plan_summary'] = $this->planSummary($service);

        return $payload;
    }

    private function userPayload(Service $service): ?array
    {
        $user = $service->user;

        if (! $user) {
            return null;
        }

        return array_merge($user->toArray(), [
            'full_name' => trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')),
            'avatar_full_url' => $user->avatar_full_url ?: null,
        ]);
    }

    private function planSummary(Service $service): array
    {
        $plan = $service->plan;
        $pricing = $plan?->pricing?->first(
            fn ($price) => ($price->billingCycle?->slug ?? null) === $service->billing_cycle
        );
        $price = $service->price ?? $pricing?->price ?? $plan?->base_price;

        return [
            'id' => $plan?->id,
            'uuid' => $plan?->uuid,
            'name' => $plan?->name,
            'slug' => $plan?->slug,
            'category' => $plan?->category?->name,
            'billing_cycle' => $service->billing_cycle,
            'currency' => 'MXN',
            'service_price' => $price !== null ? $this->money((float) $price) : null,
            'base_price' => $plan?->base_price !== null ? $this->money((float) $plan->base_price) : null,
            'setup_fee' => $plan?->setup_fee !== null ? $this->money((float) $plan->setup_fee) : null,
            'max_players' => $plan?->max_players,
            'provisioner' => $plan?->provisioner,
            'limits' => $plan?->resolvedLimits() ?? [],
            'feature_limits' => $plan?->resolvedFeatureLimits() ?? [],
        ];
    }

    private function resolvePterodactylNode(Service $service, array $serverAttributes): ?ServerNode
    {
        $pterodactylNodeId = $serverAttributes['node']
            ?? data_get($serverAttributes, 'relationships.node.attributes.id')
            ?? $service->serverNode?->pterodactyl_node_id
            ?? $service->plan?->pterodactyl_node_id
            ?? null;

        if ($pterodactylNodeId) {
            $node = ServerNode::pterodactyl()
                ->where('pterodactyl_node_id', (int) $pterodactylNodeId)
                ->first();

            if ($node) {
                return $node;
            }
        }

        return $service->serverNode;
    }

    private function eventTitle(string $key): string
    {
        return [
            'invoice_paid' => 'Factura pagada',
            'invoice_pending' => 'Factura pendiente',
            'invoice_overdue' => 'Factura vencida',
            'invoice_sent' => 'Factura enviada',
            'invoice_draft' => 'Factura en borrador',
            'invoice_process' => 'Factura en proceso',
            'ticket_open' => 'Ticket abierto',
            'ticket_in_progress' => 'Ticket en progreso',
            'ticket_waiting_customer' => 'Ticket esperando al cliente',
            'ticket_resolved' => 'Ticket resuelto',
            'ticket_closed' => 'Ticket cerrado',
            'service_active' => 'Servicio activo',
            'service_pending' => 'Servicio pendiente',
            'service_failed' => 'Servicio con error',
            'service_suspended' => 'Servicio suspendido',
            'power_running' => 'Servidor encendido',
            'power_offline' => 'Servidor apagado',
            'power_starting' => 'Servidor iniciando',
            'power_stopping' => 'Servidor deteniendo',
            'power_unknown' => 'Estado de energía desconocido',
        ][$key] ?? str_replace('_', ' ', $key);
    }

    private function javaVersion(?string $dockerImage): ?string
    {
        if (! $dockerImage) {
            return null;
        }

        return preg_match('/java[_:-]?(\d+)/i', $dockerImage, $matches) ? $matches[1] : null;
    }

    private function installedVersion(Service $service, array $serverAttributes = []): ?string
    {
        $versionKey = config('minecraft.pterodactyl.version_variable', 'MINECRAFT_VERSION');
        $environment = data_get($serverAttributes, 'container.environment', [])
            ?: ($serverAttributes['environment'] ?? []);

        return ($environment[$versionKey] ?? null)
            ?? data_get($service->configuration, 'game_server.runtime.version')
            ?? data_get($service->configuration, 'game_server.version')
            ?? $service->configuration['installed_version']
            ?? $service->configuration['minecraft_version']
            ?? $service->configuration['version']
            ?? null;
    }

    private function nullableFloat(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function money(float|int $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    private function date(mixed $value): ?string
    {
        if ($value instanceof CarbonInterface) {
            return $value->toISOString();
        }

        return $value ? (string) $value : null;
    }
}
