<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Periodo de gracia y metadatos de suspensión a nivel de servicio.
 *
 * Permite mostrar al cliente un banner ("tienes N días para pagar") y registrar
 * por qué y cuándo se suspendió, para una reactivación segura tras el pago.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            if (! Schema::hasColumn('services', 'grace_period_ends_at')) {
                $table->timestamp('grace_period_ends_at')->nullable()->after('next_due_date');
            }
            if (! Schema::hasColumn('services', 'suspended_at')) {
                $table->timestamp('suspended_at')->nullable()->after('grace_period_ends_at');
            }
            if (! Schema::hasColumn('services', 'suspension_reason')) {
                $table->string('suspension_reason')->nullable()->after('suspended_at');
            }
            $table->index('grace_period_ends_at');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropIndex(['grace_period_ends_at']);
            $table->dropColumn(['grace_period_ends_at', 'suspended_at', 'suspension_reason']);
        });
    }
};
