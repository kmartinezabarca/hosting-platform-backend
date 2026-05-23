<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;

use Illuminate\Http\Request;
use App\Models\ActivityLog;
use App\Models\Service;
use App\Models\SystemStatus;
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

            // --- 6. Datos para Gráficos ---

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
                        'total' => $totalServices,
                        'active' => $activeServices,
                        'maintenance' => $maintenanceServices,
                        'suspended' => $suspendedServices,
                        'trend' => $serviceTrend > 0 ? $serviceTrend : null,
                    ],
                    'domains' => [
                        'total' => $totalDomains,
                        'active' => $activeDomains,
                        'pending' => $pendingDomains,
                        'trend' => null,
                    ],
                    'billing' => [
                        'monthly_spend' => number_format($monthlySpend, 2),
                        'currency' => 'MXN',
                        'cycle' => 'Mensual',
                        'trend' => $billingTrend !== 0 ? $billingTrend : null,
                    ],
                    'performance' => [
                        'uptime' => $performanceUptime,
                        'uptime_history' => $uptimeHistory,
                    ],
                    'system_status' => $systemStatus,
                    'charts' => [
                        'billing_history' => $billingChartData,
                        'service_distribution' => array_values(array_filter($serviceDistributionChartData)),
                    ]
                ]
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
            $services = Service::where('user_id', $user->id)
                ->with(['plan.category'])
                ->latest()
                ->limit(4)
                ->get()
                ->map(function ($service) {
                    $plan      = $service->plan;
                    $category  = $plan?->category;
                    $conn      = is_array($service->connection_details) ? $service->connection_details : [];

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

                    return [
                        'uuid'          => $service->uuid,
                        'category_slug' => $category?->slug,
                        'name'          => $service->name ?: ($plan->name ?? 'Servicio'),
                        'plan_name'     => $plan->name ?? null,
                        'software'      => data_get($conn, 'software') ?? data_get($plan?->specifications, 'software'),
                        'type'          => $category?->name ?? 'Servicio',
                        'status'        => $service->status,
                        'plan'          => $plan->description ?? null,
                        'price'         => '$' . number_format($service->price, 2) . '/' . $service->billing_cycle,
                        'next_billing'  => optional($service->next_due_date)->isoFormat('D MMM, YYYY'),
                        'created_at'    => $service->created_at->isoFormat('D MMM, YYYY'),
                        'specs'         => $plan?->specifications,
                        'domain'        => data_get($conn, 'display') ?? data_get($conn, 'fqdn') ?? $service->name,
                        'ip'            => data_get($conn, 'server_ip') ?? data_get($conn, 'ip_address') ?? null,
                        'metrics'       => $metrics,
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
                    'id'      => $a->id,
                    'action'  => $a->action,
                    'service' => $a->service,
                    'time'    => $when?->diffForHumans(),  // "2 hours ago"
                    'type'    => $a->type,
                    'meta'    => $a->meta,
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
