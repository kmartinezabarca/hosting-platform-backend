<?php

namespace App\Domains\Platform\Http\Controllers\Admin;

use App\Domains\Platform\Models\AuditLog;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only access to the administrative audit trail. Restricted to
 * super_admin via the route group.
 */
class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->get('per_page', 25), 100);

        $logs = AuditLog::query()
            ->when($request->filled('actor_id'),    fn ($q) => $q->where('actor_id', $request->get('actor_id')))
            ->when($request->filled('action'),      fn ($q) => $q->where('action', $request->get('action')))
            ->when($request->filled('target_type'), fn ($q) => $q->where('target_type', $request->get('target_type')))
            ->when($request->filled('from'),        fn ($q) => $q->where('created_at', '>=', $request->date('from')))
            ->when($request->filled('to'),          fn ($q) => $q->where('created_at', '<=', $request->date('to')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = trim((string) $request->get('search'));
                $q->where(fn ($qq) => $qq
                    ->where('description', 'like', "%{$s}%")
                    ->orWhere('action', 'like', "%{$s}%")
                    ->orWhere('actor_name', 'like', "%{$s}%")
                    ->orWhere('actor_email', 'like', "%{$s}%"));
            })
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return response()->json(['success' => true, 'data' => $logs]);
    }

    /**
     * Distinct action keys present in the log (for filter dropdowns).
     */
    public function actions(): JsonResponse
    {
        $actions = AuditLog::query()
            ->select('action')
            ->distinct()
            ->orderBy('action')
            ->pluck('action');

        return response()->json(['success' => true, 'data' => $actions]);
    }
}
