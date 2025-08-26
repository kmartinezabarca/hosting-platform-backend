<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Service;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /**
     * Get dashboard statistics for the authenticated user
     */
    public function getStats(Request $request)
    {
        try {
            $user = Auth::user();

            if (!$user) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            // Get user's services count by status
            $servicesStats = Service::where('user_id', $user->id)
                ->select('status', DB::raw('count(*) as count'))
                ->groupBy('status')
                ->pluck('count', 'status')
                ->toArray();

            // Calculate total services
            $totalServices = array_sum($servicesStats);
            $activeServices = $servicesStats['active'] ?? 0;
            $maintenanceServices = $servicesStats['maintenance'] ?? 0;
            $suspendedServices = $servicesStats['suspended'] ?? 0;

            // Calculate service trends (compare with last month)
            $lastMonthServices = Service::where('user_id', $user->id)
                ->where('created_at', '>=', now()->subMonth())
                ->count();
            $serviceTrend = $lastMonthServices > 0 ? round(($lastMonthServices / max($totalServices - $lastMonthServices, 1)) * 100) : 0;

            // Get domains count (mock for now - would come from domains table)
            $totalDomains = 0; // No domains table yet
            $activeDomains = 0;
            $pendingDomains = 0;

            // Calculate monthly spending from user's services
            $monthlySpend = Service::where('user_id', $user->id)
                ->where('status', 'active')
                ->sum('price');

            // Calculate billing trend (compare with last month)
            $lastMonthSpend = $monthlySpend; // Would be calculated from invoices in real implementation
            $billingTrend = 0; // No change for now

            // Calculate performance metrics based on active services
            $performanceUptime = null;
            if ($activeServices > 0) {
                // Mock uptime calculation - in real implementation would come from monitoring data
                if ($suspendedServices === 0 && $maintenanceServices === 0) {
                    $performanceUptime = 99.9;
                } else if ($suspendedServices > 0) {
                    $performanceUptime = 95.0;
                } else {
                    $performanceUptime = 98.5;
                }
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'services' => [
                        'total' => $totalServices,
                        'active' => $activeServices,
                        'maintenance' => $maintenanceServices,
                        'suspended' => $suspendedServices,
                        'trend' => $serviceTrend > 0 ? $serviceTrend : null
                    ],
                    'domains' => [
                        'total' => $totalDomains,
                        'active' => $activeDomains,
                        'pending' => $pendingDomains,
                        'trend' => null // No trend data available yet
                    ],
                    'billing' => [
                        'monthly_spend' => $monthlySpend,
                        'currency' => 'USD',
                        'cycle' => 'Mensual',
                        'trend' => $billingTrend !== 0 ? $billingTrend : null
                    ],
                    'performance' => [
                        'uptime' => $performanceUptime
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to fetch dashboard stats',
                'message' => $e->getMessage()
            ], 500);
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

            $services = Service::where('user_id', $user->id)
                ->with(['product'])
                ->get()
                ->map(function ($service) {
                    return [
                        'id' => $service->id,
                        'name' => $service->product->name ?? 'Unknown Service',
                        'type' => $service->product->type ?? 'Unknown',
                        'status' => $service->status,
                        'plan' => $service->product->description ?? 'Standard Plan',
                        'price' => '$' . number_format($service->price, 2) . '/month',
                        'next_billing' => $service->next_billing_date,
                        'created_at' => $service->created_at->format('Y-m-d'),
                        'usage' => $this->generateMockUsage($service->product->type ?? 'hosting'),
                        'specs' => $this->generateMockSpecs($service->product->type ?? 'hosting'),
                        'domain' => $service->domain,
                        'ip' => $service->ip_address
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => $services
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to fetch services',
                'message' => $e->getMessage()
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

            // Mock activity data - in real implementation, this would come from an activity log table
            $activities = [
                [
                    'id' => 1,
                    'action' => 'Service deployed',
                    'service' => 'Web Hosting Pro',
                    'time' => '2 hours ago',
                    'type' => 'deployment',
                    'icon' => 'Zap'
                ],
                [
                    'id' => 2,
                    'action' => 'Payment processed',
                    'service' => 'Monthly billing',
                    'time' => '1 day ago',
                    'type' => 'payment',
                    'icon' => 'credit-card'
                ],
                [
                    'id' => 3,
                    'action' => 'Backup completed',
                    'service' => 'VPS Cloud',
                    'time' => '2 days ago',
                    'type' => 'backup',
                    'icon' => 'shield'
                ],
                [
                    'id' => 4,
                    'action' => 'Domain renewed',
                    'service' => 'roketech.com',
                    'time' => '3 days ago',
                    'type' => 'domain',
                    'icon' => 'globe'
                ]
            ];

            return response()->json([
                'success' => true,
                'data' => $activities
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to fetch activity',
                'message' => $e->getMessage()
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
