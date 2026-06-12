<?php

namespace App\Domains\Platform\Compute\Plans;

use App\Domains\Platform\Compute\Enums\BillingInterval;
use App\Domains\Platform\Compute\Enums\PlanTier;

/**
 * Catálogo de planes de cómputo (mes 3 — annual billing). Une los límites
 * (config('compute.plans')) con los precios (config('compute.billing.pricing'))
 * y calcula el ahorro del plan anual vs pagar 12 meses sueltos.
 *
 * Los montos vienen de env (string|null) y se castean aquí; un tier sin precio
 * configurado se expone con `amount = null` (no como gratis) para no inventar
 * precios. El cálculo es determinista y testeable sin Stripe ni infra.
 */
class PlanCatalog
{
    /** Moneda del catálogo (ISO 4217). */
    public function currency(): string
    {
        return (string) config('compute.billing.currency', 'MXN');
    }

    /**
     * Catálogo completo: cada tier con sus límites y precios mensual/anual.
     *
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        return array_map(fn (PlanTier $tier) => $this->forTier($tier), PlanTier::cases());
    }

    /**
     * Un tier con límites + precios + ahorro anual.
     *
     * @return array<string, mixed>
     */
    public function forTier(PlanTier $tier): array
    {
        $limits  = config("compute.plans.{$tier->value}", []);
        $monthly = $this->price($tier, BillingInterval::Monthly);
        $annual  = $this->price($tier, BillingInterval::Annual);

        return [
            'tier'     => $tier->value,
            'currency' => $this->currency(),
            'limits'   => [
                'max_resources' => $limits['max_resources'] ?? null,
                'ram_mb_max'    => $limits['ram_mb_max'] ?? null,
                'max_members'   => $limits['max_members'] ?? null,
            ],
            'pricing' => [
                'monthly' => [
                    'amount'          => $monthly,
                    'stripe_price_id' => $this->stripePriceId($tier, BillingInterval::Monthly),
                ],
                'annual' => [
                    'amount'          => $annual,
                    'stripe_price_id' => $this->stripePriceId($tier, BillingInterval::Annual),
                    'savings'         => $this->annualSavings($tier),
                ],
            ],
        ];
    }

    /** Precio (float) de un tier/intervalo, o null si no está configurado. */
    public function price(PlanTier $tier, BillingInterval $interval): ?float
    {
        $raw = config("compute.billing.pricing.{$tier->value}.{$interval->value}.amount");

        return ($raw === null || $raw === '') ? null : (float) $raw;
    }

    /** stripe_price_id de un tier/intervalo, o null. */
    public function stripePriceId(PlanTier $tier, BillingInterval $interval): ?string
    {
        $id = config("compute.billing.pricing.{$tier->value}.{$interval->value}.stripe_price_id");

        return ($id === null || $id === '') ? null : (string) $id;
    }

    /**
     * Ahorro del plan anual frente a pagar 12 meses al precio mensual.
     * Devuelve null si falta algún precio o el mensual es 0 (no hay con qué
     * comparar). amount = pesos ahorrados/año; percent = % redondeado a 2.
     *
     * @return array{amount: float, percent: float}|null
     */
    public function annualSavings(PlanTier $tier): ?array
    {
        $monthly = $this->price($tier, BillingInterval::Monthly);
        $annual  = $this->price($tier, BillingInterval::Annual);

        if ($monthly === null || $annual === null || $monthly <= 0.0) {
            return null;
        }

        $fullYear = $monthly * 12;
        $amount   = round($fullYear - $annual, 2);
        $percent  = round(($amount / $fullYear) * 100, 2);

        return ['amount' => $amount, 'percent' => $percent];
    }
}
