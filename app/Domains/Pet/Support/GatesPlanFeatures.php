<?php

namespace App\Domains\Pet\Support;

use App\Domains\Pet\Models\OwnerSubscription;
use Illuminate\Http\JsonResponse;

/**
 * Gating de features por plan para los controladores del dominio roke.pet.
 *
 * Uso en un controlador:
 *   if ($r = $this->requirePlanFeature($request->user()->uuid, 'vet_links')) return $r;
 *
 * Devuelve un 403 con código 'feature_not_in_plan' si el plan del dueño no
 * incluye la feature, o null si sí la incluye (puede continuar).
 */
trait GatesPlanFeatures
{
    protected function requirePlanFeature(string $ownerId, string $featureKey): ?JsonResponse
    {
        $sub = OwnerSubscription::where('owner_id', $ownerId)->first();

        if ($sub && $sub->hasFeature($featureKey)) {
            return null;
        }

        return response()->json([
            'error'   => 'Esta función está disponible en un plan superior. Mejora tu plan para usarla.',
            'code'    => 'feature_not_in_plan',
            'feature' => $featureKey,
        ], 403);
    }
}
