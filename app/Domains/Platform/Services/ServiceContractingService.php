<?php

namespace App\Domains\Platform\Services;

use App\Domains\Platform\Models\Receipt;
use App\Domains\Platform\Models\Invoice;
use App\Domains\Platform\Models\PaymentMethod;
use App\Domains\Platform\Models\Service;
use App\Domains\Platform\Models\ServiceAddOn;
use App\Domains\Platform\Models\CustomerFiscalProfile;
use App\Domains\Platform\Models\Subscription;
use App\Domains\Platform\Models\Transaction;
use App\Models\User;
use App\Domains\Platform\Models\ServicePlan;
use App\Domains\Platform\Models\PterodactylEgg;
use App\Domains\Platform\Models\ActivityLog;
use App\Domains\Platform\Services\Factura\CfdiService;
use App\Domains\Platform\Services\Coolify\HostingProvisioningService;
use App\Domains\Platform\Services\PaymentReceiptService;
use App\Domains\Platform\Services\Pterodactyl\GameServerProvisioningService;
use App\Domains\Platform\Services\InvoiceService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
        // ── Desviar planes gratis/trial al flujo sin cobro ───────────────────
        if ($plan->isNoCharge()) {
            return $this->contractFree($user, $plan, $validated);
        }

        // ── Egg (juego) seleccionado — solo para planes de game server ───
        // El campo `egg_id` es obligatorio cuando provisioner=pterodactyl.
        // Validamos aquí (antes del cobro) para no cobrar si el egg no existe.
        $selectedEgg    = null;
        $resolvedMaxPlayers = null;

        if ($plan->isPterodactylManaged()) {
            if (empty($validated['egg_id'])) {
                throw new \RuntimeException('Debes seleccionar un juego para este plan de servidor.');
            }

            $selectedEgg = PterodactylEgg::active()->find((int) $validated['egg_id']);

            if (! $selectedEgg) {
                throw new \RuntimeException('El juego seleccionado no está disponible.');
            }

            // Validar que el egg pertenece a un nest permitido por el plan
            if (! empty($plan->allowed_nest_ids)
                && ! in_array($selectedEgg->ptero_nest_id, $plan->allowed_nest_ids, true)) {
                throw new \RuntimeException('El juego seleccionado no está permitido en este plan.');
            }

            // MAX_PLAYERS: plan.max_players > specifications.players > egg default 20
            $resolvedMaxPlayers = $this->resolveMaxPlayers($plan);
        }

        // ── Pricing ─────────────────────────────────────────────────────
        $quoteSnapshot = $validated['_checkout_quote']['snapshot'] ?? null;
        $multiplier = $quoteSnapshot
            ? max((int) data_get($quoteSnapshot, 'billing_cycle.months', 1), 1)
            : match ($validated['billing_cycle']) {
            'monthly'       => 1,
            'quarterly'     => 3,
            'semi_annually' => 6,
            'annually'      => 12,
            default         => 1,
        };

        $selectedAddOns = $this->resolveAddOns($plan, $validated['add_ons'] ?? []);

        $planNet    = $quoteSnapshot
            ? (float) collect(data_get($quoteSnapshot, 'lines', []))->firstWhere('type', 'plan')['amount']
            : round((float) $plan->base_price * $multiplier, 2);
        $addonsNet  = $quoteSnapshot
            ? round(collect(data_get($quoteSnapshot, 'lines', []))->where('type', 'add_on')->sum('amount'), 2)
            : round($selectedAddOns->sum(fn($a) => (float) $a->price) * $multiplier, 2);
        $subtotal   = $quoteSnapshot
            ? (float) data_get($quoteSnapshot, 'subtotal')
            : round($planNet + $addonsNet, 2);

        $taxRatePct  = $quoteSnapshot ? (float) data_get($quoteSnapshot, 'tax_rate') : (float) config('billing.tax_rate_percent', 16.00);
        $taxAmount   = $quoteSnapshot ? (float) data_get($quoteSnapshot, 'tax') : round($subtotal * $taxRatePct / 100, 2);
        $total       = $quoteSnapshot ? (float) data_get($quoteSnapshot, 'total') : round($subtotal + $taxAmount, 2);
        $currency    = $quoteSnapshot ? (string) data_get($quoteSnapshot, 'currency') : ($plan->currency ? strtoupper($plan->currency) : 'MXN');
        $amountCents = (int) round($total * 100);

        $nextBillingDate = $quoteSnapshot
            ? Carbon::parse(data_get($quoteSnapshot, 'next_due_at'))
            : now()->addMonths($multiplier);

        // ── Stripe ───────────────────────────────────────────────────────
        $customerId           = $user->stripe_customer_id;
        $localPaymentMethodId = $this->resolveLocalPaymentMethodId($user, $validated);

        // Idempotencia temprana (Flow A): si ya existe un servicio para este
        // PaymentIntent, devolvemos el existente sin volver a llamar a Stripe,
        // sin recrear registros y SIN re-aprovisionar.
        if (!empty($validated['payment_intent_id'])) {
            if ($existing = $this->existingServiceFor($validated['payment_intent_id'])) {
                return $existing;
            }
        }

        // Idempotency-Key de Stripe para Flow B: blinda la ruta sin cotización
        // (landing → /services/contract con payment_method_id) contra doble click /
        // refresh. Stripe devuelve el MISMO PaymentIntent ante una key repetida,
        // evitando un segundo cargo. Ventana ~10 min (bucket de tiempo).
        $flowBKey = empty($validated['payment_intent_id'])
            ? $this->buildFlowBIdempotencyKey($user, (string) ($validated['payment_method_id'] ?? ''), $amountCents, $currency)
            : null;

        [$pi, $usedStripePmId, $pmObject] = !empty($validated['payment_intent_id'])
            ? $this->handleFlowA($validated['payment_intent_id'], $amountCents, $currency)
            : $this->handleFlowB($validated['payment_method_id'], $amountCents, $currency, $customerId, $flowBKey);

        // Idempotencia post-cobro: el PaymentIntent (Flow A o el resuelto en Flow B)
        // ya puede tener un servicio asociado (carrera con webhook / reintento).
        if ($existing = $this->existingServiceFor($pi->id ?? ($validated['payment_intent_id'] ?? ''))) {
            return $existing;
        }

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
            $localPaymentMethodId, $pi, $usedStripePmId, $cardMeta,
            $selectedEgg, $resolvedMaxPlayers
        ) {
            $paymentIntentId = $pi->id ?? $validated['payment_intent_id'];

            // 1) Service
            $service = Service::create([
                'plan_id'           => $plan->id,
                'user_id'           => $user->id,
                'price'             => $subtotal,
                'name'              => $validated['service_name'],
                'status'            => 'active',
                'billing_cycle'     => $validated['billing_cycle'],
                'domain'            => $validated['domain'] ?? null,
                'payment_intent_id' => $paymentIntentId,
                'configuration'     => $validated['additional_options'] ?? null,
                'next_due_date'     => $nextBillingDate,
                // ── Game server ──────────────────────────────────────────
                'selected_egg_id'   => $selectedEgg?->id,
                'max_players'       => $resolvedMaxPlayers,
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
            // Prioridad de fuente de datos fiscales:
            //   a) fiscal_profile_uuid → perfil guardado del cliente
            //   b) invoice{}           → datos ingresados directamente
            //   c) ninguno             → Público en General (72 h para que el cliente actualice)
            $fiscalData = $this->resolveFiscalData($user, $validated);

            if ($fiscalData) {
                $cfdiInvoice = Invoice::create([
                    'service_id'         => $service->id,
                    'rfc'                => strtoupper(trim($fiscalData['rfc'])),
                    'name'               => strtoupper(trim($fiscalData['name'])),
                    'zip'                => $fiscalData['zip'],
                    'regimen'            => $fiscalData['regimen'],
                    'cfdi_use_code'      => $fiscalData['cfdi_use_code'],
                    'constancia'         => $fiscalData['constancia'] ?? null,
                    'cfdi_status'        => Invoice::CFDI_PENDING_STAMP,
                    'is_publico_general' => false,
                ]);
            } else {
                // Sin datos fiscales → Público en General, se timbra automáticamente a las 72 h
                $cfdiInvoice = Invoice::create(
                    Invoice::publicoGeneralDefaults($service->id)
                );
            }

            // 3) Receipt (comprobante de pago interno)
            $receipt = $this->invoiceService->createWithItems(
                [
                    'user_id'             => $user->id,
                    'service_id'          => $service->id,
                    'status'              => 'paid',
                    'due_date'            => now(),
                    'paid_at'             => now(),
                    'payment_method'      => $this->resolvePaymentMethodLabel($cardMeta),
                    'payment_reference'   => $paymentIntentId,
                    'gateway'             => 'stripe',
                    'notes'               => 'Pago por contratación de servicio',
                    'currency'            => $currency,
                    'subtotal'            => $subtotal,
                    'tax_rate'            => $taxRatePct,
                    'tax_amount'          => $taxAmount,
                    'total'               => $total,
                ],
                array_merge(
                    [[
                        'service_id'          => $service->id,
                        'description'         => sprintf('%s (%s)', $plan->name, strtoupper($validated['billing_cycle'])),
                        'quantity'            => 1,
                        'unit_price'          => $planNet,
                        'sat_clave_prod_serv' => $plan->sat_clave_prod_serv ?? config('facturama.clave_prod_serv'),
                        'sat_clave_unidad'    => $plan->sat_clave_unidad    ?? config('facturama.clave_unidad', 'E48'),
                    ]],
                    $selectedAddOns->map(fn($a) => [
                        'service_id'          => $service->id,
                        'description'         => $a->name,
                        'quantity'            => 1,
                        'unit_price'          => round((float) $a->price * $multiplier, 2),
                        // Los add-ons heredan la clave del plan principal
                        'sat_clave_prod_serv' => $plan->sat_clave_prod_serv ?? config('facturama.clave_prod_serv'),
                        'sat_clave_unidad'    => $plan->sat_clave_unidad    ?? config('facturama.clave_unidad', 'E48'),
                    ])->values()->all()
                )
            );

            // 4) Transaction
            Transaction::create([
                'uuid'                    => (string) Str::uuid(),
                'user_id'                 => $user->id,
                'receipt_id'              => $receipt->id,
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
                'description'    => 'Pago de contratación de servicio',
                'failure_reason' => null,
                'processed_at'   => now(),
            ]);

            ActivityLog::record(
                'Pago de servicio',
                $plan->name,
                'payment',
                ['receipt_id' => $receipt->id, 'service_id' => $service->id, 'amount' => $total, 'currency' => $currency],
                $user->id
            );

            // 5) Post-commit: broadcasts, notificaciones, comprobante PDF y timbrado CFDI
            DB::afterCommit(function () use ($user, $receipt, $service, $cfdiInvoice, $total, $fiscalData) {
                broadcast(new \App\Domains\Platform\Events\ServicePurchased($service->fresh('user'), (float) $total));
                broadcast(new \App\Domains\Platform\Events\ReceiptGenerated($receipt));
                \Illuminate\Support\Facades\Notification::send($user, new \App\Domains\Platform\Notifications\ServiceNotification([
                    'title'   => 'Compra realizada',
                    'message' => "¡Gracias por tu compra! Tu servicio '{$service->name}' ha sido adquirido exitosamente.",
                    'type'    => 'service.purchased',
                    'data'    => ['service_id' => $service->uuid ?? $service->id, 'amount' => $total],
                ]));

                // ── Comprobante de pago (PDF interno) ───────────────────────
                try {
                    app(PaymentReceiptService::class)->generate($receipt->fresh(['user', 'items', 'service.plan']));
                    \Illuminate\Support\Facades\Notification::send($user, new \App\Domains\Platform\Notifications\PaymentReceiptNotification($receipt->fresh(['user', 'items'])));
                } catch (\Throwable $e) {
                    Log::error('Comprobante de pago: error al generar o notificar (no fatal)', [
                        'receipt_id' => $receipt->id,
                        'error'      => $e->getMessage(),
                    ]);
                }

                // ── CFDI ────────────────────────────────────────────────────
                $cfdiInvoice->update(['receipt_id' => $receipt->id]);

                if ($fiscalData) {
                    try {
                        app(CfdiService::class)->stamp($cfdiInvoice->fresh());
                    } catch (\Throwable $e) {
                        Log::error('Timbrado inmediato fallido (no fatal)', [
                            'invoice_id' => $cfdiInvoice->id,
                            'error'      => $e->getMessage(),
                        ]);
                    }
                } else {
                    \Illuminate\Support\Facades\Notification::send($user, new \App\Domains\Platform\Notifications\ServiceNotification([
                        'title'   => 'Completa tus datos de facturación',
                        'message' => 'Tu compra fue exitosa. Tienes 72 horas para ingresar tus datos fiscales; de lo contrario, tu factura se emitirá a nombre de "Público en General".',
                        'type'    => 'invoice.needs_fiscal_data',
                        'data'    => [
                            'service_id' => $service->uuid ?? $service->id,
                            'invoice_id' => $cfdiInvoice->id,
                            'deadline'   => $cfdiInvoice->stamp_scheduled_at,
                        ],
                    ]));
                }
            });

            return ['service' => $service, 'receipt' => $receipt, 'invoice' => $cfdiInvoice];
        });

        // ── 6) Provisioning — FUERA del transaction, con reintentos ──────────
        // Encola un provisioning_job idempotente y lo ejecuta de inmediato. Si
        // el proveedor falla, el job queda pendiente con backoff y lo reintenta
        // el comando provisioning:process-pending — el servicio nunca queda a
        // medias y nunca se aprovisiona dos veces.
        app(\App\Domains\Platform\Services\ProvisioningService::class)
            ->dispatch($result['service']->fresh(['plan', 'user']));

        // ── 7) Suscripción Stripe — FUERA del transaction ────────────────────
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
                        $validated['billing_cycle'],
                        $usedStripePmId
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
    // Free / Trial Flow (sin cobro)
    // ──────────────────────────────────────────────

    /**
     * Contrata un plan free o trial sin pasar por Stripe.
     *
     * - Crea el servicio con status 'active'.
     * - Para trial: fija trial_ends_at = now() + trial_days.
     * - Crea un Receipt con total = 0 (registro contable, sin CFDI).
     * - No genera Invoice/CFDI (no hay importe fiscal que declarar).
     * - Dispara las mismas notificaciones que el flujo paid.
     */
    public function contractFree(User $user, ServicePlan $plan, array $validated): array
    {
        // Serializa contrataciones concurrentes del mismo (usuario, plan) para
        // evitar duplicados por doble-submit / carrera. En producción (redis)
        // protege entre procesos; el pre-check de BD cubre el caso secuencial.
        $lock = Cache::lock("contract_free:{$user->id}:{$plan->id}", 10);
        $lock->block(5);

        try {
            // ── Idempotencia / anti-abuso ────────────────────────────────────
            // A diferencia del flujo pagado (que deduplica por payment_intent_id),
            // el flujo free/trial no tenía guarda: un doble-submit creaba servicios
            // duplicados y permitía trials ilimitados por usuario. Regla: un usuario
            // no puede tener más de un servicio vigente del mismo plan free/trial;
            // el segundo intento devuelve el existente (idempotente).
            $existingActive = Service::where('user_id', $user->id)
                ->where('plan_id', $plan->id)
                ->whereNotIn('status', ['terminated', 'cancelled'])
                ->latest('id')
                ->first();

            if ($existingActive) {
                Log::info('contractFree(): servicio idempotente devuelto (plan free/trial ya vigente)', [
                    'service_id' => $existingActive->id,
                    'user_id'    => $user->id,
                    'plan_id'    => $plan->id,
                ]);

                return [
                    'service' => $existingActive,
                    'receipt' => Receipt::where('service_id', $existingActive->id)->latest('id')->first(),
                    'invoice' => null,
                ];
            }

        // ── Egg validation (game servers) ────────────────────────────────────
        $selectedEgg        = null;
        $resolvedMaxPlayers = null;

        if ($plan->isPterodactylManaged()) {
            if (empty($validated['egg_id'])) {
                throw new \RuntimeException('Debes seleccionar un juego para este plan de servidor.');
            }
            $selectedEgg = PterodactylEgg::active()->find((int) $validated['egg_id']);
            if (!$selectedEgg) {
                throw new \RuntimeException('El juego seleccionado no está disponible.');
            }
            if (!empty($plan->allowed_nest_ids)
                && !in_array($selectedEgg->ptero_nest_id, $plan->allowed_nest_ids, true)) {
                throw new \RuntimeException('El juego seleccionado no está permitido en este plan.');
            }
            $resolvedMaxPlayers = $this->resolveMaxPlayers($plan);
        }

        // ── Multiplicador de precio (siempre 0 para free/trial) ──────────────
        $multiplier      = match ($validated['billing_cycle']) {
            'monthly'       => 1,
            'quarterly'     => 3,
            'semi_annually' => 6,
            'annually'      => 12,
            default         => 1,
        };
        $nextBillingDate = $plan->isTrial()
            ? now()->addDays($plan->trial_days ?? 14)
            : now()->addMonths($multiplier);

        $selectedAddOns = $this->resolveAddOns($plan, $validated['add_ons'] ?? []);

        $result = DB::transaction(function () use (
            $user, $plan, $validated, $selectedAddOns,
            $multiplier, $nextBillingDate,
            $selectedEgg, $resolvedMaxPlayers
        ) {
            // 1) Service
            $service = Service::create([
                'plan_id'         => $plan->id,
                'user_id'         => $user->id,
                'price'           => 0,
                'name'            => $validated['service_name'],
                'status'          => 'active',
                'billing_cycle'   => $validated['billing_cycle'],
                'domain'          => $validated['domain'] ?? null,
                'configuration'   => $validated['additional_options'] ?? null,
                'next_due_date'   => $nextBillingDate,
                'trial_ends_at'   => $plan->isTrial() ? $nextBillingDate : null,
                'plan_type'       => $plan->plan_type,
                'selected_egg_id' => $selectedEgg?->id,
                'max_players'     => $resolvedMaxPlayers,
            ]);

            // 1.1) Add-on snapshots
            foreach ($selectedAddOns as $addOn) {
                ServiceAddOn::create([
                    'service_id'  => $service->id,
                    'add_on_id'   => $addOn->id,
                    'add_on_uuid' => $addOn->uuid,
                    'name'        => $addOn->name,
                    'unit_price'  => 0,
                    'quantity'    => 1,
                ]);
            }

            // 2) Receipt $0 (registro contable, sin CFDI — no hay importe fiscal)
            $paymentLabel = $plan->isTrial()
                ? 'Periodo de prueba gratuito'
                : 'Plan gratuito';

            $receipt = $this->invoiceService->createWithItems(
                [
                    'user_id'           => $user->id,
                    'service_id'        => $service->id,
                    'status'            => 'paid',
                    'due_date'          => now(),
                    'paid_at'           => now(),
                    'payment_method'    => $paymentLabel,
                    'payment_reference' => null,
                    'gateway'           => null,
                    'notes'             => $plan->isTrial()
                        ? "Trial de {$plan->trial_days} días — vence {$nextBillingDate->format('d/m/Y')}"
                        : 'Plan gratuito sin costo',
                    'currency'          => 'MXN',
                    'subtotal'          => 0,
                    'tax_rate'          => 0,
                    'tax_amount'        => 0,
                    'total'             => 0,
                ],
                $selectedAddOns->map(fn($a) => [
                    'service_id'          => $service->id,
                    'description'         => $a->name,
                    'quantity'            => 1,
                    'unit_price'          => 0,
                    'sat_clave_prod_serv' => $plan->sat_clave_prod_serv ?? config('facturama.clave_prod_serv'),
                    'sat_clave_unidad'    => $plan->sat_clave_unidad    ?? config('facturama.clave_unidad', 'E48'),
                ])->prepend([
                    'service_id'          => $service->id,
                    'description'         => sprintf('%s (%s)', $plan->name, strtoupper($validated['billing_cycle'])),
                    'quantity'            => 1,
                    'unit_price'          => 0,
                    'sat_clave_prod_serv' => $plan->sat_clave_prod_serv ?? config('facturama.clave_prod_serv'),
                    'sat_clave_unidad'    => $plan->sat_clave_unidad    ?? config('facturama.clave_unidad', 'E48'),
                ])->values()->all()
            );

            ActivityLog::record(
                $plan->isTrial() ? 'Activación de trial' : 'Activación de plan gratuito',
                $plan->name,
                'service',
                ['receipt_id' => $receipt->id, 'service_id' => $service->id, 'plan_type' => $plan->plan_type],
                $user->id
            );

            // 3) Post-commit: notificaciones
            DB::afterCommit(function () use ($user, $receipt, $service, $plan, $nextBillingDate) {
                broadcast(new \App\Domains\Platform\Events\ServicePurchased($service->fresh('user'), 0.0));
                broadcast(new \App\Domains\Platform\Events\ReceiptGenerated($receipt));

                $message = $plan->isTrial()
                    ? "Tu periodo de prueba de '{$service->name}' ha comenzado. Vence el {$nextBillingDate->format('d/m/Y')}."
                    : "Tu servicio gratuito '{$service->name}' ha sido activado exitosamente.";

                \Illuminate\Support\Facades\Notification::send($user, new \App\Domains\Platform\Notifications\ServiceNotification([
                    'title'   => $plan->isTrial() ? 'Trial activado' : 'Servicio activado',
                    'message' => $message,
                    'type'    => $plan->isTrial() ? 'service.trial_started' : 'service.activated',
                    'data'    => [
                        'service_id'    => $service->uuid ?? $service->id,
                        'trial_ends_at' => $service->trial_ends_at?->toIso8601String(),
                    ],
                ]));

                // Comprobante PDF (puede ser $0, útil como acuse de activación)
                try {
                    app(PaymentReceiptService::class)->generate($receipt->fresh(['user', 'items', 'service.plan']));
                } catch (\Throwable $e) {
                    Log::warning('Comprobante $0: no se pudo generar (no fatal)', [
                        'receipt_id' => $receipt->id,
                        'error'      => $e->getMessage(),
                    ]);
                }
            });

            return ['service' => $service, 'receipt' => $receipt, 'invoice' => null];
        });
        } finally {
            // Liberar el lock antes del aprovisionamiento (que ya es idempotente
            // vía ProvisioningJob y puede tardar al hablar con el proveedor).
            $lock->release();
        }

        // ── Provisioning (idempotente + reintentos) ──────────────────────────
        app(\App\Domains\Platform\Services\ProvisioningService::class)
            ->dispatch($result['service']->fresh(['plan', 'user']));

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
    /**
     * Resuelve los datos fiscales desde 3 fuentes con prioridad:
     *  1. fiscal_profile_uuid  → perfil guardado del usuario
     *  2. invoice{}            → datos crudos del request
     *  3. null                 → sin datos (Público en General)
     *
     * @return array{rfc,name,zip,regimen,cfdi_use_code,constancia}|null
     */
    private function resolveFiscalData(User $user, array $validated): ?array
    {
        // Prioridad 1: perfil guardado
        if (!empty($validated['fiscal_profile_uuid'])) {
            $profile = CustomerFiscalProfile::where('uuid', $validated['fiscal_profile_uuid'])
                ->where('user_id', $user->id)
                ->first();

            if ($profile) {
                return $profile->toInvoiceData();
            }
        }

        // Prioridad 2: datos crudos en el request
        if (!empty($validated['invoice']['rfc'])) {
            return [
                'rfc'        => $validated['invoice']['rfc'],
                'name'       => $validated['invoice']['name'],
                'zip'        => $validated['invoice']['zip'],
                'regimen'    => $validated['invoice']['regimen'],
                'cfdi_use_code' => $validated['invoice']['cfdi_use_code'],
                'constancia' => $this->normalizeConstancia($validated['invoice']['constancia'] ?? null),
            ];
        }

        // Prioridad 3: buscar el perfil predeterminado del usuario
        $defaultProfile = CustomerFiscalProfile::where('user_id', $user->id)
            ->where('is_default', true)
            ->first();

        return $defaultProfile?->toInvoiceData();
    }

    /**
     * Normaliza la constancia a base64 para la columna longText `constancia`.
     * El frontend la manda como objeto {filename, mime, content_b64}; el diseño
     * original esperaba el string base64 directo. Acepta ambos.
     */
    private function normalizeConstancia(mixed $constancia): ?string
    {
        if (is_array($constancia)) {
            $b64 = $constancia['content_b64'] ?? null;

            return is_string($b64) && $b64 !== '' ? $b64 : null;
        }

        return is_string($constancia) && $constancia !== '' ? $constancia : null;
    }

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
     * Retrieve it from Stripe, validate amount/currency/status, and return [pi, stripe_pm_id, pm_object].
     *
     * @throws \RuntimeException  Si el PI no coincide con el monto calculado o no está confirmado.
     */
    private function handleFlowA(string $paymentIntentId, int $expectedCents, string $currency): array
    {
        $pi = StripePaymentIntent::retrieve(['id' => $paymentIntentId, 'expand' => ['payment_method']]);

        // Validar que el PI fue efectivamente cobrado
        if (! in_array($pi->status, ['succeeded', 'requires_capture'], true)) {
            throw new \RuntimeException(
                "El pago no está confirmado (estado: {$pi->status}). Reinicia el proceso de pago."
            );
        }

        // Blindar monto: el PI debe corresponder exactamente al total calculado en el backend
        if ($pi->amount !== $expectedCents) {
            Log::warning('Flow A: monto del PI no coincide con el calculado', [
                'payment_intent_id' => $paymentIntentId,
                'pi_amount'         => $pi->amount,
                'expected_cents'    => $expectedCents,
            ]);
            throw new \RuntimeException(
                'El monto del pago no corresponde al servicio seleccionado. Genera una nueva cotización.'
            );
        }

        // Blindar moneda
        if (strtolower($pi->currency) !== strtolower($currency)) {
            throw new \RuntimeException(
                'La moneda del pago no corresponde a la moneda del servicio. Genera una nueva cotización.'
            );
        }

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
    private function handleFlowB(string $pmId, int $amountCents, string $currency, ?string $customerId, ?string $idempotencyKey = null): array
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
        ], $idempotencyKey ? ['idempotency_key' => $idempotencyKey] : []);

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
            'funding'   => $c->funding,   // 'credit' | 'debit' | 'prepaid' | 'unknown'
            'country'   => $c->country ?? null,
        ];
    }

    /**
     * Convierte los metadatos de la tarjeta Stripe al tipo de pago descriptivo.
     * Stripe es el gateway, no el método; el método es la tarjeta.
     *
     * Ejemplos:
     *   credit + visa      → "Tarjeta de crédito (Visa ****1234)"
     *   debit  + mastercard→ "Tarjeta de débito (Mastercard ****5678)"
     */
    private function resolvePaymentMethodLabel(?array $cardMeta): string
    {
        if (!$cardMeta) {
            return 'Tarjeta';
        }

        $type  = match (strtolower($cardMeta['funding'] ?? '')) {
            'credit'  => 'Tarjeta de crédito',
            'debit'   => 'Tarjeta de débito',
            'prepaid' => 'Tarjeta prepago',
            default   => 'Tarjeta',
        };

        $brand = ucfirst(strtolower($cardMeta['brand'] ?? ''));
        $last4 = $cardMeta['last4'] ?? null;

        if ($brand && $last4) {
            return "{$type} ({$brand} ****{$last4})";
        }

        if ($brand) {
            return "{$type} ({$brand})";
        }

        return $type;
    }

    /**
     * Resuelve el número máximo de jugadores para el plan.
     *
     * Prioridad:
     *   1. plan.max_players (campo explícito)
     *   2. specifications.players (ej: "150 Jugadores" → 150)
     *   3. 20 (default seguro)
     */
    /**
     * Devuelve el resultado idempotente (mismo shape que el transaction) si ya
     * existe un servicio para el PaymentIntent dado; null en caso contrario.
     *
     * @return array{service: Service, receipt: ?Receipt, invoice: ?Invoice}|null
     */
    private function existingServiceFor(?string $paymentIntentId): ?array
    {
        if (empty($paymentIntentId)) {
            return null;
        }

        $service = Service::where('payment_intent_id', $paymentIntentId)->first();

        if (! $service) {
            return null;
        }

        Log::info('contract(): servicio idempotente devuelto para PaymentIntent existente', [
            'service_id'        => $service->id,
            'payment_intent_id' => $paymentIntentId,
        ]);

        return [
            'service' => $service,
            'receipt' => Receipt::where('service_id', $service->id)->latest('id')->first(),
            'invoice' => Invoice::where('service_id', $service->id)->latest('id')->first(),
        ];
    }

    /**
     * Clave de idempotencia determinista para el PaymentIntent de Flow B.
     *
     * Combina usuario + método de pago + monto + moneda + un bucket de ~10 min.
     * Dos peticiones idénticas dentro de la ventana comparten la misma key, de
     * modo que Stripe devuelve el mismo PaymentIntent y no genera un segundo cargo.
     */
    private function buildFlowBIdempotencyKey(User $user, string $pmId, int $amountCents, string $currency): string
    {
        $bucket = (int) floor(time() / 600); // ventana de 10 minutos

        return 'contract_b_' . hash('sha256', implode('|', [
            $user->id,
            $pmId,
            $amountCents,
            strtolower($currency),
            $bucket,
        ]));
    }

    private function resolveMaxPlayers(ServicePlan $plan): int
    {
        if (! empty($plan->max_players)) {
            return (int) $plan->max_players;
        }

        $specs   = $plan->specifications ?? [];
        $players = $specs['players'] ?? null;

        if ($players && preg_match('/(\d+)/', (string) $players, $m)) {
            return (int) $m[1];
        }

        return 20; // default seguro
    }

    private function createStripeSubscription(
        User $user, ServicePlan $plan, Service $service,
        ?string $customerId, float $total, string $currency, string $billingCycle,
        ?string $stripePaymentMethodId = null
    ): void {
        // Resolve the Stripe Price for the selected billing cycle.
        // Priority: plan_pricing.stripe_price_id → service_plans.stripe_price_id (fallback).
        // If neither exists, auto-sync the plan to Stripe first.
        $stripePriceId = app(\App\Domains\Platform\Services\StripeSyncService::class)->resolvePriceId($plan, $billingCycle);

        if (! $stripePriceId) {
            // Try to auto-sync the plan and pick up the price
            \Illuminate\Support\Facades\Log::info("createStripeSubscription: no stripe_price_id for plan #{$plan->id}, attempting auto-sync.");
            app(\App\Domains\Platform\Services\StripeSyncService::class)->syncPlan($plan->load('pricing.billingCycle'));
            $plan->refresh();
            $stripePriceId = app(\App\Domains\Platform\Services\StripeSyncService::class)->resolvePriceId($plan, $billingCycle);
        }

        if (! $stripePriceId) {
            throw new \RuntimeException("El plan '{$plan->name}' no tiene stripe_price_id para el ciclo '{$billingCycle}'. Ejecuta: php artisan stripe:sync-plans");
        }

        // El primer periodo YA se cobró vía PaymentIntent en contract(). Sin un
        // ancla, Stripe facturaría el mismo periodo otra vez de inmediato y, al
        // quedar esa factura sin pagar (default_incomplete), la suscripción
        // moriría como incomplete_expired a las ~23 h → sin auto-renovación.
        // trial_end = next_due_date pospone la PRIMERA factura de Stripe al
        // inicio del siguiente periodo (la suscripción nace 'trialing').
        $params = [
            'customer'         => $customerId,
            'items'            => [['price' => $stripePriceId]],
            'payment_behavior' => 'default_incomplete',
            'payment_settings' => ['save_default_payment_method' => 'on_subscription'],
            'expand'           => ['latest_invoice.payment_intent'],
            'metadata'         => ['user_id' => (string) $user->id, 'service_id' => (string) $service->id],
        ];

        if ($service->next_due_date && $service->next_due_date->isFuture()) {
            $params['trial_end'] = $service->next_due_date->timestamp;
        }

        // Asegurar que la renovación tenga con qué cobrar: el método de pago del
        // primer cobro se adjunta al customer (si aún no lo está) y queda como
        // default de la suscripción. Best-effort: si falla, la suscripción se
        // crea igual y Stripe usará el default del customer si existe.
        if ($stripePaymentMethodId && $customerId) {
            try {
                $pm = StripePaymentMethod::retrieve($stripePaymentMethodId);
                if (empty($pm->customer)) {
                    $pm->attach(['customer' => $customerId]);
                }
                if (empty($pm->customer) || $pm->customer === $customerId) {
                    $params['default_payment_method'] = $stripePaymentMethodId;
                }
            } catch (\Throwable $e) {
                Log::warning('createStripeSubscription: no se pudo fijar default_payment_method (no fatal)', [
                    'service_id' => $service->id,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        $stripeSub = \Stripe\Subscription::create($params);

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
            'current_period_start'   => \App\Support\StripeObjectReader::subscriptionPeriodStart($stripeSub),
            'current_period_end'     => \App\Support\StripeObjectReader::subscriptionPeriodEnd($stripeSub),
            'trial_start'            => isset($stripeSub->trial_start)           ? Carbon::createFromTimestamp($stripeSub->trial_start)          : null,
            'trial_end'              => isset($stripeSub->trial_end)             ? Carbon::createFromTimestamp($stripeSub->trial_end)            : null,
            'ends_at'                => \App\Support\StripeObjectReader::subscriptionPeriodEnd($stripeSub),
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
