<?php

namespace App\Services;

use App\Models\PlanPricing;
use App\Models\ServicePlan;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;
use Stripe\Price as StripePrice;
use Stripe\Product as StripeProduct;
use Stripe\Stripe;

/**
 * Synchronises ServicePlan records with Stripe Products / Prices.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Stripe data model used here:
 *
 *   ServicePlan  →  Stripe Product  (one per plan)
 *   PlanPricing  →  Stripe Price    (one per billing cycle)
 *
 * Billing cycle → Stripe interval mapping:
 *   monthly       → recurring { interval: month, interval_count: 1 }
 *   quarterly     → recurring { interval: month, interval_count: 3 }
 *   semi_annually → recurring { interval: month, interval_count: 6 }
 *   annually      → recurring { interval: year,  interval_count: 1 }
 *   one_time      → one_time  (no recurring)
 * ─────────────────────────────────────────────────────────────────────────────
 */
class StripeSyncService
{
    /** Cents per unit for Stripe (Stripe expects integer cents) */
    private const CURRENCY = 'mxn';

    public function __construct()
    {
        Stripe::setApiKey(config('services.stripe.secret'));
    }

    // ── Public API ───────────────────────────────────────────────────────────

    /**
     * Sync a single plan: create the Stripe Product (if missing) then sync
     * all of its PlanPricing rows.
     *
     * Returns an array summary: ['product' => ..., 'prices' => [...]]
     */
    public function syncPlan(ServicePlan $plan): array
    {
        $product = $this->ensureProduct($plan);
        $prices  = $this->syncPricesForPlan($plan, $product->id);

        return [
            'plan_id'           => $plan->id,
            'plan_name'         => $plan->name,
            'stripe_product_id' => $product->id,
            'prices'            => $prices,
        ];
    }

    /**
     * Sync every active plan that is missing either a stripe_product_id OR
     * has at least one PlanPricing row without a stripe_price_id.
     *
     * @return array  List of per-plan result summaries
     */
    public function syncAll(): array
    {
        $plans = ServicePlan::where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('stripe_product_id')
                  ->orWhereHas('pricing', fn($q2) => $q2->whereNull('stripe_price_id'));
            })
            ->with(['pricing.billingCycle'])
            ->get();

        $results = [];

        foreach ($plans as $plan) {
            try {
                $results[] = $this->syncPlan($plan);
            } catch (ApiErrorException $e) {
                Log::error("StripeSyncService: failed to sync plan #{$plan->id} ({$plan->name})", [
                    'error' => $e->getMessage(),
                ]);
                $results[] = [
                    'plan_id'   => $plan->id,
                    'plan_name' => $plan->name,
                    'error'     => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    // ── Product ──────────────────────────────────────────────────────────────

    /**
     * Retrieve the existing Stripe Product for this plan, or create one.
     */
    public function ensureProduct(ServicePlan $plan): StripeProduct
    {
        // Already synced — verify it still exists on Stripe
        if ($plan->stripe_product_id) {
            try {
                return StripeProduct::retrieve($plan->stripe_product_id);
            } catch (ApiErrorException) {
                // Product was deleted on Stripe — recreate it
                Log::warning("StripeSyncService: product {$plan->stripe_product_id} not found on Stripe, recreating.");
            }
        }

        $product = StripeProduct::create([
            'name'        => $plan->name,
            'description' => $plan->description ?? $plan->name,
            'active'      => (bool) $plan->is_active,
            'metadata'    => [
                'service_plan_id'   => (string) $plan->id,
                'service_plan_uuid' => $plan->uuid,
                'slug'              => $plan->slug,
            ],
        ]);

        $plan->update(['stripe_product_id' => $product->id]);

        Log::info("StripeSyncService: created Stripe Product {$product->id} for plan '{$plan->name}'");

        return $product;
    }

    /**
     * Update the Stripe Product metadata / name if the plan changed.
     */
    public function updateProduct(ServicePlan $plan): ?StripeProduct
    {
        if (! $plan->stripe_product_id) {
            return null;
        }

        return StripeProduct::update($plan->stripe_product_id, [
            'name'        => $plan->name,
            'description' => $plan->description ?? $plan->name,
            'active'      => (bool) $plan->is_active,
        ]);
    }

    // ── Prices ───────────────────────────────────────────────────────────────

    /**
     * For each PlanPricing row that lacks a stripe_price_id, create a Stripe
     * Price and persist it.  Returns a summary array.
     */
    public function syncPricesForPlan(ServicePlan $plan, string $stripeProductId): array
    {
        $results = [];

        // Load all pricing rows (even already-synced ones for reference)
        $pricings = $plan->pricing()->with('billingCycle')->get();

        foreach ($pricings as $pricing) {
            if ($pricing->stripe_price_id) {
                $results[] = [
                    'billing_cycle' => $pricing->billingCycle?->slug ?? '?',
                    'stripe_price_id' => $pricing->stripe_price_id,
                    'status' => 'already_synced',
                ];
                continue;
            }

            try {
                $stripePrice = $this->createStripePrice($stripeProductId, $pricing);
                $pricing->update(['stripe_price_id' => $stripePrice->id]);

                // Keep service_plans.stripe_price_id as the "monthly" (or first) price
                $cycleSlug = $pricing->billingCycle?->slug ?? '';
                if ($cycleSlug === 'monthly' && ! $plan->stripe_price_id) {
                    $plan->update(['stripe_price_id' => $stripePrice->id]);
                }

                $results[] = [
                    'billing_cycle'   => $cycleSlug,
                    'price'           => $pricing->price,
                    'stripe_price_id' => $stripePrice->id,
                    'status'          => 'created',
                ];

                Log::info("StripeSyncService: created Price {$stripePrice->id} for plan '{$plan->name}' / cycle '{$cycleSlug}'");

            } catch (ApiErrorException $e) {
                Log::error("StripeSyncService: failed to create price for plan #{$plan->id} cycle #{$pricing->billing_cycle_id}", [
                    'error' => $e->getMessage(),
                ]);
                $results[] = [
                    'billing_cycle' => $pricing->billingCycle?->slug ?? '?',
                    'error'         => $e->getMessage(),
                    'status'        => 'failed',
                ];
            }
        }

        // Edge case: plan has no plan_pricing rows — create a monthly price from base_price
        if ($pricings->isEmpty() && $plan->base_price > 0 && ! $plan->stripe_price_id) {
            try {
                $stripePrice = StripePrice::create([
                    'product'     => $stripeProductId,
                    'unit_amount' => (int) round((float) $plan->base_price * 100),
                    'currency'    => self::CURRENCY,
                    'recurring'   => ['interval' => 'month', 'interval_count' => 1],
                    'metadata'    => ['billing_cycle' => 'monthly', 'source' => 'base_price'],
                ]);

                $plan->update(['stripe_price_id' => $stripePrice->id]);

                $results[] = [
                    'billing_cycle'   => 'monthly (base_price fallback)',
                    'price'           => $plan->base_price,
                    'stripe_price_id' => $stripePrice->id,
                    'status'          => 'created',
                ];
            } catch (ApiErrorException $e) {
                $results[] = ['billing_cycle' => 'monthly', 'error' => $e->getMessage(), 'status' => 'failed'];
            }
        }

        // If still no stripe_price_id on plan, use the first synced price
        $plan->refresh();
        if (! $plan->stripe_price_id) {
            $first = $plan->pricing()->whereNotNull('stripe_price_id')->first();
            if ($first) {
                $plan->update(['stripe_price_id' => $first->stripe_price_id]);
            }
        }

        return $results;
    }

    /**
     * Resolve the correct Stripe Price ID for a given plan + billing cycle slug.
     * Used by ServiceContractingService when creating subscriptions.
     */
    public function resolvePriceId(ServicePlan $plan, string $billingCycleSlug): ?string
    {
        $pricing = $plan->pricing()
            ->whereHas('billingCycle', fn($q) => $q->where('slug', $billingCycleSlug))
            ->first();

        if ($pricing?->stripe_price_id) {
            return $pricing->stripe_price_id;
        }

        // Fallback to plan-level price
        return $plan->stripe_price_id ?: null;
    }

    // ── Internal ─────────────────────────────────────────────────────────────

    /**
     * Build and create a Stripe Price for a given PlanPricing row.
     */
    private function createStripePrice(string $stripeProductId, PlanPricing $pricing): StripePrice
    {
        $cycleSlug = $pricing->billingCycle?->slug ?? 'monthly';
        $amountCents = (int) round((float) $pricing->price * 100);

        $params = [
            'product'     => $stripeProductId,
            'unit_amount' => $amountCents,
            'currency'    => self::CURRENCY,
            'metadata'    => [
                'plan_pricing_id'   => (string) $pricing->id,
                'billing_cycle'     => $cycleSlug,
                'billing_cycle_id'  => (string) $pricing->billing_cycle_id,
            ],
        ];

        if ($cycleSlug === 'one_time') {
            // One-time payment — no recurring
        } else {
            $params['recurring'] = $this->intervalFor($cycleSlug);
        }

        return StripePrice::create($params);
    }

    /**
     * Map our billing cycle slug to a Stripe recurring interval object.
     */
    private function intervalFor(string $cycleSlug): array
    {
        return match ($cycleSlug) {
            'monthly'       => ['interval' => 'month', 'interval_count' => 1],
            'quarterly'     => ['interval' => 'month', 'interval_count' => 3],
            'semi_annually' => ['interval' => 'month', 'interval_count' => 6],
            'annually'      => ['interval' => 'year',  'interval_count' => 1],
            default         => ['interval' => 'month', 'interval_count' => 1],
        };
    }
}
