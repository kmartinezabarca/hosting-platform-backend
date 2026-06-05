<?php

namespace App\Domains\Platform\Http\Controllers\Auth;

use App\Domains\Platform\Models\ActivityLog;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\AuthCookie;
use Illuminate\Http\Request;

/**
 * Callback de Google OAuth exclusivo para el panel de administración.
 *
 * A diferencia de GoogleLoginController (portal de clientes), este controlador:
 *  - Solo acepta usuarios que ya existen en la BD.
 *  - Verifica que el usuario tenga rol admin, super_admin o support.
 *  - Rechaza con 403 cualquier cuenta que no sea del equipo interno.
 *  - No crea cuentas nuevas (los administradores no se auto-registran).
 */
class AdminGoogleLoginController extends Controller
{
    /** Roles autorizados para acceder al panel de administración. */
    private const ADMIN_ROLES = ['admin', 'super_admin', 'support'];

    public function handleAdminGoogleCallback(Request $request)
    {
        $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name'  => 'nullable|string|max:255',
            'email'      => 'required|email|max:255',
            'google_id'  => 'required|string',
            'avatar_url' => 'nullable|url|max:2048',
        ]);

        try {
            $user = User::where('email', $request->email)
                        ->orWhere('google_id', $request->google_id)
                        ->first();

            // ── Usuario no existe ──────────────────────────────────────────────
            if (! $user) {
                ActivityLog::record(
                    'Intento de acceso admin con Google — cuenta inexistente',
                    'Email: ' . $request->email,
                    'authentication',
                    ['email' => $request->email, 'status' => 'rejected_not_found'],
                );

                return response()->json([
                    'message' => 'No existe una cuenta de administrador asociada a este correo de Google. Contacta al equipo técnico.',
                ], 403);
            }

            // ── Verificar rol de administrador ─────────────────────────────────
            if (! in_array($user->role, self::ADMIN_ROLES)) {
                ActivityLog::record(
                    'Intento de acceso al panel admin bloqueado (Google)',
                    'Email: ' . $user->email . ' — rol: ' . $user->role,
                    'authentication',
                    ['email' => $user->email, 'role' => $user->role, 'status' => 'rejected_unauthorized'],
                    $user->id
                );

                return response()->json([
                    'message' => 'No tienes permisos para acceder al panel de administración.',
                ], 403);
            }

            // ── Verificar estado de la cuenta ─────────────────────────────────
            $blockResponse = $this->checkAccountStatus($user);
            if ($blockResponse) {
                return $blockResponse;
            }

            // ── Actualizar datos de Google y fecha de último login ─────────────
            $updates = [
                'google_id'         => $request->google_id,
                'last_login_at'     => now(),
                'email_verified_at' => $user->email_verified_at ?? now(),
            ];

            if ($request->avatar_url && empty($user->avatar_url)) {
                $updates['avatar_url'] = $request->avatar_url;
            }

            $user->update($updates);
            $user = $user->fresh();

            // ── 2FA pendiente ──────────────────────────────────────────────────
            if ($user->two_factor_enabled) {
                return response()->json([
                    'two_factor_required' => true,
                    'email'               => $user->email,
                    'message'             => 'Se requiere verificación de dos pasos.',
                ]);
            }

            ActivityLog::record(
                'Inicio de sesión admin exitoso (Google)',
                'Email: ' . $user->email . ' — rol: ' . $user->role,
                'authentication',
                ['email' => $user->email, 'role' => $user->role, 'status' => 'success'],
                $user->id
            );

            $token = $user->createToken('admin_auth_token')->plainTextToken;

            return AuthCookie::attachAuthCookie(response()->json([
                'message'             => 'Logged in successfully',
                'access_token'        => $token,
                'token_type'          => 'Bearer',
                'two_factor_required' => false,
                'needs_username'      => is_null($user->username),
                'user'                => $this->userPayload($user),
                'redirect_to'         => $this->getAdminRedirectPath($user->role),
            ]), $token, (int) config('sanctum.expiration'));

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Ocurrió un error durante la autenticación.',
                'debug'   => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers privados
    // ─────────────────────────────────────────────────────────────────────────

    private function checkAccountStatus(User $user): ?\Illuminate\Http\JsonResponse
    {
        $messages = [
            'suspended' => 'Tu cuenta ha sido suspendida. Contacta a soporte.',
            'banned'    => 'Tu cuenta ha sido baneada permanentemente.',
        ];

        if (isset($messages[$user->status])) {
            ActivityLog::record(
                'Intento de acceso admin bloqueado — cuenta suspendida/baneada (Google)',
                'Email: ' . $user->email . ' — estado: ' . $user->status,
                'authentication',
                ['email' => $user->email, 'status' => $user->status],
                $user->id
            );

            return response()->json(['message' => $messages[$user->status]], 403);
        }

        return null;
    }

    private function userPayload(User $user): array
    {
        return [
            'uuid'              => $user->uuid,
            'first_name'        => $user->first_name,
            'last_name'         => $user->last_name,
            'username'          => $user->username,
            'email'             => $user->email,
            'phone'             => $user->phone,
            'role'              => $user->role,
            'status'            => $user->status,
            'avatar_url'        => $user->avatar_full_url ?: null,
            'is_google_account' => $user->is_google_account,
            'needs_username'    => is_null($user->username),
        ];
    }

    private function getAdminRedirectPath(string $role): string
    {
        return match ($role) {
            'support' => '/admin/tickets',
            default   => '/admin/dashboard',
        };
    }
}
