<?php

namespace App\Domains\Platform\Http\Controllers\Auth;

use App\Domains\Platform\Models\AuditLog;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\AuthCookie;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Handles the impersonation session hand-off.
 *
 * Flow:
 *   1. An admin calls POST /api/admin/users/{id}/impersonate which stores a
 *      single-use token in the cache and returns a client-portal redirect_url
 *      carrying that token.
 *   2. The client portal calls POST /api/auth/impersonate/exchange with the
 *      token; we mint a Sanctum cookie session for the target user. The token
 *      name records the impersonator id so the session can be reverted.
 *   3. POST /api/auth/impersonate/leave reverts to the original admin session.
 */
class ImpersonationController extends Controller
{
    private const TOKEN_NAME_PREFIX = 'impersonation:';

    /**
     * Exchange a single-use impersonation token for a target-user session.
     * Public endpoint — the one-time token IS the credential.
     */
    public function exchange(Request $request): JsonResponse
    {
        $request->validate(['token' => ['required', 'string']]);

        // pull() is atomic get+forget → guarantees single use.
        $payload = Cache::pull('impersonation:' . hash('sha256', $request->input('token')));

        if (!$payload || empty($payload['target_id'])) {
            return response()->json([
                'success' => false,
                'message' => 'Token de suplantación inválido o expirado.',
            ], 422);
        }

        $target = User::find($payload['target_id']);
        if (!$target) {
            return response()->json([
                'success' => false,
                'message' => 'El usuario a suplantar ya no existe.',
            ], 422);
        }

        // Token name encodes the impersonator so /leave can revert the session.
        $token  = $target->createToken(self::TOKEN_NAME_PREFIX . $payload['impersonator_id'])->plainTextToken;

        return AuthCookie::attachAuthCookie(response()->json([
            'success' => true,
            'data'    => [
                'user'         => $this->userPayload($target),
                'impersonated' => true,
                'redirect_to'  => '/client/dashboard',
            ],
        ]), $token, 1440);
    }

    /**
     * End the impersonation session and restore the original admin session.
     */
    public function leave(Request $request): JsonResponse
    {
        $current     = $request->user();
        $accessToken = $current->currentAccessToken();
        $name        = $accessToken?->name ?? '';

        if (!str_starts_with($name, self::TOKEN_NAME_PREFIX)) {
            return response()->json([
                'success' => false,
                'message' => 'No estás en una sesión de suplantación.',
            ], 422);
        }

        $impersonatorId = (int) substr($name, strlen(self::TOKEN_NAME_PREFIX));
        $admin          = User::find($impersonatorId);

        if (!$admin) {
            return response()->json([
                'success' => false,
                'message' => 'La cuenta de administrador original ya no existe.',
            ], 422);
        }

        // Revoke the impersonation token, mint a fresh admin session.
        $accessToken->delete();
        $token = $admin->createToken('auth_token')->plainTextToken;
        $ttl = in_array($admin->role, ['super_admin', 'admin', 'support'], true) ? 480 : 1440;

        AuditLog::record(
            action: 'user.impersonation_ended',
            target: $current,
            description: "Suplantación finalizada de {$current->email}",
            actor: $admin,
        );

        return AuthCookie::attachAuthCookie(response()->json([
            'success' => true,
            'data'    => [
                'user'        => $this->userPayload($admin),
                'redirect_to' => in_array($admin->role, ['super_admin', 'admin'], true)
                    ? '/admin/dashboard'
                    : '/admin/tickets',
            ],
        ]), $token, $ttl);
    }

    private function userPayload(User $user): array
    {
        return [
            'uuid'               => $user->uuid,
            'first_name'         => $user->first_name,
            'last_name'          => $user->last_name,
            'username'           => $user->username,
            'email'              => $user->email,
            'role'               => $user->role,
            'status'             => $user->status,
            'avatar_url'         => $user->avatar_full_url ?: null,
            'two_factor_enabled' => (bool) $user->two_factor_enabled,
        ];
    }
}
