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
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = User::create([
            'first_name' => $request->first_name,
            'last_name' => $request->last_name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => 'client', // Default role for new registrations
        ]);

        $token = $user->createToken('auth_token')->plainTextToken;

        // Registrar actividad de registro de usuario
        ActivityLog::record(
            'Registro de usuario',
            'Nuevo usuario registrado: ' . $user->email,
            'authentication',
            ['user_id' => $user->id, 'email' => $user->email],
            $user->id
        );

        return response()->json([
            'message' => 'User registered successfully',
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => $user,
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
            // Registrar intento de login fallido
            ActivityLog::record(
                'Intento de inicio de sesión fallido',
                'Email: ' . $request->email,
                'authentication',
                ['email' => $request->email, 'status' => 'failed'],
                $user ? $user->id : null
            );
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
            'message' => 'Logged in successfully',
            'user' => [
                'uuid' => $user->uuid,
                'email' => $user->email,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'phone' => $user->phone,
                'role' => $user->role,
                'status' => $user->status,
            ],
            'redirect_to' => $this->getRedirectPath($user->role)
        ])->withCookie($cookie);
    }

    public function me(Request $request)
    {
        $u = $request->user();

        return response()->json([
            'success' => true,
            'data' => [
                'uuid'        => $u->uuid,
                'first_name'  => $u->first_name,
                'last_name'   => $u->last_name,
                'email'       => $u->email,
                'phone'       => $u->phone,
                'role'        => $u->role,
                'avatar_url'  => $u->avatar_full_url, // atributo calculado (ver abajo)
            ],
        ]);
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
        $user = Auth::user(); // Obtener el usuario antes de desloguear
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // Registrar actividad de cierre de sesión
        if ($user) {
            ActivityLog::record(
                'Cierre de sesión',
                'Usuario ' . $user->email . ' ha cerrado sesión.',
                'authentication',
                ['user_id' => $user->id, 'email' => $user->email],
                $user->id
            );
        }

        return response()->json(['message' => 'Logged out successfully']);
    }
}


