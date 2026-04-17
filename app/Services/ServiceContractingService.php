<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PaymentMethod;
use App\Models\Service;
use App\Models\ServiceAddOn;
use App\Models\ServiceInvoice;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\User;
use App\Models\ServicePlan;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Stripe\PaymentIntent as StripePaymentIntent;
use Stripe\PaymentMethod as StripePaymentMethod;

class ServiceContractingService
{
    public function __construct(private readonly InvoiceService $invoiceService)
    {
        \Stripe\Stripe::setApiKey(config('services.stripe.secret'));
    }

    // ──────────────────────────────────────────────
    // Main Contract Flow
    // ──────────────────────────────────────────────

    /**
     * Contract a service, charge via Stripe, persist all related records.
     *
     * Supports two flows:
     *   Flow A — frontend already confirmed the PaymentIntent → pass payment_intent_id
     *   Flow B — saved payment method → pass payment_method_id, PI created here
     *
     * @throws \Stripe\Exception\CardException   Card declined
     * @throws \RuntimeException                 Business-rule violations
     */
    public function contract(User $user, ServicePlan $plan, array $validated): array
    {
        // ── Pricing ─────────────────────────────────────────────────────
        $multiplier = match ($validated['billing_cycle']) {
            'monthly'       => 1,
            'quarterly'     => 3,
            'semi_annually' => 6,
            'annually'      => 12,
            default         => 1,
        };

        $selectedAddOns = $this->resolveAddOns($plan, $validated['add_ons'] ?? []);

        $planNet    = round((float) $plan->base_price * $multiplier, 2);
        $addonsNet  = round($selectedAddOns->sum(fn($a) => (float) $a->price) * $multiplier, 2);
        $subtotal   = round($planNet + $addonsNet, 2);

        $taxRatePct  = (float) config('billing.tax_rate_percent', 16.00);
        $taxAmount   = round($subtotal * $taxRatePct / 100, 2);
        $total       = round($subtotal + $taxAmount, 2);
        $currency    = $plan->currency ? strtoupper($plan->currency) : 'MXN';
        $amountCents = (int) round($total * 100);

        $nextBillingDate = now()->addMonths($multiplier);

        // ── Stripe ───────────────────────────────────────────────────────
        $customerId           = $user->stripe_customer_id;
        $localPaymentMethodId = $this->resolveLocalPaymentMethodId($user, $validated);

        [$pi, $usedStripePmId, $pmObject] = !empty($validated['payment_intent_id'])
            ? $this->handleFlowA($validated['payment_intent_id'])
            : $this->handleFlowB($validated['payment_method_id'], $amountCents, $currency, $customerId);

        // Map stripe PM to local record if not yet resolved
        if (!$localPaymentMethodId && $usedStripePmId) {
            $local = PaymentMethod::where('user_id', $user->id)
                ->where('stripe_payment_method_id', $usedStripePmId)
                ->first();
            $localPaymentMethodId = $local?->id;
        }

        $cardMeta = $this->extractCardMeta($pmObject);

        // ── Persistence (all-or-nothing) ─────────────────────────────────
        // IMPORTANTE: ninguna llamada a Stripe debe ir dentro de este transaction.
        // Si una API externa falla dentro de un transaction, el rollback borraría
        // el servicio y la factura aunque el cobro ya se realizó.
        $result = DB::transaction(function () use (
            $user, $plan, $validated, $selectedAddOns,
            $multiplier, $planNet, $addonsNet, $subtotal,
            $taxRatePct, $taxAmount, $total, $currency,
            $amountCents, $nextBillingDate, $customerId,
            $localPaymentMethodId, $pi, $usedStripePmId, $cardMeta
        ) {
            $paymentIntentId = $pi->id ?? $validated['payment_intent_id'];

            // 1) Service
            $service = Service::create([
                'plan_id'           => $plan->id,
                'user_id'           => $user->id,
                'price'             => $plan->base_price,
                'name'              => $validated['service_name'],
                'status'            => 'active',
                'billing_cycle'     => $validated['billing_cycle'],
                'domain'            => $validated['domain'] ?? null,
                'payment_intent_id' => $paymentIntentId,
                'configuration'     => $validated['additional_options'] ?? null,
                'next_due_date'     => $nextBillingDate,
            ]);

            // 1.1) Add-on snapshots
            foreach ($selectedAddOns as $addOn) {
                ServiceAddOn::create([
                    'service_id'  => $service->id,
                    'add_on_id'   => $addOn->id,
                    'add_on_uuid' => $addOn->uuid,
                    'name'        => $addOn->name,
                    'unit_price'  => $addOn->price,
                    'quantity'    => 1,
                ]);
            }

            // 2) Datos fiscales / CFDI
            // Si el cliente proporciona sus datos → cfdi_status = pending_stamp (listo para timbrar)
            // Si NO los proporciona        → cfdi_status = scheduled con stamp_scheduled_at = +72 h
            //                                Se timbrará automáticamente como "Público en General"
            if (!empty($validated['invoice'])) {
                ServiceInvoice::create([
                    'service_id'         => $service->id,
                    'rfc'                => strtoupper(trim($validated['invoice']['rfc'])),
                    'name'               => strtoupper(trim($validated['invoice']['name'])),
                    'zip'                => $validated['invoice']['zip'],
                    'regimen'            => $validated['invoice']['regimen'],
                    'uso_cfdi'           => $validated['invoice']['uso_cfdi'],
                    'constancia'         => $validated['invoice']['constancia'] ?? null,
                    'cfdi_status'        => \App\Models\ServiceInvoice::CFDI_PENDING_STAMP,
                    'is_publico_general' => false,
                ]);
            } else {
                // Público en General: se timbrará en 72 horas si el cliente no actualiza sus datos
                ServiceInvoice::create(
                    \App\Models\ServiceInvoice::publicoGeneralDefaults($service->id)
                );
            }

            // 3) Invoice
            $invoice = $this->invoiceService->createWithItems(
                [
                    'user_id'             => $user->id,
                    'service_id'          => $service->id,
                    'status'              => 'paid',
                    'due_date'            => now(),
                    'paid_at'             => now(),
                    'payment_method'      => 'stripe',
                    'payment_reference'   => $paymentIntentId,
                    'notes'               => 'Pago por contratación de servicio',
                    'currency'            => $currency,
                    'subtotal'            => $subtotal,
                    'tax_rate'            => $taxRatePct,
                    'tax_amount'          => $taxAmount,
                    'total'               => $total,
                ],
                array_merge(
                    [[
                        'service_id'  => $service->id,
                        'description' => sprintf('%s (%s)', $plan->name, strtoupper($validated['billing_cycle'])),
                        'quantity'    => 1,
                        'unit_price'  => $planNet,
                    ]],
                    $selectedAddOns->map(fn($a) => [
                        'service_id'  => $service->id,
                        'description' => $a->name,
                        'quantity'    => 1,
                        'unit_price'  => round((float) $a->price * $multiplier, 2),
                    ])->values()->all()
                )
            );

            // 4) Transaction
            Transaction::create([
                'uuid'                    => (string) Str::uuid(),
                'user_id'                 => $user->id,
                'invoice_id'              => $invoice->id,
                'payment_method_id'       => $localPaymentMethodId,
                'transaction_id'          => 'TRX-' . Str::upper(Str::random(10)),
                'provider_transaction_id' => $paymentIntentId,
                'type'                    => 'payment',
                'status'                  => 'completed',
                'amount'                  => $total,
                'currency'                => $currency,
                'fee_amount'              => 0,
                'provider'                => 'stripe',
                'provider_data'           => [
                    'stripe' => [
                        'payment_intent_id' => $paymentIntentId,
                        'payment_method_id' => $usedStripePmId,
                        'status'            => $pi->status ?? 'succeeded',
                        'card'              => $cardMeta,
                    ],
                    'note' => $localPaymentMethodId
                        ? 'Pago con método guardado del cliente.'
                        : 'Pago con tarjeta no guardada (one-off).',
                ],
                'description'  => 'Pago de contratación de servicio',
                'failure_reason' => null,
                'processed_at' => now(),
            ]);

            ActivityLog::record(
                'Pago de servicio',
                $plan->name,
                'payment',
                ['invoice_id' => $invoice->id, 'service_id' => $service->id, 'amount' => $total, 'currency' => $currency],
                $user->id
            );

            // 5) Post-commit: broadcasts y notificaciones
            // La suscripción de Stripe se maneja FUERA del transaction (ver abajo)
            DB::afterCommit(function () use ($user, $invoice, $service, $total) {
                broadcast(new \App\Events\ServicePurchased($service->fresh('user'), (float) $total));
                broadcast(new \App\Events\InvoiceGenerated($invoice));
                \Illuminate\Support\Facades\Notification::send($user, new \App\Notifications\ServiceNotification([
                    'title'   => 'Compra realizada',
                    'message' => "¡Gracias por tu compra! Tu servicio '{$service->name}' ha sido adquirido exitosamente.",
                    'type'    => 'service.purchased',
                    'data'    => ['service_id' => $service->uuid ?? $service->id, 'amount' => $total],
                ]));
            });

            return compact('service', 'invoice');
        });

        // ── 6) Suscripción Stripe — FUERA del transaction ────────────────────
        // CRÍTICO: las llamadas a APIs externas nunca deben estar dentro de un
        // DB::transaction(). Si fallan, el rollback borraría el servicio y la
        // factura aunque el cobro ya se realizó → el cliente pagaría sin recibir nada.
        //
        // Aquí si falla simplemente lo logueamos: el pago ya fue procesado y el
        // servicio ya existe. La suscripción se puede configurar manualmente después.
        if (! empty($validated['create_subscription'])) {
            // one_time billing cycles don't create recurring subscriptions
            if (($validated['billing_cycle'] ?? '') === 'one_time') {
                \Illuminate\Support\Facades\Log::info('create_subscription ignorado: ciclo one_time no genera suscripción recurrente.', [
                    'plan_id'    => $plan->id,
                    'service_id' => $result['service']->id,
                ]);
            } else {
                // createStripeSubscription resolves the correct stripe_price_id per cycle
                // and auto-syncs the plan to Stripe if it has no price yet.
                try {
                    $this->createStripeSubscription(
                        $user, $plan, $result['service'],
                        $customerId, $total, $currency,
                        $validated['billing_cycle']
                    );
                } catch (\Throwable $e) {
                    // No relanzamos: el servicio y la factura ya están persistidos.
                    // El administrador puede crear la suscripción manualmente.
                    \Illuminate\Support\Facades\Log::error('Error al crear suscripción Stripe (no fatal)', [
                        'plan_id'    => $plan->id,
                        'service_id' => $result['service']->id,
                        'error'      => $e->getMessage(),
                    ]);
                }
            }
        }

        return $result;
    }

    // ──────────────────────────────────────────────
    // Private Helpers
    // ──────────────────────────────────────────────

    /**
     * Validate and return the add-ons permitted by the plan.
     *
     * @throws \RuntimeException
     */
    private function resolveAddOns(ServicePlan $plan, array $requestedUuids): \Illuminate\Support\Collection
    {
        $requested    = collect($requestedUuids);
        $allowed      = $plan->addOns()->where('is_active', true)->get();
        $selectedAddOns = $allowed->whereIn('uuid', $requested);

        if ($requested->isNotEmpty() && $selectedAddOns->count() !== $requested->count()) {
            throw new \RuntimeException('Algunos add-ons no existen o no están permitidos por este plan.');
        }

        return $selectedAddOns;
    }

    /**
     * Resolve the local PaymentMethod ID from the request value (numeric ID or pm_... string).
     */
    private function resolveLocalPaymentMethodId(User $user, array $validated): ?int
    {
        $provided = $validated['payment_method_id'] ?? null;
        if (!$provided) {
            return null;
        }

        if (is_numeric($provided)) {
            return PaymentMethod::where('user_id', $user->id)->find((int) $provided)?->id;
        }

        if (str_starts_with((string) $provided, 'pm_')) {
            return PaymentMethod::where('user_id', $user->id)
                ->where('stripe_payment_method_id', $provided)
                ->first()?->id;
        }

        return null;
    }

    /**
     * Flow A: the frontend already confirmed the PaymentIntent.
     * Retrieve it from Stripe and return [pi, stripe_pm_id, pm_object].
     */
    private function handleFlowA(string $paymentIntentId): array
    {
        $pi = StripePaymentIntent::retrieve(['id' => $paymentIntentId, 'expand' => ['payment_method']]);

        if (is_string($pi->payment_method)) {
            $pmObject       = StripePaymentMethod::retrieve($pi->payment_method);
            $usedStripePmId = $pi->payment_method;
        } else {
            $pmObject       = $pi->payment_method;
            $usedStripePmId = $pmObject?->id;
        }

        return [$pi, $usedStripePmId, $pmObject];
    }

    /**
     * Flow B: saved payment method → create and confirm a PaymentIntent here.
     * Returns [pi, stripe_pm_id, pm_object].
     *
     * @throws \RuntimeException
     * @throws \Stripe\Exception\CardException
     */
    private function handleFlowB(string $pmId, int $amountCents, string $currency, ?string $customerId): array
    {
        $pmObject     = StripePaymentMethod::retrieve($pmId);
        $pmCustomerId = $pmObject->customer;

        if ($pmCustomerId && $customerId && $pmCustomerId !== $customerId) {
            throw new \RuntimeException('El método de pago no pertenece a este usuario.');
        }

        if (!$pmCustomerId) {
            if (!$customerId) {
                throw new \RuntimeException('No se pudo determinar el cliente de Stripe.');
            }
            StripePaymentMethod::attach($pmId, ['customer' => $customerId]);
        }

        $pi = StripePaymentIntent::create([
            'amount'         => $amountCents,
            'currency'       => strtolower($currency),
            'customer'       => $customerId ?? $pmObject->customer,
            'payment_method' => $pmId,
            'confirm'        => true,
            'off_session'    => true,
            'description'    => 'Pago de contratación de servicio',
        ]);

        // If 3DS authentication is required, surface client_secret to the frontend
        if ($pi->status === 'requires_action') {
            throw new \App\Exceptions\PaymentRequiresActionException($pi->client_secret);
        }

        // Reload with PM expanded
        $pi = StripePaymentIntent::retrieve(['id' => $pi->id, 'expand' => ['payment_method']]);

        if (is_string($pi->payment_method)) {
            $pmObject       = StripePaymentMethod::retrieve($pi->payment_method);
            $usedStripePmId = $pi->payment_method;
        } else {
            $pmObject       = $pi->payment_method;
            $usedStripePmId = $pmObject?->id;
        }

        return [$pi, $usedStripePmId, $pmObject];
    }

    private function extractCardMeta(mixed $pmObject): ?array
    {
        if (!$pmObject || $pmObject->type !== 'card' || !isset($pmObject->card)) {
            return null;
        }

        $c = $pmObject->card;

        return [
            'brand'     => $c->brand,
            'last4'     => $c->last4,
            'exp_month' => $c->exp_month,
            'exp_year'  => $c->exp_year,
            'funding'   => $c->funding,
            'country'   => $c->country ?? null,
        ];
    }

    private function createStripeSubscription(
        User $user, ServicePlan $plan, Service $service,
        ?string $customerId, float $total, string $currency, string $billingCycle
    ): void {
        // Resolve the Stripe Price for the selected billing cycle.
        // Priority: plan_pricing.stripe_price_id → service_plans.stripe_price_id (fallback).
        // If neither exists, auto-sync the plan to Stripe first.
        $stripePriceId = app(\App\Services\StripeSyncService::class)->resolvePriceId($plan, $billingCycle);

        if (! $stripePriceId) {
            // Try to auto-sync the plan and pick up the price
            \Illuminate\Support\Facades\Log::info("createStripeSubscription: no stripe_price_id for plan #{$plan->id}, attempting auto-sync.");
            app(\App\Services\StripeSyncService::class)->syncPlan($plan->load('pricing.billingCycle'));
            $plan->refresh();
            $stripePriceId = app(\App\Services\StripeSyncService::class)->resolvePriceId($plan, $billingCycle);
        }

        if (! $stripePriceId) {
            throw new \RuntimeException("El plan '{$plan->name}' no tiene stripe_price_id para el ciclo '{$billingCycle}'. Ejecuta: php artisan stripe:sync-plans");
        }

        $stripeSub = \Stripe\Subscription::create([
            'customer'         => $customerId,
            'items'            => [['price' => $stripePriceId]],
            'payment_behavior' => 'default_incomplete',
            'expand'           => ['latest_invoice.payment_intent'],
        ]);

        Subscription::create([
            'uuid'                   => (string) Str::uuid(),
            'user_id'                => $user->id,
            'service_id'             => $service->id,
            'stripe_subscription_id' => $stripeSub->id,
            'stripe_customer_id'     => $customerId,
            'stripe_price_id'        => $stripePriceId,
            'name'                   => $plan->name,
            'status'                 => $stripeSub->status,
            'amount'                 => $total,
            'currency'               => $currency,
            'billing_cycle'          => $billingCycle === 'annually' ? 'yearly' : 'monthly',
            'current_period_start'   => isset($stripeSub->current_period_start) ? Carbon::createFromTimestamp($stripeSub->current_period_start) : null,
            'current_period_end'     => isset($stripeSub->current_period_end)   ? Carbon::createFromTimestamp($stripeSub->current_period_end)   : null,
            'trial_start'            => isset($stripeSub->trial_start)           ? Carbon::createFromTimestamp($stripeSub->trial_start)          : null,
            'trial_end'              => isset($stripeSub->trial_end)             ? Carbon::createFromTimestamp($stripeSub->trial_end)            : null,
            'ends_at'                => isset($stripeSub->current_period_end)   ? Carbon::createFromTimestamp($stripeSub->current_period_end)   : null,
            'created_at'             => Carbon::createFromTimestamp($stripeSub->created),
        ]);

        ActivityLog::record(
            'Suscripción creada',
            "Suscripción para el plan {$plan->name} creada en Stripe.",
            'subscription',
            ['user_id' => $user->id, 'plan_id' => $plan->id, 'stripe_sub_id' => $stripeSub->id],
            $user->id
        );
    }
}
