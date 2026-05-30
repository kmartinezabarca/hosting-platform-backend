<?php

namespace App\Http\Controllers\Pet;

use App\Http\Controllers\Controller;
use App\Models\Pet\ActivationEvent;
use App\Models\Pet\Owner;
use App\Models\Pet\OwnerSubscription;
use App\Models\Pet\PetPlan;
use App\Models\Pet\StripeWebhookEvent;
use App\Services\Pet\PetStripeSyncService;
use App\Support\StripeObjectReader;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;
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

    public function createCheckoutSession(Request $request): JsonResponse
    {
        $data     = $request->validate([
            'planCode' => 'sometimes|string',
            'billing'  => 'sometimes|string|in:monthly,yearly',
        ]);
        $planCode = $data['planCode'] ?? 'starter';
        $billing  = $data['billing']  ?? 'monthly';

        $plan = PetPlan::where('slug', $planCode)->where('is_active', true)->first();

        if (!$plan) {
            return response()->json(['error' => 'Plan no encontrado'], 404);
        }

        // ── Plan gratuito: activar directamente sin pasar por Stripe ─────────
        $isFree = ($plan->price_monthly == 0) && empty($plan->stripe_price_monthly);
        if ($isFree) {
            $user = $request->user();
            $sub  = OwnerSubscription::firstOrCreate(
                ['owner_id' => $user->uuid],
                ['billing_email' => $user->email]
            );
            $sub->update([
                'plan_code' => $planCode,
                'status'    => 'active',
            ]);
            ActivationEvent::create([
                'owner_id'    => $user->uuid,
                'event_type'  => 'subscription_activated',
                'source'      => 'billing',
                'metadata'    => ['plan_code' => $planCode, 'method' => 'free'],
                'occurred_at' => now(),
            ]);
            return response()->json(['free' => true]);
        }

        // Auto-crea el Product y Price en Stripe si aún no existen
        try {
            $priceId = (new PetStripeSyncService())->ensurePrice($plan, $billing);
        } catch (ApiErrorException $e) {
            return response()->json(['error' => 'Error al conectar con Stripe: ' . $e->getMessage()], 500);
        }

        $trialDays = $plan->trialDays();

        $user  = $request->user();
        $owner = Owner::findOrFail($user->uuid);
        $sub   = OwnerSubscription::firstOrCreate(
            ['owner_id' => $user->uuid],
            ['billing_email' => $user->email]
        );

        $stripe = $this->stripe();
        if (!$sub->stripe_customer_id) {
            $customer = $stripe->customers->create([
                'email'    => $owner->email ?? $user->email,
                'name'     => $owner->display_name,
                'metadata' => ['owner_id' => $user->uuid],
            ]);
            $sub->update(['stripe_customer_id' => $customer->id]);
        }

        $sessionData = [
            'customer'          => $sub->stripe_customer_id,
            'mode'              => 'subscription',
            'line_items'        => [['price' => $priceId, 'quantity' => 1]],
            'subscription_data' => [
                'metadata' => ['owner_id' => $user->uuid, 'plan_code' => $planCode],
            ],
            'success_url' => $this->frontendUrl() . '/dashboard?checkout=success',
            'cancel_url'  => $this->frontendUrl() . '/pricing?checkout=cancelled',
            'metadata'    => ['owner_id' => $user->uuid, 'plan_code' => $planCode],
        ];

        if ($trialDays > 0) {
            $sessionData['subscription_data']['trial_period_days'] = $trialDays;
        }

        $session = $stripe->checkout->sessions->create($sessionData);

        // Don't update plan_code here — only the webhook (onCheckoutCompleted) may do that
        $sub->update([
            'stripe_checkout_session_id' => $session->id,
            'checkout_url'               => $session->url,
        ]);

        return response()->json(['url' => $session->url]);
    }

    public function getInvoices(Request $request): JsonResponse
    {
        $sub = OwnerSubscription::where('owner_id', $request->user()->uuid)->first();

        if (!$sub?->stripe_customer_id) {
            return response()->json(['invoices' => []]);
        }

        $stripeInvoices = $this->stripe()->invoices->all([
            'customer' => $sub->stripe_customer_id,
            'limit'    => 12,
        ]);

        $invoices = collect($stripeInvoices->data)->map(fn ($inv) => [
            'id'          => $inv->id,
            'number'      => $inv->number,
            'status'      => $inv->status,            // paid | open | void | draft
            'total'       => $inv->total / 100,
            'currency'    => strtoupper($inv->currency),
            'periodStart' => $inv->period_start ? date('Y-m-d', $inv->period_start) : null,
            'periodEnd'   => $inv->period_end   ? date('Y-m-d', $inv->period_end)   : null,
            'paidAt'      => isset($inv->status_transitions->paid_at)
                ? date('Y-m-d', $inv->status_transitions->paid_at) : null,
            'pdfUrl'      => $inv->invoice_pdf,
            'hostedUrl'   => $inv->hosted_invoice_url,
        ])->values();

        return response()->json(['invoices' => $invoices]);
    }

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

    public function webhook(Request $request): Response
    {
        $payload   = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');

        try {
            $event = Webhook::constructEvent($payload, $sigHeader, config('services.stripe.webhook_secret'));
        } catch (SignatureVerificationException) {
            return response('Invalid signature', 400);
        }

        $object  = $event->data->object;
        $ownerId = $object->metadata->owner_id ?? null;
        $cusId   = $object->customer ?? null;

        if (!$ownerId && $cusId) {
            $ownerId = OwnerSubscription::where('stripe_customer_id', $cusId)->value('owner_id');
        }

        // ID de suscripción defensivo: en el API "Basil" (stripe-php v17)
        // invoice.subscription se movió a invoice.parent.subscription_details.
        $subId = $object->subscription
            ?? StripeObjectReader::subscriptionIdFromInvoice($object)
            ?? ($object->id ?? null);

        // ── Idempotencia insert-first (event_id es UNIQUE) ───────────────────
        // Reclama el evento ANTES de procesar. Dos entregas concurrentes: la
        // segunda choca con el unique y se descarta. Evita el doble procesamiento.
        try {
            StripeWebhookEvent::create([
                'event_id'               => $event->id,
                'event_type'             => $event->type,
                'owner_id'               => $ownerId,
                'stripe_customer_id'     => $cusId,
                'stripe_subscription_id' => $subId,
                'payload'                => json_decode($payload, true),
                'processed_at'           => now(),
            ]);
        } catch (QueryException) {
            return response('Already processed', 200);
        }

        try {
            match ($event->type) {
                'checkout.session.completed'    => $this->onCheckoutCompleted($object, $ownerId),
                'customer.subscription.updated' => $this->onSubscriptionUpdated($object, $ownerId),
                'customer.subscription.deleted' => $this->onSubscriptionDeleted($object, $ownerId),
                'invoice.paid',
                'invoice.payment_succeeded'     => $this->onInvoicePaid($object, $ownerId),
                'invoice.payment_failed'        => $this->onInvoiceFailed($object, $ownerId),
                default                         => null,
            };
        } catch (\Throwable $e) {
            // Liberar el registro para permitir el reproceso en el reintento de Stripe.
            StripeWebhookEvent::where('event_id', $event->id)->delete();
            Log::error("Pet Stripe webhook handler error ({$event->type} / {$event->id}): " . $e->getMessage());
            return response('handler error', 500);
        }

        return response('OK', 200);
    }

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
        OwnerSubscription::where('owner_id', $ownerId)->update([
            'status'             => match ($subscription->status) {
                'active'   => 'active', 'trialing' => 'trialing',
                'past_due' => 'past_due', 'canceled' => 'canceled',
                default    => 'incomplete',
            },
            'stripe_price_id'    => $subscription->items->data[0]->price->id ?? null,
            // Basil: current_period_end se movió a items[].current_period_end.
            // StripeObjectReader lee ambos formatos → evita guardar 1970-01-01.
            'current_period_end'   => StripeObjectReader::subscriptionPeriodEnd($subscription),
            'cancel_at_period_end' => (bool) ($subscription->cancel_at_period_end ?? false),
            'canceled_at'          => StripeObjectReader::timestamp($subscription->canceled_at ?? null),
        ]);
    }

    private function onSubscriptionDeleted(object $subscription, ?string $ownerId): void
    {
        if (!$ownerId) return;
        OwnerSubscription::where('owner_id', $ownerId)->update(['status' => 'canceled', 'canceled_at' => now()]);
    }

    private function onInvoicePaid(object $invoice, ?string $ownerId): void
    {
        if (!$ownerId) return;
        OwnerSubscription::where('owner_id', $ownerId)->update([
            'status'             => 'active',
            'last_invoice_id'    => $invoice->id,
            'current_period_end' => StripeObjectReader::periodEndFromInvoice($invoice)
                ?? StripeObjectReader::timestamp($invoice->period_end ?? null),
        ]);
    }

    private function onInvoiceFailed(object $invoice, ?string $ownerId): void
    {
        if (!$ownerId) return;
        OwnerSubscription::where('owner_id', $ownerId)->update(['status' => 'past_due']);

        // #5 — avisar al dueño del cobro fallido (push). No fatal.
        try {
            (new \App\Services\Pet\PushNotificationService())->sendToOwner(
                $ownerId,
                'Problema con tu pago',
                'No pudimos cobrar tu suscripción. Actualiza tu método de pago para no perder el acceso.',
                ['url' => '/dashboard?billing=past_due'],
            );
        } catch (\Throwable $e) {
            Log::warning('Pet: no se pudo notificar pago fallido a ' . $ownerId . ': ' . $e->getMessage());
        }
    }
}
