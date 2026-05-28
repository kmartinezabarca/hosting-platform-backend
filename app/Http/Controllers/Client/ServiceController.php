<?php

namespace App\Http\Controllers\Client;

use App\Exceptions\CheckoutQuoteException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Client\ContractServiceRequest;
use App\Http\Resources\ServiceResource;
use App\Services\CheckoutQuoteService;
use App\Services\ServiceContractingService;
use App\Services\Pterodactyl\PterodactylService;
use App\Services\ServiceStatusSyncService;
use App\Exceptions\PaymentRequiresActionException;
use App\Models\Service;
use App\Models\ServicePlan;
use App\Models\ServiceMetric;
use App\Models\ActivityLog;
use App\Models\Backup;
use App\Models\BackupSchedule;
use App\Services\Backup\BackupService;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class ServiceController extends Controller
{
    public function __construct(
        private readonly ServiceContractingService $contractingService,
        private readonly CheckoutQuoteService $checkoutQuotes,
        private readonly PterodactylService $pterodactyl,
        private readonly BackupService $backupService,
        private readonly ServiceStatusSyncService $statusSync,
    ) {}

    /**
     * GET /services/plans
     * Planes de servicio disponibles.
     */
    public function getServicePlans(): JsonResponse
    {
        try {
            $plans = ServicePlan::with(['category', 'features', 'pricing.billingCycle'])
                ->active()
                ->orderBy('sort_order')
                ->get()
                ->map(fn($plan) => [
                    'id'                   => $plan->id,
                    'uuid'                 => $plan->uuid,
                    'slug'                 => $plan->slug,
                    'name'                 => $plan->name,
                    'description'          => $plan->description,
                    'base_price'           => $plan->base_price,
                    'setup_fee'            => $plan->setup_fee,
                    'is_popular'           => $plan->is_popular,
                    'plan_type'            => $plan->plan_type ?? 'paid',
                    'is_free'              => $plan->isFree(),
                    'is_trial'             => $plan->isTrial(),
                    'trial_days'           => $plan->trial_days,
                    'converts_to_plan_id'  => $plan->converts_to_plan_id,
                    'category'             => $plan->category?->name,
                    'category_slug'        => $plan->category?->slug,
                    'specifications'       => $plan->specifications,
                    'features'             => $plan->features->pluck('name')->toArray(),
                    'pricing'              => $plan->pricing->map(fn($p) => [
                        'billing_cycle'       => $p->billingCycle->name,
                        'billing_cycle_slug'  => $p->billingCycle->slug,
                        'price'               => $p->price,
                        'discount_percentage' => $p->discount_percentage,
                    ])->toArray(),
                ]);

            return response()->json(['success' => true, 'data' => $plans]);
        } catch (\Exception $e) {
            Log::error('Error fetching service plans: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Error fetching service plans'], 500);
        }
    }

    /**
     * POST /services/contract
     * Contrata un servicio.
     */
    public function contractService(ContractServiceRequest $request): JsonResponse
    {
        $quote = null;

        try {
            $user = Auth::user();
            $validated = $request->validated();

            if (!empty($validated['quote_id'])) {
                $quote = $this->checkoutQuotes->validateQuote($validated['quote_id'], $user);

                if ((float) $quote->total > 0 && empty($validated['payment_intent_id']) && empty($validated['payment_method_id'])) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Debes proporcionar un PaymentIntent confirmado o un método de pago para esta cotización.',
                    ], 422);
                }

                $plan = $quote->servicePlan()->firstOrFail();
                $validated = $this->checkoutQuotes->contractPayload($quote, $validated);
            } else {
                $plan = ServicePlan::where('slug', $validated['plan_id'])->firstOrFail();
            }

            ['service' => $service, 'receipt' => $receipt] = $this->contractingService->contract(
                $user,
                $plan,
                $validated
            );

            if ($quote) {
                $this->checkoutQuotes->markConsumed($quote);
            }

            $service = $service->fresh(['plan.category', 'plan.features', 'selectedAddOns']);

            return response()->json([
                'success' => true,
                'message' => 'Servicio contratado exitosamente.',
                'service' => new ServiceResource($service),
                'data'    => new ServiceResource($service),
                'receipt' => $receipt->only(['uuid', 'invoice_number', 'total', 'currency']),
            ], 201);
        } catch (CheckoutQuoteException $e) {
            return response()->json([
                'success' => false,
                'error'   => $e->errorCode,
                'message' => $e->getMessage(),
            ], $e->status);
        } catch (PaymentRequiresActionException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'data'    => ['client_secret' => $e->clientSecret, 'requires_action' => true],
            ], 402);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (\Stripe\Exception\CardException $e) {
            return response()->json(['success' => false, 'message' => $e->getError()->message ?? 'Payment failed.'], 402);
        } catch (\Throwable $e) {
            Log::error('Error contracting service: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json([
                'success' => false,
                'message' => 'Error al contratar el servicio.',
                'debug'   => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * GET /services/upcoming-charges
     * Próximos cobros del usuario (servicios activos/suspendidos ordenados por fecha de vencimiento).
     */
    public function upcomingCharges(): JsonResponse
    {
        try {
            $charges = Service::where('user_id', Auth::id())
                ->whereIn('status', ['active', 'suspended'])
                ->whereNotNull('next_due_date')
                ->orderBy('next_due_date')
                ->get()
                ->map(fn (Service $s) => [
                    'uuid'          => $s->uuid,
                    'service_name'  => $s->name,
                    'amount'        => (float) $s->price,
                    'currency'      => 'MXN',
                    'next_due_date' => optional($s->next_due_date)->toDateString(),
                    'billing_cycle' => $s->billing_cycle,
                    'status'        => $s->status,
                ]);

            return response()->json(['success' => true, 'data' => $charges]);
        } catch (\Exception $e) {
            Log::error('Error fetching upcoming charges: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Error fetching upcoming charges'], 500);
        }
    }

    /**
     * GET /services/user
     * Servicios del usuario autenticado con runtime status, métricas y enriquecimiento.
     *
     * Estructura devuelta por servicio:
     *  - status          → status administrativo (billing): active|pending|suspended|failed|terminated
     *  - live_status     → status del proveedor (Pterodactyl/Coolify): running|starting|stopped|deploying|error|...
     *  - runtime_status  → status efectivo unificado para la UI
     *  - live_metrics    → snapshot cacheado del último sync
     *  - metrics         → snapshot derivado de service_metrics (game servers)
     *  - hosting_info    → enriquecimiento para servicios de hosting
     *  - sparkline       → últimos 12 valores CPU (game servers)
     *  - live_synced_at  → timestamp ISO del último sync con el proveedor
     */
    public function getUserServices(Request $request): JsonResponse
    {
        try {
            $userId = Auth::id();

            $services = Service::where('user_id', $userId)
                ->with(['plan.category', 'plan.features'])
                ->orderByDesc('created_at')
                ->get();

            // Sync on-demand para servicios sin live_status o con datos viejos (>90s)
            // Sólo se ejecuta si el cliente solicita explícitamente refresh=1 o si nunca se ha sincronizado.
            $forceRefresh = (bool) $request->boolean('refresh');
            $stalenessSec = 90;

            foreach ($services as $service) {
                $stale = ! $service->live_synced_at
                    || ($service->live_synced_at instanceof Carbon
                        && $service->live_synced_at->diffInSeconds(now()) > $stalenessSec);

                if (($forceRefresh || $stale) && in_array($service->status, ['active', 'pending'], true)) {
                    try {
                        $this->statusSync->syncOne($service);
                    } catch (\Throwable $e) {
                        // syncOne ya loguea — seguimos
                    }
                }
            }

            // Pre-fetch sparklines (últimos 12 puntos CPU) en una sola query
            $serviceIds = $services->pluck('id');
            $sparklines = $serviceIds->isNotEmpty()
                ? ServiceMetric::whereIn('service_id', $serviceIds)
                    ->where('sampled_at', '>=', now()->subHours(2))
                    ->orderBy('sampled_at')
                    ->get(['service_id', 'cpu_percent', 'memory_bytes', 'memory_limit_bytes', 'state', 'sampled_at'])
                    ->groupBy('service_id')
                : collect();

            $latestMetrics = $sparklines->map(fn($rows) => $rows->last());

            $payload = $services->map(function (Service $service) use ($sparklines, $latestMetrics) {
                $plan      = $service->plan;
                $category  = $plan?->category;
                $conn      = (array) ($service->connection_details ?? []);
                $slug      = $category?->slug ?? '';

                $isGameServer = in_array($slug, ['game-servers', 'gameserver', 'minecraft'], true);
                $isHosting    = in_array($slug, ['hosting', 'web-hosting', 'webhosting'], true);

                // Runtime status: prioriza live_status (proveedor) sobre status (billing)
                $runtimeStatus = $this->resolveRuntimeStatus($service);

                // Sparkline + métricas derivadas de service_metrics
                $sparkData = $sparklines->get($service->id, collect());
                $sparkline = $sparkData->takeLast(12)
                    ->pluck('cpu_percent')
                    ->map(fn($v) => (int) round((float) $v))
                    ->values()
                    ->toArray();

                $latest = $latestMetrics->get($service->id);
                $metrics = null;
                if ($latest) {
                    $memBytes = (int) ($latest->memory_bytes ?? 0);
                    $memLimit = (int) ($latest->memory_limit_bytes ?? 0);
                    $metrics = [
                        'cpu'        => $latest->cpu_percent !== null ? (int) round((float) $latest->cpu_percent) : null,
                        'memory'     => $memLimit > 0 ? (int) round(($memBytes / $memLimit) * 100) : null,
                        'memory_bytes' => $memBytes,
                        'memory_limit_bytes' => $memLimit,
                        'state'      => $latest->state,
                        'sampled_at' => optional($latest->sampled_at)->toIso8601String(),
                    ];
                }

                // Uptime % derivado de últimos 24h de samples (state=running ratio)
                $uptimePct = null;
                if ($sparkData->count() > 0) {
                    $total   = $sparkData->count();
                    $running = $sparkData->where('state', 'running')->count();
                    $uptimePct = $total > 0 ? round(($running / $total) * 100, 1) : null;
                }

                // Enriquecimiento hosting
                $hostingInfo = null;
                if ($isHosting) {
                    $fqdn = $conn['fqdn'] ?? $conn['domain'] ?? null;
                    $hostingInfo = [
                        'fqdn'              => $fqdn,
                        'url'               => $fqdn ? (str_starts_with($fqdn, 'http') ? $fqdn : 'https://' . $fqdn) : null,
                        'ssl_enabled'       => isset($conn['ssl_enabled']) ? (bool) $conn['ssl_enabled'] : (bool) ($fqdn && str_starts_with((string) $fqdn, 'https')),
                        'db_type'           => $conn['db_type'] ?? null,
                        'db_name'           => $conn['db_name'] ?? null,
                        'db_host'           => $conn['db_host'] ?? null,
                        'db_port'           => $conn['db_port'] ?? null,
                        'build_pack'        => $conn['build_pack'] ?? null,
                        'environment'       => $conn['environment'] ?? $conn['environment_name'] ?? 'production',
                        'last_deployed_at'  => $conn['last_deployed_at'] ?? null,
                        'coolify_app_uuid'  => $conn['coolify_app_uuid'] ?? null,
                        'panel_url'         => $conn['panel_url'] ?? null,
                        'storage_quota'     => data_get($plan?->specifications, 'storage') ?? data_get($plan?->specifications, 'disk'),
                        'bandwidth_quota'   => data_get($plan?->specifications, 'bandwidth'),
                        'domains_quota'     => data_get($plan?->specifications, 'domains'),
                        'email_quota'       => data_get($plan?->specifications, 'email'),
                        'databases_quota'   => data_get($plan?->specifications, 'databases'),
                    ];
                }

                $base = $service->toArray();

                return array_merge($base, [
                    'plan'             => $plan,
                    'runtime_status'   => $runtimeStatus,
                    'live_status'      => $service->live_status,
                    'live_metrics'     => $service->live_metrics,
                    'live_synced_at'   => optional($service->live_synced_at)->toIso8601String(),
                    'metrics'          => $metrics,
                    'uptime_pct'       => $uptimePct,
                    'sparkline'        => $sparkline,
                    'hosting_info'     => $hostingInfo,
                    'is_game_server'   => $isGameServer,
                    'is_hosting'       => $isHosting,
                    'category_slug'    => $slug,
                ]);
            });

            return response()->json(['success' => true, 'data' => $payload]);
        } catch (\Exception $e) {
            Log::error('Error fetching user services: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json(['success' => false, 'message' => 'Error fetching user services'], 500);
        }
    }

    /**
     * POST /services/sync-status
     * Fuerza un re-sync del live_status/live_metrics de todos los servicios del usuario.
     * Útil cuando la UI quiere refrescar después de una acción (start/stop/restart).
     */
    public function syncStatus(): JsonResponse
    {
        try {
            $services = Service::where('user_id', Auth::id())
                ->whereIn('status', ['active', 'pending', 'maintenance'])
                ->with('plan.category')
                ->get();

            $synced = 0;
            foreach ($services as $service) {
                try {
                    $this->statusSync->syncOne($service);
                    $synced++;
                } catch (\Throwable $e) {
                    // continuar con el resto
                }
            }

            return response()->json([
                'success' => true,
                'message' => "Sincronizados {$synced} servicio(s).",
                'data'    => ['synced' => $synced, 'total' => $services->count()],
            ]);
        } catch (\Exception $e) {
            Log::error('Error en syncStatus: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Error sincronizando estados'], 500);
        }
    }

    /**
     * Determina el estado efectivo a mostrar al usuario.
     *
     * Reglas:
     *  - Si el billing status es 'pending' → "provisioning" (aprovisionando)
     *  - Si el billing status es 'failed'/'suspended'/'terminated'/'cancelled' → ese mismo
     *  - Si hay live_status del proveedor → ese (running, starting, stopping, stopped, deploying, building, error)
     *  - Si no hay live_status pero billing 'active' → 'unknown' (esperando sync)
     *  - Fallback → billing status
     */
    private function resolveRuntimeStatus(Service $service): string
    {
        $billing = $service->status;
        $live    = $service->live_status;

        // Estados no-runtime: mostrar billing tal cual
        if ($billing === 'pending')    return 'provisioning';
        if ($billing === 'failed')     return 'failed';
        if ($billing === 'suspended')  return 'suspended';
        if ($billing === 'terminated') return 'terminated';
        if ($billing === 'cancelled')  return 'cancelled';

        // Estado runtime del proveedor
        if ($live) {
            return $live; // running|starting|stopping|stopped|deploying|building|error|degraded
        }

        // Active pero aún no sincronizado
        if ($billing === 'active') return 'unknown';

        return $billing ?? 'unknown';
    }

    /**
     * GET /services/{uuid}
     * Detalle de un servicio.
     */
    public function getServiceDetails(string $uuid): JsonResponse
    {
        try {
            $service = Service::where('user_id', Auth::id())
                ->where('uuid', $uuid)
                ->with(['plan.category', 'plan.features', 'selectedAddOns'])
                ->firstOrFail();

            return response()->json(['success' => true, 'data' => new ServiceResource($service)]);
        } catch (\Exception $e) {
            Log::error('Error fetching service details: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Service not found or not authorized'], 404);
        }
    }

    /**
     * GET /services/{uuid}/invoices
     */
    public function getServiceInvoices(Request $request, string $uuid): JsonResponse
    {
        $service  = Service::where('uuid', $uuid)->where('user_id', $request->user()->id)->firstOrFail();
        $invoices = $service->invoice()->orderByDesc('created_at')->get();

        return response()->json(['success' => true, 'data' => $invoices]);
    }

    /**
     * PATCH /services/{uuid}/configuration
     * Actualiza auto_renew y otros campos simples del configuration JSON.
     */
    public function updateConfiguration(Request $request, string $uuid): JsonResponse
    {
        $service   = Service::where('uuid', $uuid)->where('user_id', $request->user()->id)->firstOrFail();
        $validated = $request->validate(['auto_renew' => 'required|boolean']);

        $config                = $service->configuration ?? [];
        $config['auto_renew'] = $validated['auto_renew'];
        $service->configuration = $config;
        $service->save();

        return response()->json(['success' => true, 'message' => 'Configuración actualizada correctamente.', 'data' => $service]);
    }

    /**
     * PUT /services/{uuid}/config
     * Actualiza claves permitidas del configuration del servicio.
     * Solo se aceptan las claves whitelisted; el resto se ignora.
     */
    public function updateServiceConfig(Request $request, string $uuid): JsonResponse
    {
        try {
            $user    = Auth::user();
            $service = Service::where('user_id', $user->id)->where('uuid', $uuid)->firstOrFail();
            $validated = $request->validate([
                'configuration'            => 'required|array',
                'configuration.auto_renew' => 'sometimes|boolean',
                'configuration.notes'      => 'sometimes|nullable|string|max:500',
                'configuration.timezone'   => 'sometimes|nullable|string|max:50',
                'configuration.language'   => 'sometimes|nullable|string|max:10',
            ]);

            // Solo permitir claves conocidas para evitar inyección de config arbitraria
            $allowed = array_intersect_key(
                $validated['configuration'],
                array_flip(['auto_renew', 'notes', 'timezone', 'language'])
            );

            $current = $service->configuration ?? [];
            $service->update(['configuration' => array_merge($current, $allowed)]);

            ActivityLog::record(
                'Configuración de servicio actualizada',
                "Configuración del servicio {$service->name} ({$service->uuid}) actualizada.",
                'service',
                ['user_id' => $user->id, 'service_id' => $service->id, 'new_config' => $validated['configuration']],
                $user->id
            );

            return response()->json(['success' => true, 'message' => 'Service configuration updated successfully', 'data' => $service->fresh()]);
        } catch (\Exception $e) {
            Log::error('Error updating service configuration: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Error updating service configuration'], 500);
        }
    }

    // ── Plan upgrade / downgrade ──────────────────────────────────────────────

    /**
     * GET /services/{uuid}/upgrade-options
     * Lista los planes disponibles en la misma categoría para upgrade/downgrade.
     */
    public function upgradeOptions(string $uuid): JsonResponse
    {
        $service = Service::where('user_id', Auth::id())
            ->where('uuid', $uuid)
            ->with(['plan.category', 'plan.pricing.billingCycle'])
            ->firstOrFail();

        $categoryId = $service->plan?->category_id;

        if (! $categoryId) {
            return response()->json(['success' => false, 'message' => 'No se pudo determinar la categoría del plan.'], 422);
        }

        $plans = ServicePlan::with(['features', 'pricing.billingCycle'])
            ->where('category_id', $categoryId)
            ->where('is_active', true)
            ->orderBy('base_price')
            ->get()
            ->map(function ($plan) use ($service) {
                $currentPrice = (float) $service->price;
                $planPrice    = (float) $plan->base_price;
                return [
                    'id'          => $plan->id,
                    'uuid'        => $plan->uuid,
                    'slug'        => $plan->slug,
                    'name'        => $plan->name,
                    'description' => $plan->description,
                    'base_price'  => $planPrice,
                    'currency'    => 'MXN',
                    'specs'       => $plan->specifications ?? [],
                    'limits'      => $plan->pterodactyl_limits ?? [],
                    'features'    => $plan->features->pluck('description')->toArray(),
                    'is_current'  => $plan->id === $service->plan_id,
                    'direction'   => $planPrice > $currentPrice ? 'upgrade'
                                   : ($planPrice < $currentPrice ? 'downgrade' : 'same'),
                ];
            });

        return response()->json([
            'success' => true,
            'data'    => [
                'current_plan' => [
                    'id'    => $service->plan_id,
                    'name'  => $service->plan?->name,
                    'price' => (float) $service->price,
                ],
                'billing_cycle' => $service->billing_cycle,
                'plans'         => $plans,
            ],
        ]);
    }

    /**
     * POST /services/{uuid}/upgrade
     * Cambia el plan del servicio.
     * Body: { plan_uuid: string }
     * - Actualiza plan_id, price en el servicio.
     * - Si es game server Pterodactyl: actualiza limits en el panel.
     * - No procesa pago diferencial aquí (se factura en próximo ciclo o vía invoice separada).
     */
    public function upgradePlan(Request $request, string $uuid): JsonResponse
    {
        $user    = Auth::user();
        $service = Service::where('user_id', $user->id)
            ->where('uuid', $uuid)
            ->with(['plan.category'])
            ->firstOrFail();

        $validated = $request->validate([
            'plan_uuid' => ['required', 'string', 'exists:service_plans,uuid'],
        ]);

        $newPlan = ServicePlan::where('uuid', $validated['plan_uuid'])
            ->where('is_active', true)
            ->firstOrFail();

        // Validar misma categoría
        if ($newPlan->category_id !== $service->plan?->category_id) {
            return response()->json([
                'success' => false,
                'message' => 'No puedes cambiar a un plan de otra categoría.',
            ], 422);
        }

        if ($newPlan->id === $service->plan_id) {
            return response()->json([
                'success' => false,
                'message' => 'Ya tienes este plan activo.',
            ], 422);
        }

        $oldPlanName  = $service->plan?->name ?? '—';
        $oldPrice     = (float) $service->price;
        $newPrice     = (float) $newPlan->base_price;
        $direction    = $newPrice >= $oldPrice ? 'upgrade' : 'downgrade';

        // Actualizar servicio
        $service->update([
            'plan_id' => $newPlan->id,
            'price'   => $newPrice,
        ]);

        // Si es Pterodactyl: actualizar límites de RAM/CPU/disco en el panel
        if ($service->isPterodactylManaged() && $service->pterodactyl_server_id) {
            $limits = $newPlan->pterodactyl_limits ?? [];
            if (! empty($limits)) {
                try {
                    $this->pterodactyl->updateServerBuild((int) $service->pterodactyl_server_id, [
                        'memory'       => (int) ($limits['memory']       ?? 0),
                        'swap'         => (int) ($limits['swap']         ?? 0),
                        'disk'         => (int) ($limits['disk']         ?? 0),
                        'io'           => (int) ($limits['io']           ?? 500),
                        'cpu'          => (int) ($limits['cpu']          ?? 0),
                        'threads'      => $limits['threads']             ?? null,
                        'feature_limits' => [
                            'databases'  => (int) ($newPlan->pterodactyl_feature_limits['databases'] ?? 0),
                            'backups'    => (int) ($newPlan->pterodactyl_feature_limits['backups']   ?? 0),
                            'allocations'=> (int) ($newPlan->pterodactyl_feature_limits['allocations'] ?? 1),
                        ],
                    ]);
                } catch (\Throwable $e) {
                    Log::warning('upgrade: no se pudieron actualizar los límites en Pterodactyl', [
                        'service_id' => $service->id,
                        'error'      => $e->getMessage(),
                    ]);
                    // No es fatal — el cambio de plan ya quedó registrado
                }
            }
        }

        ActivityLog::record(
            "Plan {$direction}: {$oldPlanName} → {$newPlan->name}",
            "El cliente cambió de plan de «{$oldPlanName}» a «{$newPlan->name}». Precio anterior: \${$oldPrice} → nuevo: \${$newPrice}.",
            'service',
            ['service_id' => $service->id, 'old_plan' => $oldPlanName, 'new_plan' => $newPlan->name],
            $user->id,
        );

        return response()->json([
            'success'   => true,
            'message'   => ucfirst($direction) . " completado. Ahora tienes el plan «{$newPlan->name}».",
            'data'      => [
                'direction' => $direction,
                'old_plan'  => $oldPlanName,
                'new_plan'  => $newPlan->name,
                'old_price' => $oldPrice,
                'new_price' => $newPrice,
            ],
        ]);
    }

    // ── Activity log ──────────────────────────────────────────────────────────

    /**
     * GET /services/{uuid}/activity
     * Devuelve el historial de actividad del servicio (últimas 100 entradas).
     * Query params: page, per_page (max 50).
     */
    public function activityLog(Request $request, string $uuid): JsonResponse
    {
        $user    = Auth::user();
        $service = Service::where('uuid', $uuid)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $perPage = min(50, max(5, (int) $request->query('per_page', 20)));

        $logs = ActivityLog::where('type', 'service')
            ->where(function ($q) use ($service) {
                $q->whereJsonContains('meta->service_id', $service->id)
                  ->orWhere('meta->service_id', (string) $service->id);
            })
            ->where('user_id', $user->id)
            ->orderByDesc('occurred_at')
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'data'    => [
                'items'        => $logs->map(fn ($log) => [
                    'uuid'        => $log->uuid,
                    'action'      => $log->action,
                    'description' => $log->service,
                    'type'        => $log->type,
                    'occurred_at' => $log->occurred_at?->toISOString(),
                    'meta'        => $log->meta,
                ]),
                'current_page' => $logs->currentPage(),
                'last_page'    => $logs->lastPage(),
                'total'        => $logs->total(),
            ],
        ]);
    }

    /**
     * POST /services/{uuid}/cancel
     */
    public function cancelService(string $uuid): JsonResponse
    {
        return $this->changeServiceStatus($uuid, 'cancelled', ['cancelled_at' => now()], 'Servicio cancelado');
    }

    /**
     * POST /services/{uuid}/suspend
     */
    public function suspendService(string $uuid): JsonResponse
    {
        return $this->changeServiceStatus($uuid, 'suspended', ['suspended_at' => now()], 'Servicio suspendido');
    }

    /**
     * POST /services/{uuid}/reactivate
     */
    public function reactivateService(string $uuid): JsonResponse
    {
        return $this->changeServiceStatus($uuid, 'active', ['suspended_at' => null], 'Servicio reactivado');
    }

    /** Servicio del usuario autenticado o 404. */
    private function ownedService(string $uuid): Service
    {
        return Service::where('user_id', Auth::id())
            ->where('uuid', $uuid)
            ->firstOrFail();
    }

    /** Identificador Pterodactyl del servicio (game server) o null. */
    private function pteroId(Service $service): ?string
    {
        return $service->connection_details['identifier'] ?? null;
    }

    /** ¿Es un servicio de hosting Coolify con base de datos? */
    private function isHosting(Service $service): bool
    {
        $c = $service->connection_details ?? [];
        return empty($c['identifier']) && !empty($c['db_name']);
    }

    /** Mapea un registro Backup al formato que espera el frontend. */
    private function mapHostingBackup(Backup $b): array
    {
        return [
            'id'            => $b->uuid,
            'uuid'          => $b->uuid,
            'name'          => $b->name,
            'bytes'         => $b->size_bytes,
            'size'          => $b->size_bytes,
            'is_successful' => $b->status === 'completed',
            'is_locked'     => false,
            'created_at'    => optional($b->created_at)->toISOString(),
            'completed_at'  => optional($b->completed_at)->toISOString(),
        ];
    }

    /**
     * GET /services/{uuid}/backups
     */
    public function getServiceBackups(string $uuid): JsonResponse
    {
        $service = $this->ownedService($uuid);

        // Game server → API nativa de Pterodactyl
        if ($identifier = $this->pteroId($service)) {
            try {
                return response()->json(['success' => true, 'data' => $this->pterodactyl->listBackups($identifier)]);
            } catch (\Throwable $e) {
                Log::error('Error listando backups', ['uuid' => $uuid, 'error' => $e->getMessage()]);
                return response()->json(['success' => false, 'message' => 'No se pudieron obtener las copias.', 'data' => []], 502);
            }
        }

        // Hosting Coolify → registros de la tabla backups (DB en el NAS)
        if ($this->isHosting($service)) {
            $rows = Backup::where('service_id', $service->id)
                ->where('type', 'hosting')
                ->orderByDesc('created_at')
                ->get()
                ->map(fn (Backup $b) => $this->mapHostingBackup($b));
            return response()->json(['success' => true, 'data' => $rows]);
        }

        return response()->json(['success' => true, 'data' => []]);
    }

    /**
     * POST /services/{uuid}/backups
     */
    public function createServiceBackup(string $uuid, Request $request): JsonResponse
    {
        $user    = Auth::user();
        $service = $this->ownedService($uuid);
        $name    = mb_substr(trim($request->input('name', '')), 0, 160) ?: 'Backup ' . now()->format('d/m/Y H:i');

        if ($identifier = $this->pteroId($service)) {
            try {
                $backup = $this->pterodactyl->createBackup($identifier, $name);
            } catch (\Throwable $e) {
                Log::error('Error creando backup', ['uuid' => $uuid, 'error' => $e->getMessage()]);
                return response()->json([
                    'success' => false,
                    'message' => str_contains($e->getMessage(), 'limit')
                        ? 'Alcanzaste el límite de copias de tu plan. Elimina una para crear otra.'
                        : 'No se pudo crear la copia de seguridad.',
                ], 422);
            }
        } elseif ($this->isHosting($service)) {
            $b = $this->backupService->create('hosting', [
                'name'       => $name,
                'user_id'    => $service->user_id,
                'service_id' => $service->id,
                'conn'       => $service->connection_details ?? [],
            ]);
            if ($b->status === 'failed') {
                return response()->json([
                    'success' => false,
                    'message' => 'No se pudo crear la copia de la base de datos del sitio.',
                ], 422);
            }
            $backup = $this->mapHostingBackup($b);
        } else {
            return response()->json(['success' => false, 'message' => 'Este servicio no admite copias de seguridad.'], 422);
        }

        ActivityLog::record(
            'Copia de seguridad creada',
            "El usuario creó una copia de seguridad para el servicio {$service->name}.",
            'service',
            ['user_id' => $user->id, 'service_id' => $service->id],
            $user->id
        );

        return response()->json([
            'success' => true,
            'data'    => $backup,
            'message' => 'Copia de seguridad creada. Estará lista en unos minutos.',
        ], 201);
    }

    /**
     * POST /services/{uuid}/backups/{backupId}/restore
     */
    public function restoreServiceBackup(string $uuid, string $backupId): JsonResponse
    {
        $user    = Auth::user();
        $service = $this->ownedService($uuid);

        if ($identifier = $this->pteroId($service)) {
            try {
                $this->pterodactyl->restoreBackup($identifier, $backupId);
            } catch (\Throwable $e) {
                Log::error('Error restaurando backup', ['uuid' => $uuid, 'error' => $e->getMessage()]);
                return response()->json([
                    'success' => false,
                    'message' => 'No se pudo iniciar la restauración. Verifica que el servidor esté detenido.',
                ], 422);
            }

            ActivityLog::record(
                'Restauración de servicio',
                "El usuario restauró el servicio {$service->name} desde la copia {$backupId}.",
                'service',
                ['user_id' => $user->id, 'service_id' => $service->id, 'backup_id' => $backupId],
                $user->id
            );

            return response()->json([
                'success' => true,
                'message' => 'Restauración iniciada. El servidor volverá en unos minutos.',
            ]);
        }

        // Hosting: la restauración de BD es asistida para evitar pérdida de datos.
        return response()->json([
            'success' => false,
            'message' => 'Para restaurar tu sitio, descarga la copia y solicita la restauración asistida a soporte.',
        ], 422);
    }

    /**
     * DELETE /services/{uuid}/backups/{backupId}
     */
    public function deleteServiceBackup(string $uuid, string $backupId): JsonResponse
    {
        $service = $this->ownedService($uuid);

        if ($identifier = $this->pteroId($service)) {
            try {
                $this->pterodactyl->deleteBackup($identifier, $backupId);
            } catch (\Throwable $e) {
                Log::error('Error eliminando backup', ['uuid' => $uuid, 'error' => $e->getMessage()]);
                return response()->json(['success' => false, 'message' => 'No se pudo eliminar la copia.'], 422);
            }
            return response()->json(['success' => true, 'message' => 'Copia de seguridad eliminada.']);
        }

        $backup = Backup::where('service_id', $service->id)->where('uuid', $backupId)->first();
        if (!$backup) {
            return response()->json(['success' => false, 'message' => 'Copia no encontrada.'], 404);
        }
        $this->backupService->delete($backup);
        return response()->json(['success' => true, 'message' => 'Copia de seguridad eliminada.']);
    }

    /**
     * GET /services/{uuid}/backups/{backupId}/download
     * Devuelve una URL para descargar la copia.
     */
    public function downloadServiceBackup(string $uuid, string $backupId): JsonResponse
    {
        $service = $this->ownedService($uuid);

        if ($identifier = $this->pteroId($service)) {
            try {
                $url = $this->pterodactyl->getBackupDownloadUrl($identifier, $backupId);
            } catch (\Throwable $e) {
                Log::error('Error descargando backup', ['uuid' => $uuid, 'error' => $e->getMessage()]);
                return response()->json(['success' => false, 'message' => 'No se pudo generar la descarga.'], 422);
            }
            return response()->json(['success' => true, 'data' => ['url' => $url]]);
        }

        // Hosting → URL a nuestro endpoint de streaming protegido
        $backup = Backup::where('service_id', $service->id)->where('uuid', $backupId)->first();
        if (!$backup) {
            return response()->json(['success' => false, 'message' => 'Copia no encontrada.'], 404);
        }
        return response()->json([
            'success' => true,
            'data'    => ['url' => url("/api/services/{$uuid}/backups/{$backupId}/file")],
        ]);
    }

    /**
     * GET /services/{uuid}/backups/{backupId}/file
     * Descarga directa (streaming) de una copia de hosting desde el NAS.
     */
    public function streamServiceBackup(string $uuid, string $backupId)
    {
        $service = $this->ownedService($uuid);
        $backup  = Backup::where('service_id', $service->id)->where('uuid', $backupId)->firstOrFail();

        $disk = \Illuminate\Support\Facades\Storage::disk($backup->disk);
        if (!$backup->path || !$disk->exists($backup->path)) {
            abort(404, 'El archivo de respaldo no está disponible.');
        }

        return $disk->download($backup->path, $backup->name . '.zip');
    }

    // ─── Backup schedules (client) ────────────────────────────────────────────

    /**
     * GET /services/{uuid}/backups/schedules
     * Lista los schedules de backup del servicio del usuario.
     */
    public function getBackupSchedules(string $uuid): JsonResponse
    {
        $service   = $this->ownedService($uuid);
        $schedules = BackupSchedule::where('scope', 'service')
            ->where('scope_id', $service->id)
            ->orderBy('created_at')
            ->get()
            ->map(fn (BackupSchedule $s) => $this->mapSchedule($s));

        return response()->json(['success' => true, 'data' => $schedules]);
    }

    /**
     * POST /services/{uuid}/backups/schedules
     * Crea un schedule de backup para el servicio.
     */
    public function createBackupSchedule(string $uuid, Request $request): JsonResponse
    {
        $service = $this->ownedService($uuid);

        $data = $request->validate([
            'name'           => ['required', 'string', 'max:100'],
            'frequency'      => ['required', 'in:daily,weekly,monthly'],
            'run_at_time'    => ['nullable', 'string', 'regex:/^\d{2}:\d{2}$/'],
            'run_at_day'     => ['nullable', 'integer', 'min:0', 'max:31'],
            'retention_days' => ['nullable', 'integer', 'min:1', 'max:90'],
            'is_enabled'     => ['nullable', 'boolean'],
        ]);

        $type = $this->pteroId($service) ? 'game_server' : 'hosting';

        $schedule = BackupSchedule::create([
            'name'           => $data['name'],
            'type'           => $type,
            'scope'          => 'service',
            'scope_id'       => $service->id,
            'frequency'      => $data['frequency'],
            'run_at_time'    => $data['run_at_time'] ?? '03:00',
            'run_at_day'     => $data['run_at_day'] ?? 1,
            'retention_days' => $data['retention_days'] ?? 7,
            'is_enabled'     => $data['is_enabled'] ?? true,
        ]);

        $schedule->update(['next_run_at' => $schedule->computeNextRun()]);

        return response()->json([
            'success' => true,
            'data'    => $this->mapSchedule($schedule->fresh()),
            'message' => 'Programación de backup creada.',
        ], 201);
    }

    /**
     * PUT /services/{uuid}/backups/schedules/{scheduleUuid}
     * Actualiza un schedule (activa/desactiva, cambia frecuencia, etc.)
     */
    public function updateBackupSchedule(string $uuid, string $scheduleUuid, Request $request): JsonResponse
    {
        $service  = $this->ownedService($uuid);
        $schedule = BackupSchedule::where('uuid', $scheduleUuid)
            ->where('scope', 'service')
            ->where('scope_id', $service->id)
            ->firstOrFail();

        $data = $request->validate([
            'name'           => ['sometimes', 'string', 'max:100'],
            'frequency'      => ['sometimes', 'in:daily,weekly,monthly'],
            'run_at_time'    => ['sometimes', 'nullable', 'string', 'regex:/^\d{2}:\d{2}$/'],
            'run_at_day'     => ['sometimes', 'nullable', 'integer', 'min:0', 'max:31'],
            'retention_days' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:90'],
            'is_enabled'     => ['sometimes', 'boolean'],
        ]);

        $schedule->update($data);
        $schedule->update(['next_run_at' => $schedule->computeNextRun()]);

        return response()->json([
            'success' => true,
            'data'    => $this->mapSchedule($schedule->fresh()),
            'message' => 'Programación actualizada.',
        ]);
    }

    /**
     * DELETE /services/{uuid}/backups/schedules/{scheduleUuid}
     * Elimina un schedule.
     */
    public function deleteBackupSchedule(string $uuid, string $scheduleUuid): JsonResponse
    {
        $service  = $this->ownedService($uuid);
        $schedule = BackupSchedule::where('uuid', $scheduleUuid)
            ->where('scope', 'service')
            ->where('scope_id', $service->id)
            ->firstOrFail();

        $schedule->delete();

        return response()->json(['success' => true, 'message' => 'Programación eliminada.']);
    }

    /** Formatea un BackupSchedule para el frontend. */
    private function mapSchedule(BackupSchedule $s): array
    {
        return [
            'uuid'           => $s->uuid,
            'name'           => $s->name,
            'frequency'      => $s->frequency,
            'run_at_time'    => $s->run_at_time,
            'run_at_day'     => $s->run_at_day,
            'retention_days' => $s->retention_days,
            'is_enabled'     => $s->is_enabled,
            'last_run_at'    => optional($s->last_run_at)->toISOString(),
            'next_run_at'    => optional($s->next_run_at)->toISOString(),
            'created_at'     => optional($s->created_at)->toISOString(),
        ];
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    private function changeServiceStatus(string $uuid, string $status, array $extra, string $logTitle): JsonResponse
    {
        try {
            $user    = Auth::user();
            $service = Service::where('user_id', $user->id)->where('uuid', $uuid)->firstOrFail();

            $service->update(array_merge(['status' => $status], $extra));

            ActivityLog::record(
                $logTitle,
                "{$logTitle}: {$service->name} ({$service->uuid}).",
                'service',
                ['user_id' => $user->id, 'service_id' => $service->id],
                $user->id
            );

            return response()->json(['success' => true, 'message' => "{$logTitle} exitosamente.", 'data' => $service->fresh()]);
        } catch (\Exception $e) {
            Log::error("{$logTitle} error: " . $e->getMessage());
            return response()->json(['success' => false, 'message' => "Error: {$logTitle}"], 500);
        }
    }
}
