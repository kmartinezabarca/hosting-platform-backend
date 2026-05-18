<?php

namespace App\Http\Controllers\RokePet;

use App\Http\Controllers\Controller;
use App\Models\RokePet\ActivationEvent;
use App\Models\RokePet\Owner;
use App\Models\RokePet\OwnerSubscription;
use App\Models\RokePet\StripeWebhookEvent;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Stripe\Exception\SignatureVerificationException;
use Stripe\StripeClient;
use Stripe\Webhook;

class StripeController extends Controller
{
    private function stripe(): StripeClient
    {
        return new StripeClient(config('services.stripe.secret'));
    }

    private function frontendUrl(): string
    {
        return config('services.rokepet.frontend_url', 'https://roke.pet');
    }

    // ── Crear sesión de checkout ──────────────────────────────────────────────

    public function createCheckoutSession(Request $request): JsonResponse
    {
        $data = $request->validate([
            'planCode' => 'sometimes|string|in:starter,pro',
        ]);

        $planCode  = $data['planCode'] ?? 'starter';
        $priceId   = $planCode === 'pro'
            ? config('services.rokepet.stripe_price_pro')
            : config('services.rokepet.stripe_price_starter');

        if (!$priceId) {
            return response()->json(['error' => 'Plan no configurado en el servidor'], 500);
        }

        $user  = $request->user();
        $owner = Owner::findOrFail($user->uuid);
        $sub   = OwnerSubscription::firstOrCreate(
            ['owner_id' => $user->uuid],
            ['billing_email' => $user->email]
        );

        $stripe = $this->stripe();

        // Crear o recuperar el customer de Stripe
        if (!$sub->stripe_customer_id) {
            $customer = $stripe->customers->create([
                'email'    => $owner->email ?? $user->email,
                'name'     => $owner->display_name,
                'metadata' => ['owner_id' => $user->uuid],
            ]);
            $sub->update(['stripe_customer_id' => $customer->id]);
        }

        $trialDays = config('services.rokepet.trial_days', 14);

        $session = $stripe->checkout->sessions->create([
            'customer'    => $sub->stripe_customer_id,
            'mode'        => 'subscription',
            'line_items'  => [[
                'price'    => $priceId,
                'quantity' => 1,
            ]],
            'subscription_data' => [
                'trial_period_days' => $trialDays,
                'metadata'          => ['owner_id' => $user->uuid, 'plan_code' => $planCode],
            ],
            'success_url' => $this->frontendUrl() . '/dashboard?checkout=success',
            'cancel_url'  => $this->frontendUrl() . '/pricing?checkout=cancelled',
            'metadata'    => ['owner_id' => $user->uuid, 'plan_code' => $planCode],
        ]);

        $sub->update([
            'stripe_checkout_session_id' => $session->id,
            'plan_code'                  => $planCode,
            'checkout_url'               => $session->url,
        ]);

        return response()->json(['url' => $session->url]);
    }

    // ── Portal de facturación (gestionar suscripción existente) ──────────────

    public function billingPortal(Request $request): JsonResponse
    {
        $sub = OwnerSubscription::where('owner_id', $request->user()->uuid)->firstOrFail();

        if (!$sub->stripe_customer_id) {
            return response()->json(['error' => 'No hay suscripción activa'], 404);
        }

        $session = $this->stripe()->billingPortal->sessions->create([
            'customer'   => $sub->stripe_customer_id,
            'return_url' => $this->frontendUrl() . '/dashboard',
        ]);

        return response()->json(['url' => $session->url]);
    }

    // ── Webhook de Stripe ─────────────────────────────────────────────────────

    public function webhook(Request $request): Response
    {
        $payload   = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');
        $secret    = config('services.stripe.webhook_secret');

        try {
            $event = Webhook::constructEvent($payload, $sigHeader, $secret);
        } catch (SignatureVerificationException) {
            return response('Invalid signature', 400);
        }

        // Idempotencia: ignorar si ya procesamos este evento
        if (StripeWebhookEvent::where('event_id', $event->id)->exists()) {
            return response('Already processed', 200);
        }

        $object   = $event->data->object;
        $ownerId  = $object->metadata->owner_id ?? null;
        $cusId    = $object->customer ?? null;

        // Si no tenemos owner_id en metadata, buscamos por customer_id
        if (!$ownerId && $cusId) {
            $sub     = OwnerSubscription::where('stripe_customer_id', $cusId)->first();
            $ownerId = $sub?->owner_id;
        }

        match ($event->type) {
            'checkout.session.completed'         => $this->onCheckoutCompleted($object, $ownerId),
            'customer.subscription.updated'      => $this->onSubscriptionUpdated($object, $ownerId),
            'customer.subscription.deleted'      => $this->onSubscriptionDeleted($object, $ownerId),
            'invoice.payment_succeeded'          => $this->onInvoicePaid($object, $ownerId),
            'invoice.payment_failed'             => $this->onInvoiceFailed($object, $ownerId),
            default                              => null,
        };

        StripeWebhookEvent::create([
            'event_id'               => $event->id,
            'event_type'             => $event->type,
            'owner_id'               => $ownerId,
            'stripe_customer_id'     => $cusId,
            'stripe_subscription_id' => $object->subscription ?? $object->id ?? null,
            'payload'                => json_decode($payload, true),
            'processed_at'           => now(),
        ]);

        return response('OK', 200);
    }

    // ── Handlers de eventos ───────────────────────────────────────────────────

    private function onCheckoutCompleted(object $session, ?string $ownerId): void
    {
        if (!$ownerId) return;

        $planCode = $session->metadata->plan_code ?? 'starter';

        OwnerSubscription::where('owner_id', $ownerId)->update([
            'status'                     => 'active',
            'plan_code'                  => $planCode,
            'stripe_subscription_id'     => $session->subscription ?? null,
            'stripe_checkout_session_id' => $session->id,
        ]);

        ActivationEvent::create([
            'owner_id'    => $ownerId,
            'event_type'  => 'subscription_activated',
            'source'      => 'billing',
            'metadata'    => ['plan_code' => $planCode, 'session_id' => $session->id],
            'occurred_at' => now(),
        ]);
    }

    private function onSubscriptionUpdated(object $subscription, ?string $ownerId): void
    {
        if (!$ownerId) return;

        $status = match ($subscription->status) {
            'active'   => 'active',
            'trialing' => 'trialing',
            'past_due' => 'past_due',
            'canceled' => 'canceled',
            default    => 'incomplete',
        };

        OwnerSubscription::where('owner_id', $ownerId)->update([
            'status'               => $status,
            'stripe_price_id'      => $subscription->items->data[0]->price->id ?? null,
            'current_period_end'   => date('Y-m-d H:i:s', $subscription->current_period_end),
            'canceled_at'          => $subscription->canceled_at
                ? date('Y-m-d H:i:s', $subscription->canceled_at)
                : null,
        ]);
    }

    private function onSubscriptionDeleted(object $subscription, ?string $ownerId): void
    {
        if (!$ownerId) return;

        OwnerSubscription::where('owner_id', $ownerId)->update([
            'status'      => 'canceled',
            'canceled_at' => now(),
        ]);
    }

    private function onInvoicePaid(object $invoice, ?string $ownerId): void
    {
        if (!$ownerId) return;

        OwnerSubscription::where('owner_id', $ownerId)->update([
            'status'          => 'active',
            'last_invoice_id' => $invoice->id,
            'current_period_end' => isset($invoice->period_end)
                ? date('Y-m-d H:i:s', $invoice->period_end)
                : null,
        ]);
    }

    private function onInvoiceFailed(object $invoice, ?string $ownerId): void
    {
        if (!$ownerId) return;

        OwnerSubscription::where('owner_id', $ownerId)->update([
            'status' => 'past_due',
        ]);
    }
}
