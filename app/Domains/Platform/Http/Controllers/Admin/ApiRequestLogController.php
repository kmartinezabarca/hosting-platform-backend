<?php

namespace App\Domains\Platform\Http\Controllers\Admin;

use App\Domains\Platform\Models\ApiRequestLog;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApiRequestLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->get('per_page', 25), 1), 100);

        $query = ApiRequestLog::query()
            ->with('user:id,uuid,first_name,last_name,email,role');

        $this->applyFilters($query, $request);

        $logs = $query
            ->orderByDesc('created_at')
            ->paginate($perPage);

        $logs->getCollection()->transform(fn (ApiRequestLog $log) => $this->summaryPayload($log));

        return response()->json(['success' => true, 'data' => $logs]);
    }

    public function show(ApiRequestLog $apiRequestLog): JsonResponse
    {
        $apiRequestLog->load('user:id,uuid,first_name,last_name,email,role');

        return response()->json([
            'success' => true,
            'data' => array_merge($this->summaryPayload($apiRequestLog), [
                'route_action' => $apiRequestLog->route_action,
                'host' => $apiRequestLog->host,
                'origin' => $apiRequestLog->origin,
                'referer' => $apiRequestLog->referer,
                'content_type' => $apiRequestLog->content_type,
                'accept' => $apiRequestLog->accept,
                'request_headers' => $apiRequestLog->request_headers,
                'query_params' => $apiRequestLog->query_params,
                'route_params' => $apiRequestLog->route_params,
                'request_body' => $apiRequestLog->request_body,
                'uploaded_files' => $apiRequestLog->uploaded_files,
                'response_headers' => $apiRequestLog->response_headers,
                'response_body' => $apiRequestLog->response_body,
                'error_trace' => $apiRequestLog->error_trace,
                'updated_at' => optional($apiRequestLog->updated_at)->toISOString(),
            ]),
        ]);
    }

    public function routes(): JsonResponse
    {
        $routes = ApiRequestLog::query()
            ->whereNotNull('route_name')
            ->where('route_name', '<>', '')
            ->select('route_name')
            ->distinct()
            ->orderBy('route_name')
            ->limit(500)
            ->pluck('route_name');

        return response()->json(['success' => true, 'data' => $routes]);
    }

    private function applyFilters(Builder $query, Request $request): void
    {
        $query
            ->when($request->filled('method'), fn (Builder $q) => $q->where('method', strtoupper((string) $request->get('method'))))
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status_code', (int) $request->get('status')))
            ->when($request->filled('route_name'), fn (Builder $q) => $q->where('route_name', $request->get('route_name')))
            ->when($request->filled('request_id'), fn (Builder $q) => $q->where('request_id', $request->get('request_id')))
            ->when($request->filled('user_id'), fn (Builder $q) => $q->where('user_id', $request->get('user_id')))
            ->when($request->filled('from'), fn (Builder $q) => $q->where('created_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn (Builder $q) => $q->where('created_at', '<=', $request->date('to')?->endOfDay()));

        if ($request->filled('successful')) {
            $query->where('successful', filter_var($request->get('successful'), FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->filled('status_group')) {
            match ((string) $request->get('status_group')) {
                '2xx' => $query->whereBetween('status_code', [200, 299]),
                '3xx' => $query->whereBetween('status_code', [300, 399]),
                '4xx' => $query->whereBetween('status_code', [400, 499]),
                '5xx' => $query->whereBetween('status_code', [500, 599]),
                'failed' => $query->where('successful', false),
                default => null,
            };
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->get('search'));

            $query->where(function (Builder $q) use ($search) {
                $q->where('request_id', 'like', "%{$search}%")
                    ->orWhere('path', 'like', "%{$search}%")
                    ->orWhere('full_url', 'like', "%{$search}%")
                    ->orWhere('route_name', 'like', "%{$search}%")
                    ->orWhere('ip_address', 'like', "%{$search}%")
                    ->orWhere('error_message', 'like', "%{$search}%")
                    ->orWhereHas('user', function (Builder $userQuery) use ($search) {
                        $userQuery
                            ->where('email', 'like', "%{$search}%")
                            ->orWhere('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%");
                    });
            });
        }
    }

    private function summaryPayload(ApiRequestLog $log): array
    {
        return [
            'id' => $log->id,
            'uuid' => $log->uuid,
            'request_id' => $log->request_id,
            'method' => $log->method,
            'path' => $log->path,
            'full_url' => $log->full_url,
            'route_name' => $log->route_name,
            'status_code' => $log->status_code,
            'successful' => $log->successful,
            'duration_ms' => $log->duration_ms,
            'ip_address' => $log->ip_address,
            'ip_chain' => $log->ip_chain,
            'user_agent' => $log->user_agent,
            'request_truncated' => $log->request_truncated,
            'response_truncated' => $log->response_truncated,
            'has_request_body' => $log->request_body !== null,
            'has_response_body' => $log->response_body !== null,
            'error_class' => $log->error_class,
            'error_message' => $log->error_message,
            'created_at' => optional($log->created_at)->toISOString(),
            'user' => $log->user ? [
                'id' => $log->user->id,
                'uuid' => $log->user->uuid,
                'first_name' => $log->user->first_name,
                'last_name' => $log->user->last_name,
                'email' => $log->user->email,
                'role' => $log->user->role,
            ] : null,
        ];
    }
}
