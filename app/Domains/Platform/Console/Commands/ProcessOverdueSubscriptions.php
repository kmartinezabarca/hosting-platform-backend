<?php

namespace App\Domains\Platform\Console\Commands;

use App\Domains\Platform\Models\Subscription;
use App\Domains\Platform\Notifications\ServiceNotification;
use App\Domains\Platform\Services\ServiceSuspensionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Suspende automáticamente los servicios cuyo periodo de gracia por pago
 * fallido ya venció, y reconcilia reactivaciones perdidas.
 *
 * Flujo de morosidad (dunning):
 *   1. invoice.payment_failed → suscripción past_due + grace_period_ends_at = now()+N días.
 *   2. El servicio sigue ACTIVO durante la gracia (banner avisa al cliente).
 *   3. Este comando, al vencer la gracia, suspende el servicio en el proveedor.
 *   4. Si el cliente paga (invoice.paid), el webhook reactiva; este comando
 *      también reconcilia por si el webhook se perdió.
 *
 * Programado en app/Console/Kernel.php (hourly).
 */
class ProcessOverdueSubscriptions extends Command
{
    protected $signature = 'subscriptions:process-overdue {--dry-run : Mostrar qué se haría sin aplicar cambios}';

    protected $description = 'Suspende servicios con periodo de gracia vencido por pago fallido y reconcilia reactivaciones.';

    public function handle(ServiceSuspensionService $suspension): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $suspended  = $this->suspendExpiredGrace($suspension, $dryRun);
        $reactivated = $this->reconcileReactivations($suspension, $dryRun);

        $this->info(($dryRun ? '[DRY-RUN] ' : '') . "Suspendidos: {$suspended} · Reactivados: {$reactivated}");

        return self::SUCCESS;
    }

    /**
     * Suspende los servicios cuya gracia venció y siguen sin pagar.
     */
    private function suspendExpiredGrace(ServiceSuspensionService $suspension, bool $dryRun): int
    {
        $count = 0;

        Subscription::query()
            ->where('status', 'past_due')
            ->whereNotNull('grace_period_ends_at')
            ->where('grace_period_ends_at', '<=', now())
            ->whereNull('suspended_at')
            ->with('service.plan', 'user')
            ->chunkById(100, function ($subscriptions) use ($suspension, $dryRun, &$count) {
                foreach ($subscriptions as $subscription) {
                    $service = $subscription->service;

                    // Sin servicio o ya suspendido/cancelado → sólo marcar la suscripción.
                    if (! $service || in_array($service->status, ['cancelled', 'terminated'], true)) {
                        if (! $dryRun) {
                            $subscription->update(['suspended_at' => now(), 'suspension_reason' => 'payment_overdue']);
                        }
                        continue;
                    }

                    $this->line(" → Suspendiendo servicio #{$service->id} ({$service->name}) — gracia vencida");

                    if ($dryRun) {
                        $count++;
                        continue;
                    }

                    try {
                        $suspension->suspend($service, 'payment_overdue');

                        $subscription->update([
                            'suspended_at'      => now(),
                            'suspension_reason' => 'payment_overdue',
                        ]);

                        if ($subscription->user) {
                            $subscription->user->notify(new ServiceNotification([
                                'title'   => 'Servicio suspendido',
                                'message' => "Tu servicio '{$service->name}' fue suspendido por falta de pago. Actualiza tu método de pago para reactivarlo.",
                                'type'    => 'service.suspended',
                                'data'    => ['service_id' => $service->uuid ?? $service->id, 'subscription_id' => $subscription->uuid],
                            ]));
                        }

                        $count++;
                    } catch (\Throwable $e) {
                        Log::error('process-overdue: error suspendiendo servicio', [
                            'service_id' => $service->id,
                            'error'      => $e->getMessage(),
                        ]);
                    }
                }
            });

        return $count;
    }

    /**
     * Reconciliación: suscripciones activas cuyo servicio sigue suspendido por
     * morosidad (webhook de pago exitoso perdido) → reactivar.
     */
    private function reconcileReactivations(ServiceSuspensionService $suspension, bool $dryRun): int
    {
        $count = 0;

        Subscription::query()
            ->where('status', 'active')
            ->whereHas('service', function ($q) {
                $q->where('status', 'suspended')->where('suspension_reason', 'payment_overdue');
            })
            ->with('service.plan')
            ->chunkById(100, function ($subscriptions) use ($suspension, $dryRun, &$count) {
                foreach ($subscriptions as $subscription) {
                    $service = $subscription->service;
                    if (! $service) {
                        continue;
                    }

                    $this->line(" → Reactivando servicio #{$service->id} ({$service->name}) — suscripción activa");

                    if ($dryRun) {
                        $count++;
                        continue;
                    }

                    try {
                        $suspension->reactivate($service);
                        $subscription->update(['suspended_at' => null, 'suspension_reason' => null]);
                        $count++;
                    } catch (\Throwable $e) {
                        Log::error('process-overdue: error reactivando servicio', [
                            'service_id' => $service->id,
                            'error'      => $e->getMessage(),
                        ]);
                    }
                }
            });

        return $count;
    }
}
