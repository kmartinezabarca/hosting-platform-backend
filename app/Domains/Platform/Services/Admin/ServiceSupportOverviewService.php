<?php

namespace App\Domains\Platform\Services\Admin;

use App\Domains\Platform\Models\Receipt;
use App\Domains\Platform\Models\Service;
use App\Domains\Platform\Models\Ticket;
use App\Domains\Platform\Services\Pterodactyl\PterodactylService;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class ServiceSupportOverviewService
{
    public function __construct(
        private readonly PterodactylService $pterodactyl,
    ) {
    }

    public function build(string $uuid): array
    {
        $service = Service::with([
            'user',
            'plan.category',
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

        $runtime = $this->runtimeSnapshot($service);
        $billing = $this->billing($service, $receipts);
        $support = $this->support($service, $tickets, $receipts, $runtime, $billing);

        return [
            'service' => $service->toArray(),
            'game_server' => $this->gameServer($service, $runtime),
            'billing' => $billing,
            'support' => $support,
        ];
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
        $limits = $serverAttributes['limits'] ?? $service->plan?->resolvedLimits() ?? [];
        $featureLimits = $serverAttributes['feature_limits'] ?? $service->plan?->resolvedFeatureLimits() ?? [];

        return array_merge($base, [
            'pterodactyl_status' => [
                'status' => $this->normalizePowerState(
                    $resources['current_state'] ?? $serverAttributes['status'] ?? null
                ),
                'suspended' => (bool) ($serverAttributes['suspended'] ?? $resources['is_suspended'] ?? $service->status === 'suspended'),
                'suspension_reason' => $this->suspensionReason($service, $serverAttributes),
                'node' => $serverAttributes['node'] ?? $service->plan?->pterodactyl_node_id ?? null,
                'node_name' => $serverAttributes['node_name'] ?? data_get($serverAttributes, 'relationships.node.attributes.name') ?? $service->serverNode?->name,
                'node_location' => $serverAttributes['node_location'] ?? data_get($serverAttributes, 'relationships.node.attributes.location') ?? $service->serverNode?->location,
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
                'dns_status' => $this->dnsStatus($connection),
                'hostname_resolves' => null,
                'srv_record_ok' => null,
                'cname_record_ok' => null,
                'last_checked_at' => $runtime['checked_at'],
            ],
            'software' => [
                'egg_name' => $selectedEgg?->egg_name,
                'display_name' => $selectedEgg?->display_name,
                'docker_image' => $service->plan?->pterodactyl_docker_image ?? $selectedEgg?->docker_image,
                'java_version' => $this->javaVersion($service->plan?->pterodactyl_docker_image ?? $selectedEgg?->docker_image),
                'installed_version' => $this->installedVersion($service),
            ],
            'backups' => [
                'latest_backup_at' => null,
                'latest_backup_status' => null,
                'backups_used' => null,
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

    private function support(Service $service, Collection $tickets, Collection $receipts, array $runtime, array $billing): array
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
            'risk_flags' => $this->riskFlags($service, $runtime, $billing),
        ];
    }

    private function paymentEvents(Collection $receipts): array
    {
        return $receipts->take(5)->map(fn (Receipt $receipt) => [
            'type' => 'payment',
            'title' => 'invoice_' . $receipt->status,
            'description' => $receipt->receipt_number,
            'created_at' => $this->date($receipt->paid_at ?? $receipt->created_at),
        ])->all();
    }

    private function ticketEvents(Collection $tickets): array
    {
        return $tickets->map(fn (Ticket $ticket) => [
            'type' => 'ticket',
            'title' => 'ticket_' . $ticket->status,
            'description' => $ticket->subject,
            'created_at' => $this->date($ticket->updated_at),
        ])->all();
    }

    private function serviceEvents(Service $service, array $runtime): array
    {
        $events = [[
            'type' => $service->status === 'suspended' ? 'suspension' : 'provisioning',
            'title' => 'service_' . $service->status,
            'description' => $service->notes,
            'created_at' => $this->date($service->updated_at),
        ]];

        if ($runtime['resources']) {
            $events[] = [
                'type' => 'power',
                'title' => 'power_' . $this->normalizePowerState($runtime['resources']['current_state'] ?? null),
                'description' => null,
                'created_at' => $runtime['checked_at'],
            ];
        }

        return $events;
    }

    private function riskFlags(Service $service, array $runtime, array $billing): array
    {
        $flags = [];

        if ($service->status === 'failed') {
            $flags[] = [
                'severity' => 'critical',
                'code' => 'provisioning_failed',
                'message' => 'Provisioning failed for this service.',
            ];
        }

        if ($service->status === 'suspended' || ($runtime['server']['attributes']['suspended'] ?? false)) {
            $flags[] = [
                'severity' => 'critical',
                'code' => 'server_suspended',
                'message' => 'The service or Pterodactyl server is suspended.',
            ];
        }

        if (($billing['stats']['overdue_invoices'] ?? 0) > 0) {
            $flags[] = [
                'severity' => 'warning',
                'code' => 'billing_overdue',
                'message' => 'The service has overdue invoices.',
            ];
        }

        if ($service->plan?->isPterodactylManaged() && ! $service->pterodactyl_server_id) {
            $flags[] = [
                'severity' => 'warning',
                'code' => 'missing_pterodactyl_server',
                'message' => 'The service plan expects Pterodactyl but no server is linked.',
            ];
        }

        if ($runtime['server_error'] || $runtime['resources_error']) {
            $flags[] = [
                'severity' => 'warning',
                'code' => 'pterodactyl_unreachable',
                'message' => 'Pterodactyl data could not be refreshed.',
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

        $recordIds = $connection['dns_record_ids'] ?? [];

        return ! empty($recordIds) ? 'healthy' : 'degraded';
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

    private function javaVersion(?string $dockerImage): ?string
    {
        if (! $dockerImage) {
            return null;
        }

        return preg_match('/java[_:-]?(\d+)/i', $dockerImage, $matches) ? $matches[1] : null;
    }

    private function installedVersion(Service $service): ?string
    {
        return $service->configuration['installed_version']
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
