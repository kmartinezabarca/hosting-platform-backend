<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * Lectura defensiva de objetos de Stripe (webhooks y respuestas del API).
 *
 * stripe-php v17 usa por defecto el API "Basil" (2025-03-31), que MOVIÓ campos
 * clave de facturación:
 *
 *   - invoice.subscription                → invoice.parent.subscription_details.subscription
 *                                           (y también en invoice.lines.data[].parent...)
 *   - subscription.current_period_start/end (nivel raíz, REMOVIDOS)
 *                                           → subscription.items.data[].current_period_start/end
 *
 * El API version de un evento de webhook lo fija la cuenta (o el endpoint), no
 * la librería; por eso leemos AMBAS ubicaciones (legacy y Basil) para ser
 * robustos sin importar la versión que envíe Stripe.
 */
class StripeObjectReader
{
    /**
     * Convierte un timestamp Unix (o null) a Carbon.
     */
    public static function timestamp(mixed $value): ?Carbon
    {
        return $value ? Carbon::createFromTimestamp((int) $value) : null;
    }

    /**
     * Resuelve el ID de la suscripción a partir de un objeto invoice,
     * soportando formato legacy y Basil.
     */
    public static function subscriptionIdFromInvoice(object $invoice): ?string
    {
        // Legacy (≤ 2025-02): invoice.subscription
        $legacy = $invoice->subscription ?? null;
        if (!empty($legacy)) {
            return is_string($legacy) ? $legacy : ($legacy->id ?? null);
        }

        // Basil: invoice.parent.subscription_details.subscription
        $basil = $invoice->parent?->subscription_details?->subscription ?? null;
        if (!empty($basil)) {
            return is_string($basil) ? $basil : ($basil->id ?? null);
        }

        // Fallback Basil: primer line item → parent.subscription_item_details.subscription
        $line = $invoice->lines->data[0] ?? null;
        $fromLine = $line?->parent?->subscription_item_details?->subscription ?? null;
        if (!empty($fromLine)) {
            return is_string($fromLine) ? $fromLine : ($fromLine->id ?? null);
        }

        return null;
    }

    /**
     * Fin del periodo facturado según el primer line item de la invoice.
     * Esta ubicación (lines.data[].period.end) NO cambió en Basil.
     */
    public static function periodEndFromInvoice(object $invoice): ?Carbon
    {
        return self::timestamp($invoice->lines->data[0]->period->end ?? null);
    }

    /**
     * Inicio del periodo facturado según el primer line item de la invoice.
     */
    public static function periodStartFromInvoice(object $invoice): ?Carbon
    {
        return self::timestamp($invoice->lines->data[0]->period->start ?? null);
    }

    /**
     * PaymentIntent ID de una invoice (legacy invoice.payment_intent o Basil
     * invoice.payments.data[0].payment.payment_intent).
     */
    public static function paymentIntentIdFromInvoice(object $invoice): ?string
    {
        $legacy = $invoice->payment_intent ?? null;
        if (!empty($legacy)) {
            return is_string($legacy) ? $legacy : ($legacy->id ?? null);
        }

        $basil = $invoice->payments->data[0]->payment->payment_intent ?? null;
        if (!empty($basil)) {
            return is_string($basil) ? $basil : ($basil->id ?? null);
        }

        return null;
    }

    /**
     * Charge ID de una invoice (legacy invoice.charge o Basil payments[]).
     */
    public static function chargeIdFromInvoice(object $invoice): ?string
    {
        $legacy = $invoice->charge ?? null;
        if (!empty($legacy)) {
            return is_string($legacy) ? $legacy : ($legacy->id ?? null);
        }

        $basil = $invoice->payments->data[0]->payment->charge ?? null;
        if (!empty($basil)) {
            return is_string($basil) ? $basil : ($basil->id ?? null);
        }

        return null;
    }

    /**
     * current_period_start de una suscripción (raíz legacy o por ítem en Basil).
     */
    public static function subscriptionPeriodStart(object $sub): ?Carbon
    {
        $root = $sub->current_period_start ?? null;
        if (!empty($root)) {
            return self::timestamp($root);
        }

        return self::timestamp($sub->items->data[0]->current_period_start ?? null);
    }

    /**
     * current_period_end de una suscripción (raíz legacy o por ítem en Basil).
     */
    public static function subscriptionPeriodEnd(object $sub): ?Carbon
    {
        $root = $sub->current_period_end ?? null;
        if (!empty($root)) {
            return self::timestamp($root);
        }

        return self::timestamp($sub->items->data[0]->current_period_end ?? null);
    }
}
