<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\PersonalAccessToken;
use Illuminate\Support\Facades\Session as SessionFacade;
use Carbon\Carbon;
use App\Models\Service;
use App\Models\UserSession;

class ProfileController extends Controller
{
    /**
     * Get user profile information
     */
    public function getProfile()
    {
        try {
            $user = Auth::user();

            $yearsWithUs = Carbon::now()->diffInYears($user->created_at);
            $activeServices = Service::where('user_id', $user->id)->active()->count();

            $avatarFull = $user->avatar_url
                ? asset('storage/' . $user->avatar_url)
                : null;

            return response()->json([
                'success' => true,
                'data' => [
                    'uuid' => $user->uuid,
                    'email' => $user->email,
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'phone' => $user->phone,
                    'address' => $user->address,
                    'city' => $user->city,
                    'state' => $user->state,
                    'country' => $user->country,
                    'postal_code' => $user->postal_code,
                    'role' => $user->role,
                    'status' => $user->status,
                    'two_factor_enabled' => $user->two_factor_enabled,
                    'email_verified_at' => $user->email_verified_at,
                    'last_login_at' => $user->last_login_at,
                    'created_at' => $user->created_at,
                    'avatar_url'     => $avatarFull,
                    'years_with_us'  => $yearsWithUs,
                    'active_services' => $activeServices
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error fetching profile',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update user profile information
     */
    public function updateProfile(Request $request)
    {
        try {
            $user = Auth::user();

            $validated = $request->validate([
                'first_name' => 'sometimes|string|max:100',
                'last_name' => 'sometimes|string|max:100',
                'phone' => 'nullable|string|max:20',
                'address' => 'nullable|string|max:500',
                'city' => 'nullable|string|max:100',
                'state' => 'nullable|string|max:100',
                'country' => 'nullable|string|size:2',
                'postal_code' => 'nullable|string|max:20',
            ]);

            $user->update($validated);

            return response()->json([
                'success' => true,
                'message' => 'Profile updated successfully',
                'data' => $user->fresh()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error updating profile',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update user email
     */
    public function updateEmail(Request $request)
    {
        try {
            $user = Auth::user();

            $validated = $request->validate([
                'email' => ['required', 'email', Rule::unique('users')->ignore($user->id)],
                'password' => 'required|string'
            ]);

            // Verify current password
            if (!Hash::check($request->password, $user->password)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Current password is incorrect'
                ], 400);
            }

            $user->update([
                'email' => $validated['email'],
                'email_verified_at' => null // Reset email verification
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Email updated successfully. Please verify your new email address.',
                'data' => $user->fresh()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error updating email',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update user password
     */
    public function updatePassword(Request $request)
    {
        try {
            $user = Auth::user();

            $validated = $request->validate([
                'current_password' => 'required|string',
                'new_password' => 'required|string|min:8|confirmed',
            ]);

            // Verify current password
            if (!Hash::check($request->current_password, $user->password)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Current password is incorrect'
                ], 400);
            }

            // Update password
            $user->update([
                'password' => Hash::make($validated['new_password'])
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Password updated successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error updating password',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update the user's avatar
     */
    public function updateAvatar(Request $request)
    {
        try {
            $user = Auth::user();

            $request->validate([
                'avatar' => ['required', 'image', 'mimes:jpg,jpeg,png,gif,svg', 'max:2048'],
            ]);

            $path = $request->file('avatar')->store('avatars', 'public');

            if ($user->avatar_url) {
                Storage::disk('public')->delete($user->avatar_url);
            }

            $user->avatar_url = $path;
            $user->save();

            $publicUrl = asset('storage/' . $path);

            return response()->json([
                'success' => true,
                'message' => 'Avatar actualizado correctamente',
                'data' => [
                    'avatar_url' => $publicUrl,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar el avatar',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
    /**
     * Get user sessions (login history)
     */
      /**
     * Listar sesiones del usuario autenticado.
     */
    public function getSessions()
    {
        try {
            $user = Auth::user();

            // Resolver “sesión actual”
            $currentTokenId = null;
            $currentSessionId = null;

            if ($tokenString = request()->bearerToken()) {
                $pat = PersonalAccessToken::findToken($tokenString);
                if ($pat && (int) $pat->tokenable_id === (int) $user->getKey()) {
                    $currentTokenId = $pat->id;
                }
            }

            if (method_exists(request(), 'hasSession') && request()->hasSession()) {
                $currentSessionId = request()->session()->getId();
            }

            $sessions = UserSession::where('user_id', $user->id)
                ->orderByDesc('last_activity')
                ->limit(50)
                ->get()
                ->map(function (UserSession $s) use ($currentTokenId, $currentSessionId) {
                    $isCurrent = false;

                    if (!is_null($s->sanctum_token_id) && $currentTokenId && $s->sanctum_token_id === $currentTokenId) {
                        $isCurrent = true;
                    }
                    if (!is_null($s->laravel_session_id) && $currentSessionId && $s->laravel_session_id === $currentSessionId) {
                        $isCurrent = true;
                    }

                    return [
                        'uuid'          => $s->uuid,
                        'ip_address'    => $s->ip_address,
                        'user_agent'    => $s->user_agent,
                        'location'      => $this->composeLocation($s), // si tienes city/region/country
                        'login_at'      => $s->login_at,
                        'last_activity' => $s->last_activity,
                        'logout_at'     => $s->logout_at,
                        'is_current'    => $isCurrent,
                        'device'        => $s->device,
                        'platform'      => $s->platform,
                        'browser'       => $s->browser,
                        'created_at'    => $s->created_at,
                    ];
                })
                ->values();

            return response()->json([
                'success' => true,
                'data'    => $sessions,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error fetching sessions',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    private function composeLocation(UserSession $s): ?string
    {
        if ($s->city || $s->region || $s->country) {
            $parts = array_filter([$s->city, $s->region, $s->country]);
            return implode(', ', $parts);
        }
        return null;
    }

    /**
     * Revocar una sesión específica.
     */
    public function revokeSession(string $uuid)
    {
        try {
            $user = Auth::user();
            $session = UserSession::where('user_id', $user->id)
                ->where('uuid', $uuid)
                ->firstOrFail();

            // Si fue token personal de Sanctum, puedes borrar el token:
            if ($session->sanctum_token_id) {
                $patModel = config('sanctum.personal_access_token_model', \Laravel\Sanctum\PersonalAccessToken::class);
                $token = $patModel::find($session->sanctum_token_id);
                if ($token) {
                    $token->delete();
                }
            }

            // Si fue sesión Laravel (SPA), puedes intentar destruirla si tu handler lo permite.
            // (Opcional; muchos handlers de sesión no exponen destroy por ID en runtime)
            // $handler = app('session')->getHandler();
            // if (method_exists($handler, 'destroy') && $session->laravel_session_id) {
            //     $handler->destroy($session->laravel_session_id);
            // }

            $session->logout_at = now();
            $session->save();

            return response()->json([
                'success' => true,
                'message' => 'Sesión revocada',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'No fue posible revocar la sesión',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete user account
     */
    public function deleteAccount(Request $request)
    {
        try {
            $user = Auth::user();

            $validated = $request->validate([
                'password' => 'required|string',
                'confirmation' => 'required|string|in:DELETE'
            ]);

            // Verify password
            if (!Hash::check($request->password, $user->password)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Password is incorrect'
                ], 400);
            }

            // Prevent deletion of admin users
            if (in_array($user->role, ['super_admin', 'admin'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Administrator accounts cannot be deleted'
                ], 403);
            }

            // Soft delete the user
            $user->delete();

            return response()->json([
                'success' => true,
                'message' => 'Account deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error deleting account',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get account security overview
     */
    public function getSecurityOverview()
    {
        try {
            $user = Auth::user();

            $overview = [
                'password_last_changed' => $user->updated_at, // Approximate
                'two_factor_enabled' => $user->two_factor_enabled,
                'email_verified' => !is_null($user->email_verified_at),
                'last_login' => $user->last_login_at,
                'account_status' => $user->status,
                'login_attempts_today' => 0, // Would track in production
                'security_score' => $this->calculateSecurityScore($user)
            ];

            return response()->json([
                'success' => true,
                'data' => $overview
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error fetching security overview',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Calculate security score based on user settings
     */
    private function calculateSecurityScore($user)
    {
        $score = 0;

        // Base score
        $score += 20;

        // Email verified
        if ($user->email_verified_at) {
            $score += 20;
        }

        // 2FA enabled
        if ($user->two_factor_enabled) {
            $score += 30;
        }

        // Strong password (assume if recent)
        if ($user->updated_at->diffInDays() < 90) {
            $score += 15;
        }

        // Recent login activity
        if ($user->last_login_at && $user->last_login_at->diffInDays() < 7) {
            $score += 15;
        }

        return min($score, 100);
    }
}
