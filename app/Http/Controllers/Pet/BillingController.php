<?php

namespace App\Http\Controllers\Pet;

use App\Http\Controllers\Controller;
use App\Models\Pet\OwnerSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BillingController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $sub = OwnerSubscription::where('owner_id', $request->user()->uuid)->first();
        return response()->json($sub ? $this->format($sub) : null);
    }

    public function upsert(Request $request): JsonResponse
    {
        // SEGURIDAD: el estado de la suscripción (status, plan_code, IDs de Stripe,
        // fechas de periodo) SOLO puede mutarlo el webhook de Stripe, verificado por
        // firma. Si se aceptaran del cliente, cualquier dueño autenticado podría
        // auto-otorgarse un plan de pago activo sin pagar. Aquí únicamente se permite
        // actualizar datos NO sensibles (email de facturación).
        $data = $request->validate([
            'billingEmail' => 'sometimes|nullable|email',
        ]);

        $sub = OwnerSubscription::updateOrCreate(
            ['owner_id' => $request->user()->uuid],
            array_filter([
                'billing_email' => $data['billingEmail'] ?? null,
            ], fn ($v) => $v !== null)
        );

        return response()->json($this->format($sub->fresh()));
    }

    private function format(OwnerSubscription $sub): array
    {
        return [
            'ownerId'                => $sub->owner_id,
            'planCode'               => $sub->plan_code,
            'status'                 => $sub->status,
            'cancelAtPeriodEnd'      => (bool) $sub->cancel_at_period_end,
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
