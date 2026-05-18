<?php

namespace App\Http\Controllers\RokePet;

use App\Http\Controllers\Controller;
use App\Models\RokePet\OwnerSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BillingController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $sub = OwnerSubscription::where('owner_id', $request->user()->uuid)->first();

        if (!$sub) {
            return response()->json(null);
        }

        return response()->json($this->format($sub));
    }

    public function upsert(Request $request): JsonResponse
    {
        $data = $request->validate([
            'planCode'               => 'sometimes|string',
            'status'                 => 'sometimes|in:trialing,active,past_due,canceled,incomplete',
            'provider'               => 'sometimes|string',
            'checkoutUrl'            => 'sometimes|nullable|string',
            'stripeCustomerId'       => 'sometimes|nullable|string',
            'stripeSubscriptionId'   => 'sometimes|nullable|string',
            'stripeCheckoutSessionId'=> 'sometimes|nullable|string',
            'stripePriceId'          => 'sometimes|nullable|string',
            'trialEndsAt'            => 'sometimes|nullable|date',
            'currentPeriodEnd'       => 'sometimes|nullable|date',
            'supportNotes'           => 'sometimes|nullable|string',
        ]);

        $sub = OwnerSubscription::updateOrCreate(
            ['owner_id' => $request->user()->uuid],
            array_filter([
                'plan_code'                  => $data['planCode'] ?? null,
                'status'                     => $data['status'] ?? null,
                'provider'                   => $data['provider'] ?? null,
                'checkout_url'               => $data['checkoutUrl'] ?? null,
                'stripe_customer_id'         => $data['stripeCustomerId'] ?? null,
                'stripe_subscription_id'     => $data['stripeSubscriptionId'] ?? null,
                'stripe_checkout_session_id' => $data['stripeCheckoutSessionId'] ?? null,
                'stripe_price_id'            => $data['stripePriceId'] ?? null,
                'trial_ends_at'              => $data['trialEndsAt'] ?? null,
                'current_period_end'         => $data['currentPeriodEnd'] ?? null,
                'support_notes'              => $data['supportNotes'] ?? null,
            ], fn($v) => $v !== null)
        );

        return response()->json($this->format($sub->fresh()));
    }

    private function format(OwnerSubscription $sub): array
    {
        return [
            'ownerId'                => $sub->owner_id,
            'planCode'               => $sub->plan_code,
            'status'                 => $sub->status,
            'provider'               => $sub->provider,
            'checkoutUrl'            => $sub->checkout_url,
            'stripeCustomerId'       => $sub->stripe_customer_id,
            'stripeSubscriptionId'   => $sub->stripe_subscription_id,
            'stripeCheckoutSessionId'=> $sub->stripe_checkout_session_id,
            'stripePriceId'          => $sub->stripe_price_id,
            'trialEndsAt'            => $sub->trial_ends_at,
            'currentPeriodEnd'       => $sub->current_period_end,
            'supportNotes'           => $sub->support_notes ?? '',
            'updatedAt'              => $sub->updated_at,
        ];
    }
}
