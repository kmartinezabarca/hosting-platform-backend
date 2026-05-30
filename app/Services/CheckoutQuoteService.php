<?php

namespace App\Services;

use App\Exceptions\CheckoutQuoteException;
use App\Models\AddOn;
use App\Models\BillingCycle;
use App\Models\Category;
use App\Models\CheckoutQuote;
use App\Models\CustomerFiscalProfile;
use App\Models\PlanPricing;
use App\Models\ServicePlan;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CheckoutQuoteService
{
    public function catalog(): array
    {
        $categories = Category::active()
            ->with([
                'activeServicePlans' => fn ($query) => $query
                    ->with(['features', 'pricing.billingCycle', 'addOns'])
                    ->orderBy('sort_order')
                    ->orderBy('name'),
            ])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return [
            'categories' => $categories
                ->filter(fn (Category $category) => $category->activeServicePlans->isNotEmpty())
                ->map(fn (Category $category) => [
                    'id'          => $category->id,
                    'uuid'        => $category->uuid,
                    'name'        => $category->name,
                    'slug'        => $category->slug,
                    'description' => $category->description,
                    'plans'       => $category->activeServicePlans
                        ->map(fn (ServicePlan $plan) => $this->formatCatalogPlan($plan))
                        ->values(),
                ])
                ->values(),
        ];
    }

    public function createQuote(?User $user, array $input): CheckoutQuote
    {
        if (!empty($input['fiscal_profile_uuid'])) {
            $exists = $user && CustomerFiscalProfile::where('uuid', $input['fiscal_profile_uuid'])
                ->where('user_id', $user->id)
                ->exists();

            if (! $exists) {
                throw new CheckoutQuoteException('FISCAL_PROFILE_UNAVAILABLE', 'El perfil fiscal no existe o no pertenece al usuario.', 422);
            }
        }

        $built = $this->buildQuote($input);

        return DB::transaction(fn () => CheckoutQuote::create([
            'user_id'             => $user?->id,
            'service_plan_id'     => $built['plan']->id,
            'billing_cycle_id'    => $built['cycle']->id,
            'selected_add_on_ids' => $built['add_ons']->pluck('id')->values()->all(),
            'request_payload'     => $built['request_payload'],
            'pricing_snapshot'    => $built['snapshot'],
            'quote_hash'          => $built['hash'],
            'currency'            => $built['snapshot']['currency'],
            'subtotal'            => $built['snapshot']['subtotal'],
            'discount'            => $built['snapshot']['discount'],
            'tax'                 => $built['snapshot']['tax'],
            'total'               => $built['snapshot']['total'],
            'is_free'             => $built['snapshot']['is_free'],
            'is_trial'            => $built['snapshot']['is_trial'],
            'trial_days'          => $built['snapshot']['trial_days'],
            'expires_at'          => now()->addMinutes((int) config('checkout.quote_ttl_minutes', 30)),
        ]));
    }

    public function validateQuote(string $quoteId, User $user): CheckoutQuote
    {
        $quote = CheckoutQuote::with(['servicePlan', 'billingCycle'])
            ->where('uuid', $quoteId)
            ->firstOrFail();

        if ($quote->user_id !== null && $quote->user_id !== $user->id) {
            throw new CheckoutQuoteException('QUOTE_NOT_FOUND', 'La cotización no existe para este usuario.', 404);
        }

        if ($quote->expires_at->isPast()) {
            throw new CheckoutQuoteException('QUOTE_EXPIRED', 'La cotización expiró. Genera una nueva.', 409);
        }

        if ($quote->consumed_at !== null) {
            throw new CheckoutQuoteException('QUOTE_ALREADY_USED', 'Esta cotización ya fue utilizada. Genera una nueva.', 409);
        }

        try {
            $rebuilt = $this->buildQuote($quote->request_payload);
        } catch (CheckoutQuoteException) {
            throw new CheckoutQuoteException('QUOTE_CHANGED', 'El plan, ciclo, add-ons o impuestos cambiaron. Genera una nueva cotización.', 409);
        }

        if (! hash_equals($quote->quote_hash, $rebuilt['hash'])) {
            throw new CheckoutQuoteException('QUOTE_CHANGED', 'El plan, ciclo, add-ons o impuestos cambiaron. Genera una nueva cotización.', 409);
        }

        return $quote;
    }

    public function contractPayload(CheckoutQuote $quote, array $requestData): array
    {
        $snapshot = $quote->pricing_snapshot;

        return array_merge($requestData, [
            'plan_id'          => data_get($snapshot, 'plan.slug'),
            'billing_cycle'    => data_get($snapshot, 'billing_cycle.code'),
            'add_ons'          => data_get($snapshot, 'selected_add_on_uuids', []),
            'fiscal_profile_uuid' => $requestData['fiscal_profile_uuid']
                ?? data_get($snapshot, 'request.fiscal_profile_uuid'),
            'create_subscription' => $requestData['create_subscription']
                ?? (bool) data_get($snapshot, 'request.auto_renew', true),
            'additional_options' => array_merge(
                ['auto_renew' => (bool) data_get($snapshot, 'request.auto_renew', true)],
                $requestData['additional_options'] ?? []
            ),
            '_checkout_quote' => [
                'uuid'      => $quote->uuid,
                'snapshot'  => $snapshot,
                'expires_at'=> $quote->expires_at?->toIso8601String(),
            ],
        ]);
    }

    public function markConsumed(CheckoutQuote $quote): void
    {
        $quote->forceFill(['consumed_at' => now()])->save();
    }

    /**
     * Reclama la cotización de forma atómica ANTES de cobrar.
     *
     * Usa un UPDATE condicional (consumed_at IS NULL) para que dos peticiones
     * concurrentes (doble click / refresh / retry) no puedan contratar dos veces
     * la misma cotización: sólo la primera marca consumed_at; la segunda recibe
     * 0 filas afectadas y se rechaza.
     *
     * @throws CheckoutQuoteException  Si la cotización ya fue reclamada.
     */
    public function claim(CheckoutQuote $quote): void
    {
        $claimed = CheckoutQuote::whereKey($quote->id)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        if ($claimed === 0) {
            throw new CheckoutQuoteException('QUOTE_ALREADY_USED', 'Esta cotización ya fue utilizada. Genera una nueva.', 409);
        }

        $quote->consumed_at = now();
    }

    /**
     * Libera una cotización reclamada cuando la contratación falla
     * (tarjeta rechazada, 3DS pendiente, error de validación), para que el
     * cliente pueda reintentar con la misma cotización.
     */
    public function release(CheckoutQuote $quote): void
    {
        CheckoutQuote::whereKey($quote->id)->update(['consumed_at' => null]);
        $quote->consumed_at = null;
    }

    public function responseData(CheckoutQuote $quote): array
    {
        return array_merge($quote->pricing_snapshot, [
            'quote_id'   => $quote->uuid,
            'expires_at' => $quote->expires_at?->toIso8601String(),
        ]);
    }

    private function buildQuote(array $input): array
    {
        $plan = $this->resolveActivePlan($input['plan_id'] ?? null);
        $cycle = $this->resolveActiveCycle($input['billing_cycle'] ?? null);
        $pricing = $this->pricingFor($plan, $cycle);
        $addOns = $this->resolveAddOns($plan, $input['add_ons'] ?? []);

        $taxRate = (float) config('billing.tax_rate_percent', 16.00);
        $currency = strtoupper((string) config('billing.currency', 'MXN'));
        $cyclePricing = $this->calculatePlanCycle($plan, $cycle, $pricing, $taxRate);
        $months = max((int) $cycle->months, 0);
        $multiplier = $months > 0 ? $months : 1;

        $isNoCharge = $plan->isNoCharge();
        $planAmount = $isNoCharge ? 0.0 : $cyclePricing['subtotal'];
        $discount = $isNoCharge ? 0.0 : $cyclePricing['discount'];
        $addOnsAmount = $isNoCharge ? 0.0 : round($addOns->sum(fn (AddOn $addOn) => (float) $addOn->price) * $multiplier, 2);
        $subtotal = round($planAmount + $addOnsAmount, 2);
        $tax = round($subtotal * $taxRate / 100, 2);
        $total = round($subtotal + $tax, 2);
        $nextDue = $plan->isTrial()
            ? now()->addDays($plan->trial_days ?: 14)
            : ($months > 0 ? now()->addMonthsNoOverflow($months) : now());

        $lines = [[
            'type'   => 'plan',
            'label'  => $plan->name,
            'amount' => $planAmount,
        ]];

        foreach ($addOns as $addOn) {
            $lines[] = [
                'type'   => 'add_on',
                'label'  => $addOn->name,
                'amount' => $isNoCharge ? 0.0 : round((float) $addOn->price * $multiplier, 2),
            ];
        }

        if ($discount > 0) {
            $lines[] = [
                'type'   => 'discount',
                'label'  => 'Descuento ' . $cycle->name,
                'amount' => -$discount,
            ];
        }

        if ($tax > 0) {
            $lines[] = [
                'type'   => 'tax',
                'label'  => 'IVA ' . rtrim(rtrim(number_format($taxRate, 2, '.', ''), '0'), '.') . '%',
                'amount' => $tax,
            ];
        }

        $requestPayload = [
            'plan_id'             => $plan->id,
            'billing_cycle'       => $cycle->slug,
            'add_ons'             => $addOns->pluck('id')->values()->all(),
            'generate_cfdi'       => (bool) ($input['generate_cfdi'] ?? false),
            'fiscal_profile_uuid' => $input['fiscal_profile_uuid'] ?? null,
            'auto_renew'          => (bool) ($input['auto_renew'] ?? true),
        ];

        $snapshot = [
            'currency'      => $currency,
            'tax_behavior'  => 'exclusive',
            'tax_rate'      => $taxRate,
            'plan'          => [
                'id' => $plan->id,
                'uuid' => $plan->uuid,
                'slug' => $plan->slug,
                'name' => $plan->name,
                'plan_type' => $plan->plan_type ?? ServicePlan::TYPE_PAID,
            ],
            'billing_cycle' => $this->formatCycle($cycle, $cyclePricing),
            'lines'         => $lines,
            'subtotal'      => $subtotal,
            'discount'      => $discount,
            'tax'           => $tax,
            'total'         => $total,
            'is_free'       => $plan->isFree(),
            'is_trial'      => $plan->isTrial(),
            'trial_days'    => $plan->isTrial() ? (int) ($plan->trial_days ?: 14) : 0,
            'next_due_at'   => $nextDue->toIso8601String(),
            'selected_add_ons' => $addOns->map(fn (AddOn $addOn) => [
                'id' => $addOn->id,
                'uuid' => $addOn->uuid,
                'name' => $addOn->name,
                'amount' => $isNoCharge ? 0.0 : round((float) $addOn->price * $multiplier, 2),
            ])->values()->all(),
            'selected_add_on_uuids' => $addOns->pluck('uuid')->values()->all(),
            'request' => $requestPayload,
        ];

        return [
            'plan'            => $plan,
            'cycle'           => $cycle,
            'pricing'         => $pricing,
            'add_ons'         => $addOns,
            'request_payload' => $requestPayload,
            'snapshot'        => $snapshot,
            'hash'            => $this->hashFor($plan, $cycle, $pricing, $addOns, $requestPayload, $taxRate),
        ];
    }

    private function formatCatalogPlan(ServicePlan $plan): array
    {
        return [
            'id'             => $plan->id,
            'uuid'           => $plan->uuid,
            'slug'           => $plan->slug,
            'name'           => $plan->name,
            'description'    => $plan->description,
            'currency'       => strtoupper((string) config('billing.currency', 'MXN')),
            'tax_behavior'   => 'exclusive',
            'is_free'        => $plan->isFree(),
            'is_trial'       => $plan->isTrial(),
            'trial_days'     => $plan->isTrial() ? (int) ($plan->trial_days ?: 14) : 0,
            'is_popular'     => (bool) $plan->is_popular,
            'features'       => $plan->features->sortBy('sort_order')->pluck('feature')->values(),
            'specifications' => $plan->specifications ?? [],
            'billing_cycles' => $this->catalogCyclesFor($plan),
            'add_ons'        => $plan->addOns
                ->where('is_active', true)
                ->map(fn (AddOn $addOn) => [
                    'id'          => $addOn->id,
                    'uuid'        => $addOn->uuid,
                    'slug'        => $addOn->slug,
                    'name'        => $addOn->name,
                    'description' => $addOn->description,
                    'price'       => (float) $addOn->price,
                    'currency'    => strtoupper($addOn->currency ?? config('billing.currency', 'MXN')),
                    'is_default'  => (bool) data_get($addOn, 'pivot.is_default', false),
                    'metadata'    => $addOn->metadata ?? (object) [],
                ])
                ->values(),
        ];
    }

    private function catalogCyclesFor(ServicePlan $plan): Collection
    {
        $taxRate = (float) config('billing.tax_rate_percent', 16.00);
        $pricingRows = $plan->pricing
            ->filter(fn (PlanPricing $pricing) => $pricing->billingCycle?->is_active)
            ->sortBy(fn (PlanPricing $pricing) => $pricing->billingCycle->sort_order);

        if ($pricingRows->isNotEmpty()) {
            return $pricingRows
                ->map(fn (PlanPricing $pricing) => $this->formatCycle(
                    $pricing->billingCycle,
                    $this->calculatePlanCycle($plan, $pricing->billingCycle, $pricing, $taxRate)
                ))
                ->values();
        }

        return BillingCycle::active()
            ->orderBy('sort_order')
            ->get()
            ->map(fn (BillingCycle $cycle) => $this->formatCycle(
                $cycle,
                $this->calculatePlanCycle($plan, $cycle, null, $taxRate)
            ))
            ->values();
    }

    private function calculatePlanCycle(ServicePlan $plan, BillingCycle $cycle, ?PlanPricing $pricing, float $taxRate): array
    {
        $months = max((int) $cycle->months, 0);

        if ($plan->isNoCharge() || $months === 0) {
            return [
                'discount' => 0.0,
                'discount_percent' => (float) $cycle->discount_percentage,
                'subtotal' => 0.0,
                'tax' => 0.0,
                'total' => 0.0,
            ];
        }

        $base = round((float) $plan->base_price * $months, 2);
        $cycleUnit = $pricing ? (float) $pricing->price : $cycle->calculateDiscountedPrice((float) $plan->base_price);
        $subtotal = round($cycleUnit * $months, 2);
        $discount = round(max($base - $subtotal, 0), 2);
        $tax = round($subtotal * $taxRate / 100, 2);

        return [
            'discount' => $discount,
            'discount_percent' => $base > 0 ? round($discount / $base * 100, 2) : 0.0,
            'subtotal' => $subtotal,
            'tax' => $tax,
            'total' => round($subtotal + $tax, 2),
        ];
    }

    private function formatCycle(BillingCycle $cycle, array $pricing): array
    {
        return [
            'id'               => $cycle->id,
            'uuid'             => $cycle->uuid,
            'code'             => $cycle->slug,
            'name'             => $cycle->name,
            'interval'         => (int) $cycle->months === 12 ? 'year' : ((int) $cycle->months === 0 ? 'day' : 'month'),
            'interval_count'   => (int) $cycle->months === 12 ? 1 : (int) $cycle->months,
            'months'           => (int) $cycle->months,
            'discount_percent' => (float) $pricing['discount_percent'],
            'subtotal'         => (float) $pricing['subtotal'],
            'tax'              => (float) $pricing['tax'],
            'total'            => (float) $pricing['total'],
        ];
    }

    private function resolveActivePlan(mixed $planId): ServicePlan
    {
        $plan = ServicePlan::with(['category', 'features', 'pricing.billingCycle', 'addOns'])
            ->when(is_numeric($planId), fn ($query) => $query->where('id', (int) $planId))
            ->when(! is_numeric($planId), fn ($query) => $query->where(function ($q) use ($planId) {
                $q->where('uuid', $planId)->orWhere('slug', $planId);
            }))
            ->first();

        if (! $plan || ! $plan->is_active || ! $plan->category?->is_active) {
            throw new CheckoutQuoteException('PLAN_UNAVAILABLE', 'El plan seleccionado no existe o no está activo.', 422);
        }

        return $plan;
    }

    private function resolveActiveCycle(?string $slug): BillingCycle
    {
        $cycle = BillingCycle::active()->where('slug', $slug)->first();

        if (! $cycle) {
            throw new CheckoutQuoteException('BILLING_CYCLE_UNAVAILABLE', 'El ciclo de facturación no existe o no está activo.', 422);
        }

        return $cycle;
    }

    private function pricingFor(ServicePlan $plan, BillingCycle $cycle): ?PlanPricing
    {
        $pricing = $plan->pricing->firstWhere('billing_cycle_id', $cycle->id);

        if ($plan->pricing->isNotEmpty() && ! $pricing) {
            throw new CheckoutQuoteException('BILLING_CYCLE_UNAVAILABLE', 'El ciclo de facturación no está disponible para este plan.', 422);
        }

        return $pricing;
    }

    private function resolveAddOns(ServicePlan $plan, array $requested): EloquentCollection
    {
        $ids = collect($requested)->filter(fn ($value) => $value !== null && $value !== '')->values();

        if ($ids->isEmpty()) {
            return new EloquentCollection();
        }

        $allowed = $plan->addOns()->where('is_active', true)->get();
        $selected = $allowed->filter(fn (AddOn $addOn) => $ids->contains($addOn->id) || $ids->contains($addOn->uuid))->values();

        if ($selected->count() !== $ids->unique()->count()) {
            throw new CheckoutQuoteException('ADD_ON_UNAVAILABLE', 'Uno o más add-ons no existen, están inactivos o no pertenecen al plan.', 422);
        }

        return new EloquentCollection($selected->all());
    }

    private function hashFor(
        ServicePlan $plan,
        BillingCycle $cycle,
        ?PlanPricing $pricing,
        EloquentCollection $addOns,
        array $requestPayload,
        float $taxRate,
    ): string {
        return hash('sha256', json_encode([
            'request' => $requestPayload,
            'tax_rate' => $taxRate,
            'plan' => [
                'id' => $plan->id,
                'is_active' => $plan->is_active,
                'base_price' => (float) $plan->base_price,
                'plan_type' => $plan->plan_type,
                'trial_days' => $plan->trial_days,
                'updated_at' => $plan->updated_at?->toIso8601String(),
            ],
            'cycle' => [
                'id' => $cycle->id,
                'slug' => $cycle->slug,
                'months' => $cycle->months,
                'discount_percentage' => (float) $cycle->discount_percentage,
                'is_active' => $cycle->is_active,
                'updated_at' => $cycle->updated_at?->toIso8601String(),
            ],
            'pricing' => $pricing ? [
                'id' => $pricing->id,
                'price' => (float) $pricing->price,
                'updated_at' => $pricing->updated_at?->toIso8601String(),
            ] : null,
            'add_ons' => $addOns->map(fn (AddOn $addOn) => [
                'id' => $addOn->id,
                'price' => (float) $addOn->price,
                'is_active' => $addOn->is_active,
                'updated_at' => $addOn->updated_at?->toIso8601String(),
            ])->values()->all(),
        ], JSON_THROW_ON_ERROR));
    }
}
