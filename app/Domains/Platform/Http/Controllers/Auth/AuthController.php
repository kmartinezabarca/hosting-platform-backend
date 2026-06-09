<?php

namespace App\Domains\Platform\Http\Controllers\Auth;

use App\Domains\Platform\Models\ActivityLog; // Importar el modelo ActivityLog
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\AuthCookie;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name'  => ['required', 'string', 'max:100'],
            'username'   => [
                'nullable',
                'string',
                'min:3',
                'max:30',
                'unique:users,username',
                'regex:/^[a-zA-Z0-9_-]+$/',
            ],
            'email'    => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed'],
        ], [
            'username.regex'  => 'El nombre de usuario solo puede contener letras, números, guiones y guiones bajos.',
            'username.unique' => 'Este nombre de usuario ya está en uso.',
            'email.unique'    => 'Este correo ya tiene una cuenta registrada.',
        ]);

        $user = User::create([
            'first_name' => $request->first_name,
            'last_name'  => $request->last_name,
            'username'   => $request->filled('username') ? strtolower($request->username) : null,
            'email'      => $request->email,
            'password'   => Hash::make($request->password),
            'role'       => 'client',
            'status'     => 'active',
        ]);

        $token = $user->createToken('auth_token')->plainTextToken;

        ActivityLog::record(
            'Registro de usuario',
            'Nuevo usuario registrado: ' . $user->email,
            'authentication',
            ['user_id' => $user->id, 'email' => $user->email, 'username' => $user->username],
            $user->id
        );

        return AuthCookie::attachAuthCookie(response()->json([
            'message'      => 'Usuario registrado exitosamente.',
            'access_token' => $token,   // Para clientes móviles (Bearer)
            'token_type'   => 'Bearer',
            'user'         => $this->userPayload($user),
        ], 201), $token, 1440);
    }

    public function login(Request $request)
    {
        $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'remember_me' => ['sometimes', 'boolean'],
        ]);

        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            // Only log when a real account exists — user_id is NOT NULL in activity_logs
            if ($user) {
                ActivityLog::record(
                    'Intento de inicio de sesión fallido',
                    'Email: ' . $request->email,
                    'authentication',
                    ['email' => $request->email, 'status' => 'failed'],
                    $user->id
                );
            }
            throw ValidationException::withMessages([
                'email' => ['The provided credentials do not match our records.'],
            ]);
        }

        // Check if account is active
        if ($user->status !== 'active') {
            // Registrar intento de login de cuenta inactiva
            ActivityLog::record(
                'Intento de inicio de sesión - Cuenta inactiva',
                'Email: ' . $user->email,
                'authentication',
                ['user_id' => $user->id, 'email' => $user->email, 'status' => 'inactive_account'],
                $user->id
            );
            return response()->json([
                'success' => false,
                'message' => 'Account is not active. Please contact support.',
                'error_code' => 'ACCOUNT_INACTIVE'
            ], 403);
        }

        if ($user->two_factor_enabled) {
            // Registrar intento de login con 2FA requerido
            ActivityLog::record(
                'Intento de inicio de sesión - 2FA requerido',
                'Email: ' . $user->email,
                'authentication',
                ['user_id' => $user->id, 'email' => $user->email, 'status' => '2fa_required'],
                $user->id
            );
            return response()->json([
                'two_factor_required' => true,
                'email' => $user->email,
                'message' => 'Se requiere verificación de dos pasos.'
            ]);
        }

        // Update last login
        $user->update(['last_login_at' => now()]);

        $rememberSession = $request->boolean('remember_me', false);
        $ttlMinutes = $this->sessionTtlMinutes($user->role, $rememberSession);
        $token = $user->createToken($rememberSession ? 'auth_token:remember' : 'auth_token')->plainTextToken;

        // Registrar inicio de sesión exitoso
        ActivityLog::record(
            'Inicio de sesión exitoso',
            'Usuario ' . $user->email . ' ha iniciado sesión.',
            'authentication',
            ['user_id' => $user->id, 'email' => $user->email, 'status' => 'success', 'remember_me' => $rememberSession],
            $user->id
        );

        return AuthCookie::attachAuthCookie(response()->json([
            'message'        => 'Logged in successfully',
            'access_token'   => $token,   // Para clientes móviles (Bearer)
            'token_type'     => 'Bearer',
            'user'           => $this->userPayload($user),
            'needs_username' => is_null($user->username),
            'redirect_to'    => $this->getRedirectPath($user->role),
            'expires_in_minutes' => $ttlMinutes,
        ]), $token, $ttlMinutes);
    }

    public function me(Request $request)
    {
        return response()->json([
            'success' => true,
            'data'    => $this->userPayload($request->user()),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /** Payload normalizado del usuario para todas las respuestas de auth. */
    private function userPayload(User $user): array
    {
        return [
            'uuid'               => $user->uuid,
            'first_name'         => $user->first_name,
            'last_name'          => $user->last_name,
            'username'           => $user->username,
            'email'              => $user->email,
            'phone'              => $user->phone,
            'role'               => $user->role,
            'status'             => $user->status,
            'avatar_url'         => $user->avatar_full_url ?: null,
            'is_google_account'  => (bool) $user->is_google_account,
            'needs_username'     => is_null($user->username),
            'two_factor_enabled' => (bool) $user->two_factor_enabled,
            'created_at'         => $user->created_at?->toISOString(),
            'email_verified_at'  => $user->email_verified_at?->toISOString(),
            'last_login_at'      => $user->last_login_at?->toISOString(),
        ];
    }

    private function sessionTtlMinutes(string $role, bool $rememberSession): int
    {
        if (in_array($role, ['super_admin', 'admin', 'support'], true)) {
            return 480;
        }

        return $rememberSession ? (int) (config('sanctum.expiration') ?: 43200) : 360;
    }

    /**
     * Get redirect path based on user role
     */
    private function getRedirectPath($role)
    {
        switch ($role) {
            case 'super_admin':
            case 'admin':
                return '/admin/dashboard';
            case 'support':
                return '/admin/tickets';
            default:
                return '/client/dashboard';
        }
    }

    public function logout(Request $request)
    {
        $user = $request->user();

        // Revocar token actual
        $currentToken = $user?->currentAccessToken();
        if ($currentToken && method_exists($currentToken, 'delete')) {
            $currentToken->delete();
        }

        // Log actividad
        if ($user) {
            ActivityLog::record(
                'Cierre de sesión',
                'Usuario ' . $user->email . ' ha cerrado sesión.',
                'authentication',
                [
                    'user_id' => $user->id,
                    'email' => $user->email,
                ],
                $user->id
            );
        }

        return AuthCookie::attachForgetCookies(response()->json([
            'success' => true,
            'message' => 'Logout successful.',
            'code' => 'LOGOUT_SUCCESS',
        ]));
    }
}
