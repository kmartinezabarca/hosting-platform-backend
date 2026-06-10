<?php

namespace App\Domains\Platform\Http\Controllers\Common;

use App\Http\Controllers\Controller;
use App\Domains\Platform\Models\Receipt;
use App\Domains\Platform\Models\Service;
use App\Domains\Platform\Models\StripeEvent;
use App\Domains\Platform\Models\Subscription;
use App\Domains\Platform\Models\Transaction;
use App\Models\User;
use App\Domains\Platform\Notifications\PaymentNotification;
use App\Domains\Platform\Notifications\ServiceNotification;
use App\Support\StripeObjectReader;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;

class StripeWebhookController extends Controller
{
    public function __construct()
    {
        \Stripe\Stripe::setApiKey(config('services.stripe.secret'));
    }

    // ──────────────────────────────────────────────
    // Entry point
    // ──────────────────────────────────────────────

    public function handleWebhook(Request $request): JsonResponse
    {
        $payload   = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');
        $secret    = config('services.stripe.webhook_secret');

        try {
            $event = Webhook::constructEvent($payload, $sigHeader, $secret);
        } catch (\UnexpectedValueException $e) {
            Log::error('Stripe webhook — invalid payload: ' . $e->getMessage());
            return response()->json(['error' => 'Invalid payload'], 400);
        } catch (SignatureVerificationException $e) {
            Log::error('Stripe webhook — invalid signature: ' . $e->getMessage());
            return response()->json(['error' => 'Invalid signature'], 400);
        }

        // ── Idempotencia por event_id ────────────────────────────────────────
        // Stripe reintenta cada evento hasta 3 días. Registramos el event_id
        // (único en BD) y omitimos cualquier evento ya procesado con éxito.
        // Si un evento previo falló, se permite el reproceso (Stripe reintenta).
        $record = StripeEvent::firstOrNew(['event_id' => $event->id]);

        if ($record->exists && $record->status === StripeEvent::STATUS_PROCESSED) {
            Log::info("Stripe webhook duplicado ignorado: {$event->id} ({$event->type})");
            return response()->json(['status' => 'duplicate']);
        }

        $record->fill([
            'type'     => $event->type,
            'status'   => StripeEvent::STATUS_PROCESSING,
            'attempts' => ($record->attempts ?? 0) + 1,
            'payload'  => json_decode(json_encode($event->data->object), true),
            'error'    => null,
        ]);

        try {
            $record->save();
        } catch (\Illuminate\Database\QueryException $e) {
            // Violación de unique: otra petición concurrente ya tomó este evento.
            Log::info("Stripe webhook en proceso por otra petición: {$event->id}");
            return response()->json(['status' => 'in_progress']);
        }

        $object = $event->data->object;

        try {
            match ($event->type) {
                'payment_intent.succeeded'          => $this->onPaymentIntentSucceeded($object),
                'payment_intent.payment_failed'     => $this->onPaymentIntentFailed($object),
                'invoice.paid',
                'invoice.payment_succeeded'         => $this->onInvoicePaymentSucceeded($object),
                'invoice.payment_failed'            => $this->onInvoicePaymentFailed($object),
                'invoice.payment_action_required'   => $this->onInvoicePaymentActionRequired($object),
                'invoice.finalized'                 => $this->onInvoiceFinalized($object),
                'customer.subscription.created'     => $this->onSubscriptionCreated($object),
                'customer.subscription.updated'     => $this->onSubscriptionUpdated($object),
                'customer.subscription.deleted'     => $this->onSubscriptionDeleted($object),
                'checkout.session.completed'        => $this->onCheckoutSessionCompleted($object),
                'charge.refunded'                   => $this->onChargeRefunded($object),
                'charge.dispute.created',
                'charge.dispute.updated'            => $this->onDisputeOpenedOrUpdated($object),
                'charge.dispute.closed'             => $this->onDisputeClosed($object),
                default                             => Log::info("Stripe webhook ignored: {$event->type}"),
            };
        } catch (\Throwable $e) {
            Log::error("Stripe webhook handler error ({$event->type} / {$event->id}): " . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            $record->update([
                'status' => StripeEvent::STATUS_FAILED,
                'error'  => $e->getMessage(),
            ]);

            // 500 → Stripe reintentará; al ser idempotente, el reproceso es seguro.
            return response()->json(['error' => 'handler_failed'], 500);
        }

        $record->update([
            'status'       => StripeEvent::STATUS_PROCESSED,
            'processed_at' => now(),
        ]);

        return response()->json(['status' => 'ok']);
    }

    // ──────────────────────────────────────────────
    // PaymentIntent handlers
    // ──────────────────────────────────────────────

    /**
     * payment_intent.succeeded
     *
     * Fired when a one-off charge is confirmed on Stripe's side.
     * We mark the matching Transaction and Invoice as completed/paid.
     */
    private function onPaymentIntentSucceeded(object $pi): void
    {
        Log::info("Stripe: payment_intent.succeeded {$pi->id}");

        DB::transaction(function () use ($pi) {
            // Mark transaction completed
            $transaction = Transaction::where('provider_transaction_id', $pi->id)->first();

            if ($transaction) {
                $transaction->update([
                    'status'       => 'completed',
                    'processed_at' => now(),
                ]);

                // Mark the linked invoice paid
                $invoice = $transaction->invoice;
                if ($invoice && $invoice->status !== Receipt::STATUS_PAID) {
                    $invoice->update([
                        'status'  => Receipt::STATUS_PAID,
                        'paid_at' => now(),
                    ]);
                }
            }

            // Ensure the related service is active
            $serviceId = $pi->metadata->service_id ?? null;
            if ($serviceId) {
                Service::where('id', $serviceId)
                    ->where('status', '!=', 'active')
                    ->update(['status' => 'active']);
            }

            // Avisar al admin del cobro confirmado.
            if ($transaction) {
                \App\Domains\Platform\Support\AdminNotifier::notify(
                    'Pago recibido',
                    "Pago de {$transaction->amount} {$transaction->currency} confirmado de {$transaction->user?->full_name}.",
                    'admin_payment_received',
                    ['transaction_id' => $transaction->uuid, 'amount' => $transaction->amount, 'currency' => $transaction->currency],
                );
            }
        });
    }

    /**
     * payment_intent.payment_failed
     *
     * A charge attempt failed. Mark the Transaction as failed and
     * notify the user so they can update their payment method.
     */
    private function onPaymentIntentFailed(object $pi): void
    {
        Log::warning("Stripe: payment_intent.payment_failed {$pi->id}");

        DB::transaction(function () use ($pi) {
            $transaction = Transaction::where('provider_transaction_id', $pi->id)->first();

            if ($transaction) {
                $failureMessage = $pi->last_payment_error->message ?? 'Payment failed';

                $transaction->update([
                    'status'         => 'failed',
                    'failure_reason' => $failureMessage,
                    'processed_at'   => now(),
                ]);

                // Notify the user
                $user = $transaction->user;
                if ($user) {
                    $user->notify(new PaymentNotification([
                        'title'   => 'Pago fallido',
                        'message' => "Tu pago no pudo ser procesado: {$failureMessage}. Por favor actualiza tu método de pago.",
                        'type'    => 'payment.failed',
                        'data'    => ['transaction_id' => $transaction->uuid],
                    ]));
                }

                // Avisar al admin del pago rechazado.
                \App\Domains\Platform\Support\AdminNotifier::notify(
                    'Pago fallido',
                    "Pago de {$transaction->amount} {$transaction->currency} de {$user?->full_name} fue rechazado: {$failureMessage}",
                    'admin_payment_failed',
                    ['transaction_id' => $transaction->uuid, 'reason' => $failureMessage],
                );
            }
        });
    }

    // ──────────────────────────────────────────────
    // Subscription invoice handlers
    // ──────────────────────────────────────────────

    /**
     * invoice.payment_succeeded
     *
     * Stripe renews a subscription — extend the billing period and
     * keep the service active.
     */
    private function onInvoicePaymentSucceeded(object $stripeInvoice): void
    {
        $subId = StripeObjectReader::subscriptionIdFromInvoice($stripeInvoice);
        if (!$subId) {
            // Invoice no ligada a suscripción (p.ej. pago one-off) — nada que renovar.
            return;
        }

        Log::info("Stripe: invoice paid subscription={$subId}");

        DB::transaction(function () use ($stripeInvoice, $subId) {
            $subscription = Subscription::where('stripe_subscription_id', $subId)->first();

            if (!$subscription) {
                return;
            }

            $periodEnd = StripeObjectReader::periodEndFromInvoice($stripeInvoice);

            // Si venía de un fallo, ¿estaba el servicio suspendido por morosidad?
            $wasSuspendedForPayment = optional($subscription->service)->status === 'suspended'
                && optional($subscription->service)->suspension_reason === 'payment_overdue';

            // Pago exitoso → limpiar todo el estado de morosidad.
            $subscription->update([
                'status'               => 'active',
                'current_period_end'   => $periodEnd,
                'ends_at'              => $periodEnd,
                'payment_failed_at'    => null,
                'grace_period_ends_at' => null,
                'next_payment_attempt' => null,
                'last_payment_error'   => null,
                'suspended_at'         => null,
                'suspension_reason'    => null,
            ]);

            // Contabilidad interna de la renovación: Receipt + Transaction +
            // CFDI. Idempotente por provider_invoice_id (invoice.paid e
            // invoice.payment_succeeded llegan como eventos distintos).
            app(\App\Domains\Platform\Services\RenewalAccountingService::class)
                ->recordRenewal($subscription, $stripeInvoice);

            // Reactivar/keep-active el servicio y limpiar la gracia.
            $service = $subscription->service;
            if ($service) {
                $service->update([
                    'status'               => 'active',
                    'next_due_date'        => $periodEnd,
                    'grace_period_ends_at' => null,
                    'suspended_at'         => null,
                    'suspension_reason'    => null,
                ]);

                // Si estaba suspendido por falta de pago, reactivar en el proveedor.
                if ($wasSuspendedForPayment) {
                    DB::afterCommit(fn () => app(\App\Domains\Platform\Services\ServiceSuspensionService::class)->reactivate($service->fresh('plan')));
                }
            }

            // Notify user
            $user = $subscription->user;
            if ($user) {
                $user->notify(new ServiceNotification([
                    'title'   => 'Pago de suscripción exitoso',
                    'message' => "Tu suscripción '{$subscription->name}' ha sido renovada exitosamente.",
                    'type'    => 'subscription.renewed',
                    'data'    => ['subscription_id' => $subscription->uuid],
                ]));
            }

            // Avisar al admin de la renovación cobrada.
            \App\Domains\Platform\Support\AdminNotifier::notify(
                'Renovación de suscripción cobrada',
                "Se renovó la suscripción '{$subscription->name}' de {$user?->full_name}.",
                'admin_subscription_renewed',
                ['subscription_id' => $subscription->uuid],
            );
        });
    }

    /**
     * invoice.payment_failed
     *
     * Stripe failed to charge for a subscription renewal.
     * Suspend the service and notify the user.
     */
    private function onInvoicePaymentFailed(object $stripeInvoice): void
    {
        $subId = StripeObjectReader::subscriptionIdFromInvoice($stripeInvoice);
        if (!$subId) {
            return;
        }

        Log::warning("Stripe: invoice.payment_failed subscription={$subId}");

        DB::transaction(function () use ($stripeInvoice, $subId) {
            $subscription = Subscription::where('stripe_subscription_id', $subId)->first();

            if (!$subscription) {
                return;
            }

            $graceDays = (int) config('billing.grace_period_days', 5);

            // Sólo abrimos una ventana de gracia nueva si no había una en curso,
            // para que reintentos de Stripe no la "reinicien" cada vez.
            $graceEnds = $subscription->grace_period_ends_at && $subscription->grace_period_ends_at->isFuture()
                ? $subscription->grace_period_ends_at
                : now()->addDays($graceDays);

            $errorMessage = $stripeInvoice->last_finalization_error->message
                ?? $stripeInvoice->last_payment_error->message
                ?? 'El cobro de la renovación fue rechazado.';

            $subscription->update([
                'status'               => 'past_due',
                'payment_failed_at'    => now(),
                'grace_period_ends_at' => $graceEnds,
                'next_payment_attempt' => StripeObjectReader::timestamp($stripeInvoice->next_payment_attempt ?? null),
                'last_payment_error'   => $errorMessage,
            ]);

            // El servicio sigue ACTIVO durante la gracia; lo reflejamos también
            // a nivel de servicio para el banner del frontend.
            $service = $subscription->service;
            if ($service && $service->status === 'active') {
                $service->update(['grace_period_ends_at' => $graceEnds]);
            }

            $user = $subscription->user;
            if ($user) {
                $user->notify(new ServiceNotification([
                    'title'   => 'No pudimos procesar tu pago',
                    'message' => "Tu pago no pudo procesarse. Tienes {$graceDays} días para actualizar tu método de pago antes de que el servicio sea suspendido.",
                    'type'    => 'subscription.payment_failed',
                    'data'    => [
                        'subscription_id'      => $subscription->uuid,
                        'grace_period_ends_at' => $graceEnds->toIso8601String(),
                    ],
                ]));
            }

            // Avisar al admin de la morosidad: el cliente entró en periodo de gracia.
            \App\Domains\Platform\Support\AdminNotifier::notify(
                'Renovación fallida (cliente en gracia)',
                "Falló el cobro de la suscripción '{$subscription->name}' de {$user?->full_name}. En gracia hasta {$graceEnds->format('d/m/Y')}. Motivo: {$errorMessage}",
                'admin_subscription_payment_failed',
                [
                    'subscription_id'      => $subscription->uuid,
                    'grace_period_ends_at' => $graceEnds->toIso8601String(),
                ],
            );
        });
    }

    // ──────────────────────────────────────────────
    // Subscription lifecycle handlers
    // ──────────────────────────────────────────────

    /**
     * customer.subscription.updated
     *
     * Plan change, pause, trial end, etc.
     */
    private function onSubscriptionUpdated(object $stripeSub): void
    {
        Log::info("Stripe: customer.subscription.updated {$stripeSub->id}");

        $subscription = Subscription::where('stripe_subscription_id', $stripeSub->id)->first();

        if (!$subscription) {
            return;
        }

        $periodEnd = StripeObjectReader::subscriptionPeriodEnd($stripeSub);
        $cancelAtPeriodEnd = (bool) ($stripeSub->cancel_at_period_end ?? false);
        $wasCancelScheduled = (bool) $subscription->cancel_at_period_end;

        $subscription->update([
            'status'               => $stripeSub->status,
            'cancel_at_period_end' => $cancelAtPeriodEnd,
            'current_period_start' => StripeObjectReader::subscriptionPeriodStart($stripeSub),
            'current_period_end'   => $periodEnd,
            // ends_at refleja la fecha de fin efectiva: cancel_at si está programada
            // la cancelación, en su defecto el fin del periodo vigente.
            'ends_at'              => StripeObjectReader::timestamp($stripeSub->cancel_at ?? null) ?? $periodEnd,
            'canceled_at'          => StripeObjectReader::timestamp($stripeSub->canceled_at ?? null),
        ]);

        // Mantener el servicio alineado con el estado real de la suscripción.
        $service = $subscription->service;
        if ($service) {
            if (in_array($stripeSub->status, ['active', 'trialing'], true) && $service->status === 'suspended') {
                $service->update(['status' => 'active']);
            } elseif ($stripeSub->status === 'past_due') {
                // No suspendemos aquí; invoice.payment_failed ya gobierna ese caso.
                Log::info("Subscription {$stripeSub->id} past_due (servicio sin cambios en updated).");
            }
        }

        // El cliente acaba de PROGRAMAR la cancelación al fin del periodo: confirmarle.
        if ($cancelAtPeriodEnd && ! $wasCancelScheduled) {
            $user = $subscription->user;
            $endsLabel = $subscription->ends_at?->format('d/m/Y') ?? 'el fin de tu periodo';

            if ($user) {
                $user->notify(new ServiceNotification([
                    'title'   => 'Cancelación programada',
                    'message' => "Tu suscripción '{$subscription->name}' seguirá activa hasta {$endsLabel} y no se renovará. Puedes reactivarla antes de esa fecha.",
                    'type'    => 'subscription.cancel_scheduled',
                    'data'    => ['subscription_id' => $subscription->uuid, 'ends_at' => $subscription->ends_at?->toIso8601String()],
                ]));
            }

            \App\Domains\Platform\Support\AdminNotifier::notify(
                'Cancelación programada',
                "{$user?->full_name} programó cancelar '{$subscription->name}' (activa hasta {$endsLabel}).",
                'admin_subscription_cancel_scheduled',
                ['subscription_id' => $subscription->uuid],
            );
        }
    }

    /**
     * customer.subscription.deleted
     *
     * Subscription fully cancelled — cancel the local record and service.
     */
    private function onSubscriptionDeleted(object $stripeSub): void
    {
        Log::info("Stripe: customer.subscription.deleted {$stripeSub->id}");

        DB::transaction(function () use ($stripeSub) {
            $subscription = Subscription::where('stripe_subscription_id', $stripeSub->id)->first();

            if (!$subscription) {
                return;
            }

            // 'canceled' (una sola L) es el valor del ENUM de subscriptions.status
            // y coincide con el estado que reporta Stripe.
            $subscription->update([
                'status'      => 'canceled',
                'canceled_at' => now(),
                'ends_at'     => now(),
            ]);

            $service = $subscription->service;
            if ($service) {
                $service->update([
                    'status'         => 'cancelled',
                    'terminated_at'  => now(),
                ]);
            }

            $user = $subscription->user;
            if ($user) {
                $user->notify(new ServiceNotification([
                    'title'   => 'Suscripción cancelada',
                    'message' => "Tu suscripción '{$subscription->name}' ha sido cancelada.",
                    'type'    => 'subscription.cancelled',
                    'data'    => ['subscription_id' => $subscription->uuid],
                ]));
            }

            // Avisar al admin de la baja (churn).
            \App\Domains\Platform\Support\AdminNotifier::notify(
                'Suscripción cancelada (baja)',
                "Se canceló la suscripción '{$subscription->name}' de {$user?->full_name}. El servicio quedó terminado.",
                'admin_subscription_cancelled',
                ['subscription_id' => $subscription->uuid],
            );
        });
    }

    /**
     * customer.subscription.created
     *
     * Sincroniza el estado/periodo cuando Stripe crea la suscripción. La fila
     * local suele crearse en el flujo de contratación; aquí sólo actualizamos
     * estado y periodo si ya existe (idempotente). Si no existe todavía, se
     * registra para diagnóstico — la creación canónica vive en el backend.
     */
    private function onSubscriptionCreated(object $stripeSub): void
    {
        Log::info("Stripe: customer.subscription.created {$stripeSub->id}");

        $subscription = Subscription::where('stripe_subscription_id', $stripeSub->id)->first();

        if (!$subscription) {
            Log::info("subscription.created sin fila local todavía: {$stripeSub->id}");
            return;
        }

        $subscription->update([
            'status'               => $stripeSub->status,
            'current_period_start' => StripeObjectReader::subscriptionPeriodStart($stripeSub),
            'current_period_end'   => StripeObjectReader::subscriptionPeriodEnd($stripeSub),
        ]);
    }

    // ──────────────────────────────────────────────
    // Otros eventos de invoice / checkout
    // ──────────────────────────────────────────────

    /**
     * invoice.payment_action_required
     *
     * El cobro de la suscripción requiere autenticación (3DS). Marcamos la
     * suscripción y avisamos al cliente para que complete la verificación.
     */
    private function onInvoicePaymentActionRequired(object $stripeInvoice): void
    {
        $subId = StripeObjectReader::subscriptionIdFromInvoice($stripeInvoice);
        if (!$subId) {
            return;
        }

        Log::warning("Stripe: invoice.payment_action_required subscription={$subId}");

        $subscription = Subscription::where('stripe_subscription_id', $subId)->first();
        if (!$subscription) {
            return;
        }

        $user = $subscription->user;
        if ($user) {
            $user->notify(new ServiceNotification([
                'title'   => 'Acción requerida en tu pago',
                'message' => "Tu banco requiere verificación para cobrar la suscripción '{$subscription->name}'. Por favor completa la autenticación para no perder el servicio.",
                'type'    => 'subscription.action_required',
                'data'    => ['subscription_id' => $subscription->uuid],
            ]));
        }
    }

    /**
     * invoice.finalized
     *
     * La factura quedó finalizada (lista para cobro). Sólo registramos para
     * trazabilidad; el cobro lo confirman invoice.paid / invoice.payment_failed.
     */
    private function onInvoiceFinalized(object $stripeInvoice): void
    {
        $subId = StripeObjectReader::subscriptionIdFromInvoice($stripeInvoice);
        Log::info('Stripe: invoice.finalized', [
            'invoice_id'   => $stripeInvoice->id ?? null,
            'subscription' => $subId,
        ]);
    }

    /**
     * checkout.session.completed
     *
     * El sistema NO usa Stripe Checkout Sessions (el flujo es PaymentIntent +
     * /services/contract). Se registra por si en el futuro se habilita Checkout,
     * para no perder el evento silenciosamente.
     */
    private function onCheckoutSessionCompleted(object $session): void
    {
        Log::info('Stripe: checkout.session.completed (no usado por esta plataforma)', [
            'session_id' => $session->id ?? null,
        ]);
    }

    // ──────────────────────────────────────────────
    // Refunds y disputas (chargebacks)
    // ──────────────────────────────────────────────

    /**
     * charge.refunded
     *
     * Sincroniza reembolsos hechos desde el dashboard de Stripe (o por API)
     * hacia la contabilidad interna. Idempotente por DELTA: registra solo la
     * diferencia entre amount_refunded del charge y lo ya registrado en
     * transactions tipo refund del mismo receipt; un reintento del evento o
     * un evento ya procesado produce delta 0 y no escribe nada.
     */
    private function onChargeRefunded(object $charge): void
    {
        $receipt = $this->receiptForCharge($charge);

        if (! $receipt) {
            Log::info('Stripe: charge.refunded sin receipt local (se ignora)', [
                'charge_id'         => $charge->id ?? null,
                'payment_intent_id' => $charge->payment_intent ?? null,
            ]);
            return;
        }

        DB::transaction(function () use ($charge, $receipt) {
            $totalRefunded = round(((int) ($charge->amount_refunded ?? 0)) / 100, 2);

            $alreadyRecorded = (float) Transaction::where('receipt_id', $receipt->id)
                ->where('type', 'refund')
                ->where('status', 'completed')
                ->sum('amount');

            $delta = round($totalRefunded - $alreadyRecorded, 2);

            if ($delta <= 0) {
                Log::info('Stripe: charge.refunded ya registrado (idempotente)', [
                    'receipt_id' => $receipt->id,
                    'charge_id'  => $charge->id ?? null,
                ]);
                return;
            }

            // El id del último refund si viene expandido; si no, referencia determinista.
            $refundId = $charge->refunds->data[0]->id
                ?? (($charge->id ?? 'ch_unknown') . ':refunded:' . (int) ($charge->amount_refunded ?? 0));

            Transaction::create([
                'uuid'                    => (string) \Illuminate\Support\Str::uuid(),
                'user_id'                 => $receipt->user_id,
                'receipt_id'              => $receipt->id,
                'payment_method_id'       => null,
                'transaction_id'          => 'REF-' . \Illuminate\Support\Str::upper(\Illuminate\Support\Str::random(10)),
                'provider_transaction_id' => $refundId,
                'type'                    => 'refund',
                'status'                  => 'completed',
                'amount'                  => $delta,
                'currency'                => strtoupper((string) ($charge->currency ?? $receipt->currency ?? 'MXN')),
                'fee_amount'              => 0,
                'provider'                => 'stripe',
                'provider_data'           => [
                    'stripe' => [
                        'charge_id'         => $charge->id ?? null,
                        'payment_intent_id' => $charge->payment_intent ?? null,
                        'amount_refunded'   => $totalRefunded,
                        'source'            => 'webhook charge.refunded',
                    ],
                ],
                'description'    => $totalRefunded >= (float) $receipt->total
                    ? 'Reembolso total (sincronizado desde Stripe)'
                    : 'Reembolso parcial (sincronizado desde Stripe)',
                'failure_reason' => null,
                'processed_at'   => now(),
            ]);

            // Total → refunded; parcial → sigue paid (el monto reembolsado queda
            // registrado en transactions).
            if ($totalRefunded >= (float) $receipt->total && $receipt->status !== Receipt::STATUS_REFUNDED) {
                $receipt->update(['status' => Receipt::STATUS_REFUNDED]);
            }

            \App\Domains\Platform\Support\AdminNotifier::notify(
                'Reembolso sincronizado desde Stripe',
                "Se registró un reembolso de {$delta} " . strtoupper((string) ($charge->currency ?? 'MXN')) . " para el recibo #{$receipt->receipt_number}.",
                'admin_refund_synced',
                ['receipt_id' => $receipt->id, 'amount' => $delta],
            );
        });
    }

    /**
     * charge.dispute.created / charge.dispute.updated
     *
     * Marca el cargo en disputa: la transacción de pago y el recibo pasan a
     * 'disputed' hasta que la disputa se cierre. Idempotente (updates por estado).
     */
    private function onDisputeOpenedOrUpdated(object $dispute): void
    {
        $receipt = $this->receiptForCharge($dispute);

        if (! $receipt) {
            Log::warning('Stripe: disputa sin receipt local', [
                'dispute_id' => $dispute->id ?? null,
                'charge_id'  => $dispute->charge ?? null,
            ]);
            return;
        }

        DB::transaction(function () use ($dispute, $receipt) {
            Transaction::where('receipt_id', $receipt->id)
                ->where('type', 'payment')
                ->where('status', '!=', 'disputed')
                ->update(['status' => 'disputed']);

            if (! in_array($receipt->status, ['disputed', Receipt::STATUS_REFUNDED], true)) {
                $receipt->update(['status' => 'disputed']);
            }

            \App\Domains\Platform\Support\AdminNotifier::notify(
                'Disputa de cargo (chargeback)',
                "El recibo #{$receipt->receipt_number} está en disputa (estado Stripe: " . ($dispute->status ?? '?') . '). Responde la disputa en el dashboard de Stripe.',
                'admin_charge_disputed',
                ['receipt_id' => $receipt->id, 'dispute_id' => $dispute->id ?? null, 'dispute_status' => $dispute->status ?? null],
                ['email' => true, 'subtitle' => 'Acción requerida', 'action_url' => '/admin/invoices', 'action_text' => 'Ver recibo'],
            );
        });
    }

    /**
     * charge.dispute.closed
     *
     * won  → restaurar pago/recibo a su estado normal.
     * lost → el dinero se devolvió: registrar chargeback y marcar refunded.
     */
    private function onDisputeClosed(object $dispute): void
    {
        $receipt = $this->receiptForCharge($dispute);

        if (! $receipt) {
            return;
        }

        DB::transaction(function () use ($dispute, $receipt) {
            if (($dispute->status ?? null) === 'won') {
                Transaction::where('receipt_id', $receipt->id)
                    ->where('type', 'payment')
                    ->where('status', 'disputed')
                    ->update(['status' => 'completed']);

                if ($receipt->status === 'disputed') {
                    $receipt->update(['status' => Receipt::STATUS_PAID]);
                }

                \App\Domains\Platform\Support\AdminNotifier::notify(
                    'Disputa ganada',
                    "La disputa del recibo #{$receipt->receipt_number} se resolvió a favor. El cobro se mantiene.",
                    'admin_dispute_won',
                    ['receipt_id' => $receipt->id, 'dispute_id' => $dispute->id ?? null],
                );
                return;
            }

            // lost (o cualquier cierre no ganado): chargeback consumado.
            $amount = round(((int) ($dispute->amount ?? 0)) / 100, 2) ?: (float) $receipt->total;

            $exists = Transaction::where('receipt_id', $receipt->id)
                ->where('type', 'chargeback')
                ->where('provider_transaction_id', $dispute->id ?? '')
                ->exists();

            if (! $exists) {
                Transaction::create([
                    'uuid'                    => (string) \Illuminate\Support\Str::uuid(),
                    'user_id'                 => $receipt->user_id,
                    'receipt_id'              => $receipt->id,
                    'payment_method_id'       => null,
                    'transaction_id'          => 'CHB-' . \Illuminate\Support\Str::upper(\Illuminate\Support\Str::random(10)),
                    'provider_transaction_id' => $dispute->id ?? ('dispute:' . $receipt->id),
                    'type'                    => 'chargeback',
                    'status'                  => 'completed',
                    'amount'                  => $amount,
                    'currency'                => strtoupper((string) ($dispute->currency ?? $receipt->currency ?? 'MXN')),
                    'fee_amount'              => 0,
                    'provider'                => 'stripe',
                    'provider_data'           => ['stripe' => ['dispute_id' => $dispute->id ?? null, 'charge_id' => $dispute->charge ?? null]],
                    'description'             => 'Contracargo: disputa perdida',
                    'failure_reason'          => null,
                    'processed_at'            => now(),
                ]);
            }

            Transaction::where('receipt_id', $receipt->id)
                ->where('type', 'payment')
                ->where('status', 'disputed')
                ->update(['status' => 'refunded']);

            $receipt->update(['status' => Receipt::STATUS_REFUNDED]);

            \App\Domains\Platform\Support\AdminNotifier::notify(
                'Disputa perdida (contracargo)',
                "Se perdió la disputa del recibo #{$receipt->receipt_number}: el cargo fue devuelto al cliente.",
                'admin_dispute_lost',
                ['receipt_id' => $receipt->id, 'dispute_id' => $dispute->id ?? null],
                ['email' => true, 'subtitle' => 'Contracargo consumado'],
            );
        });
    }

    /**
     * Resuelve el Receipt local de un charge o disputa de Stripe:
     * por PaymentIntent (payment_reference) o por la transacción de pago.
     */
    private function receiptForCharge(object $chargeOrDispute): ?Receipt
    {
        $paymentIntentId = $chargeOrDispute->payment_intent ?? null;
        $paymentIntentId = is_string($paymentIntentId) ? $paymentIntentId : ($paymentIntentId->id ?? null);

        if ($paymentIntentId) {
            $receipt = Receipt::where('payment_reference', $paymentIntentId)->first();
            if ($receipt) {
                return $receipt;
            }

            $tx = Transaction::where('provider_transaction_id', $paymentIntentId)
                ->where('type', 'payment')
                ->first();
            if ($tx?->receipt_id) {
                return Receipt::find($tx->receipt_id);
            }
        }

        return null;
    }
}
