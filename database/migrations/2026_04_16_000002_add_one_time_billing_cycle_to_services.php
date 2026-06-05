<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Servicios profesionales (desarrollo, consultoría, soporte) pueden ser pago único
        DB::statement("ALTER TABLE services MODIFY COLUMN billing_cycle
            ENUM('monthly','quarterly','semi_annually','annually','one_time') NOT NULL");
    }

    public function down(): void
    {
        // Primero convertir 'one_time' existentes a 'monthly' para evitar error de constraint
        DB::statement("UPDATE services SET billing_cycle = 'monthly' WHERE billing_cycle = 'one_time'");
        DB::statement("ALTER TABLE services MODIFY COLUMN billing_cycle
            ENUM('monthly','quarterly','semi_annually','annually') NOT NULL");
    }
};
