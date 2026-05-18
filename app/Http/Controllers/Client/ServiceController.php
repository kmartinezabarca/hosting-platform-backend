<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Requests\Client\ContractServiceRequest;
use App\Http\Resources\ServiceResource;
use App\Services\ServiceContractingService;
use App\Services\Pterodactyl\PterodactylService;
use App\Exceptions\PaymentRequiresActionException;
use App\Models\Service;
use App\Models\ServicePlan;
use App\Models\ActivityLog;
use App\Models\Backup;
use App\Services\Backup\BackupService;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class ServiceController extends Controller
{
    public function __construct(
        private readonly ServiceContractingService $contractingService,
        private readonly PterodactylService $pterodactyl,
        private readonly BackupService $backupService,
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
        try {
            $user = Auth::user();
            $plan = ServicePlan::where('slug', $request->validated('plan_id'))->firstOrFail();

            ['service' => $service, 'receipt' => $receipt] = $this->contractingService->contract(
                $user,
                $plan,
                $request->validated()
            );

            return response()->json([
                'success' => true,
                'message' => 'Servicio contratado exitosamente.',
                'service' => new ServiceResource($service),
                'receipt' => $receipt->only(['uuid', 'invoice_number', 'total', 'currency']),
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
        $name    = $request->input('name') ?: 'Backup ' . now()->format('d/m/Y H:i');

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
