<?php

namespace App\Http\Controllers\Common;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\PaymentNotification;
use App\Notifications\ServiceNotification;
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

        $object = $event->data->object;

        match ($event->type) {
            'payment_intent.succeeded'         => $this->onPaymentIntentSucceeded($object),
            'payment_intent.payment_failed'    => $this->onPaymentIntentFailed($object),
            'invoice.payment_succeeded'        => $this->onInvoicePaymentSucceeded($object),
            'invoice.payment_failed'           => $this->onInvoicePaymentFailed($object),
            'customer.subscription.updated'    => $this->onSubscriptionUpdated($object),
            'customer.subscription.deleted'    => $this->onSubscriptionDeleted($object),
            default                            => Log::info("Stripe webhook ignored: {$event->type}"),
        };

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
                if ($invoice && $invoice->status !== Invoice::STATUS_PAID) {
                    $invoice->update([
                        'status'  => Invoice::STATUS_PAID,
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
        $subId = $stripeInvoice->subscription ?? null;
        if (!$subId) {
            return;
        }

        Log::info("Stripe: invoice.payment_succeeded subscription={$subId}");

        DB::transaction(function () use ($stripeInvoice, $subId) {
            $subscription = Subscription::where('stripe_subscription_id', $subId)->first();

            if (!$subscription) {
                return;
            }

            $periodEnd = isset($stripeInvoice->lines->data[0]->period->end)
                ? Carbon::createFromTimestamp($stripeInvoice->lines->data[0]->period->end)
                : null;

            $subscription->update([
                'status'               => 'active',
                'current_period_end'   => $periodEnd,
                'ends_at'              => $periodEnd,
            ]);

            // Keep the service active and push next due date
            $service = $subscription->service;
            if ($service) {
                $service->update([
                    'status'        => 'active',
                    'next_due_date' => $periodEnd,
                ]);
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
        $subId = $stripeInvoice->subscription ?? null;
        if (!$subId) {
            return;
        }

        Log::warning("Stripe: invoice.payment_failed subscription={$subId}");

        DB::transaction(function () use ($stripeInvoice, $subId) {
            $subscription = Subscription::where('stripe_subscription_id', $subId)->first();

            if (!$subscription) {
                return;
            }

            $subscription->update(['status' => 'past_due']);

            // Suspend the service
            $service = $subscription->service;
            if ($service) {
                $service->update(['status' => 'suspended']);
            }

            // Notify user
            $user = $subscription->user;
            if ($user) {
                $user->notify(new ServiceNotification([
                    'title'   => 'Fallo en el pago de suscripción',
                    'message' => "No pudimos cobrar tu suscripción '{$subscription->name}'. Tu servicio ha sido suspendido. Por favor actualiza tu método de pago.",
                    'type'    => 'subscription.payment_failed',
                    'data'    => ['subscription_id' => $subscription->uuid],
                ]));
            }
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

        $subscription->update([
            'status'               => $stripeSub->status,
            'current_period_start' => isset($stripeSub->current_period_start) ? Carbon::createFromTimestamp($stripeSub->current_period_start) : null,
            'current_period_end'   => isset($stripeSub->current_period_end)   ? Carbon::createFromTimestamp($stripeSub->current_period_end)   : null,
            'ends_at'              => isset($stripeSub->cancel_at)            ? Carbon::createFromTimestamp($stripeSub->cancel_at)            : null,
            'canceled_at'          => isset($stripeSub->canceled_at)          ? Carbon::createFromTimestamp($stripeSub->canceled_at)          : null,
        ]);
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

            $subscription->update([
                'status'      => 'cancelled',
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
        });
    }
}
