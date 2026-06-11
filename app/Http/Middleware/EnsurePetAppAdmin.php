<?php

namespace App\Http\Middleware;

use App\Domains\Pet\Models\AppAdmin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guard de ruta para el panel admin de roke.pet (/api/rp/admin/*).
 *
 * Los controladores ya verifican app_admins por método (requireAdmin); este
 * middleware añade la misma comprobación A NIVEL DE RUTA para que un endpoint
 * admin futuro no pueda quedar expuesto por olvidar la llamada en el método.
 */
class EnsurePetAppAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! AppAdmin::where('user_id', $user->uuid)->exists()) {
            return response()->json(['error' => 'Acceso denegado'], 403);
        }

        return $next($request);
    }
}
