<?php

namespace App\Http\Controllers\Auth;

use Throwable;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use App\Models\User;

class PasswordResetController extends Controller
{
    public function sendResetLinkEmail(Request $request)
    {
        $payload = null;

        try {

            $request->validate([
                'email' => ['required', 'email:rfc,dns']
            ]);

            $email = Str::lower(trim($request->email));

            $rateLimitKey = sprintf(
                'forgot-password:%s:%s',
                $request->ip(),
                $email
            );

            if (RateLimiter::tooManyAttempts($rateLimitKey, 5)) {

                Log::warning('Password reset rate limited', [
                    'email' => $email,
                    'ip' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ]);

                $payload = response()->json([
                    'success' => false,
                    'code' => 'RATE_LIMITED',
                    'message' => null,
                    'meta' => [
                        'retry_after_seconds' => RateLimiter::availableIn($rateLimitKey),
                    ]
                ], 429);

            } else {

                RateLimiter::hit($rateLimitKey, 300);

                $user = User::query()
                    ->where('email', $email)
                    ->first();

                if ($user) {

                    $usesSocialAuthOnly =
                        !empty($user->google_id)
                        && empty($user->password);

                    if ($usesSocialAuthOnly) {

                        Log::warning('Password reset blocked for social auth account', [
                            'email' => $email,
                            'ip' => $request->ip(),
                        ]);

                        return response()->json([
                            'success' => false,
                            'code' => 'PASSWORD_RESET_NOT_ALLOWED',
                            'message' => null,
                        ], 403);
                    }
                }

                $response = Password::sendResetLink([
                    'email' => $email
                ]);

                Log::info('Password reset requested', [
                    'email' => $email,
                    'ip' => $request->ip(),
                    'status' => $response,
                    'user_agent' => $request->userAgent(),
                ]);

                /**
                 * SECURITY:
                 * Nunca revelar si el usuario existe o no.
                 */
                if (
                    $response === Password::RESET_LINK_SENT ||
                    $response === Password::INVALID_USER
                ) {

                    $payload = response()->json([
                        'success' => true,
                        'code' => 'RESET_LINK_SENT',
                        'message' => null,
                    ], 200);

                } elseif ($response === Password::RESET_THROTTLED) {

                    Log::warning('Password reset throttled', [
                        'email' => $email,
                        'ip' => $request->ip(),
                        'response' => $response,
                    ]);

                    $payload = response()->json([
                        'success' => false,
                        'code' => 'RESET_THROTTLED',
                        'message' => null,
                    ], 429);

                } else {

                    Log::warning('Password reset failed', [
                        'email' => $email,
                        'ip' => $request->ip(),
                        'response' => $response,
                    ]);

                    $payload = response()->json([
                        'success' => false,
                        'code' => 'RESET_LINK_FAILED',
                        'message' => null,
                    ], 422);
                }
            }

        } catch (Throwable $e) {

            Log::error('Password reset exception', [
                'email' => $request->email ?? null,
                'ip' => $request->ip(),
                'error' => $e->getMessage(),
            ]);

            $payload = response()->json([
                'success' => false,
                'code' => 'INTERNAL_SERVER_ERROR',
                'message' => null,
            ], 500);
        }

        return $payload;
    }

    public function reset(Request $request)
    {
        $payload = null;

        try {

            $request->validate([
                'token' => ['required'],
                'email' => ['required', 'email:rfc,dns'],
                'password' => ['required', 'confirmed', 'min:8'],
            ]);

            $response = Password::reset(
                $request->only(
                    'email',
                    'password',
                    'password_confirmation',
                    'token'
                ),
                function ($user, $password) {

                    $user->forceFill([
                        'password' => bcrypt($password),
                    ])->setRememberToken(Str::random(60));

                    $user->save();
                }
            );

            if ($response === Password::PASSWORD_RESET) {

                Log::info('Password reset completed', [
                    'email' => $request->email,
                    'ip' => $request->ip(),
                ]);

                $payload = response()->json([
                    'success' => true,
                    'code' => 'PASSWORD_RESET_SUCCESS',
                    'message' => null,
                ], 200);

            } else {

                Log::warning('Password reset token invalid', [
                    'email' => $request->email,
                    'ip' => $request->ip(),
                    'response' => $response,
                ]);

                $payload = response()->json([
                    'success' => false,
                    'code' => 'PASSWORD_RESET_FAILED',
                    'message' => null,
                ], 422);
            }

        } catch (Throwable $e) {

            Log::error('Password reset exception', [
                'email' => $request->email ?? null,
                'ip' => $request->ip(),
                'error' => $e->getMessage(),
            ]);

            $payload = response()->json([
                'success' => false,
                'code' => 'INTERNAL_SERVER_ERROR',
                'message' => null,
            ], 500);
        }

        return $payload;
    }
}
