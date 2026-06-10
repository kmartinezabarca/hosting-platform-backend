<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El código escribe estados que el ENUM de services.status no aceptaba:
 *   - 'cancelled'   → ServiceController::cancelService (cancelación inmediata),
 *                     StripeWebhookController::onSubscriptionDeleted,
 *                     DashboardStatsService (conteo).
 *   - 'maintenance' → AdminController::updateServiceStatus (Rule::in lo permite).
 *
 * En MySQL strict mode cada UPDATE con esos valores lanzaba QueryException:
 * la cancelación de servicio del cliente y el webhook customer.subscription.deleted
 * fallaban siempre. Ampliación aditiva del ENUM (no destruye datos existentes).
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement("ALTER TABLE services MODIFY COLUMN status ENUM('pending','active','suspended','terminated','failed','cancelled','maintenance') NOT NULL DEFAULT 'pending'");
    }

    public function down(): void
    {
        // Reasignar valores nuevos a equivalentes previos antes de contraer el ENUM.
        DB::statement("UPDATE services SET status = 'terminated' WHERE status = 'cancelled'");
        DB::statement("UPDATE services SET status = 'suspended'  WHERE status = 'maintenance'");
        DB::statement("ALTER TABLE services MODIFY COLUMN status ENUM('pending','active','suspended','terminated','failed') NOT NULL DEFAULT 'pending'");
    }
};
