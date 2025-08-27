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
            $plans = ServicePlan::with(['category', 'features', 'pricing.billingCycle'])
                ->active()
                ->orderBy('sort_order')
                ->get()
                ->map(function ($plan) {
                    $planData = [
                        'id' => $plan->id,
                        'uuid' => $plan->uuid,
                        'slug' => $plan->slug,
                        'name' => $plan->name,
                        'description' => $plan->description,
                        'base_price' => $plan->base_price,
                        'setup_fee' => $plan->setup_fee,
                        'is_popular' => $plan->is_popular,
                        'category' => $plan->category ? $plan->category->name : null,
                        'category_slug' => $plan->category ? $plan->category->slug : null,
                        'specifications' => $plan->specifications,
                        'features' => $plan->features->pluck('name')->toArray(),
                        'pricing' => $plan->pricing->map(function ($pricing) {
                            return [
                                'billing_cycle' => $pricing->billingCycle->name,
                                'billing_cycle_slug' => $pricing->billingCycle->slug,
                                'price' => $pricing->price,
                                'discount_percentage' => $pricing->discount_percentage,
                            ];
                        })->toArray()
                    ];
                    
                    return $planData;
                });

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

            $services = Service::with(['plan.category'])
                ->where('user_id', $user->id)
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(function ($service) {
                    return [
                        'id' => $service->id,
                        'uuid' => $service->uuid,
                        'plan_name' => $service->plan ? $service->plan->name : 'Plan no disponible',
                        'plan_slug' => $service->plan ? $service->plan->slug : null,
                        'category' => $service->plan && $service->plan->category ? $service->plan->category->name : null,
                        'status' => $service->status,
                        'name' => $service->name,
                        'created_at' => $service->created_at->toISOString(),
                        'next_due_date' => $service->next_due_date,
                        'price' => $service->price,
                        'setup_fee' => $service->setup_fee,
                        'billing_cycle' => $service->billing_cycle,
                        'connection_details' => $service->connection_details,
                        'configuration' => $service->configuration,
                        'notes' => $service->notes
                    ];
                });

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

            $service = Service::with(['plan.category', 'plan.features', 'user'])
                ->where('id', $serviceId)
                ->where('user_id', $user->id)
                ->first();

            if (!$service) {
                return response()->json([
                    'success' => false,
                    'message' => 'Servicio no encontrado'
                ], 404);
            }

            $serviceData = [
                'id' => $service->id,
                'uuid' => $service->uuid,
                'name' => $service->name,
                'plan_name' => $service->plan ? $service->plan->name : 'Plan no disponible',
                'plan_slug' => $service->plan ? $service->plan->slug : null,
                'category' => $service->plan && $service->plan->category ? $service->plan->category->name : null,
                'status' => $service->status,
                'created_at' => $service->created_at->toISOString(),
                'next_due_date' => $service->next_due_date,
                'price' => $service->price,
                'setup_fee' => $service->setup_fee,
                'billing_cycle' => $service->billing_cycle,
                'connection_details' => $service->connection_details,
                'configuration' => $service->configuration,
                'specifications' => $service->plan ? $service->plan->specifications : null,
                'features' => $service->plan ? $service->plan->features->pluck('name')->toArray() : [],
                'external_id' => $service->external_id,
                'notes' => $service->notes,
                'terminated_at' => $service->terminated_at
            ];

            return response()->json([
                'success' => true,
                'data' => $serviceData
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

            $service = Service::where('id', $serviceId)
                ->where('user_id', $user->id)
                ->first();

            if (!$service) {
                return response()->json([
                    'success' => false,
                    'message' => 'Servicio no encontrado'
                ], 404);
            }

            // Actualizar la configuración del servicio
            $service->configuration = $request->configuration;
            $service->save();

            return response()->json([
                'success' => true,
                'data' => [
                    'service_id' => $service->id,
                    'uuid' => $service->uuid,
                    'configuration' => $service->configuration,
                    'updated_at' => $service->updated_at
                ],
                'message' => 'Configuración del servicio actualizada exitosamente'
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

            $service = Service::where('id', $serviceId)
                ->where('user_id', $user->id)
                ->first();

            if (!$service) {
                return response()->json([
                    'success' => false,
                    'message' => 'Servicio no encontrado'
                ], 404);
            }

            // Actualizar el estado del servicio a terminado
            $service->status = 'terminated';
            $service->terminated_at = now();
            $service->notes = ($service->notes ? $service->notes . "\n" : '') . 
                             "Cancelado el " . now()->format('Y-m-d H:i:s') . ". Razón: " . $request->reason;
            $service->save();

            return response()->json([
                'success' => true,
                'data' => [
                    'service_id' => $service->id,
                    'uuid' => $service->uuid,
                    'status' => $service->status,
                    'cancellation_reason' => $request->reason,
                    'cancelled_at' => $service->terminated_at
                ],
                'message' => 'Servicio cancelado exitosamente'
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

            $service = Service::where('id', $serviceId)
                ->where('user_id', $user->id)
                ->first();

            if (!$service) {
                return response()->json([
                    'success' => false,
                    'message' => 'Servicio no encontrado'
                ], 404);
            }

            // Actualizar el estado del servicio a suspendido
            $service->status = 'suspended';
            $service->notes = ($service->notes ? $service->notes . "\n" : '') . 
                             "Suspendido el " . now()->format('Y-m-d H:i:s') . ". Razón: " . $request->reason;
            $service->save();

            return response()->json([
                'success' => true,
                'data' => [
                    'service_id' => $service->id,
                    'uuid' => $service->uuid,
                    'status' => $service->status,
                    'suspension_reason' => $request->reason,
                    'suspended_at' => now()
                ],
                'message' => 'Servicio suspendido exitosamente'
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

            $service = Service::where('id', $serviceId)
                ->where('user_id', $user->id)
                ->first();

            if (!$service) {
                return response()->json([
                    'success' => false,
                    'message' => 'Servicio no encontrado'
                ], 404);
            }

            // Actualizar el estado del servicio a activo
            $service->status = 'active';
            $service->notes = ($service->notes ? $service->notes . "\n" : '') . 
                             "Reactivado el " . now()->format('Y-m-d H:i:s');
            $service->save();

            return response()->json([
                'success' => true,
                'data' => [
                    'service_id' => $service->id,
                    'uuid' => $service->uuid,
                    'status' => $service->status,
                    'reactivated_at' => now()
                ],
                'message' => 'Servicio reactivado exitosamente'
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

            $service = Service::where('id', $serviceId)
                ->where('user_id', $user->id)
                ->first();

            if (!$service) {
                return response()->json([
                    'success' => false,
                    'message' => 'Servicio no encontrado'
                ], 404);
            }

            // TODO: Integrar con sistema de monitoreo real (Prometheus, Grafana, etc.)
            // Por ahora retornamos datos de ejemplo basados en el servicio real
            $usage = [
                'service_id' => $service->id,
                'service_uuid' => $service->uuid,
                'service_name' => $service->name,
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
                'uptime' => 99.95,
                'last_updated' => now()
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

            $service = Service::where('id', $serviceId)
                ->where('user_id', $user->id)
                ->first();

            if (!$service) {
                return response()->json([
                    'success' => false,
                    'message' => 'Servicio no encontrado'
                ], 404);
            }

            // TODO: Integrar con sistema de backup real (Proxmox, cPanel, etc.)
            // Por ahora retornamos datos de ejemplo basados en el servicio real
            $backups = [
                [
                    'id' => 1,
                    'service_id' => $service->id,
                    'service_uuid' => $service->uuid,
                    'name' => 'Daily Backup - ' . now()->subDay()->format('Y-m-d'),
                    'type' => 'automatic',
                    'size' => '2.5 GB',
                    'created_at' => now()->subDay()->setTime(2, 0)->toISOString(),
                    'status' => 'completed'
                ],
                [
                    'id' => 2,
                    'service_id' => $service->id,
                    'service_uuid' => $service->uuid,
                    'name' => 'Manual Backup - Pre-Update',
                    'type' => 'manual',
                    'size' => '2.4 GB',
                    'created_at' => now()->subDays(2)->setTime(14, 30)->toISOString(),
                    'status' => 'completed'
                ],
                [
                    'id' => 3,
                    'service_id' => $service->id,
                    'service_uuid' => $service->uuid,
                    'name' => 'Daily Backup - ' . now()->subDays(2)->format('Y-m-d'),
                    'type' => 'automatic',
                    'size' => '2.3 GB',
                    'created_at' => now()->subDays(2)->setTime(2, 0)->toISOString(),
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

            $service = Service::where('id', $serviceId)
                ->where('user_id', $user->id)
                ->first();

            if (!$service) {
                return response()->json([
                    'success' => false,
                    'message' => 'Servicio no encontrado'
                ], 404);
            }

            // TODO: Integrar con sistema de backup real para crear el backup
            // Por ahora simulamos la creación del backup
            $backup = [
                'id' => rand(100, 999),
                'service_id' => $service->id,
                'service_uuid' => $service->uuid,
                'name' => $request->name,
                'type' => 'manual',
                'status' => 'in_progress',
                'created_at' => now()
            ];

            return response()->json([
                'success' => true,
                'data' => $backup,
                'message' => 'Creación de backup iniciada exitosamente'
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

            $service = Service::where('id', $serviceId)
                ->where('user_id', $user->id)
                ->first();

            if (!$service) {
                return response()->json([
                    'success' => false,
                    'message' => 'Servicio no encontrado'
                ], 404);
            }

            // TODO: Integrar con sistema de backup real para restaurar el backup
            // Por ahora simulamos la restauración del backup
            return response()->json([
                'success' => true,
                'data' => [
                    'service_id' => $service->id,
                    'service_uuid' => $service->uuid,
                    'backup_id' => $backupId,
                    'status' => 'restoration_in_progress',
                    'started_at' => now()
                ],
                'message' => 'Restauración de backup iniciada exitosamente'
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

