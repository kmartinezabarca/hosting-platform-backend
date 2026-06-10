<?php

namespace App\Domains\Platform\Services;

use App\Domains\Platform\Models\CustomerFiscalProfile;
use App\Domains\Platform\Models\Invoice;
use App\Domains\Platform\Models\Receipt;
use App\Domains\Platform\Models\Subscription;
use App\Domains\Platform\Models\Transaction;
use App\Domains\Platform\Services\Factura\CfdiService;
use App\Support\StripeObjectReader;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Registra la contabilidad interna de una RENOVACIÓN de suscripción cobrada
 * por Stripe (invoice.paid / invoice.payment_succeeded):
 *
 *   Receipt (comprobante) + ReceiptItems + Transaction + Invoice (CFDI).
 *
 * Antes de esto las renovaciones solo actualizaban estados: el cliente pagaba
 * cada mes y no quedaba NINGÚN registro contable ni fiscal interno.
 *
 * Idempotencia (los webhooks se reintentan y invoice.paid +
 * invoice.payment_succeeded llegan como DOS eventos distintos para la misma
 * factura): se deduplica por receipts.provider_invoice_id = invoice de Stripe.
 */
class RenewalAccountingService
{
    public function __construct(private readonly InvoiceService $invoiceService)
    {
    }

    /**
     * Crea (una sola vez) los registros contables de la renovación.
     * Devuelve el Receipt creado o existente; null si no aplica (p. ej. $0).
     *
     * Debe llamarse DENTRO de la transacción del webhook; el timbrado CFDI y
     * el PDF se difieren a afterCommit.
     */
    public function recordRenewal(Subscription $subscription, object $stripeInvoice): ?Receipt
    {
        $stripeInvoiceId = $stripeInvoice->id ?? null;
        if (! $stripeInvoiceId) {
            return null;
        }

        // ── Idempotencia: una factura de Stripe → un Receipt ────────────────
        $existing = Receipt::where('gateway', 'stripe')
            ->where('provider_invoice_id', $stripeInvoiceId)
            ->first();
        if ($existing) {
            return $existing;
        }

        // ── No aplica: facturas $0 ──────────────────────────────────────────
        // La primera invoice de una suscripción anclada (trial_end) es de $0;
        // el primer periodo ya tiene su Receipt del flujo de contratación.
        $totalCents = (int) ($stripeInvoice->amount_paid ?? $stripeInvoice->total ?? 0);
        if ($totalCents <= 0) {
            return null;
        }

        // ── No aplica: invoice de creación cuyo PI ya tiene Receipt ─────────
        // (suscripciones legadas sin ancla: el cargo inicial ya se contabilizó
        // en la contratación con el mismo PaymentIntent).
        $paymentIntentId = StripeObjectReader::paymentIntentIdFromInvoice($stripeInvoice);
        if ($paymentIntentId && Receipt::where('payment_reference', $paymentIntentId)->exists()) {
            return null;
        }

        $service = $subscription->service;
        $user    = $subscription->user;

        if (! $user) {
            Log::warning('RenewalAccounting: suscripción sin usuario, se omite.', [
                'subscription_id' => $subscription->id,
            ]);
            return null;
        }

        $plan        = $service?->plan;
        $currency    = strtoupper((string) ($stripeInvoice->currency ?? $subscription->currency ?? 'MXN'));
        $total       = round($totalCents / 100, 2);
        $taxRatePct  = (float) config('billing.tax_rate_percent', 16.00);

        // Stripe puede traer subtotal/tax; si no, derivar del total con la tasa local.
        $subtotalCents = (int) ($stripeInvoice->subtotal ?? 0);
        $taxCents      = (int) ($stripeInvoice->tax ?? 0);

        if ($subtotalCents > 0 && $taxCents > 0) {
            $subtotal = round($subtotalCents / 100, 2);
            $tax      = round($taxCents / 100, 2);
        } else {
            $subtotal = round($total / (1 + $taxRatePct / 100), 2);
            $tax      = round($total - $subtotal, 2);
        }

        $periodStart = StripeObjectReader::periodStartFromInvoice($stripeInvoice);
        $periodEnd   = StripeObjectReader::periodEndFromInvoice($stripeInvoice);
        $periodLabel = $periodStart && $periodEnd
            ? sprintf('%s — %s', $periodStart->format('d/m/Y'), $periodEnd->format('d/m/Y'))
            : 'periodo de renovación';

        $chargeId = StripeObjectReader::chargeIdFromInvoice($stripeInvoice);

        // ── Receipt + items ─────────────────────────────────────────────────
        $receipt = $this->invoiceService->createWithItems(
            [
                'user_id'             => $user->id,
                'service_id'          => $service?->id,
                'status'              => Receipt::STATUS_PAID,
                'due_date'            => now(),
                'paid_at'             => now(),
                'payment_method'      => 'Renovación automática (Stripe)',
                'payment_reference'   => $paymentIntentId ?? $stripeInvoiceId,
                'provider_invoice_id' => $stripeInvoiceId,
                'gateway'             => 'stripe',
                'notes'               => "Renovación de suscripción '{$subscription->name}' · {$periodLabel}",
                'currency'            => $currency,
                'subtotal'            => $subtotal,
                'tax_rate'            => $taxRatePct,
                'tax_amount'          => $tax,
                'total'               => $total,
            ],
            [[
                'service_id'          => $service?->id,
                'description'         => sprintf(
                    '%s — renovación (%s)',
                    $plan?->name ?? $subscription->name,
                    $periodLabel
                ),
                'quantity'            => 1,
                'unit_price'          => $subtotal,
                'sat_clave_prod_serv' => $plan?->sat_clave_prod_serv ?? config('facturama.clave_prod_serv'),
                'sat_clave_unidad'    => $plan?->sat_clave_unidad    ?? config('facturama.clave_unidad', 'E48'),
            ]]
        );

        // ── Transaction ─────────────────────────────────────────────────────
        Transaction::create([
            'uuid'                    => (string) Str::uuid(),
            'user_id'                 => $user->id,
            'receipt_id'              => $receipt->id,
            'payment_method_id'       => null,
            'transaction_id'          => 'TRX-' . Str::upper(Str::random(10)),
            'provider_transaction_id' => $paymentIntentId ?? $stripeInvoiceId,
            'type'                    => 'payment',
            'status'                  => 'completed',
            'amount'                  => $total,
            'currency'                => $currency,
            'fee_amount'              => 0,
            'provider'                => 'stripe',
            'provider_data'           => [
                'stripe' => [
                    'invoice_id'        => $stripeInvoiceId,
                    'subscription_id'   => $subscription->stripe_subscription_id,
                    'payment_intent_id' => $paymentIntentId,
                    'charge_id'         => $chargeId,
                    'period_start'      => $periodStart?->toIso8601String(),
                    'period_end'        => $periodEnd?->toIso8601String(),
                ],
            ],
            'description'    => "Renovación de suscripción '{$subscription->name}'",
            'failure_reason' => null,
            'processed_at'   => now(),
        ]);

        // ── CFDI: mismas reglas de negocio que la contratación ──────────────
        //   perfil fiscal default del usuario → timbrar; sin perfil → Público
        //   en General programado a 72 h.
        $fiscalData = CustomerFiscalProfile::where('user_id', $user->id)
            ->where('is_default', true)
            ->first()?->toInvoiceData();

        if ($fiscalData && $service) {
            $cfdiInvoice = Invoice::create([
                'service_id'         => $service->id,
                'receipt_id'         => $receipt->id,
                'rfc'                => strtoupper(trim($fiscalData['rfc'])),
                'name'               => strtoupper(trim($fiscalData['name'])),
                'zip'                => $fiscalData['zip'],
                'regimen'            => $fiscalData['regimen'],
                'cfdi_use_code'      => $fiscalData['cfdi_use_code'],
                'constancia'         => $fiscalData['constancia'] ?? null,
                'cfdi_status'        => Invoice::CFDI_PENDING_STAMP,
                'is_publico_general' => false,
            ]);
        } elseif ($service) {
            $cfdiInvoice = Invoice::create(array_merge(
                Invoice::publicoGeneralDefaults($service->id),
                ['receipt_id' => $receipt->id],
            ));
        } else {
            $cfdiInvoice = null;
        }

        // ── Post-commit: timbrado + PDF (no fatales) ────────────────────────
        DB::afterCommit(function () use ($receipt, $cfdiInvoice, $fiscalData) {
            if ($cfdiInvoice && $fiscalData) {
                try {
                    app(CfdiService::class)->stamp($cfdiInvoice->fresh());
                } catch (\Throwable $e) {
                    Log::error('RenewalAccounting: timbrado CFDI de renovación falló (no fatal)', [
                        'invoice_id' => $cfdiInvoice->id,
                        'error'      => $e->getMessage(),
                    ]);
                }
            }

            try {
                app(PaymentReceiptService::class)->generate($receipt->fresh(['user', 'items', 'service.plan']));
            } catch (\Throwable $e) {
                Log::warning('RenewalAccounting: PDF de comprobante de renovación falló (no fatal)', [
                    'receipt_id' => $receipt->id,
                    'error'      => $e->getMessage(),
                ]);
            }
        });

        Log::info('RenewalAccounting: renovación contabilizada', [
            'receipt_id'        => $receipt->id,
            'subscription_id'   => $subscription->id,
            'stripe_invoice_id' => $stripeInvoiceId,
            'total'             => $total,
            'currency'          => $currency,
        ]);

        return $receipt;
    }
}
