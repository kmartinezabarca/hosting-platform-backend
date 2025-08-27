<?php

namespace App\Http\Controllers;

use Stripe\Stripe;
use App\Models\Service;
use App\Models\ServicePlan;
use App\Models\ServiceInvoice;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ServiceController extends Controller
{
    public function __construct()
    {
        // Set Stripe API key
        Stripe::setApiKey(config('services.stripe.secret'));
    }

    /**
     * Get available service plans
     */
    public function getServicePlans(): JsonResponse
    {
        try {
            // Mock service plans data - replace with actual database query
            $plans = [
                [
                    'id' => 1,
                    'name' => 'Basic VPS',
                    'description' => 'Perfect for small projects and development',
                    'price' => 9.99,
                    'currency' => 'USD',
                    'billing_cycle' => 'monthly',
                    'features' => [
                        '1 vCPU',
                        '1GB RAM',
                        '25GB SSD Storage',
                        '1TB Bandwidth',
                        '24/7 Support'
                    ],
                    'category' => 'vps'
                ],
                [
                    'id' => 2,
                    'name' => 'Standard VPS',
                    'description' => 'Great for growing applications',
                    'price' => 19.99,
                    'currency' => 'USD',
                    'billing_cycle' => 'monthly',
                    'features' => [
                        '2 vCPU',
                        '4GB RAM',
                        '80GB SSD Storage',
                        '3TB Bandwidth',
                        '24/7 Support'
                    ],
                    'category' => 'vps'
                ],
                [
                    'id' => 3,
                    'name' => 'Premium VPS',
                    'description' => 'High performance for demanding applications',
                    'price' => 39.99,
                    'currency' => 'USD',
                    'billing_cycle' => 'monthly',
                    'features' => [
                        '4 vCPU',
                        '8GB RAM',
                        '160GB SSD Storage',
                        '5TB Bandwidth',
                        'Priority Support'
                    ],
                    'category' => 'vps'
                ],
                [
                    'id' => 4,
                    'name' => 'Basic Web Hosting',
                    'description' => 'Shared hosting for simple websites',
                    'price' => 4.99,
                    'currency' => 'USD',
                    'billing_cycle' => 'monthly',
                    'features' => [
                        '10GB Storage',
                        'Unlimited Bandwidth',
                        '5 Email Accounts',
                        'cPanel Access',
                        'SSL Certificate'
                    ],
                    'category' => 'hosting'
                ]
            ];

            return response()->json([
                'success' => true,
                'data' => $plans
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching service plans: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error fetching service plans'
            ], 500);
        }
    }

    /**
     * Contract a new service
     */
    public function contractService(Request $request): JsonResponse
    {
        try {
            $validatedData = $request->validate([
                'plan_id'         => ['required', 'string', Rule::exists('service_plans', 'slug')],
                'billing_cycle'   => ['required', Rule::in(['monthly', 'quarterly', 'annually'])],
                'domain'          => ['nullable', 'string', 'max:255'],
                'service_name'    => ['required', 'string', 'max:255'],
                'payment_intent_id' => ['required', 'string'],
                'additional_options' => ['nullable', 'array'],
                'invoice'         => ['sometimes', 'array'],
                'invoice.rfc'     => ['required_with:invoice', 'string', 'max:13'],
                'invoice.name'    => ['required_with:invoice', 'string', 'max:255'],
                'invoice.zip'     => ['required_with:invoice', 'string', 'size:5'],
                'invoice.regimen' => ['required_with:invoice', 'string', 'max:4'],
                'invoice.uso_cfdi' => ['required_with:invoice', 'string', 'max:10'],
                'invoice.constancia' => ['nullable', 'string'], // cadena base64 o null
            ]);

            $plan = ServicePlan::where('slug', $validatedData['plan_id'])->firstOrFail();
            $user = Auth::user();

            $nextBillingDate = now()->add(match ($validatedData['billing_cycle']) {
                'monthly'   => 1,
                'quarterly' => 3,
                'annually'  => 12,
            }, 'month');

            DB::beginTransaction();

            $service = Service::create([
                'plan_id'        => $plan->id,
                'user_id'           => $user->id,
                'price'             => $plan->base_price,
                'name'              => $validatedData['service_name'],
                'status'            => 'active',
                'billing_cycle'     => $validatedData['billing_cycle'],
                'domain'            => $validatedData['domain'] ?? null,
                'payment_intent_id' => $validatedData['payment_intent_id'],
                'configuration' => $validatedData['additional_options'] ?? null,
                'next_due_date'     => $nextBillingDate,
            ]);

            // Si hay datos de factura, crea el registro asociado
            if (!empty($validatedData['invoice'])) {
                ServiceInvoice::create([
                    'service_id'  => $service->id,
                    'rfc'         => $validatedData['invoice']['rfc'],
                    'name'        => $validatedData['invoice']['name'],
                    'zip'         => $validatedData['invoice']['zip'],
                    'regimen'     => $validatedData['invoice']['regimen'],
                    'uso_cfdi'    => $validatedData['invoice']['uso_cfdi'],
                    'constancia'  => $validatedData['invoice']['constancia'] ?? null,
                ]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'data'    => $service,
                'message' => 'Servicio contratado exitosamente.',
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Datos de entrada inválidos.',
                'errors'  => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Error al contratar el servicio: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json([
                'success' => false,
                'message' => 'Ocurrió un error inesperado al procesar la solicitud.',
            ], 500);
        }
    }

    /**
     * Get user's services
     */
    public function getUserServices(): JsonResponse
    {
        try {
            $user = Auth::user();

            // Mock user services - replace with actual database query
            $services = [
                [
                    'id' => 1001,
                    'plan_name' => 'Standard VPS',
                    'status' => 'active',
                    'domain' => 'example.com',
                    'created_at' => '2024-01-15T10:30:00Z',
                    'next_billing_date' => '2024-02-15T10:30:00Z',
                    'price' => 19.99,
                    'currency' => 'USD',
                    'billing_cycle' => 'monthly'
                ],
                [
                    'id' => 1002,
                    'plan_name' => 'Basic Web Hosting',
                    'status' => 'active',
                    'domain' => 'mysite.com',
                    'created_at' => '2024-01-10T14:20:00Z',
                    'next_billing_date' => '2024-02-10T14:20:00Z',
                    'price' => 4.99,
                    'currency' => 'USD',
                    'billing_cycle' => 'monthly'
                ]
            ];

            return response()->json([
                'success' => true,
                'data' => $services
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching user services: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error fetching services'
            ], 500);
        }
    }

    /**
     * Get service details
     */
    public function getServiceDetails($serviceId): JsonResponse
    {
        try {
            $user = Auth::user();

            // Mock service details - replace with actual database query
            $service = [
                'id' => $serviceId,
                'plan_name' => 'Standard VPS',
                'status' => 'active',
                'domain' => 'example.com',
                'ip_address' => '192.168.1.100',
                'created_at' => '2024-01-15T10:30:00Z',
                'next_billing_date' => '2024-02-15T10:30:00Z',
                'price' => 19.99,
                'currency' => 'USD',
                'billing_cycle' => 'monthly',
                'specifications' => [
                    'cpu' => '2 vCPU',
                    'ram' => '4GB',
                    'storage' => '80GB SSD',
                    'bandwidth' => '3TB'
                ],
                'configuration' => [
                    'os' => 'Ubuntu 22.04',
                    'control_panel' => 'cPanel',
                    'backup_enabled' => true,
                    'monitoring_enabled' => true
                ]
            ];

            return response()->json([
                'success' => true,
                'data' => $service
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching service details: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error fetching service details'
            ], 500);
        }
    }

    /**
     * Update service configuration
     */
    public function updateServiceConfig(Request $request, $serviceId): JsonResponse
    {
        try {
            $request->validate([
                'configuration' => 'required|array'
            ]);

            $user = Auth::user();

            // Mock configuration update - replace with actual database logic
            $updatedConfig = $request->configuration;

            return response()->json([
                'success' => true,
                'data' => [
                    'service_id' => $serviceId,
                    'configuration' => $updatedConfig,
                    'updated_at' => now()
                ],
                'message' => 'Service configuration updated successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error updating service config: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error updating service configuration'
            ], 500);
        }
    }

    /**
     * Cancel service
     */
    public function cancelService(Request $request, $serviceId): JsonResponse
    {
        try {
            $request->validate([
                'reason' => 'required|string|max:500'
            ]);

            $user = Auth::user();

            // Mock service cancellation - replace with actual database logic
            return response()->json([
                'success' => true,
                'data' => [
                    'service_id' => $serviceId,
                    'status' => 'cancelled',
                    'cancellation_reason' => $request->reason,
                    'cancelled_at' => now()
                ],
                'message' => 'Service cancelled successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error cancelling service: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error cancelling service'
            ], 500);
        }
    }

    /**
     * Suspend service
     */
    public function suspendService(Request $request, $serviceId): JsonResponse
    {
        try {
            $request->validate([
                'reason' => 'required|string|max:500'
            ]);

            $user = Auth::user();

            // Mock service suspension - replace with actual database logic
            return response()->json([
                'success' => true,
                'data' => [
                    'service_id' => $serviceId,
                    'status' => 'suspended',
                    'suspension_reason' => $request->reason,
                    'suspended_at' => now()
                ],
                'message' => 'Service suspended successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error suspending service: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error suspending service'
            ], 500);
        }
    }

    /**
     * Reactivate service
     */
    public function reactivateService($serviceId): JsonResponse
    {
        try {
            $user = Auth::user();

            // Mock service reactivation - replace with actual database logic
            return response()->json([
                'success' => true,
                'data' => [
                    'service_id' => $serviceId,
                    'status' => 'active',
                    'reactivated_at' => now()
                ],
                'message' => 'Service reactivated successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error reactivating service: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error reactivating service'
            ], 500);
        }
    }

    /**
     * Get service usage statistics
     */
    public function getServiceUsage($serviceId): JsonResponse
    {
        try {
            $user = Auth::user();

            // Mock usage statistics - replace with actual monitoring data
            $usage = [
                'service_id' => $serviceId,
                'period' => 'last_30_days',
                'cpu_usage' => [
                    'average' => 45.2,
                    'peak' => 89.5,
                    'unit' => 'percentage'
                ],
                'memory_usage' => [
                    'average' => 2.1,
                    'peak' => 3.8,
                    'total' => 4.0,
                    'unit' => 'GB'
                ],
                'disk_usage' => [
                    'used' => 45.2,
                    'total' => 80.0,
                    'unit' => 'GB'
                ],
                'bandwidth_usage' => [
                    'inbound' => 125.5,
                    'outbound' => 89.2,
                    'total_limit' => 3000.0,
                    'unit' => 'GB'
                ],
                'uptime' => 99.95
            ];

            return response()->json([
                'success' => true,
                'data' => $usage
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching service usage: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error fetching service usage'
            ], 500);
        }
    }

    /**
     * Get service backups
     */
    public function getServiceBackups($serviceId): JsonResponse
    {
        try {
            $user = Auth::user();

            // Mock backup data - replace with actual backup system integration
            $backups = [
                [
                    'id' => 1,
                    'name' => 'Daily Backup - 2024-01-26',
                    'type' => 'automatic',
                    'size' => '2.5 GB',
                    'created_at' => '2024-01-26T02:00:00Z',
                    'status' => 'completed'
                ],
                [
                    'id' => 2,
                    'name' => 'Manual Backup - Pre-Update',
                    'type' => 'manual',
                    'size' => '2.4 GB',
                    'created_at' => '2024-01-25T14:30:00Z',
                    'status' => 'completed'
                ],
                [
                    'id' => 3,
                    'name' => 'Daily Backup - 2024-01-25',
                    'type' => 'automatic',
                    'size' => '2.3 GB',
                    'created_at' => '2024-01-25T02:00:00Z',
                    'status' => 'completed'
                ]
            ];

            return response()->json([
                'success' => true,
                'data' => $backups
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching service backups: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error fetching service backups'
            ], 500);
        }
    }

    /**
     * Create service backup
     */
    public function createServiceBackup(Request $request, $serviceId): JsonResponse
    {
        try {
            $request->validate([
                'name' => 'required|string|max:255'
            ]);

            $user = Auth::user();

            // Mock backup creation - replace with actual backup system integration
            $backup = [
                'id' => rand(100, 999),
                'service_id' => $serviceId,
                'name' => $request->name,
                'type' => 'manual',
                'status' => 'in_progress',
                'created_at' => now()
            ];

            return response()->json([
                'success' => true,
                'data' => $backup,
                'message' => 'Backup creation initiated successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error creating service backup: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error creating service backup'
            ], 500);
        }
    }

    /**
     * Restore service backup
     */
    public function restoreServiceBackup($serviceId, $backupId): JsonResponse
    {
        try {
            $user = Auth::user();

            // Mock backup restoration - replace with actual backup system integration
            return response()->json([
                'success' => true,
                'data' => [
                    'service_id' => $serviceId,
                    'backup_id' => $backupId,
                    'status' => 'restoration_in_progress',
                    'started_at' => now()
                ],
                'message' => 'Backup restoration initiated successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error restoring service backup: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error restoring service backup'
            ], 500);
        }
    }
}

