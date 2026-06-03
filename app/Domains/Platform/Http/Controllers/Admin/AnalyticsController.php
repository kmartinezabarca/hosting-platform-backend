<?php

namespace App\Domains\Platform\Http\Controllers\Admin;

use App\Domains\Platform\Services\AnalyticsService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Revenue / subscription analytics. Restricted to super_admin / admin
 * (revenue data is financial; support does not see finances).
 */
class AnalyticsController extends Controller
{
    public function __construct(private readonly AnalyticsService $analytics) {}

    public function overview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'range' => ['nullable', 'in:7d,30d,90d,12m'],
        ]);

        $data = $this->analytics->overview($validated['range'] ?? '30d');

        return response()->json(['success' => true, 'data' => $data]);
    }
}
