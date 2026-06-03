<?php

namespace App\Domains\Platform\Http\Controllers\Client;

use App\Domains\Platform\Models\UserRequest;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Client-facing submission of requests (documentation / API access) that staff
 * later approve or reject from the admin panel.
 */
class UserRequestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $requests = UserRequest::where('user_id', Auth::id())
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->get('status')))
            ->orderByDesc('created_at')
            ->paginate(min((int) $request->get('per_page', 20), 100));

        return response()->json(['success' => true, 'data' => $requests]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'kind'        => ['required', Rule::in([
                UserRequest::KIND_DOCUMENTATION,
                UserRequest::KIND_API_DOCUMENTATION,
            ])],
            'subject'     => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
        ]);

        $userRequest = UserRequest::create([
            'user_id'     => Auth::id(),
            'kind'        => $validated['kind'],
            'status'      => UserRequest::STATUS_PENDING,
            'subject'     => $validated['subject'],
            'description' => $validated['description'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Solicitud enviada. Te notificaremos cuando sea revisada.',
            'data'    => $userRequest,
        ], 201);
    }

    public function show(int $id): JsonResponse
    {
        $userRequest = UserRequest::where('user_id', Auth::id())->findOrFail($id);

        return response()->json(['success' => true, 'data' => $userRequest]);
    }
}
