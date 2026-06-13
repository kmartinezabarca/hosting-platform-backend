<?php

use App\Domains\Platform\Models\ServicePlan;
use Illuminate\Database\Migrations\Migration;

/**
 * Alinea el copy de los planes de hosting con lo que realmente entregamos:
 *  - "X Cuentas de email" → no provisionamos buzones; el correo se configura
 *    con un proveedor externo (Google/Zoho). El cliente paga al proveedor.
 *  - "Panel de control cPanel" → usamos Coolify, no cPanel.
 *
 * Evita re-seedear (que resetearía precios). Actualiza solo las filas existentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        ServicePlan::query()
            ->where('provisioner', 'coolify')
            ->with('features')
            ->get()
            ->each(function (ServicePlan $plan) {
                foreach ($plan->features as $feature) {
                    $text = (string) $feature->feature;

                    if (preg_match('/email|correo/i', $text)) {
                        $feature->update(['feature' => 'Configuración de correo Google/Zoho (proveedor aparte)']);
                    } elseif (stripos($text, 'cpanel') !== false) {
                        $feature->update(['feature' => 'Panel de control web (Coolify)']);
                    }
                }

                $specs = $plan->specifications ?? [];
                if (array_key_exists('email', $specs)) {
                    $specs['email'] = 'Google/Zoho';
                    $plan->forceFill(['specifications' => $specs])->save();
                }
            });
    }

    public function down(): void
    {
        // Cambio de copy hacia la realidad: no se revierte (los textos previos
        // prometían servicios que no se entregan).
    }
};
