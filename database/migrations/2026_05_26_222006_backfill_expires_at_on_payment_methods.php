<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rellena expires_at en payment_methods que tienen exp_month/exp_year
 * guardados dentro de la columna `details` (JSON) pero no tienen la fecha
 * de expiración en la columna dedicada.
 *
 * expires_at = último instante del mes de expiración de la tarjeta.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('payment_methods')
            ->whereNull('expires_at')
            ->whereNotNull('details')
            ->orderBy('id')
            ->each(function (object $row): void {
                $details = json_decode($row->details ?? '{}', true);

                $expMonth = (int) ($details['exp_month'] ?? 0);
                $expYear  = (int) ($details['exp_year']  ?? 0);

                if ($expMonth < 1 || $expMonth > 12 || $expYear < 2000) {
                    return; // datos inválidos — omitir
                }

                $expiresAt = Carbon::createFromDate($expYear, $expMonth, 1)
                    ->endOfMonth()
                    ->startOfDay()
                    ->toDateTimeString();

                DB::table('payment_methods')
                    ->where('id', $row->id)
                    ->update(['expires_at' => $expiresAt]);
            });
    }

    public function down(): void
    {
        // No reversible de forma fiable.
    }
};
