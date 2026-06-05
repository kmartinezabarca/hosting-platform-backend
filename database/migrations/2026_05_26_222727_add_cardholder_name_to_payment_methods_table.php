<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega la columna `cardholder_name` como campo de primer nivel y la
 * backfilla desde details->cardholder_name (JSON) para registros existentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->string('cardholder_name')->nullable()->after('last4');
        });

        // Backfill desde details JSON para registros ya existentes
        DB::table('payment_methods')
            ->whereNull('cardholder_name')
            ->whereNotNull('details')
            ->orderBy('id')
            ->each(function (object $row): void {
                $details = json_decode($row->details ?? '{}', true);
                $name    = $details['cardholder_name'] ?? null;

                if ($name) {
                    DB::table('payment_methods')
                        ->where('id', $row->id)
                        ->update(['cardholder_name' => $name]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->dropColumn('cardholder_name');
        });
    }
};
