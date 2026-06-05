<?php

namespace App\Domains\Pet\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Domains\Pet\Mail\PetPasswordResetMail;
use App\Domains\Pet\Models\Owner;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Throwable;

/**
 * Recuperación de contraseña para roke.pet (dominio Pet).
 *
 * Los usuarios de roke.pet viven en la misma tabla `users` que la plataforma,
 * así que usamos el broker de contraseñas por defecto de Laravel
 * (tabla password_reset_tokens). La diferencia es que aquí generamos el token
 * manualmente y enviamos un correo CON MARCA roke.pet cuyo enlace apunta al
 * frontend de roke.pet (config services.rokepet.frontend_url).
 */
class PasswordResetController extends Controller
{
    /** POST /api/rp/auth/forgot-password */
    public function sendResetLink(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'email' => ['required', 'email'],
            ]);

            $email = Str::lower(trim($request->email));

            // Rate limit por IP+correo (5 intentos / 5 min).
            $key = 'rp-forgot:' . $request->ip() . ':' . $email;
            if (RateLimiter::tooManyAttempts($key, 5)) {
                Log::warning('[rokepet] Password reset rate limited', ['email' => $email, 'ip' => $request->ip()]);
                return response()->json([
                    'success' => false,
                    'code'    => 'RATE_LIMITED',
                    'message' => 'Demasiados intentos. Inténtalo más tarde.',
                    'meta'    => ['retry_after_seconds' => RateLimiter::availableIn($key)],
                ], 429);
            }
            RateLimiter::hit($key, 300);

            $user = User::where('email', $email)->first();

            // Cuenta creada solo con Google (sin contraseña): no hay contraseña
            // que restablecer. Le decimos explícitamente que use Google para que
            // no espere un correo que nunca llegará.
            if ($user && !empty($user->google_id) && empty($user->password)) {
                Log::info('[rokepet] Password reset for social-only account', ['email' => $email]);
                return response()->json([
                    'success' => false,
                    'code'    => 'GOOGLE_ACCOUNT',
                    'message' => 'Esta cuenta usa inicio de sesión con Google. Entra con el botón «Continuar con Google».',
                ]);
            }

            if ($user) {
                $token    = Password::broker()->createToken($user);
                $base     = rtrim((string) config('services.rokepet.frontend_url', 'https://roke.pet'), '/');
                $resetUrl = $base . '/reset-password?token=' . $token . '&email=' . urlencode($email);

                $owner     = Owner::find($user->uuid);
                $ownerName = $owner?->display_name
                    ?: (trim("{$user->first_name} {$user->last_name}") ?: 'Hola');

                Mail::to($email)->send(new PetPasswordResetMail(
                    ownerName:      $ownerName,
                    resetUrl:       $resetUrl,
                    expiresMinutes: (int) config('auth.passwords.users.expire', 60),
                    ipAddress:      $request->ip(),
                ));

                Log::info('[rokepet] Password reset link sent', ['email' => $email, 'ip' => $request->ip()]);
            } else {
                // No revelamos que el correo no existe.
                Log::info('[rokepet] Password reset requested for unknown email', ['email' => $email, 'ip' => $request->ip()]);
            }

            return $this->genericOk();

        } catch (Throwable $e) {
            Log::error('[rokepet] Password reset exception', [
                'email' => $request->email ?? null,
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'success' => false,
                'code'    => 'INTERNAL_SERVER_ERROR',
                'message' => 'Ocurrió un error. Inténtalo más tarde.',
            ], 500);
        }
    }

    /** POST /api/rp/auth/reset-password */
    public function reset(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'token'    => ['required'],
                'email'    => ['required', 'email'],
                'password' => ['required', 'confirmed', 'min:8', 'max:255'],
            ]);

            $status = Password::reset(
                $request->only('email', 'password', 'password_confirmation', 'token'),
                function (User $user, string $password) {
                    $user->forceFill([
                        'password' => bcrypt($password),
                    ])->setRememberToken(Str::random(60));
                    $user->save();
                }
            );

            if ($status === Password::PASSWORD_RESET) {
                Log::info('[rokepet] Password reset completed', ['email' => $request->email, 'ip' => $request->ip()]);
                return response()->json([
                    'success' => true,
                    'code'    => 'PASSWORD_RESET_SUCCESS',
                    'message' => 'Tu contraseña se actualizó correctamente.',
                ]);
            }

            Log::warning('[rokepet] Password reset token invalid', ['email' => $request->email, 'status' => $status]);
            return response()->json([
                'success' => false,
                'code'    => 'PASSWORD_RESET_FAILED',
                'message' => $status === Password::INVALID_TOKEN
                    ? 'El enlace es inválido o expiró. Solicita uno nuevo.'
                    : 'No se pudo restablecer la contraseña.',
            ], 422);

        } catch (Throwable $e) {
            Log::error('[rokepet] Password reset (reset) exception', [
                'email' => $request->email ?? null,
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'success' => false,
                'code'    => 'INTERNAL_SERVER_ERROR',
                'message' => 'Ocurrió un error. Inténtalo más tarde.',
            ], 500);
        }
    }

    /**
     * Respuesta genérica de éxito. Nunca revela si el correo existe o no
     * (protección contra enumeración de cuentas).
     */
    private function genericOk(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'code'    => 'RESET_LINK_SENT',
            'message' => 'Si el correo está registrado, te enviamos un enlace para restablecer tu contraseña.',
        ]);
    }
}
