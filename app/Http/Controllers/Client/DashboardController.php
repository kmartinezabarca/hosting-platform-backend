<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;

use Illuminate\Http\Request;
use App\Models\ActivityLog;
use App\Models\Service;
use App\Models\ServiceMetric;
use App\Models\SystemStatus;
use App\Models\Ticket;
use App\Models\User;
use App\Models\UserSession;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DashboardController extends Controller
{
    /**
     * Define la paleta de colores profesional para los gráficos.
     * Estos colores se envían al frontend para mantener la consistencia de la marca.
     */
    private const CHART_COLORS = [
        'active'      => '#2E7D32', // Verde oscuro y sobrio
        'suspended'   => '#D84315', // Naranja/Rojo quemado, para alertas
        'maintenance' => '#FBC02D', // Amarillo/Mostaza, para advertencias
        'pending'     => '#1976D2', // Azul corporativo
        'default'     => '#546E7A', // Gris azulado para otros estados
    ];

    /**
     * Centraliza las traducciones de los estados para mantener la consistencia.
     */
    private const STATUS_TRANSLATIONS = [
        'active'      => 'Activos',
        'suspended'   => 'Suspendidos',
        'maintenance' => 'En Mantenimiento',
        'pending'     => 'Pendientes',
    ];

    /**
     * Obtiene todas las estadísticas, incluyendo datos para gráficos, para el dashboard del usuario.
     */
    public function getStats(Request $request)
    {
        try {
            $user = Auth::user();
            if (!$user) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            // --- 1. Estadísticas de Servicios ---
            $servicesQuery = Service::where('user_id', $user->id);
            $servicesStats = (clone $servicesQuery)
                ->select('status', DB::raw('count(*) as count'))
                ->groupBy('status')
                ->pluck('count', 'status');

            $totalServices = $servicesStats->sum();
            $activeServices = $servicesStats->get('active', 0);
            $maintenanceServices = $servicesStats->get('maintenance', 0);
            $suspendedServices = $servicesStats->get('suspended', 0);

            $newServicesLast30Days = (clone $servicesQuery)
                ->where('created_at', '>=', now()->subDays(30))
                ->count();
            $previousServices = $totalServices - $newServicesLast30Days;
            $serviceTrend = ($previousServices > 0) ? round(($newServicesLast30Days / $previousServices) * 100) : ($newServicesLast30Days > 0 ? 100 : 0);

            // --- 2. Estadísticas de Dominios (Preparado para el futuro) ---
            $totalDomains = 0;
            $activeDomains = 0;
            $pendingDomains = 0;

            // --- 3. Estadísticas de Facturación ---
            $activeServicesQuery = Service::where('user_id', $user->id);
            $monthlySpend = (clone $activeServicesQuery)->sum('price');

            $lastMonthSpend = (clone $activeServicesQuery)
                ->where('created_at', '<', now()->startOfMonth())
                ->sum('price');
            $billingTrend = ($lastMonthSpend > 0) ? round((($monthlySpend - $lastMonthSpend) / $lastMonthSpend) * 100) : ($monthlySpend > 0 ? 100 : 0);

            // --- 4. Métricas de Rendimiento ---
            $performanceUptime = ($activeServices > 0) ? (($suspendedServices > 0 || $maintenanceServices > 0) ? 95.0 : 99.9) : null;

            // Uptime history (last 15 days) — derived from activity incident logs only.
            // Days with no incidents stay at the base uptime (no artificial variance added).
            $uptimeHistory = [];
            $baseUptime = $performanceUptime ?? 99.9;
            for ($i = 14; $i >= 0; $i--) {
                $day = Carbon::now()->subDays($i)->toDateString();
                $incidents = ActivityLog::where('user_id', $user->id)
                    ->whereDate('occurred_at', $day)
                    ->where(function ($q) {
                        $q->where('type', 'error')
                          ->orWhere('type', 'alert')
                          ->orWhere('type', 'incident')
                          ->orWhere('action', 'like', '%suspend%')
                          ->orWhere('action', 'like', '%incident%');
                    })
                    ->count();

                $uptimeHistory[] = $incidents > 0
                    ? round(max(85.0, $baseUptime - ($incidents * 4.9)), 1)
                    : $baseUptime;
            }

            // --- 5. Estado global del sistema ---
            $allStatuses = \App\Models\SystemStatus::all()->pluck('status');
            $systemStatus = 'operational';
            if ($allStatuses->contains('outage')) {
                $systemStatus = 'outage';
            } elseif ($allStatuses->contains('degraded')) {
                $systemStatus = 'degraded';
            }

            // --- 6. Tickets abiertos ---
            $openTickets = Ticket::where('user_id', $user->id)
                ->whereIn('status', ['open', 'in_progress', 'pending', 'waiting_customer'])
                ->count();

            // --- 7. Fleet breakdown por tipo de categoría ---
            $userServicesWithPlan = Service::where('user_id', $user->id)
                ->with('plan.category')
                ->get();

            $fleetTypes = ['game_server' => 0, 'hosting' => 0, 'vps' => 0, 'other' => 0];
            foreach ($userServicesWithPlan as $svc) {
                $slug = $svc->plan?->category?->slug ?? '';
                if ($slug === 'game-servers' || $slug === 'gameserver') {
                    $fleetTypes['game_server']++;
                } elseif (in_array($slug, ['hosting', 'shared-hosting', 'web-hosting'])) {
                    $fleetTypes['hosting']++;
                } elseif (in_array($slug, ['vps', 'dedicated', 'cloud'])) {
                    $fleetTypes['vps']++;
                } else {
                    $fleetTypes['other']++;
                }
            }

            // --- 8. CPU y red promedio desde ServiceMetric (últimas muestras por servicio) ---
            $userServiceIds = Service::where('user_id', $user->id)->pluck('id');
            $cpuAvg   = 0;
            $netTxMbps = 0.0;
            if ($userServiceIds->isNotEmpty()) {
                // Latest metric per service_id
                $latestMetrics = ServiceMetric::whereIn('service_id', $userServiceIds)
                    ->select('service_id', DB::raw('MAX(sampled_at) as latest_at'))
                    ->groupBy('service_id')
                    ->get();

                $cpuValues  = [];
                $netTxTotal = 0.0;

                foreach ($latestMetrics as $lm) {
                    $metric = ServiceMetric::where('service_id', $lm->service_id)
                        ->where('sampled_at', $lm->latest_at)
                        ->first();
                    if ($metric) {
                        $cpuValues[] = $metric->cpu_percent ?? 0;
                        // Approximate TX rate: divide cumulative bytes by sample interval (5 min) to get Mbps
                        $netTxTotal += ($metric->network_tx_bytes ?? 0) / 300 / 125000;
                    }
                }
                $cpuAvg    = count($cpuValues) > 0 ? round(array_sum($cpuValues) / count($cpuValues)) : 0;
                $netTxMbps = round($netTxTotal, 1);
            }

            // --- 9. Alertas por servicio (servicios que no están activos o próximos a vencer) ---
            $alerts = [];
            $attentionServices = Service::where('user_id', $user->id)
                ->with('plan')
                ->whereNotIn('status', ['active'])
                ->limit(5)
                ->get();

            foreach ($attentionServices as $svc) {
                $issue = match ($svc->status) {
                    'pending'     => 'Aprovisionando',
                    'suspended'   => 'Suspendido',
                    'maintenance' => 'En mantenimiento',
                    'failed'      => 'Error de servicio',
                    default       => ucfirst($svc->status),
                };
                $alerts[] = [
                    'service_uuid' => $svc->uuid,
                    'service_name' => $svc->name ?: ($svc->plan->name ?? 'Servicio'),
                    'issue'        => $issue,
                    'tone'         => in_array($svc->status, ['failed', 'suspended']) ? 'critical' : 'warning',
                ];
            }

            // Servicios que vencen pronto (< 7 días)
            $expiringSoon = Service::where('user_id', $user->id)
                ->whereIn('status', ['active'])
                ->whereNotNull('next_due_date')
                ->where('next_due_date', '<=', now()->addDays(7))
                ->with('plan')
                ->limit(3)
                ->get();

            foreach ($expiringSoon as $svc) {
                $daysLeft = now()->diffInDays($svc->next_due_date, false);
                $already  = in_array($svc->uuid, array_column($alerts, 'service_uuid'));
                if (!$already && $daysLeft >= 0) {
                    $alerts[] = [
                        'service_uuid' => $svc->uuid,
                        'service_name' => $svc->name ?: ($svc->plan->name ?? 'Servicio'),
                        'issue'        => "Vence en {$daysLeft} día" . ($daysLeft !== 1 ? 's' : ''),
                        'tone'         => 'warning',
                    ];
                }
            }

            // Servicios con CPU alta (> 80%) desde ServiceMetric reciente
            $highCpuMetrics = ServiceMetric::whereIn('service_id', $userServiceIds)
                ->where('cpu_percent', '>', 80)
                ->where('sampled_at', '>=', now()->subMinutes(60))
                ->with('service.plan')
                ->orderByDesc('cpu_percent')
                ->limit(3)
                ->get();

            foreach ($highCpuMetrics as $m) {
                $svc = $m->service;
                if (!$svc) continue;
                $already = in_array($svc->uuid, array_column($alerts, 'service_uuid'));
                if (!$already) {
                    $cpu = round($m->cpu_percent);
                    // How long has it been high? (within last hour)
                    $sinceMinutes = now()->diffInMinutes($m->sampled_at);
                    $alerts[] = [
                        'service_uuid' => $svc->uuid,
                        'service_name' => $svc->name ?: ($svc->plan->name ?? 'Servicio'),
                        'issue'        => "CPU > {$cpu}% por {$sinceMinutes} min",
                        'tone'         => 'warning',
                    ];
                }
            }

            // --- 10. Próxima facturación ---
            $nextBillingService = Service::where('user_id', $user->id)
                ->whereIn('status', ['active'])
                ->whereNotNull('next_due_date')
                ->orderBy('next_due_date')
                ->with('plan')
                ->first();

            $nextBilling = null;
            if ($nextBillingService) {
                $nextBilling = [
                    'date'         => $nextBillingService->next_due_date?->toIso8601String(),
                    'date_label'   => $nextBillingService->next_due_date?->isoFormat('D MMM'),
                    'amount'       => (float) $nextBillingService->price,
                    'service_name' => $nextBillingService->name ?: ($nextBillingService->plan->name ?? 'Servicio'),
                ];
            }

            // --- 11. Datos para Gráficos ---

            // Historial de gasto de los últimos 12 meses
            $billingHistory = Service::where('user_id', $user->id)
                ->select(DB::raw('SUM(price) as total'), DB::raw("DATE_FORMAT(created_at, '%Y-%m') as month"))
                ->where('created_at', '>=', Carbon::now()->subMonths(11)->startOfMonth())
                ->groupBy('month')->orderBy('month', 'asc')->pluck('total', 'month');

            $billingChartData = [];
            for ($i = 11; $i >= 0; $i--) {
                $month = Carbon::now()->subMonths($i)->format('Y-m');
                $billingChartData[] = (float) ($billingHistory[$month] ?? 0);
            }

            // Distribución de servicios con traducciones, porcentajes y colores
            $serviceDistributionChartData = $servicesStats->map(function ($count, $status) use ($totalServices) {
                return [
                    'name'       => self::STATUS_TRANSLATIONS[$status] ?? ucfirst($status),
                    'value'      => $count,
                    'percentage' => $totalServices > 0 ? round(($count / $totalServices) * 100) : 0,
                    'color'      => self::CHART_COLORS[$status] ?? self::CHART_COLORS['default'],
                ];
            })->values()->all();

            // --- RESPUESTA FINAL Y COMPLETA ---
            return response()->json([
                'success' => true,
                'data' => [
                    'services' => [
                        'total'       => $totalServices,
                        'active'      => $activeServices,
                        'maintenance' => $maintenanceServices,
                        'suspended'   => $suspendedServices,
                        'trend'       => $serviceTrend > 0 ? $serviceTrend : null,
                    ],
                    'open_tickets' => $openTickets,
                    'domains' => [
                        'total'   => $totalDomains,
                        'active'  => $activeDomains,
                        'pending' => $pendingDomains,
                        'trend'   => null,
                    ],
                    'billing' => [
                        'monthly_spend' => number_format($monthlySpend, 2),
                        'currency'      => 'MXN',
                        'cycle'         => 'Mensual',
                        'trend'         => $billingTrend !== 0 ? $billingTrend : null,
                    ],
                    'performance' => [
                        'uptime'         => $performanceUptime,
                        'uptime_history' => $uptimeHistory,
                    ],
                    'fleet' => [
                        'types'        => $fleetTypes,
                        'cpu_avg'      => $cpuAvg,
                        'net_tx_mbps'  => $netTxMbps,
                    ],
                    'alerts'       => array_values($alerts),
                    'next_billing' => $nextBilling,
                    'system_status' => $systemStatus,
                    'charts' => [
                        'billing_history'      => $billingChartData,
                        'service_distribution' => array_values(array_filter($serviceDistributionChartData)),
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            // Logueo de errores profesional para depuración
            \Log::error('Dashboard Stats Error: ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine());
            return response()->json(['success' => false, 'error' => 'Failed to fetch dashboard stats'], 500);
        }
    }

    /**
     * Get user's services with details
     */
    public function getServices(Request $request)
    {
        try {
            $user = Auth::user();

            if (!$user) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            // Eager-load del plan y la categoría para evitar N+1.
            $servicesList = Service::where('user_id', $user->id)
                ->with(['plan.category'])
                ->latest()
                ->limit(8)
                ->get();

            $serviceIds = $servicesList->pluck('id');

            // ── ONE query: pull all recent metrics for these services ─────────
            // Covers last 24 h (288 samples @5-min interval × 8 services = ~2 304 rows max).
            // We derive three things from this single collection:
            //   1. Latest snapshot per service  → cpu, ram, state
            //   2. Uptime %                     → running_samples / total_samples (24 h)
            //   3. Sparkline                    → last 15 cpu_percent values (chronological)
            $recentMetrics = $serviceIds->isNotEmpty()
                ? ServiceMetric::whereIn('service_id', $serviceIds)
                    ->where('sampled_at', '>=', now()->subHours(24))
                    ->select('service_id', 'cpu_percent', 'memory_bytes', 'memory_limit_bytes',
                             'disk_bytes', 'disk_limit_bytes', 'network_rx_bytes', 'network_tx_bytes',
                             'state', 'sampled_at')
                    ->orderBy('sampled_at')   // asc so last() gives newest
                    ->get()
                    ->groupBy('service_id')
                : collect();

            // Latest snapshot per service (last element after asc sort)
            $latestMetrics = $recentMetrics->map(fn($rows) => $rows->last());

            // Uptime % per service: fraction of 24-h samples where state = 'running'
            $uptimePctPerService = $recentMetrics->map(function ($rows) {
                $total   = $rows->count();
                if ($total === 0) return null;
                $running = $rows->where('state', 'running')->count();
                return round(($running / $total) * 100, 2);
            });

            // Sparkline: last 15 cpu_percent values (already ordered asc → just take last 15)
            $allSparklines = $recentMetrics->map(
                fn($rows) => $rows->slice(-15)
                    ->pluck('cpu_percent')
                    ->map(fn($v) => round((float) $v))
                    ->values()
                    ->toArray()
            );

            $services = $servicesList->map(function ($service) use ($latestMetrics, $allSparklines, $uptimePctPerService) {
                    $plan      = $service->plan;
                    $category  = $plan?->category;
                    $conn      = is_array($service->connection_details) ? $service->connection_details : [];
                    $slug      = $category?->slug ?? '';

                    // Normalise category slug → frontend type key
                    $typeKey = match (true) {
                        in_array($slug, ['game-servers', 'gameserver', 'game_server']) => 'game_server',
                        in_array($slug, ['hosting', 'shared-hosting', 'web-hosting', 'shared_hosting']) => 'hosting',
                        in_array($slug, ['vps', 'vps-hosting'])   => 'vps',
                        in_array($slug, ['dedicated', 'bare-metal']) => 'dedicated',
                        in_array($slug, ['database', 'databases']) => 'database',
                        in_array($slug, ['domain', 'domains'])     => 'domain',
                        default                                    => 'hosting',
                    };

                    // El servicio puede traer métricas live en `service->metrics` si el job de polling
                    // las dejó cacheadas, o en `connection_details->metrics` para hosting.
                    $metricsRaw = $service->metrics
                        ?? data_get($conn, 'metrics')
                        ?? data_get($conn, 'live')
                        ?? null;

                    $metrics = null;
                    if (is_array($metricsRaw) || is_object($metricsRaw)) {
                        $m = (array) $metricsRaw;
                        $metrics = [
                            'cpu'        => isset($m['cpu_absolute']) ? round((float)$m['cpu_absolute']) : (isset($m['cpu']) ? round((float)$m['cpu']) : null),
                            'ram'        => isset($m['ram']) ? round((float)$m['ram']) : (isset($m['memory_pct']) ? round((float)$m['memory_pct']) : null),
                            'ram_human'  => $m['ram_human']    ?? null,
                            'players'    => $m['players']      ?? null,
                            'visits'     => $m['visits']       ?? ($m['visits_today'] ?? null),
                            'uptime_pct' => $m['uptime_pct']   ?? null,
                        ];
                    }

                    // Computed uptime from 24-h ServiceMetric snapshots (no schema change needed)
                    $computedUptime = $uptimePctPerService->get($service->id); // float|null

                    // Fallback: pull last cached metric from ServiceMetric table (pre-fetched)
                    if (!$metrics || ($metrics['cpu'] === null && $metrics['ram'] === null)) {
                        $lastMetric = $latestMetrics->get($service->id);
                        if ($lastMetric) {
                            $ramSpec   = data_get($plan?->specifications, 'ram')
                                ?? data_get($plan?->specifications, 'memory')
                                ?? null;
                            $ramMb     = $this->parseRamMb($ramSpec);
                            $memBytes  = (int) ($lastMetric->memory_bytes ?? 0);
                            $memLimit  = (int) ($lastMetric->memory_limit_bytes ?? 0);
                            // Prefer limit bytes for accurate %; fall back to plan spec
                            if ($memLimit > 0) {
                                $ramPct = round(($memBytes / $memLimit) * 100);
                            } elseif ($ramMb > 0 && $memBytes > 0) {
                                $ramPct = round(($memBytes / 1024 / 1024 / $ramMb) * 100);
                            } else {
                                $ramPct = null;
                            }
                            $metrics = array_merge($metrics ?? [], [
                                'cpu'        => $lastMetric->cpu_percent !== null ? round($lastMetric->cpu_percent) : null,
                                'ram'        => $ramPct,
                                'ram_human'  => $memBytes > 0
                                    ? round($memBytes / 1024 / 1024) . ' MB'
                                    : null,
                                'players'    => $metrics['players'] ?? null,
                                'visits'     => $metrics['visits']  ?? null,
                                'uptime_pct' => $computedUptime,   // ← real value, never null when data exists
                            ]);
                        }
                    } else {
                        // Even if REST-cache metrics exist, override uptime with computed value when available
                        if ($computedUptime !== null) {
                            $metrics['uptime_pct'] = $computedUptime;
                        }
                    }

                    // Sparkline: 15 CPU samples (oldest → newest) — pre-fetched
                    $sparkline = $allSparklines->get($service->id, []);

                    $isGameServer = $service->isGameServer();
                    $ramSpec      = data_get($plan?->specifications, 'ram')
                        ?? data_get($plan?->specifications, 'memory')
                        ?? data_get($plan?->specifications, 'ram_gb');

                    // Max players: from connection_details or plan specifications
                    $maxPlayers = data_get($conn, 'max_players')
                        ?? data_get($plan?->specifications, 'max_players')
                        ?? data_get($plan?->specifications, 'players')
                        ?? $service->max_players
                        ?? null;

                    // Next billing date in ISO format for frontend formatting
                    $nextBillingDate = $service->next_due_date?->toDateString() ?? null;

                    return [
                        'uuid'              => $service->uuid,
                        'category_slug'     => $slug,
                        'name'              => $service->name ?: ($plan->name ?? 'Servicio'),
                        'plan_name'         => $plan->name ?? null,
                        'software'          => data_get($conn, 'software') ?? data_get($plan?->specifications, 'software'),
                        'type'              => $typeKey,
                        'status'            => $service->status,
                        'is_game_server'    => $isGameServer,
                        'ram_spec'          => $ramSpec ? (string) $ramSpec : null,
                        'plan'              => $plan ? [
                            'name'          => $plan->name,
                            'price'         => (float) $service->price,
                            'billing_cycle' => $service->billing_cycle,
                        ] : null,
                        'next_billing_date' => $nextBillingDate,
                        'created_at'        => $service->created_at->isoFormat('D MMM, YYYY'),
                        'specs'             => $plan?->specifications,
                        'domain'            => data_get($conn, 'display') ?? data_get($conn, 'fqdn') ?? $service->name,
                        'ip'                => data_get($conn, 'server_ip') ?? data_get($conn, 'ip_address') ?? null,
                        'max_players'       => $maxPlayers ? (int) $maxPlayers : null,
                        'metrics'           => $metrics,
                        'sparkline'         => $sparkline,
                        // Métricas runtime del proveedor (hosting: uptime/latencia reales del health check).
                        'live_status'       => $service->live_status,
                        'live_metrics'      => $service->live_metrics,
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => $services
            ]);
        } catch (\Exception $e) {
            // Loguear el error para depuración es una buena práctica.
            \Log::error('Error fetching services: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'error' => 'Failed to fetch services',
                'message' => 'An unexpected error occurred.' // No exponer el mensaje de error real al cliente
            ], 500);
        }
    }

    /**
     * Parse a RAM spec string like "4 GB", "512 MB", "2GB" into MB.
     */
    private function parseRamMb(mixed $spec): int
    {
        if (!$spec) return 0;
        $s   = strtolower(trim((string) $spec));
        $num = (float) preg_replace('/[^0-9.]/', '', $s);
        if ($num <= 0) return 0;
        if (str_contains($s, 'tb')) return (int) ($num * 1024 * 1024);
        if (str_contains($s, 'gb')) return (int) ($num * 1024);
        return (int) $num; // assume MB
    }

    /**
     * Get recent activity for the user
     */
    public function getActivity(Request $request)
    {
        try {
            $user = Auth::user();
            if (!$user) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            // Validación básica de filtros
            $validated = $request->validate([
                'per_page' => 'sometimes|integer|min:1|max:100',
                'type'     => 'sometimes', // string "a,b,c" o array
                'q'        => 'sometimes|string|max:200',
                'from'     => 'sometimes|date',
                'to'       => 'sometimes|date',
            ]);

            $perPage = (int)($validated['per_page'] ?? 10);
            $types   = $request->input('type');
            if (is_string($types)) {
                $types = array_filter(array_map('trim', explode(',', $types)));
            } elseif (!is_array($types)) {
                $types = null;
            }

            $query = ActivityLog::query()
                ->where('user_id', $user->id);

            if ($types && count($types)) {
                $types = array_map(fn($t) => strtolower($t), $types);
                $query->whereIn('type', $types);
            }

            if ($search = $request->input('q')) {
                $query->where(function ($q) use ($search) {
                    $q->where('action', 'like', "%{$search}%")
                      ->orWhere('service', 'like', "%{$search}%")
                      ->orWhere('type', 'like', "%{$search}%");
                });
            }

            if ($from = $request->input('from')) {
                $query->where('occurred_at', '>=', Carbon::parse($from));
            }
            if ($to = $request->input('to')) {
                $query->where('occurred_at', '<=', Carbon::parse($to));
            }

            $paginator = $query
                ->orderByDesc('occurred_at')
                ->orderByDesc('id')
                ->paginate($perPage);

            $items = collect($paginator->items())->map(function (ActivityLog $a) {
                $when = $a->occurred_at ?: $a->created_at;

                return [
                    'id'          => $a->id,
                    'type'        => $a->type,
                    'action'      => $a->action,
                    'description' => $a->action,
                    'service'     => $a->service,
                    'meta'        => $a->meta,
                    'created_at'  => $when?->toIso8601String(),
                ];
            });

            return response()->json([
                'success' => true,
                'data'    => $items,
                'meta'    => [
                    'current_page'  => $paginator->currentPage(),
                    'per_page'      => $paginator->perPage(),
                    'total'         => $paginator->total(),
                    'last_page'     => $paginator->lastPage(),
                    'next_page_url' => $paginator->nextPageUrl(),
                    'prev_page_url' => $paginator->previousPageUrl(),
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener la actividad.',
            ], 500);
        }
    }

}
