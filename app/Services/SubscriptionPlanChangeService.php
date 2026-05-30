<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Service;
use App\Models\ServicePlan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Pterodactyl\PterodactylService;
use App\Support\StripeObjectReader;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Subscription as StripeSubscription;

/**
 * Cambio de plan REAL (upgrade / downgrade / cambio de ciclo) sobre la
 * suscripción de Stripe, con prorrateo y rollback.
 *
 * Reglas:
 *  - Upgrade (sube el precio)  → prorrateo inmediato y se factura la diferencia ya
 *    (proration_behavior = always_invoice).
 *  - Downgrade (baja el precio)→ prorrateo con crédito aplicado a la próxima
 *    factura (proration_behavior = create_prorations).
 *  - Si la actualización local falla tras cambiar Stripe, se revierte el precio
 *    en Stripe (rollback) para no dejar facturación y catálogo desalineados.
 *  - La actualización de recursos del proveedor (Pterodactyl) es best-effort y
 *    NO bloquea ni revierte el cambio de facturación.
 */
class SubscriptionPlanChangeService
{
    public function __construct(
        private readonly StripeSyncService $stripeSync,
        private readonly PterodactylService $pterodactyl,
    ) {
        \Stripe\Stripe::setApiKey(config('services.stripe.secret'));
    }

    /**
     * @throws \RuntimeException  Reglas de negocio (mensaje apto para el cliente).
     */
    public function change(User $user, Service $service, ServicePlan $newPlan, ?string $billingCycleSlug = null): array
    {
        $subscription = $service->subscription;

        if (! $subscription || ! in_array($subscription->status, ['active', 'trialing', 'past_due'], true)) {
            throw new \RuntimeException('Este servicio no tiene una suscripción activa que permita cambiar de plan en línea.');
        }

        if (! $newPlan->is_active) {
            throw new \RuntimeException('El plan seleccionado no está disponible.');
        }

        if ($newPlan->category_id !== $service->plan?->category_id) {
            throw new \RuntimeException('No puedes cambiar a un plan de otra categoría.');
        }

        // Ciclo objetivo: el indicado o el actual de la suscripción.
        $cycleSlug = $billingCycleSlug ?: ($subscription->billing_cycle === 'yearly' ? 'annually' : 'monthly');

        if ($newPlan->id === $service->plan_id && $cycleSlug === ($subscription->billing_cycle === 'yearly' ? 'annually' : 'monthly')) {
            throw new \RuntimeException('Ya tienes este plan y ciclo activos.');
        }

        // Resolver el price de Stripe para el plan+ciclo (auto-sync si falta).
        $newPriceId = $this->stripeSync->resolvePriceId($newPlan, $cycleSlug);
        if (! $newPriceId) {
            $this->stripeSync->syncPlan($newPlan->load('pricing.billingCycle'));
            $newPlan->refresh();
            $newPriceId = $this->stripeSync->resolvePriceId($newPlan, $cycleSlug);
        }
        if (! $newPriceId) {
            throw new \RuntimeException("El plan '{$newPlan->name}' no tiene precio configurado para el ciclo seleccionado.");
        }

        $oldPriceId  = $subscription->stripe_price_id;
        $oldPlanId   = $service->plan_id;
        $oldPlanName = $service->plan?->name ?? '—';
        $oldPrice    = (float) $service->price;

        if ($newPriceId === $oldPriceId) {
            throw new \RuntimeException('El plan seleccionado corresponde al mismo precio actual.');
        }

        // Precio nuevo desde el catálogo local (para el registro local).
        $newPricing = $newPlan->pricing()
            ->whereHas('billingCycle', fn ($q) => $q->where('slug', $cycleSlug))
            ->first();
        $newPrice = (float) ($newPricing?->price ?? $newPlan->base_price);

        $direction = $newPrice >= $oldPrice ? 'upgrade' : 'downgrade';
        $prorationBehavior = $direction === 'upgrade' ? 'always_invoice' : 'create_prorations';

        // ── 1) Cambiar el item de la suscripción en Stripe ────────────────────
        $stripeSub = StripeSubscription::retrieve($subscription->stripe_subscription_id);
        $itemId    = $stripeSub->items->data[0]->id ?? null;

        if (! $itemId) {
            throw new \RuntimeException('No se pudo determinar el ítem de la suscripción en Stripe.');
        }

        $updatedSub = StripeSubscription::update($subscription->stripe_subscription_id, [
            'items'              => [['id' => $itemId, 'price' => $newPriceId]],
            'proration_behavior' => $prorationBehavior,
            'payment_behavior'   => 'error_if_incomplete',
            'expand'             => ['items'],
        ]);

        // ── 2) Persistir localmente; rollback en Stripe si la BD falla ────────
        try {
            DB::transaction(function () use ($subscription, $service, $newPlan, $newPriceId, $newPrice, $cycleSlug, $updatedSub) {
                $subscription->update([
                    'stripe_price_id'      => $newPriceId,
                    'amount'               => $newPrice,
                    'billing_cycle'        => $cycleSlug === 'annually' ? 'yearly' : 'monthly',
                    'current_period_start' => StripeObjectReader::subscriptionPeriodStart($updatedSub),
                    'current_period_end'   => StripeObjectReader::subscriptionPeriodEnd($updatedSub),
                ]);

                $service->update([
                    'plan_id' => $newPlan->id,
                    'price'   => $newPrice,
                ]);
            });
        } catch (\Throwable $e) {
            Log::error('Cambio de plan: fallo al persistir; revirtiendo Stripe', [
                'service_id' => $service->id,
                'error'      => $e->getMessage(),
            ]);

            try {
                StripeSubscription::update($subscription->stripe_subscription_id, [
                    'items'              => [['id' => $itemId, 'price' => $oldPriceId]],
                    'proration_behavior' => 'none',
                ]);
            } catch (\Throwable $rollbackError) {
                Log::critical('Cambio de plan: ROLLBACK en Stripe TAMBIÉN falló — revisar manualmente', [
                    'service_id'        => $service->id,
                    'subscription_id'   => $subscription->stripe_subscription_id,
                    'rollback_error'    => $rollbackError->getMessage(),
                ]);
            }

            throw new \RuntimeException('No se pudo completar el cambio de plan. No se realizaron cargos.');
        }

        // ── 3) Recursos del proveedor (best-effort, no fatal, no rollback) ────
        $this->updateProviderResources($service->fresh('plan'), $newPlan);

        ActivityLog::record(
            "Plan {$direction}: {$oldPlanName} → {$newPlan->name}",
            "Cambio de plan ({$direction}) con prorrateo. Precio: \${$oldPrice} → \${$newPrice}.",
            'service',
            [
                'service_id'  => $service->id,
                'old_plan_id' => $oldPlanId,
                'new_plan_id' => $newPlan->id,
                'direction'   => $direction,
                'proration'   => $prorationBehavior,
            ],
            $user->id,
        );

        return [
            'direction'  => $direction,
            'old_price'  => $oldPrice,
            'new_price'  => $newPrice,
            'new_plan'   => $newPlan->name,
            'cycle'      => $cycleSlug,
            'proration'  => $prorationBehavior,
            'service'    => $service->fresh(['plan.category', 'subscription']),
        ];
    }

    /**
     * Actualiza los límites de recursos en el proveedor (sólo Pterodactyl por ahora).
     * Best-effort: un fallo se registra pero no afecta el cambio de facturación.
     */
    private function updateProviderResources(Service $service, ServicePlan $newPlan): void
    {
        if (! $service->isPterodactylManaged() || ! $service->pterodactyl_server_id) {
            return;
        }

        $limits = $newPlan->pterodactyl_limits ?? [];
        if (empty($limits)) {
            return;
        }

        try {
            $this->pterodactyl->updateServerBuild((int) $service->pterodactyl_server_id, [
                'memory'         => (int) ($limits['memory'] ?? 0),
                'swap'           => (int) ($limits['swap'] ?? 0),
                'disk'           => (int) ($limits['disk'] ?? 0),
                'io'             => (int) ($limits['io'] ?? 500),
                'cpu'            => (int) ($limits['cpu'] ?? 0),
                'threads'        => $limits['threads'] ?? null,
                'feature_limits' => [
                    'databases'   => (int) ($newPlan->pterodactyl_feature_limits['databases'] ?? 0),
                    'backups'     => (int) ($newPlan->pterodactyl_feature_limits['backups'] ?? 0),
                    'allocations' => (int) ($newPlan->pterodactyl_feature_limits['allocations'] ?? 1),
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning('Cambio de plan: no se pudieron actualizar los límites en Pterodactyl (no fatal)', [
                'service_id' => $service->id,
                'error'      => $e->getMessage(),
            ]);
        }
    }
}
