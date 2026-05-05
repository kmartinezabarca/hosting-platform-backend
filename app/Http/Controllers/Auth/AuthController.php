<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use App\Models\ActivityLog; // Importar el modelo ActivityLog

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name'  => ['required', 'string', 'max:100'],
            'username'   => [
                'required',
                'string',
                'min:3',
                'max:30',
                'unique:users,username',
                'regex:/^[a-zA-Z0-9_-]+$/',
            ],
            'email'    => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ], [
            'username.regex'  => 'El nombre de usuario solo puede contener letras, números, guiones y guiones bajos.',
            'username.unique' => 'Este nombre de usuario ya está en uso.',
            'email.unique'    => 'Este correo ya tiene una cuenta registrada.',
        ]);

        $user = User::create([
            'first_name' => $request->first_name,
            'last_name'  => $request->last_name,
            'username'   => strtolower($request->username),
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

        return response()->json([
            'message'      => 'Usuario registrado exitosamente.',
            'access_token' => $token,
            'token_type'   => 'Bearer',
            'user'         => $this->userPayload($user),
        ], 201);
    }

    public function login(Request $request)
    {
        $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
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

        $token = $user->createToken('auth_token')->plainTextToken;

        $cookie = cookie('auth_token', $token, config('sanctum.expiration'), null, null, config('session.secure'), true, false, config('session.same_site', 'lax'));

        // Registrar inicio de sesión exitoso
        ActivityLog::record(
            'Inicio de sesión exitoso',
            'Usuario ' . $user->email . ' ha iniciado sesión.',
            'authentication',
            ['user_id' => $user->id, 'email' => $user->email, 'status' => 'success'],
            $user->id
        );

        return response()->json([
            'message'        => 'Logged in successfully',
            'user'           => $this->userPayload($user),
            // Si el usuario no tiene username (cuenta anterior a esta feature)
            // el frontend debe llevarlo a la pantalla de configuración de username.
            'needs_username' => is_null($user->username),
            'redirect_to'    => $this->getRedirectPath($user->role),
        ])->withCookie($cookie);
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

        // Revoke current Sanctum token (Bearer token)
        if ($user && $user->currentAccessToken()) {
            $user->currentAccessToken()->delete();
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($user) {
            ActivityLog::record(
                'Cierre de sesión',
                'Usuario ' . $user->email . ' ha cerrado sesión.',
                'authentication',
                ['user_id' => $user->id, 'email' => $user->email],
                $user->id
            );
        }

        return response()->json(['message' => 'Logged out successfully'])
            ->withCookie(\Cookie::forget('auth_token'));
    }
}


