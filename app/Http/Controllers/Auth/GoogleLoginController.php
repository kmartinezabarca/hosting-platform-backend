<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class GoogleLoginController extends Controller
{
    /**
     * Tiempo de vida del setup_token en minutos.
     * El usuario tiene 15 minutos para completar su perfil.
     */
    private const SETUP_TOKEN_TTL = 15;

    public function handleGoogleCallback(Request $request)
    {
        $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name'  => 'nullable|string|max:255',
            'email'      => 'required|email|max:255',
            'google_id'  => 'required|string',
            'avatar_url' => 'nullable|url|max:2048',
        ]);

        try {
            $existing = User::where('email', $request->email)
                            ->orWhere('google_id', $request->google_id)
                            ->first();

            if ($existing) {
                return $this->handleExistingUser($existing, $request);
            }

            return $this->handleNewUser($request);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Ocurrió un error durante la autenticación.',
                'debug'   => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Handlers privados
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Usuario que ya existe en la BD (puede ser vía Google o email/password).
     */
    private function handleExistingUser(User $user, Request $request): \Illuminate\Http\JsonResponse
    {
        // Actualizar datos de Google en la cuenta existente
        $updates = [
            'google_id'         => $request->google_id,
            'last_login_at'     => now(),
            'email_verified_at' => $user->email_verified_at ?? now(),
            'status'            => $user->status === 'pending_verification' ? 'active' : $user->status,
        ];

        if ($request->avatar_url && empty($user->avatar_url)) {
            $updates['avatar_url'] = $request->avatar_url;
        }

        $user->update($updates);
        $user = $user->fresh();

        // Verificar estado de cuenta
        $blockResponse = $this->checkAccountStatus($user);
        if ($blockResponse) {
            return $blockResponse;
        }

        // 2FA pendiente
        if ($user->two_factor_enabled) {
            return response()->json([
                'two_factor_required' => true,
                'email'               => $user->email,
                'message'             => 'Se requiere verificación de dos pasos.',
            ]);
        }

        ActivityLog::record(
            'Inicio de sesión exitoso (Google)',
            'Email: ' . $user->email,
            'authentication',
            ['email' => $user->email, 'status' => 'success'],
            $user->id
        );

        // ── ¿El usuario no tiene username? ─────────────────────────────────
        // (cuenta creada antes de esta feature o via Google sin haberlo puesto)
        // No bloqueamos el login — devolvemos el token pero marcamos needs_username.
        // El frontend decide si mostrar modal o redirigir.
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message'             => 'Logged in successfully',
            'two_factor_required' => false,
            'needs_username'      => is_null($user->username),
            'user'                => $this->userPayload($user),
            'redirect_to'         => $this->getRedirectPath($user->role),
        ])->withCookie(
            cookie('auth_token', $token, config('sanctum.expiration'), null, null,
                   config('session.secure'), true, false, config('session.same_site', 'lax'))
        );
    }

    /**
     * Usuario completamente nuevo — nunca se ha registrado.
     * No creamos la cuenta todavía: emitimos un setup_token para que
     * el frontend lleve al usuario a elegir su username.
     */
    private function handleNewUser(Request $request): \Illuminate\Http\JsonResponse
    {
        $setupToken = Str::random(48);

        // Guardar datos de Google en caché durante 15 minutos
        Cache::put("google_setup:{$setupToken}", [
            'first_name' => $request->first_name,
            'last_name'  => $request->last_name ?? '',
            'email'      => $request->email,
            'google_id'  => $request->google_id,
            'avatar_url' => $request->avatar_url,
        ], now()->addMinutes(self::SETUP_TOKEN_TTL));

        return response()->json([
            'username_required' => true,
            'setup_token'       => $setupToken,
            // Datos preview para mostrar en el formulario de username
            'user_preview' => [
                'first_name' => $request->first_name,
                'last_name'  => $request->last_name ?? '',
                'email'      => $request->email,
                'avatar_url' => $request->avatar_url,
            ],
            'message' => 'Elige un nombre de usuario para completar tu registro.',
            'expires_in_minutes' => self::SETUP_TOKEN_TTL,
        ], 200);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function checkAccountStatus(User $user): ?\Illuminate\Http\JsonResponse
    {
        $messages = [
            'suspended'            => 'Tu cuenta ha sido suspendida. Contacta a soporte.',
            'banned'               => 'Tu cuenta ha sido baneada permanentemente.',
            'pending_verification' => 'Tu cuenta está pendiente de verificación.',
        ];

        if (isset($messages[$user->status])) {
            ActivityLog::record(
                'Intento de inicio de sesión bloqueado',
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

    private function getRedirectPath(string $role): string
    {
        return match ($role) {
            'super_admin', 'admin' => '/admin/dashboard',
            'support'              => '/admin/tickets',
            default                => '/client/dashboard',
        };
    }
}
