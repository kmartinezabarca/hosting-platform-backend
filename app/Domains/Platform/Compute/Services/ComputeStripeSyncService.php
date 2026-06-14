<?php

namespace App\Domains\Platform\Compute\Services;

use App\Domains\Platform\Compute\Enums\BillingInterval;
use App\Domains\Platform\Compute\Models\ComputePlan;
use RuntimeException;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

class ComputeStripeSyncService
{
    private function stripe(): StripeClient
    {
        $secret = config('services.stripe.secret');
        if (! is_string($secret) || $secret === '') {
            throw new RuntimeException('stripe_secret_missing');
        }

        return new StripeClient($secret);
    }

    /**
     * Devuelve un Stripe Price ID para el plan/periodo. Si no existe, crea
     * Product y Price en Stripe y persiste los IDs en DB.
     *
     * @throws ApiErrorException|RuntimeException
     */
    public function ensurePrice(ComputePlan $plan, BillingInterval $interval): string
    {
        $productId = $this->ensureProduct($plan);

        $field = $interval === BillingInterval::Annual
            ? 'stripe_annual_price_id'
            : 'stripe_monthly_price_id';

        $existing = $plan->{$field};
        if ($existing) {
            try {
                $this->stripe()->prices->retrieve($existing);
                return $existing;
            } catch (ApiErrorException) {
                // El ID guardado ya no existe en Stripe; se recrea abajo.
            }
        }

        $amount = $interval === BillingInterval::Annual
            ? $plan->annual_amount
            : $plan->monthly_amount;

        if ($amount === null || (float) $amount <= 0) {
            throw new RuntimeException('compute_plan_has_no_paid_price');
        }

        $price = $this->stripe()->prices->create([
            'product' => $productId,
            'unit_amount' => (int) round(((float) $amount) * 100),
            'currency' => strtolower($plan->currency ?: 'MXN'),
            'recurring' => [
                'interval' => $interval === BillingInterval::Annual ? 'year' : 'month',
                'interval_count' => 1,
            ],
            'metadata' => [
                'source' => 'roke_compute',
                'compute_plan_id' => (string) $plan->id,
                'compute_plan_tier' => $plan->tier,
                'billing_interval' => $interval->value,
                'kind' => $plan->kind,
            ],
        ]);

        $plan->forceFill([$field => $price->id])->save();

        return $price->id;
    }

    /**
     * @throws ApiErrorException|RuntimeException
     */
    public function ensureProduct(ComputePlan $plan): string
    {
        if ($plan->stripe_product_id) {
            try {
                $this->stripe()->products->retrieve($plan->stripe_product_id);
                return $plan->stripe_product_id;
            } catch (ApiErrorException) {
                // Se recrea si fue borrado manualmente en Stripe.
            }
        }

        $product = $this->stripe()->products->create([
            'name' => "ROKE Deploy - {$plan->name}",
            'description' => $plan->description ?: "Plan {$plan->name} de ROKE Deploy",
            'active' => (bool) $plan->is_active,
            'metadata' => [
                'source' => 'roke_compute',
                'compute_plan_id' => (string) $plan->id,
                'compute_plan_tier' => $plan->tier,
                'kind' => $plan->kind,
            ],
        ]);

        $plan->forceFill(['stripe_product_id' => $product->id])->save();

        return $product->id;
    }
}
