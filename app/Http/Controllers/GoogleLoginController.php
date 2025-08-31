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
            $token = $user->createToken('google-auth-token')->plainTextToken;

            // 4. Devolver el token y los datos del usuario
            return response()->json([
                'access_token' => $token,
                'token_type' => 'Bearer',
                'user' => $user,
            ]);

        } catch (\Exception $e) {
            // Manejo de errores
            return response()->json([
                'message' => 'Ocurrió un error durante la autenticación.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
