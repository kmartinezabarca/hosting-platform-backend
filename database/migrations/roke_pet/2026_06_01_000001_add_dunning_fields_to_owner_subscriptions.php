<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campos de morosidad (dunning) y avisos de vencimiento para las suscripciones
 * de roke.pet.
 *
 * Flujo de gracia:
 *   1. invoice.payment_failed → status past_due + payment_failed_at = now()
 *      + grace_period_ends_at = now()+N días (la cuenta sigue activa durante la gracia).
 *   2. Si paga (invoice.paid) → se limpian estos campos y vuelve a active.
 *   3. Si la gracia vence sin pago → comando rokepet:process-overdue-subscriptions
 *      degrada la cuenta al plan gratuito.
 *
 * expiry_notified_at evita reenviar el aviso de "tu plan está por vencer" en cada
 * corrida del scheduler; se reinicia cuando la suscripción se renueva.
 */
return new class extends Migration
{
    protected $connection = 'roke_pet';

    public function up(): void
    {
        $schema = Schema::connection('roke_pet');

        $schema->table('owner_subscriptions', function (Blueprint $table) use ($schema) {
            if (! $schema->hasColumn('owner_subscriptions', 'payment_failed_at')) {
                $table->timestamp('payment_failed_at')->nullable()->after('current_period_end');
            }
            if (! $schema->hasColumn('owner_subscriptions', 'grace_period_ends_at')) {
                $table->timestamp('grace_period_ends_at')->nullable()->after('payment_failed_at');
            }
            if (! $schema->hasColumn('owner_subscriptions', 'last_payment_error')) {
                $table->string('last_payment_error')->nullable()->after('grace_period_ends_at');
            }
            if (! $schema->hasColumn('owner_subscriptions', 'expiry_notified_at')) {
                $table->timestamp('expiry_notified_at')->nullable()->after('last_payment_error');
            }
        });
    }

    public function down(): void
    {
        $schema = Schema::connection('roke_pet');

        $schema->table('owner_subscriptions', function (Blueprint $table) use ($schema) {
            foreach (['payment_failed_at', 'grace_period_ends_at', 'last_payment_error', 'expiry_notified_at'] as $col) {
                if ($schema->hasColumn('owner_subscriptions', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
