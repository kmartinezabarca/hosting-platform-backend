<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Requests\Client\ContractServiceRequest;
use App\Http\Resources\ServiceResource;
use App\Services\ServiceContractingService;
use App\Exceptions\PaymentRequiresActionException;

use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

use App\Models\Service;
use App\Models\ServicePlan;
use App\Models\Invoice;
use App\Models\ActivityLog;

class ServiceController extends Controller
{
    public function __construct(private readonly ServiceContractingService $contractingService)
    {
    }

    /**
     * Get available service plans
     */
    public function getServicePlans(): JsonResponse
    {
        try {
            $plans = ServicePlan::with(["category", "features", "pricing.billingCycle"])
                ->active()
                ->orderBy("sort_order")
                ->get()
                ->map(function ($plan) {
                    $planData = [
                        "id" => $plan->id,
                        "uuid" => $plan->uuid,
                        "slug" => $plan->slug,
                        "name" => $plan->name,
                        "description" => $plan->description,
                        "base_price" => $plan->base_price,
                        "setup_fee" => $plan->setup_fee,
                        "is_popular" => $plan->is_popular,
                        "category" => $plan->category ? $plan->category->name : null,
                        "category_slug" => $plan->category ? $plan->category->slug : null,
                        "specifications" => $plan->specifications,
                        "features" => $plan->features->pluck("name")->toArray(),
                        "pricing" => $plan->pricing->map(function ($pricing) {
                            return [
                                "billing_cycle" => $pricing->billingCycle->name,
                                "billing_cycle_slug" => $pricing->billingCycle->slug,
                                "price" => $pricing->price,
                                "discount_percentage" => $pricing->discount_percentage,
                            ];
                        })->toArray()
                    ];

                    return $planData;
                });

            return response()->json([
                "success" => true,
                "data" => $plans
            ]);
        } catch (\Exception $e) {
            Log::error("Error fetching service plans: " . $e->getMessage());
            return response()->json([
                "success" => false,
                "message" => "Error fetching service plans"
            ], 500);
        }
    }

    /**
     * Contract a service — validates input, delegates all business logic to ServiceContractingService.
     */
    public function contractService(ContractServiceRequest $request): JsonResponse
    {
        try {
            $user = Auth::user();
            $plan = ServicePlan::where('slug', $request->validated('plan_id'))->firstOrFail();

            ['service' => $service, 'invoice' => $invoice] = $this->contractingService->contract(
                $user,
                $plan,
                $request->validated()
            );

            return response()->json([
                'success' => true,
                'message' => 'Servicio contratado exitosamente.',
                'service' => new ServiceResource($service),
                'invoice' => $invoice->only(['uuid', 'invoice_number', 'total', 'currency']),
            ], 201);
        } catch (PaymentRequiresActionException $e) {
            return response()->json([
                'success'         => false,
                'message'         => $e->getMessage(),
                'data'            => ['client_secret' => $e->clientSecret, 'requires_action' => true],
            ], 402);
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Stripe\Exception\CardException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getError()->message ?? 'Payment failed.',
            ], 402);
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
     * Get user's services
     */
    public function getUserServices(): JsonResponse
    {
        try {
            $user = Auth::user();
            $services = Service::where("user_id", $user->id)
                ->with(["plan", "plan.category", "plan.features"])
                ->orderByDesc("created_at")
                ->get();

            return response()->json([
                "success" => true,
                "data" => $services
            ]);
        } catch (\Exception $e) {
            Log::error("Error fetching user services: " . $e->getMessage());
            return response()->json([
                "success" => false,
                "message" => "Error fetching user services"
            ], 500);
        }
    }

    /**
     * Get service details
     */
    public function getServiceDetails(string $serviceId): JsonResponse
    {
        try {
            $user = Auth::user();
            $service = Service::where("user_id", $user->id)
                ->where("uuid", $serviceId)
                ->with(["plan", "plan.category", "plan.features"])
                ->firstOrFail();

            return response()->json([
                "success" => true,
                "data" => $service
            ]);
        } catch (\Exception $e) {
            Log::error("Error fetching service details: " . $e->getMessage());
            return response()->json([
                "success" => false,
                "message" => "Service not found or not authorized"
            ], 404);
        }
    }

    public function getServiceInvoices(Request $request, $uuid)
    {
        $service = Service::where('uuid', $uuid)->where('user_id', $request->user()->id)->firstOrFail();

        $invoices = $service->invoice()->orderBy('created_at', 'desc')->get();

        return response()->json([
            'success' => true,
            'data' => $invoices,
        ]);
    }

    public function updateConfiguration(Request $request, $uuid)
    {
        $service = Service::where('uuid', $uuid)->where('user_id', $request->user()->id)->firstOrFail();

        $validated = $request->validate([
            'auto_renew' => 'required|boolean',
        ]);

        // El ->cast('array') en el modelo Service se encarga de esto
        $currentConfig = $service->configuration;
        $currentConfig['auto_renew'] = $validated['auto_renew'];
        $service->configuration = $currentConfig;

        $service->save();

        return response()->json([
            'success' => true,
            'message' => 'Configuración actualizada correctamente.',
            'data' => $service,
        ]);
    }

    /**
     * Update service configuration
     */
    public function updateServiceConfig(Request $request, string $serviceId): JsonResponse
    {
        try {
            $user = Auth::user();
            $service = Service::where("user_id", $user->id)
                ->where("uuid", $serviceId)
                ->firstOrFail();

            $validated = $request->validate([
                "configuration" => "required|array",
            ]);

            $service->update([
                "configuration" => $validated["configuration"]
            ]);

            ActivityLog::record(
                "Configuración de servicio actualizada",
                "Configuración del servicio " . $service->name . " (" . $service->uuid . ") actualizada.",
                "service",
                ["user_id" => $user->id, "service_id" => $service->id, "new_config" => $validated["configuration"]],
                $user->id
            );

            return response()->json([
                "success" => true,
                "message" => "Service configuration updated successfully",
                "data" => $service->fresh()
            ]);
        } catch (\Exception $e) {
            Log::error("Error updating service configuration: " . $e->getMessage());
            return response()->json([
                "success" => false,
                "message" => "Error updating service configuration"
            ], 500);
        }
    }

    /**
     * Cancel a service
     */
    public function cancelService(string $serviceId): JsonResponse
    {
        try {
            $user = Auth::user();
            $service = Service::where("user_id", $user->id)
                ->where("uuid", $serviceId)
                ->firstOrFail();

            $service->update([
                "status" => "cancelled",
                "cancelled_at" => now(),
            ]);

            ActivityLog::record(
                "Servicio cancelado",
                "El servicio " . $service->name . " (" . $service->uuid . ") ha sido cancelado.",
                "service",
                ["user_id" => $user->id, "service_id" => $service->id],
                $user->id
            );

            return response()->json([
                "success" => true,
                "message" => "Service cancelled successfully",
                "data" => $service->fresh()
            ]);
        } catch (\Exception $e) {
            Log::error("Error cancelling service: " . $e->getMessage());
            return response()->json([
                "success" => false,
                "message" => "Error cancelling service"
            ], 500);
        }
    }

    /**
     * Suspend a service
     */
    public function suspendService(string $serviceId): JsonResponse
    {
        try {
            $user = Auth::user();
            $service = Service::where("user_id", $user->id)
                ->where("uuid", $serviceId)
                ->firstOrFail();

            $service->update([
                "status" => "suspended",
                "suspended_at" => now(),
            ]);

            ActivityLog::record(
                "Servicio suspendido",
                "El servicio " . $service->name . " (" . $service->uuid . ") ha sido suspendido.",
                "service",
                ["user_id" => $user->id, "service_id" => $service->id],
                $user->id
            );

            return response()->json([
                "success" => true,
                "message" => "Service suspended successfully",
                "data" => $service->fresh()
            ]);
        } catch (\Exception $e) {
            Log::error("Error suspending service: " . $e->getMessage());
            return response()->json([
                "success" => false,
                "message" => "Error suspending service"
            ], 500);
        }
    }

    /**
     * Reactivate a service
     */
    public function reactivateService(string $serviceId): JsonResponse
    {
        try {
            $user = Auth::user();
            $service = Service::where("user_id", $user->id)
                ->where("uuid", $serviceId)
                ->firstOrFail();

            $service->update([
                "status" => "active",
                "suspended_at" => null,
            ]);

            ActivityLog::record(
                "Servicio reactivado",
                "El servicio " . $service->name . " (" . $service->uuid . ") ha sido reactivado.",
                "service",
                ["user_id" => $user->id, "service_id" => $service->id],
                $user->id
            );

            return response()->json([
                "success" => true,
                "message" => "Service reactivated successfully",
                "data" => $service->fresh()
            ]);
        } catch (\Exception $e) {
            Log::error("Error reactivating service: " . $e->getMessage());
            return response()->json([
                "success" => false,
                "message" => "Error reactivating service"
            ], 500);
        }
    }

    /**
     * Get service usage statistics
     */
    public function getServiceUsage(string $serviceId): JsonResponse
    {
        try {
            $user = Auth::user();
            $service = Service::where("user_id", $user->id)
                ->where("uuid", $serviceId)
                ->firstOrFail();

            // For now, return dummy data. In a real scenario, this would fetch from monitoring systems.
            $usageData = [
                "cpu_usage" => rand(10, 90),
                "memory_usage" => rand(20, 80),
                "disk_usage" => rand(5, 95),
                "bandwidth_usage" => rand(100, 1000),
                "last_updated" => now()->toDateTimeString(),
            ];

            return response()->json([
                "success" => true,
                "data" => $usageData
            ]);
        } catch (\Exception $e) {
            Log::error("Error fetching service usage: " . $e->getMessage());
            return response()->json([
                "success" => false,
                "message" => "Error fetching service usage"
            ], 500);
        }
    }

    /**
     * Get service backups
     */
    public function getServiceBackups(string $serviceId): JsonResponse
    {
        try {
            $user = Auth::user();
            $service = Service::where("user_id", $user->id)
                ->where("uuid", $serviceId)
                ->firstOrFail();

            // Dummy data for backups. In a real app, this would fetch from a backup system.
            $backups = [
                ["id" => Str::uuid(), "date" => now()->subDays(1)->toDateTimeString(), "size_mb" => 500, "type" => "full"],
                ["id" => Str::uuid(), "date" => now()->subDays(3)->toDateTimeString(), "size_mb" => 200, "type" => "incremental"],
                ["id" => Str::uuid(), "date" => now()->subDays(7)->toDateTimeString(), "size_mb" => 700, "type" => "full"],
            ];

            return response()->json([
                "success" => true,
                "data" => $backups
            ]);
        } catch (\Exception $e) {
            Log::error("Error fetching service backups: " . $e->getMessage());
            return response()->json([
                "success" => false,
                "message" => "Error fetching service backups"
            ], 500);
        }
    }

    /**
     * Create a service backup
     */
    public function createServiceBackup(string $serviceId): JsonResponse
    {
        try {
            $user = Auth::user();
            $service = Service::where("user_id", $user->id)
                ->where("uuid", $serviceId)
                ->firstOrFail();

            // Simulate backup creation process
            sleep(2); // Simulate a delay

            $newBackup = [
                "id" => Str::uuid(),
                "date" => now()->toDateTimeString(),
                "size_mb" => rand(100, 1000),
                "type" => "full",
                "status" => "completed"
            ];

            ActivityLog::record(
                "Copia de seguridad de servicio creada",
                "Copia de seguridad para el servicio " . $service->name . " (" . $service->uuid . ") creada.",
                "service",
                ["user_id" => $user->id, "service_id" => $service->id, "backup_id" => $newBackup["id"]],
                $user->id
            );

            return response()->json([
                "success" => true,
                "message" => "Backup created successfully",
                "data" => $newBackup
            ], 201);
        } catch (\Exception $e) {
            Log::error("Error creating service backup: " . $e->getMessage());
            return response()->json([
                "success" => false,
                "message" => "Error creating service backup"
            ], 500);
        }
    }

    /**
     * Restore a service backup
     */
    public function restoreServiceBackup(string $serviceId, string $backupId): JsonResponse
    {
        try {
            $user = Auth::user();
            $service = Service::where("user_id", $user->id)
                ->where("uuid", $serviceId)
                ->firstOrFail();

            // Simulate backup restoration process
            sleep(3); // Simulate a delay

            ActivityLog::record(
                "Restauración de servicio desde copia de seguridad",
                "Servicio " . $service->name . " (" . $service->uuid . ") restaurado desde la copia de seguridad " . $backupId . ".",
                "service",
                ["user_id" => $user->id, "service_id" => $service->id, "backup_id" => $backupId],
                $user->id
            );

            return response()->json([
                "success" => true,
                "message" => "Service restored successfully from backup " . $backupId
            ]);
        } catch (\Exception $e) {
            Log::error("Error restoring service backup: " . $e->getMessage());
            return response()->json([
                "success" => false,
                "message" => "Error restoring service backup"
            ], 500);
        }
    }

}
