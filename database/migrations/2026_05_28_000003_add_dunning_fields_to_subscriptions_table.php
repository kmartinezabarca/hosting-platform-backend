<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campos de "dunning" (gestión de morosidad) para suscripciones.
 *
 * Cuando un cobro de renovación falla, la suscripción entra en `past_due` y se
 * abre un periodo de gracia. El servicio NO se suspende de inmediato; el comando
 * subscriptions:process-overdue lo suspende sólo al vencer `grace_period_ends_at`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->timestamp('payment_failed_at')->nullable()->after('ends_at');
            $table->timestamp('grace_period_ends_at')->nullable()->after('payment_failed_at');
            $table->timestamp('next_payment_attempt')->nullable()->after('grace_period_ends_at');
            $table->text('last_payment_error')->nullable()->after('next_payment_attempt');
            $table->timestamp('suspended_at')->nullable()->after('last_payment_error');
            $table->string('suspension_reason')->nullable()->after('suspended_at');

            $table->index('grace_period_ends_at');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropIndex(['grace_period_ends_at']);
            $table->dropColumn([
                'payment_failed_at',
                'grace_period_ends_at',
                'next_payment_attempt',
                'last_payment_error',
                'suspended_at',
                'suspension_reason',
            ]);
        });
    }
};
