<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use PragmaRX\Google2FA\Google2FA;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Support\Facades\Validator;
use App\Models\User;

class TwoFactorController extends Controller
{
    protected $google2fa;

    public function __construct()
    {
        $this->google2fa = new Google2FA();
    }

    /**
     * Generate 2FA secret and QR code
     */
    public function generateSecret(Request $request)
    {
        try {
            $user = Auth::user();

            // Generate a new secret key
            $secret = $this->google2fa->generateSecretKey();

            // Save the secret to the user (temporarily, until verified)
            $user->update(['two_factor_secret' => encrypt($secret)]);

            // Generate QR code
            $qrCodeUrl = $this->google2fa->getQRCodeUrl(
                config('app.name'),
                $user->email,
                $secret
            );

            // Create QR code image
            $qrCode = new QrCode($qrCodeUrl);
            $qrCode->setSize(200);
            $qrCode->setMargin(10);

            $writer = new PngWriter();
            $result = $writer->write($qrCode);

            $qrCodeImage = base64_encode($result->getString());

            return response()->json([
                'success' => true,
                'data' => [
                    'secret' => $secret,
                    'qr_code' => 'data:image/png;base64,' . $qrCodeImage,
                    'manual_entry_key' => $secret
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error generating 2FA secret',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Enable 2FA after verification
     */
    public function enable(Request $request)
    {
        try {
            $request->validate([
                'code' => 'required|string|size:6'
            ]);

            $user = Auth::user();

            if (!$user->two_factor_secret) {
                return response()->json([
                    'success' => false,
                    'message' => 'No 2FA secret found. Please generate a secret first.'
                ], 400);
            }

            $secret = decrypt($user->two_factor_secret);

            // Verify the provided code
            $valid = $this->google2fa->verifyKey($secret, $request->code);

            if (!$valid) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid verification code. Please try again.'
                ], 400);
            }

            // Enable 2FA for the user
            $user->update(['two_factor_enabled' => true]);

            return response()->json([
                'success' => true,
                'message' => '2FA has been successfully enabled for your account.'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error enabling 2FA',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Disable 2FA
     */
    public function disable(Request $request)
    {
        try {
            $request->validate([
                'password' => 'required|string',
                'code' => 'required|string|size:6'
            ]);

            $user = Auth::user();

            // Verify password
            if (!\Hash::check($request->password, $user->password)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid password.'
                ], 400);
            }

            // Verify 2FA code
            if ($user->two_factor_enabled && $user->two_factor_secret) {
                $secret = decrypt($user->two_factor_secret);
                $valid = $this->google2fa->verifyKey($secret, $request->code);

                if (!$valid) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Invalid 2FA code.'
                    ], 400);
                }
            }

            // Disable 2FA
            $user->update([
                'two_factor_enabled' => false,
                'two_factor_secret' => null
            ]);

            return response()->json([
                'success' => true,
                'message' => '2FA has been successfully disabled for your account.'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error disabling 2FA',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Verify 2FA code during login
     */
    public function verify(Request $request)
    {
        try {
            $request->validate([
                'code' => 'required|string|size:6',
                'email' => 'required|email'
            ]);

            $user = \App\Models\User::where('email', $request->email)->first();

            if (!$user || !$user->two_factor_enabled || !$user->two_factor_secret) {
                return response()->json([
                    'success' => false,
                    'message' => '2FA is not enabled for this account.'
                ], 400);
            }

            $secret = decrypt($user->two_factor_secret);
            $valid = $this->google2fa->verifyKey($secret, $request->code);

            if (!$valid) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid 2FA code.'
                ], 400);
            }

            return response()->json([
                'success' => true,
                'message' => '2FA verification successful.'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error verifying 2FA code',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Verifica el código 2FA durante el proceso de login.
     * Esta es una ruta pública, por lo que busca al usuario por email.
     */
    public function verifyLogin(Request $request)
    {
        // 1. Validar la petición
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|exists:users,email',
            'code' => 'required|string|digits:6',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // 2. Encontrar al usuario por su email
        $user = User::where('email', $request->email)->first();

        // 3. Verificar que el usuario realmente tiene 2FA activado
        if (!$user || !$user->two_factor_enabled || !$user->two_factor_secret) {
            return response()->json(['message' => 'La autenticación de dos factores no está activada para esta cuenta.'], 422);
        }

        // 4. Validar el código 2FA
        $google2fa = new Google2FA();
        $secret = decrypt($user->two_factor_secret);
        $isValid = $google2fa->verifyKey($secret, $request->code);

        if (!$isValid) {
            return response()->json(['message' => 'El código de verificación es incorrecto.'], 401);
        }

        // 5. ¡Éxito! El código es correcto. Generar el token de sesión final.
        $token = $user->createToken('2fa-verified-auth-token')->plainTextToken;

        return response()->json([
            'message' => 'Verificación exitosa.',
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => $user,
        ]);
    }

    /**
     * Get 2FA status
     */
    public function getStatus()
    {
        try {
            $user = Auth::user();

            return response()->json([
                'success' => true,
                'data' => [
                    'enabled' => $user->two_factor_enabled,
                    'has_secret' => !empty($user->two_factor_secret)
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error getting 2FA status',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}

