<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ActivityLog;
use App\Models\User;
use App\Models\Service;
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

            // --- 5. Datos para Gráficos ---

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
                    ],
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

            // ¡CORREGIDO! Hacemos "eager loading" de la relación 'plan' y su 'category'.
            $services = Service::where('user_id', $user->id)
                ->with(['plan.category'])
                ->latest()
                ->limit(3)
                ->get()
                ->map(function ($service) {
                    $plan = $service->plan;

                    return [
                        'uuid' => $service->uuid,
                        'category_slug' => $plan->category ? $plan->category->slug : null,
                        'name' => $plan->name ?? 'Servicio Desconocido',
                        'type' => $plan->category->name ?? 'Desconocido',
                        'status' => $service->status,
                        'plan' => $plan->description ?? 'Plan Estándar',
                        'price' => '$' . number_format($service->price, 2) . '/' . $service->billing_cycle,
                        'next_billing' => $service->next_due_date->format('d M, Y'),
                        'created_at' => $service->created_at->format('d M, Y'),
                        'usage' => $this->generateMockUsage($plan->category->slug ?? 'hosting'),
                        'specs' => $plan->specifications ?? $this->generateMockSpecs($plan->category->slug ?? 'hosting'),
                        'domain' => $service->name,
                        'ip' => $service->connection_details['ip_address'] ?? null
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
                'error'   => 'Failed to fetch activity',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Generate mock usage data based on service type
     */
    private function generateMockUsage($type)
    {
        switch (strtolower($type)) {
            case 'hosting':
            case 'shared hosting':
                return [
                    'disk' => rand(30, 80),
                    'bandwidth' => rand(20, 60)
                ];
            case 'game server':
            case 'minecraft':
                return [
                    'ram' => rand(30, 70),
                    'cpu' => rand(20, 50),
                    'players' => rand(5, 18)
                ];
            case 'vps':
            case 'virtual server':
                return [
                    'ram' => rand(40, 85),
                    'cpu' => rand(30, 75),
                    'disk' => rand(25, 65)
                ];
            default:
                return [
                    'usage' => rand(20, 80)
                ];
        }
    }

    /**
     * Generate mock specs based on service type
     */
    private function generateMockSpecs($type)
    {
        switch (strtolower($type)) {
            case 'hosting':
            case 'shared hosting':
                return [
                    'disk' => '50 GB SSD',
                    'bandwidth' => 'Unlimited',
                    'domains' => '10 Domains',
                    'email' => 'Unlimited Email'
                ];
            case 'game server':
            case 'minecraft':
                return [
                    'ram' => '4 GB RAM',
                    'cpu' => '2 vCPU',
                    'storage' => '25 GB SSD',
                    'players' => '20 Max Players'
                ];
            case 'vps':
            case 'virtual server':
                return [
                    'ram' => '8 GB RAM',
                    'cpu' => '4 vCPU',
                    'storage' => '100 GB SSD',
                    'bandwidth' => '5 TB'
                ];
            default:
                return [
                    'plan' => 'Standard Plan'
                ];
        }
    }
}
