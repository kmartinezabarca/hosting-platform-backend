<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Actualiza receipts.payment_method de 'stripe' al tipo real de tarjeta,
 * leyendo provider_data de la tabla transactions.
 *
 * Stripe es el gateway; el método de pago real es:
 *   - Tarjeta de crédito (Visa ****1234)
 *   - Tarjeta de débito  (Mastercard ****5678)
 */
return new class extends Migration
{
    public function up(): void
    {
        $receipts = DB::table('receipts')
            ->where('payment_method', 'stripe')
            ->get(['id']);

        foreach ($receipts as $receipt) {
            $transaction = DB::table('transactions')
                ->where('invoice_id', $receipt->id)
                ->where('type', 'payment')
                ->whereNotNull('provider_data')
                ->latest()
                ->first();

            if (!$transaction) {
                continue;
            }

            $providerData = is_string($transaction->provider_data)
                ? json_decode($transaction->provider_data, true)
                : (array) $transaction->provider_data;

            $card = $providerData['stripe']['card'] ?? null;

            if (!$card) {
                continue;
            }

            DB::table('receipts')
                ->where('id', $receipt->id)
                ->update(['payment_method' => $this->buildLabel($card)]);
        }
    }

    public function down(): void
    {
        // Revertir valores descriptivos a 'stripe'
        DB::table('receipts')
            ->where('payment_method', 'like', 'Tarjeta%')
            ->update(['payment_method' => 'stripe']);
    }

    private function buildLabel(array $card): string
    {
        $funding = strtolower($card['funding'] ?? '');
        $brand   = ucfirst(strtolower($card['brand'] ?? ''));
        $last4   = $card['last4'] ?? null;

        $type = match ($funding) {
            'credit'  => 'Tarjeta de crédito',
            'debit'   => 'Tarjeta de débito',
            'prepaid' => 'Tarjeta prepago',
            default   => 'Tarjeta',
        };

        if ($brand && $last4) {
            return "{$type} ({$brand} ****{$last4})";
        }

        return $brand ? "{$type} ({$brand})" : $type;
    }
};
