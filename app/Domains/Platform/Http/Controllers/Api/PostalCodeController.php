<?php

namespace App\Domains\Platform\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Domains\Platform\Models\PostalCode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;

class PostalCodeController extends Controller
{
    /**
     * Search for postal code information.
     */
    public function search(Request $request, $code)
    {
        $country = $request->query('country', 'MX');
        
        $results = PostalCode::byCode($code, $country)->get();
        
        if ($results->isEmpty()) {
            return Response::json([
                'success' => false,
                'message' => 'Postal code not found',
            ], 404);
        }

        // Agrupar colonias si hay múltiples registros para el mismo CP
        $first = $results->first();
        
        return Response::json([
            'success' => true,
            'data' => [
                'postal_code' => $first->postal_code,
                'state' => $first->state,
                'city' => $first->city,
                'township' => $first->township,
                'country' => $first->country,
                'colonies' => $results->pluck('township')->unique()->values(),
            ]
        ]);
    }
}
