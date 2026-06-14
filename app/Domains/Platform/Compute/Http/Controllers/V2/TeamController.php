<?php

namespace App\Domains\Platform\Compute\Http\Controllers\V2;

use App\Domains\Platform\Compute\Models\Team;
use App\Domains\Platform\Compute\Plans\PlanLimits;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TeamController extends Controller
{
    /**
     * GET /api/v2/teams — equipos donde el usuario es miembro.
     */
    public function index(Request $request, PlanLimits $planLimits): JsonResponse
    {
        $teams = Team::forUser($request->user())
            ->withCount('projects')
            ->orderByDesc('is_personal')
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data'    => $teams->map(fn (Team $team) => [
                'uuid'           => $team->uuid,
                'name'           => $team->name,
                'slug'           => $team->slug,
                'plan_tier'        => $team->plan_tier,
                'billing_interval' => $team->billing_interval,
                'billing_status'   => $team->billing_status,
                'current_period_ends_at' => $team->current_period_ends_at?->toIso8601String(),
                'is_personal'      => $team->is_personal,
                'projects_count' => $team->projects_count,
                'role'           => $team->roleFor($request->user())?->value,
                // Uso vs. cupo del plan, para que la UI muestre "2/2 recursos".
                'usage'          => $planLimits->usage($team),
            ]),
        ]);
    }
}
