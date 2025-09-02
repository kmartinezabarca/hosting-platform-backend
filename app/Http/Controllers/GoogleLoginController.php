<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class GoogleLoginController extends Controller
{
    public function handleGoogleCallback(Request $request)
    {
        // 1. Validar la información que llega desde React
        $validator = Validator::make($request->all(), [
            'first_name' => 'required|string|max:255',
            'last_name' => 'string|max:255',
            'email' => 'required|email|max:255',
            'google_id' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // 2. Buscar o crear el usuario
        try {
            $user = User::updateOrCreate(
                ['email' => $request->email],
                [
                    'first_name' => $request->first_name,
                    'last_name' => $request->last_name,
                    'google_id' => $request->google_id,
                    'last_login_at' => now(),
                    'email_verified_at' => now(),
                    'password' => Hash::make(uniqid()),
                    'status' => 'active',
                ]
            );

            if ($user->wasRecentlyCreated === false && is_null($user->google_id)) {
                $user->google_id = $request->google_id;
                $user->save();
            }

            $statusMessages = [
                'suspended' => 'Tu cuenta ha sido suspendida temporalmente. Por favor, contacta a soporte para más información.',
                'banned' => 'Tu cuenta ha sido baneada permanentemente. No puedes iniciar sesión.',
                'pending_verification' => 'Tu cuenta está pendiente de verificación. Por favor, revisa tu correo electrónico para completar el registro.'
            ];

            if (array_key_exists($user->status, $statusMessages)) {
                return response()->json([
                    'message' => $statusMessages[$user->status]
                ], 403); // 403 Forbidden
            }

             if ($user->two_factor_enabled) {
                return response()->json([
                    'two_factor_required' => true,
                    'email' => $user->email,
                    'message' => 'Se requiere verificación de dos pasos.'
                ]);
            }

            // 3. Generar un token de API con Sanctum
            \Illuminate\Support\Facades\Auth::login($user);

            $request->session()->regenerate();

            // 4. Devolver el token y los datos del usuario
            return response()->json([
                'message' => 'Logged in successfully',
                'two_factor_required' => $user->two_factor_enabled,
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
            ]);

        } catch (\Exception $e) {
            // Manejo de errores
            return response()->json([
                'message' => 'Ocurrió un error durante la autenticación.',
                'error' => $e->getMessage()
            ], 500);
        }
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
}
