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

    /**
     * Banners de estado de facturación para el dashboard del dueño.
     *
     * El frontend consume GET /api/rp/billing/banners y muestra avisos claros:
     * cobro fallido + días de gracia, trial por vencer, plan que no se renueva.
     */
    public function banners(Request $request): JsonResponse
    {
        $sub      = OwnerSubscription::where('owner_id', $request->user()->uuid)->first();
        $banners  = [];

        if (!$sub) {
            return response()->json(['banners' => []]);
        }

        // ── Cobro fallido en periodo de gracia ────────────────────────────────
        if ($sub->status === 'past_due' && $sub->grace_period_ends_at) {
            $daysLeft = max(0, (int) ceil(now()->diffInHours($sub->grace_period_ends_at, false) / 24));
            $banners[] = [
                'type'              => 'payment_failed',
                'severity'          => 'warning',
                'title'             => 'Tu pago no pudo procesarse',
                'message'           => "Tienes {$daysLeft} día(s) para actualizar tu método de pago antes de que tu cuenta pase al plan gratuito.",
                'daysLeft'          => $daysLeft,
                'gracePeriodEndsAt' => $sub->grace_period_ends_at->toIso8601String(),
                'action'            => ['label' => 'Actualizar pago', 'route' => '/billing'],
            ];
        }

        // ── Trial por vencer (≤ 3 días) ───────────────────────────────────────
        if ($sub->status === 'trialing' && $sub->trial_ends_at) {
            $daysLeft = (int) ceil(now()->diffInHours($sub->trial_ends_at, false) / 24);
            if ($daysLeft >= 0 && $daysLeft <= 3) {
                $banners[] = [
                    'type'        => 'trial_expiring',
                    'severity'    => 'info',
                    'title'       => 'Tu prueba está por terminar',
                    'message'     => "Tu período de prueba vence en {$daysLeft} día(s). Activa tu plan para no perder el acceso premium.",
                    'daysLeft'    => $daysLeft,
                    'expiresAt'   => $sub->trial_ends_at->toIso8601String(),
                    'action'      => ['label' => 'Activar plan', 'route' => '/billing'],
                ];
            }
        }

        // ── Suscripción que no se renovará (≤ 7 días) ─────────────────────────
        if ($sub->status === 'active' && $sub->cancel_at_period_end && $sub->current_period_end) {
            $daysLeft = (int) ceil(now()->diffInHours($sub->current_period_end, false) / 24);
            if ($daysLeft >= 0 && $daysLeft <= 7) {
                $banners[] = [
                    'type'        => 'subscription_expiring',
                    'severity'    => 'warning',
                    'title'       => 'Tu suscripción vence pronto',
                    'message'     => "Tu plan termina en {$daysLeft} día(s) y no se renovará. Reactívalo para mantener tus funciones premium.",
                    'daysLeft'    => $daysLeft,
                    'expiresAt'   => $sub->current_period_end->toIso8601String(),
                    'action'      => ['label' => 'Reactivar plan', 'route' => '/billing'],
                ];
            }
        }

        return response()->json(['banners' => $banners]);
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
            'gracePeriodEndsAt'      => $sub->grace_period_ends_at,
            'paymentFailedAt'        => $sub->payment_failed_at,
            'supportNotes'           => $sub->support_notes ?? '',
            'updatedAt'              => $sub->updated_at,
        ];
    }
}
