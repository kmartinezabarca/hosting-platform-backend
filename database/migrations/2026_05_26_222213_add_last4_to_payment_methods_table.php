<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega la columna `last4` (últimos 4 dígitos de la tarjeta) como campo
 * de primer nivel en payment_methods y la backfilla desde details (JSON).
 *
 * También corrige el campo `name` para que solo contenga el tipo/marca
 * de la tarjeta ("Visa", "Mastercard"…) eliminando el sufijo " •••• XXXX"
 * que se guardó en el backfill anterior.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Agregar columna
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->char('last4', 4)->nullable()->after('name');
        });

        // 2. Backfill last4 + corregir name para registros existentes
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
            ->whereNotNull('details')
            ->orderBy('id')
            ->each(function (object $row) use ($brandMap): void {
                $details = json_decode($row->details ?? '{}', true);
                if (!is_array($details)) return;

                $updates = [];

                // ── last4 ─────────────────────────────────────────────────────
                $last4 = $details['last4'] ?? null;
                if ($last4 && empty($row->last4)) {
                    $updates['last4'] = (string) $last4;
                }

                // ── name: quitar " •••• XXXX" o cualquier sufijo con dígitos ─
                // Solo toca el campo si todavía tiene el formato compuesto.
                $currentName = trim($row->name ?? '');
                if (preg_match('/\s+[•·\*]+\s+\d{4}$/', $currentName)) {
                    $brand      = strtolower($details['brand'] ?? '');
                    $brandLabel = $brandMap[$brand] ?? ucfirst($brand ?: 'Tarjeta');
                    $updates['name'] = $brandLabel;
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
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->dropColumn('last4');
        });
    }
};
