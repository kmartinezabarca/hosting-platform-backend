<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Requests\Client\ContractServiceRequest;
use App\Http\Resources\ServiceResource;
use App\Services\ServiceContractingService;
use App\Exceptions\PaymentRequiresActionException;
use App\Models\Service;
use App\Models\ServicePlan;
use App\Models\ActivityLog;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class ServiceController extends Controller
{
    public function __construct(
        private readonly ServiceContractingService $contractingService
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
                    'id'             => $plan->id,
                    'uuid'           => $plan->uuid,
                    'slug'           => $plan->slug,
                    'name'           => $plan->name,
                    'description'    => $plan->description,
                    'base_price'     => $plan->base_price,
                    'setup_fee'      => $plan->setup_fee,
                    'is_popular'     => $plan->is_popular,
                    'category'       => $plan->category?->name,
                    'category_slug'  => $plan->category?->slug,
                    'specifications' => $plan->specifications,
                    'features'       => $plan->features->pluck('name')->toArray(),
                    'pricing'        => $plan->pricing->map(fn($p) => [
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
     * GET /services/user
     * Servicios del usuario autenticado.
     */
    public function getUserServices(): JsonResponse
    {
        try {
            $services = Service::where('user_id', Auth::id())
                ->with(['plan', 'plan.category', 'plan.features'])
                ->orderByDesc('created_at')
                ->get();

            return response()->json(['success' => true, 'data' => $services]);
        } catch (\Exception $e) {
            Log::error('Error fetching user services: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Error fetching user services'], 500);
        }
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
     * Reemplaza el configuration completo.
     */
    public function updateServiceConfig(Request $request, string $uuid): JsonResponse
    {
        try {
            $user    = Auth::user();
            $service = Service::where('user_id', $user->id)->where('uuid', $uuid)->firstOrFail();
            $validated = $request->validate(['configuration' => 'required|array']);

            $service->update(['configuration' => $validated['configuration']]);

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

    /**
     * GET /services/{uuid}/backups
     */
    public function getServiceBackups(string $uuid): JsonResponse
    {
        $service = Service::where('user_id', Auth::id())->where('uuid', $uuid)->firstOrFail();
        return response()->json(['success' => true, 'data' => $service->configuration['backups'] ?? []]);
    }

    /**
     * POST /services/{uuid}/backups
     */
    public function createServiceBackup(string $uuid): JsonResponse
    {
        $user    = Auth::user();
        $service = Service::where('user_id', $user->id)->where('uuid', $uuid)->firstOrFail();

        ActivityLog::record(
            'Solicitud de copia de seguridad',
            "El usuario solicitó una copia de seguridad para el servicio {$service->name}.",
            'service',
            ['user_id' => $user->id, 'service_id' => $service->id],
            $user->id
        );

        return response()->json(['success' => true, 'message' => 'Solicitud registrada. Se te notificará cuando esté lista.'], 202);
    }

    /**
     * POST /services/{uuid}/backups/{backupId}/restore
     */
    public function restoreServiceBackup(string $uuid, string $backupId): JsonResponse
    {
        $user    = Auth::user();
        $service = Service::where('user_id', $user->id)->where('uuid', $uuid)->firstOrFail();

        ActivityLog::record(
            'Solicitud de restauración de servicio',
            "El usuario solicitó restaurar el servicio {$service->name} desde la copia {$backupId}.",
            'service',
            ['user_id' => $user->id, 'service_id' => $service->id, 'backup_id' => $backupId],
            $user->id
        );

        return response()->json(['success' => true, 'message' => 'Solicitud de restauración registrada.'], 202);
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
