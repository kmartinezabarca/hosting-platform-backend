<?php

namespace App\Domains\Platform\Compute\Http\Controllers\V2;

use App\Domains\Platform\Compute\Plans\PlanCatalog;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class PlanController extends Controller
{
    /**
     * GET /api/v2/plans — catálogo de planes de cómputo con precios mensual y
     * anual (y el ahorro del anual). Lo consume la tabla de precios del portal.
     */
    public function index(PlanCatalog $catalog): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => [
                'currency'         => $catalog->currency(),
                'billing_intervals' => \App\Domains\Platform\Compute\Enums\BillingInterval::values(),
                'plans'            => $catalog->all(),
            ],
        ]);
    }
}
