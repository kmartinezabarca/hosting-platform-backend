<?php

namespace App\Domains\Platform\Compute\Http\Controllers\Admin;

use App\Domains\Platform\Compute\Models\ComputePlan;
use App\Domains\Platform\Compute\Services\ComputeStripeSyncService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ComputePlanController extends Controller
{
    public function index(): JsonResponse
    {
        $plans = ComputePlan::query()
            ->compute()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (ComputePlan $plan) => $this->transform($plan));

        return response()->json([
            'success' => true,
            'data' => $plans,
        ]);
    }

    public function update(Request $request, ComputePlan $plan): JsonResponse
    {
        abort_unless($plan->kind === 'compute', 404);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:180'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:999'],
            'currency' => ['sometimes', 'required', 'string', 'size:3'],
            'monthly_amount' => ['nullable', 'numeric', 'min:0'],
            'annual_amount' => ['nullable', 'numeric', 'min:0'],
            'stripe_product_id' => ['nullable', 'string', 'max:255'],
            'stripe_monthly_price_id' => ['nullable', 'string', 'max:255'],
            'stripe_annual_price_id' => ['nullable', 'string', 'max:255'],
            'max_resources' => ['nullable', 'integer', 'min:0'],
            'ram_mb_max' => ['nullable', 'integer', 'min:0'],
            'max_members' => ['nullable', 'integer', 'min:0'],
            'features' => ['nullable', 'array'],
            'features.*' => ['string', 'max:160'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('currency', $data)) {
            $data['currency'] = strtoupper($data['currency']);
        }

        $plan->update($data);

        return response()->json([
            'success' => true,
            'data' => $this->transform($plan->fresh()),
        ]);
    }

    public function syncStripe(ComputePlan $plan, ComputeStripeSyncService $stripeSync): JsonResponse
    {
        abort_unless($plan->kind === 'compute', 404);

        if ($plan->monthly_amount !== null && (float) $plan->monthly_amount > 0) {
            $stripeSync->ensurePrice($plan, \App\Domains\Platform\Compute\Enums\BillingInterval::Monthly);
        }

        if ($plan->annual_amount !== null && (float) $plan->annual_amount > 0) {
            $stripeSync->ensurePrice($plan->fresh(), \App\Domains\Platform\Compute\Enums\BillingInterval::Annual);
        }

        return response()->json([
            'success' => true,
            'data' => $this->transform($plan->fresh()),
        ]);
    }

    private function transform(ComputePlan $plan): array
    {
        return [
            'id' => $plan->id,
            'kind' => $plan->kind,
            'tier' => $plan->tier,
            'name' => $plan->name,
            'description' => $plan->description,
            'sort_order' => $plan->sort_order,
            'currency' => $plan->currency,
            'monthly_amount' => $plan->monthly_amount === null ? null : (float) $plan->monthly_amount,
            'annual_amount' => $plan->annual_amount === null ? null : (float) $plan->annual_amount,
            'stripe_product_id' => $plan->stripe_product_id,
            'stripe_monthly_price_id' => $plan->stripe_monthly_price_id,
            'stripe_annual_price_id' => $plan->stripe_annual_price_id,
            'max_resources' => $plan->max_resources,
            'ram_mb_max' => $plan->ram_mb_max,
            'max_members' => $plan->max_members,
            'features' => $plan->features ?? [],
            'is_active' => (bool) $plan->is_active,
            'created_at' => $plan->created_at?->toIso8601String(),
            'updated_at' => $plan->updated_at?->toIso8601String(),
        ];
    }
}
