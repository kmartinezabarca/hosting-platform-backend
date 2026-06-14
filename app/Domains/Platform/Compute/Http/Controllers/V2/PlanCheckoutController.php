<?php

namespace App\Domains\Platform\Compute\Http\Controllers\V2;

use App\Domains\Platform\Compute\Enums\BillingInterval;
use App\Domains\Platform\Compute\Enums\PlanTier;
use App\Domains\Platform\Compute\Enums\TeamRole;
use App\Domains\Platform\Compute\Models\ComputePlan;
use App\Domains\Platform\Compute\Models\Team;
use App\Domains\Platform\Compute\Services\ComputeStripeSyncService;
use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;
use Stripe\StripeClient;

class PlanCheckoutController extends Controller
{
    public function store(Request $request, Team $team, ComputeStripeSyncService $stripeSync): JsonResponse
    {
        $role = $team->roleFor($request->user());
        abort_unless(
            $role && in_array($role, [TeamRole::Owner, TeamRole::Admin, TeamRole::Billing], true),
            403,
            'No tienes permisos de facturación para este equipo.',
        );

        $validated = $request->validate([
            'tier' => ['required', Rule::in(PlanTier::values())],
            'interval' => ['required', Rule::in(BillingInterval::values())],
        ]);

        $tier = PlanTier::from($validated['tier']);
        $interval = BillingInterval::from($validated['interval']);

        $plan = ComputePlan::query()
            ->compute()
            ->where('is_active', true)
            ->where('tier', $tier->value)
            ->first();

        abort_unless($plan, 404, 'Plan no disponible.');

        $amount = $interval === BillingInterval::Annual
            ? $plan->annual_amount
            : $plan->monthly_amount;

        abort_if($amount === null, 422, 'Este plan todavía no tiene precio configurado.');

        $metadata = [
            'source' => 'roke_compute',
            'team_id' => (string) $team->id,
            'team_uuid' => $team->uuid,
            'plan_tier' => $tier->value,
            'billing_interval' => $interval->value,
            'compute_plan_id' => (string) $plan->id,
        ];

        if ((float) $amount <= 0.0) {
            if ($team->stripe_subscription_id) {
                $this->stripe()->subscriptions->cancel($team->stripe_subscription_id);
            }

            $team->update([
                'plan_tier' => $tier,
                'billing_interval' => $interval,
                'billing_status' => 'active',
                'stripe_subscription_id' => null,
                'stripe_checkout_session_id' => null,
                'stripe_price_id' => null,
                'current_period_ends_at' => null,
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'free' => true,
                    'checkout_url' => null,
                    'team' => [
                        'uuid' => $team->uuid,
                        'plan_tier' => $team->plan_tier,
                        'billing_interval' => $team->billing_interval,
                        'billing_status' => $team->billing_status,
                    ],
                ],
            ]);
        }

        $priceId = $stripeSync->ensurePrice($plan, $interval);
        $user = $request->user();
        $stripe = $this->stripe();

        if (! $team->stripe_customer_id) {
            $customer = $stripe->customers->create([
                'email' => $user->email,
                'name' => trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: $user->email,
                'metadata' => $metadata + ['user_id' => (string) $user->id],
            ]);

            $team->forceFill(['stripe_customer_id' => $customer->id])->save();
        }

        if ($team->stripe_subscription_id) {
            $subscription = $stripe->subscriptions->retrieve($team->stripe_subscription_id);
            $itemId = $subscription->items->data[0]->id ?? null;

            abort_unless($itemId, 422, 'No se pudo localizar el plan activo en Stripe.');

            $updated = $stripe->subscriptions->update($team->stripe_subscription_id, [
                'items' => [[
                    'id' => $itemId,
                    'price' => $priceId,
                ]],
                'proration_behavior' => 'create_prorations',
                'metadata' => $metadata,
            ]);

            $team->update([
                'plan_tier' => $tier,
                'billing_interval' => $interval,
                'billing_status' => $updated->status ?? 'active',
                'stripe_price_id' => $priceId,
                'current_period_ends_at' => isset($updated->current_period_end)
                    ? Carbon::createFromTimestamp((int) $updated->current_period_end)
                    : $team->current_period_ends_at,
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'free' => false,
                    'checkout_url' => null,
                    'subscription_updated' => true,
                    'team' => [
                        'uuid' => $team->uuid,
                        'plan_tier' => $team->plan_tier,
                        'billing_interval' => $team->billing_interval,
                        'billing_status' => $team->billing_status,
                    ],
                ],
            ]);
        }

        $frontend = rtrim((string) config('app.frontend_url', 'http://localhost:5173'), '/');
        $returnUrl = "{$frontend}/client/teams/{$team->uuid}";

        $session = $stripe->checkout->sessions->create([
            'customer' => $team->stripe_customer_id,
            'mode' => 'subscription',
            'line_items' => [[
                'price' => $priceId,
                'quantity' => 1,
            ]],
            'subscription_data' => [
                'metadata' => $metadata,
            ],
            'success_url' => "{$returnUrl}?checkout=success&session_id={CHECKOUT_SESSION_ID}",
            'cancel_url' => "{$returnUrl}?checkout=cancelled",
            'metadata' => $metadata,
        ]);

        $team->update([
            'billing_status' => 'checkout_pending',
            'stripe_checkout_session_id' => $session->id,
            'stripe_price_id' => $priceId,
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'free' => false,
                'checkout_url' => $session->url,
                'session_id' => $session->id,
            ],
        ], 201);
    }

    private function stripe(): StripeClient
    {
        $secret = config('services.stripe.secret');

        if (! is_string($secret) || $secret === '') {
            throw new RuntimeException('stripe_secret_missing');
        }

        return new StripeClient($secret);
    }
}
