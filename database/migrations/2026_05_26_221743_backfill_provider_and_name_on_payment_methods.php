<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill payment_methods con datos que faltaban al momento de crear el registro:
 *
 *  - provider     → siempre 'stripe' cuando stripe_payment_method_id no es NULL
 *  - provider_id  → espejo de stripe_payment_method_id
 *  - name         → reemplaza el formato feo "**** **** **** 1234" por "Visa •••• 1234"
 *                   usando los datos ya guardados en la columna `details` (JSON).
 */
return new class extends Migration
{
    public function up(): void
    {
        $brandMap = [
            'visa'             => 'Visa',
            'mastercard'       => 'Mastercard',
            'amex'             => 'American Express',
            'discover'         => 'Discover',
            'diners'           => 'Diners Club',
            'diners_club'      => 'Diners Club',
            'jcb'              => 'JCB',
            'unionpay'         => 'UnionPay',
            'cartes_bancaires' => 'Cartes Bancaires',
        ];

        DB::table('payment_methods')
            ->whereNotNull('stripe_payment_method_id')
            ->orderBy('id')
            ->each(function (object $row) use ($brandMap): void {
                $updates = [];

                // ── provider ──────────────────────────────────────────────────
                if (empty($row->provider)) {
                    $updates['provider'] = 'stripe';
                }

                // ── provider_id ───────────────────────────────────────────────
                if (empty($row->provider_id)) {
                    $updates['provider_id'] = $row->stripe_payment_method_id;
                }

                // ── name ──────────────────────────────────────────────────────
                // Solo re-genera el nombre si luce como el fallback antiguo ("**** **** **** XXXX")
                // o si está vacío, para no pisar nombres que el usuario haya personalizado.
                $needsNameFix = empty($row->name)
                    || (bool) preg_match('/^\*{4}\s+\*{4}\s+\*{4}\s+\d{4}$/', trim($row->name ?? ''));

                if ($needsNameFix) {
                    $details = [];
                    if (!empty($row->details)) {
                        $decoded = json_decode($row->details, true);
                        if (is_array($decoded)) {
                            $details = $decoded;
                        }
                    }

                    $brand = strtolower($details['brand'] ?? '');
                    $last4 = $details['last4'] ?? null;

                    if ($last4) {
                        $brandLabel = $brandMap[$brand] ?? ucfirst($brand ?: 'Tarjeta');
                        $updates['name'] = "{$brandLabel} \u{2022}\u{2022}\u{2022}\u{2022} {$last4}";
                    }
                }

                if (!empty($updates)) {
                    DB::table('payment_methods')
                        ->where('id', $row->id)
                        ->update($updates);
                }
            });
    }

    public function down(): void
    {
        // No reversible — no se puede recuperar el formato original de forma fiable.
    }
};
