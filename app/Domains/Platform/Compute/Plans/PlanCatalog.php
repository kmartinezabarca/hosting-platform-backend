<?php

namespace App\Domains\Platform\Compute\Plans;

use App\Domains\Platform\Compute\Enums\BillingInterval;
use App\Domains\Platform\Compute\Enums\PlanTier;
use App\Domains\Platform\Compute\Models\ComputePlan;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Catálogo de planes de cómputo. La fuente principal es DB
 * (`compute_plan_catalog_entries`, kind=compute) para poder administrar precios
 * y límites como datos. El config queda como fallback para entornos sin migrar.
 */
class PlanCatalog
{
    private const KIND = 'compute';

    /** Moneda del catálogo (ISO 4217). */
    public function currency(): string
    {
        $currency = $this->dbPlans()?->first()?->currency;

        if (is_string($currency) && $currency !== '') {
            return $currency;
        }

        return (string) config('compute.billing.currency', 'MXN');
    }

    /**
     * Catálogo completo: cada tier con sus límites y precios mensual/anual.
     *
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        $plans = $this->dbPlans();

        if ($plans !== null) {
            return $plans->map(fn (ComputePlan $plan) => $this->fromModel($plan))->values()->all();
        }

        return array_map(fn (PlanTier $tier) => $this->fromConfig($tier), PlanTier::cases());
    }

    /**
     * Un tier con límites + precios + ahorro anual.
     *
     * @return array<string, mixed>
     */
    public function forTier(PlanTier $tier): array
    {
        $plan = $this->dbPlanForTier($tier);

        return $plan ? $this->fromModel($plan) : $this->fromConfig($tier);
    }

    /** Precio (float) de un tier/intervalo, o null si no está configurado. */
    public function price(PlanTier $tier, BillingInterval $interval): ?float
    {
        $plan = $this->dbPlanForTier($tier);

        if ($plan) {
            return $this->amount($interval === BillingInterval::Annual
                ? $plan->annual_amount
                : $plan->monthly_amount);
        }

        $raw = config("compute.billing.pricing.{$tier->value}.{$interval->value}.amount");

        return ($raw === null || $raw === '') ? null : (float) $raw;
    }

    /** stripe_price_id de un tier/intervalo, o null. */
    public function stripePriceId(PlanTier $tier, BillingInterval $interval): ?string
    {
        $plan = $this->dbPlanForTier($tier);

        if ($plan) {
            $id = $interval === BillingInterval::Annual
                ? $plan->stripe_annual_price_id
                : $plan->stripe_monthly_price_id;

            return ($id === null || $id === '') ? null : (string) $id;
        }

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

    /**
     * @return Collection<int, ComputePlan>|null
     */
    private function dbPlans(): ?Collection
    {
        try {
            if (! Schema::hasTable('compute_plan_catalog_entries')) {
                return null;
            }

            $plans = ComputePlan::query()
                ->compute()
                ->where('is_active', true)
                ->whereIn('tier', PlanTier::values())
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get();

            return $plans->isEmpty() ? null : $plans;
        } catch (Throwable) {
            return null;
        }
    }

    private function dbPlanForTier(PlanTier $tier): ?ComputePlan
    {
        try {
            if (! Schema::hasTable('compute_plan_catalog_entries')) {
                return null;
            }

            return ComputePlan::query()
                ->compute()
                ->where('is_active', true)
                ->where('tier', $tier->value)
                ->first();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function fromModel(ComputePlan $plan): array
    {
        $monthly = $this->amount($plan->monthly_amount);
        $annual = $this->amount($plan->annual_amount);

        return [
            'kind' => $plan->kind,
            'tier' => $plan->tier,
            'name' => $plan->name,
            'description' => $plan->description,
            'currency' => $plan->currency,
            'limits' => [
                'max_resources' => $plan->max_resources,
                'ram_mb_max' => $plan->ram_mb_max,
                'max_members' => $plan->max_members,
            ],
            'features' => $plan->features ?? [],
            'pricing' => [
                'monthly' => [
                    'amount' => $monthly,
                    'stripe_price_id' => $plan->stripe_monthly_price_id,
                ],
                'annual' => [
                    'amount' => $annual,
                    'stripe_price_id' => $plan->stripe_annual_price_id,
                    'savings' => $this->annualSavingsFrom($monthly, $annual),
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fromConfig(PlanTier $tier): array
    {
        $limits = config("compute.plans.{$tier->value}", []);
        $monthly = $this->priceFromConfig($tier, BillingInterval::Monthly);
        $annual = $this->priceFromConfig($tier, BillingInterval::Annual);

        return [
            'kind' => self::KIND,
            'tier' => $tier->value,
            'name' => ucfirst($tier->value),
            'description' => null,
            'currency' => (string) config('compute.billing.currency', 'MXN'),
            'limits' => [
                'max_resources' => $limits['max_resources'] ?? null,
                'ram_mb_max' => $limits['ram_mb_max'] ?? null,
                'max_members' => $limits['max_members'] ?? null,
            ],
            'features' => [],
            'pricing' => [
                'monthly' => [
                    'amount' => $monthly,
                    'stripe_price_id' => $this->stripePriceIdFromConfig($tier, BillingInterval::Monthly),
                ],
                'annual' => [
                    'amount' => $annual,
                    'stripe_price_id' => $this->stripePriceIdFromConfig($tier, BillingInterval::Annual),
                    'savings' => $this->annualSavingsFrom($monthly, $annual),
                ],
            ],
        ];
    }

    private function priceFromConfig(PlanTier $tier, BillingInterval $interval): ?float
    {
        $raw = config("compute.billing.pricing.{$tier->value}.{$interval->value}.amount");

        return ($raw === null || $raw === '') ? null : (float) $raw;
    }

    private function stripePriceIdFromConfig(PlanTier $tier, BillingInterval $interval): ?string
    {
        $id = config("compute.billing.pricing.{$tier->value}.{$interval->value}.stripe_price_id");

        return ($id === null || $id === '') ? null : (string) $id;
    }

    private function amount(mixed $value): ?float
    {
        return ($value === null || $value === '') ? null : (float) $value;
    }

    /**
     * @return array{amount: float, percent: float}|null
     */
    private function annualSavingsFrom(?float $monthly, ?float $annual): ?array
    {
        if ($monthly === null || $annual === null || $monthly <= 0.0) {
            return null;
        }

        $fullYear = $monthly * 12;
        $amount = round($fullYear - $annual, 2);
        $percent = round(($amount / $fullYear) * 100, 2);

        return ['amount' => $amount, 'percent' => $percent];
    }
}
