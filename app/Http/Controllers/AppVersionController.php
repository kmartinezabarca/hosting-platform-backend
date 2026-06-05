<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

/**
 * Returns the currently deployed backend version.
 * Public route — no authentication required.
 *
 * Values come from environment variables set by the deployment script
 * (same values that Jenkins writes into the frontend .env and version.json).
 *
 * GET /api/app/version
 */
class AppVersionController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json([
            'app'       => config('app.name'),
            'env'       => app()->environment(),
            'version'   => config('version.number'),
            'buildId'   => config('version.build_id'),
            'commit'    => config('version.commit'),
            'builtAt'   => config('version.built_at'),
        ]);
    }
}
