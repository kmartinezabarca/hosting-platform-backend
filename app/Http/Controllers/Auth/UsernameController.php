<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UsernameController extends Controller
{
    // ─────────────────────────────────────────────────────────────────────────
    // Público — sin autenticación
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * GET /auth/username/check?username=kmartinez
     *
     * Comprueba en tiempo real si un username está disponible.
     * Seguro para llamar sin autenticación (no expone datos de usuarios).
     */
    public function check(Request $request): JsonResponse
    {
        $request->validate([
            'username' => ['required', 'string', 'min:3', 'max:30', 'regex:/^[a-zA-Z0-9_-]+$/'],
        ]);

        $username  = strtolower($request->username);
        $available = ! User::where('username', $username)->exists();

        return response()->json([
            'username'  => $username,
            'available' => $available,
        ]);
    }

    /**
     * POST /auth/complete-profile
     *
     * Finaliza el registro de un usuario que vino de Google OAuth.
     * Recibe el setup_token emitido por GoogleLoginController y el username elegido.
     * Crea la cuenta y devuelve el token de sesión.
     */
    public function completeGoogleProfile(Request $request): JsonResponse
    {
        $request->validate([
            'setup_token' => ['required', 'string'],
            'username'    => [
                'required',
                'string',
                'min:3',
                'max:30',
                'unique:users,username',
                'regex:/^[a-zA-Z0-9_-]+$/',
            ],
        ], [
            'username.regex'  => 'El nombre de usuario solo puede contener letras, números, guiones y guiones bajos.',
            'username.unique' => 'Este nombre de usuario ya está en uso.',
            'setup_token.required' => 'Token de configuración inválido o expirado.',
        ]);

        // Recuperar datos de Google desde caché
        $cacheKey  = "google_setup:{$request->setup_token}";
        $googleData = Cache::get($cacheKey);

        if (! $googleData) {
            return response()->json([
                'message'    => 'El enlace de configuración ha expirado. Por favor inicia sesión con Google nuevamente.',
                'error_code' => 'SETUP_TOKEN_EXPIRED',
            ], 422);
        }

        // Verificar que el email no se haya registrado mientras tanto
        if (User::where('email', $googleData['email'])->exists()) {
            Cache::forget($cacheKey);
            return response()->json([
                'message'    => 'Ya existe una cuenta con este correo. Inicia sesión normalmente.',
                'error_code' => 'EMAIL_ALREADY_EXISTS',
            ], 409);
        }

        // Crear el usuario
        $user = User::create([
            'first_name'        => $googleData['first_name'],
            'last_name'         => $googleData['last_name'],
            'username'          => strtolower($request->username),
            'email'             => $googleData['email'],
            'google_id'         => $googleData['google_id'],
            'avatar_url'        => $googleData['avatar_url'],
            'password'          => Hash::make(Str::random(32)), // contraseña aleatoria — cuenta Google
            'last_login_at'     => now(),
            'email_verified_at' => now(),
            'status'            => 'active',
            'role'              => 'client',
        ]);

        // Eliminar el token de la caché (uso único)
        Cache::forget($cacheKey);

        $token = $user->createToken('auth_token')->plainTextToken;

        ActivityLog::record(
            'Registro de usuario (Google)',
            'Nuevo usuario registrado vía Google: ' . $user->email,
            'authentication',
            ['user_id' => $user->id, 'email' => $user->email, 'username' => $user->username],
            $user->id
        );

        return response()->json([
            'message'      => '¡Bienvenido! Tu cuenta ha sido creada exitosamente.',
            'access_token' => $token,
            'token_type'   => 'Bearer',
            'user'         => $this->userPayload($user),
            'redirect_to'  => '/client/dashboard',
        ], 201)->withCookie(
            cookie('auth_token', $token, config('sanctum.expiration'), null, null,
                   config('session.secure'), true, false, config('session.same_site', 'lax'))
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Autenticado — requiere token
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * POST /auth/setup-username
     *
     * Para usuarios ya autenticados que no tienen username todavía
     * (cuentas antiguas o cuentas Google que completaron su perfil incompleto).
     * Solo se puede usar una vez — una vez asignado, el username no se cambia aquí.
     */
    public function setupUsername(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! is_null($user->username)) {
            return response()->json([
                'message'    => 'Ya tienes un nombre de usuario asignado.',
                'error_code' => 'USERNAME_ALREADY_SET',
                'username'   => $user->username,
            ], 409);
        }

        $request->validate([
            'username' => [
                'required',
                'string',
                'min:3',
                'max:30',
                'unique:users,username',
                'regex:/^[a-zA-Z0-9_-]+$/',
            ],
        ], [
            'username.regex'  => 'El nombre de usuario solo puede contener letras, números, guiones y guiones bajos.',
            'username.unique' => 'Este nombre de usuario ya está en uso.',
        ]);

        $user->update(['username' => strtolower($request->username)]);

        ActivityLog::record(
            'Username configurado',
            'El usuario ' . $user->email . ' eligió el username: ' . $user->username,
            'authentication',
            ['user_id' => $user->id, 'username' => $user->username],
            $user->id
        );

        return response()->json([
            'message'  => 'Nombre de usuario configurado correctamente.',
            'user'     => $this->userPayload($user->fresh()),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helper
    // ─────────────────────────────────────────────────────────────────────────

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
}
