<?php

namespace App\Domains\Pet\Services;

use App\Domains\Pet\Models\PetPlan;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;
use Stripe\Price as StripePrice;
use Stripe\Product as StripeProduct;
use Stripe\Stripe;

/**
 * Sincroniza PetPlan con Stripe Products/Prices.
 *
 * Si un plan no tiene stripe_product_id → crea el Product en Stripe y guarda el ID.
 * Si un plan no tiene stripe_price_monthly/yearly → crea el Price y guarda el ID.
 *
 * Así nunca se necesita configurar IDs manualmente en .env ni en la DB.
 */
class PetStripeSyncService
{
    private const CURRENCY = 'mxn';

    public function __construct()
    {
        Stripe::setApiKey(config('services.stripe.secret'));
    }

    /**
     * Devuelve el Stripe Price ID para el plan y periodo dados.
     * Crea el Product y/o Price en Stripe si no existen todavía.
     *
     * @throws ApiErrorException
     */
    public function ensurePrice(PetPlan $plan, string $billing = 'monthly'): string
    {
        $stripeProductId = $this->ensureProduct($plan);

        $priceField = $billing === 'yearly' ? 'stripe_price_yearly' : 'stripe_price_monthly';
        $existing   = $plan->$priceField;

        if ($existing) {
            // Verificar que el Price aún existe en Stripe
            try {
                StripePrice::retrieve($existing);
                return $existing;
            } catch (ApiErrorException) {
                Log::warning("PetStripeSyncService: price {$existing} not found on Stripe, recreating.");
            }
        }

        $priceId = $this->createPrice($stripeProductId, $plan, $billing);
        $plan->update([$priceField => $priceId]);

        Log::info("PetStripeSyncService: created Price {$priceId} for plan '{$plan->name}' ({$billing})");

        return $priceId;
    }

    // ── Private ──────────────────────────────────────────────────────────────

    private function ensureProduct(PetPlan $plan): string
    {
        if ($plan->stripe_product_id) {
            try {
                StripeProduct::retrieve($plan->stripe_product_id);
                return $plan->stripe_product_id;
            } catch (ApiErrorException) {
                Log::warning("PetStripeSyncService: product {$plan->stripe_product_id} not found on Stripe, recreating.");
            }
        }

        $product = StripeProduct::create([
            'name'        => "roke.pet — {$plan->name}",
            'description' => $plan->description ?? $plan->name,
            'active'      => (bool) $plan->is_active,
            'metadata'    => [
                'pet_plan_id'   => $plan->id,
                'pet_plan_slug' => $plan->slug,
                'source'        => 'roke.pet',
            ],
        ]);

        $plan->update(['stripe_product_id' => $product->id]);

        Log::info("PetStripeSyncService: created Product {$product->id} for plan '{$plan->name}'");

        return $product->id;
    }

    private function createPrice(string $stripeProductId, PetPlan $plan, string $billing): string
    {
        $amount = $billing === 'yearly'
            ? (float) ($plan->price_yearly ?? $plan->price_monthly * 10)
            : (float) $plan->price_monthly;

        $params = [
            'product'     => $stripeProductId,
            'unit_amount' => (int) round($amount * 100),
            'currency'    => self::CURRENCY,
            'recurring'   => $billing === 'yearly'
                ? ['interval' => 'year',  'interval_count' => 1]
                : ['interval' => 'month', 'interval_count' => 1],
            'metadata'    => [
                'pet_plan_slug' => $plan->slug,
                'billing'       => $billing,
                'source'        => 'roke.pet',
            ],
        ];

        return StripePrice::create($params)->id;
    }
}
