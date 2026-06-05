<?php

namespace App\Domains\Platform\Http\Controllers\Admin;

use App\Domains\Platform\Models\AuditLog;
use App\Domains\Platform\Models\UserRequest;
use App\Domains\Platform\Notifications\UserRequestStatusNotification;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin management of user-submitted requests (documentation / API access /
 * KYC). Approve / reject transitions are audited and notify the requester.
 *
 * Restricted to super_admin / admin via the route group.
 */
class UserRequestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->get('per_page', 20), 100);

        $requests = UserRequest::with('user:id,first_name,last_name,email,avatar_url')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->get('status')))
            ->when($request->filled('kind'),   fn ($q) => $q->where('kind', $request->get('kind')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = trim((string) $request->get('search'));
                $q->where(fn ($qq) => $qq
                    ->where('subject', 'like', "%{$s}%")
                    ->orWhere('description', 'like', "%{$s}%")
                    ->orWhereHas('user', fn ($u) => $u
                        ->where('first_name', 'like', "%{$s}%")
                        ->orWhere('last_name', 'like', "%{$s}%")
                        ->orWhere('email', 'like', "%{$s}%")));
            })
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return response()->json(['success' => true, 'data' => $requests]);
    }

    public function show(int $id): JsonResponse
    {
        $userRequest = UserRequest::with('user:id,first_name,last_name,email,avatar_url')->findOrFail($id);

        return response()->json(['success' => true, 'data' => $userRequest]);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        return $this->resolve($request, $id, UserRequest::STATUS_APPROVED, $validated['note'] ?? null, null);
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        return $this->resolve($request, $id, UserRequest::STATUS_REJECTED, null, $validated['reason']);
    }

    private function resolve(Request $request, int $id, string $status, ?string $note, ?string $reason): JsonResponse
    {
        $userRequest = UserRequest::findOrFail($id);

        if (!$userRequest->isPending()) {
            return response()->json([
                'success' => false,
                'message' => 'Esta solicitud ya fue resuelta.',
            ], 422);
        }

        $userRequest->update([
            'status'      => $status,
            'note'        => $note,
            'reason'      => $reason,
            'resolved_at' => now(),
            'resolved_by' => $request->user()->id,
        ]);

        // Notify the requester (in-app + broadcast) when we know who they are.
        if ($userRequest->user) {
            $userRequest->user->notify(new UserRequestStatusNotification(
                status: $status,
                kind: $userRequest->kind,
                message: $status === UserRequest::STATUS_APPROVED ? $note : $reason,
                data: ['request_id' => $userRequest->uuid],
            ));
        }

        AuditLog::record(
            action: 'user_request.' . $status,
            target: $userRequest,
            description: ($status === UserRequest::STATUS_APPROVED ? 'Solicitud aprobada' : 'Solicitud rechazada')
                . " ({$userRequest->kind})" . ($reason ? " — motivo: {$reason}" : ''),
            changes: ['status' => [UserRequest::STATUS_PENDING, $status]],
        );

        return response()->json([
            'success' => true,
            'message' => $status === UserRequest::STATUS_APPROVED ? 'Solicitud aprobada.' : 'Solicitud rechazada.',
            'data'    => $userRequest->fresh('user'),
        ]);
    }
}
