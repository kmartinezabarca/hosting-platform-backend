<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use App\Models\UserSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

class ProfileController extends Controller
{
    /**
     * Get user profile
     */
    public function getProfile()
    {
        $user = Auth::user();
        return response()->json([
            "success" => true,
            "data" => $user
        ]);
    }

    /**
     * Update user profile
     */
    public function updateProfile(Request $request)
    {
        try {
            $user = Auth::user();
            $validated = $request->validate([
                "first_name" => "required|string|max:255",
                "last_name"  => "required|string|max:255",
                "phone"      => "nullable|string|max:20",
            ]);

            $user->update($validated);

            // Registrar actividad
            ActivityLog::record(
                "Perfil actualizado",
                "El usuario " . $user->email . " actualizó su información personal.",
                "profile",
                ["user_id" => $user->id],
                $user->id
            );

            return response()->json([
                "success" => true,
                "message" => "Profile updated successfully",
                "data" => $user
            ]);
        } catch (\Exception $e) {
            return response()->json([
                "success" => false,
                "message" => "Error updating profile",
                "error" => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update user avatar
     */
    public function updateAvatar(Request $request)
    {
        try {
            $request->validate([
                'avatar' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048',
            ]);

            $user = Auth::user();

            if ($request->hasFile('avatar')) {
                // Delete old avatar if exists
                if ($user->avatar_url) {
                    Storage::disk('public')->delete($user->avatar_url);
                }

                $path = $request->file('avatar')->store('avatars', 'public');
                $user->update(['avatar_url' => $path]);

                // Registrar actividad
                ActivityLog::record(
                    "Avatar actualizado",
                    "El usuario " . $user->email . " actualizó su foto de perfil.",
                    "profile",
                    ["user_id" => $user->id, "avatar_path" => $path],
                    $user->id
                );

                return response()->json([
                    "success" => true,
                    "message" => "Avatar updated successfully",
                    "avatar_url" => $user->avatar_full_url
                ]);
            }

            return response()->json([
                "success" => false,
                "message" => "No avatar file provided",
            ], 400);

        } catch (\Throwable $e) {
            return response()->json([
                "success" => false,
                "message" => "Error al actualizar el avatar",
                "error" => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Listar sesiones del usuario autenticado.
     */
    public function getSessions(Request $request)
    {
        try {
            $user = Auth::user();
            $currentDeviceToken = $request->cookie('device_token');
            $currentTokenId = null;

            if ($tokenString = $request->bearerToken()) {
                $pat = PersonalAccessToken::findToken($tokenString);
                if ($pat && (int) $pat->tokenable_id === (int) $user->getKey()) {
                    $currentTokenId = $pat->id;
                }
            }

            $perPage = $request->query('per_page', 15);
            
            // Obtenemos las sesiones únicas por device_token o por combinación de IP + UserAgent si no hay token
            // Pero como el middleware asegura device_token, confiaremos en él.
            // Para evitar duplicados de IP que menciona el usuario, agruparemos por device_token
            // y tomaremos la actividad más reciente.
            
            $sessionsQuery = UserSession::where("user_id", $user->id)
                ->whereIn('id', function($query) {
                    $query->selectRaw('MAX(id)')
                        ->from('user_sessions')
                        ->groupBy('device_token');
                })
                ->orderByDesc("last_activity");

            $sessions = $sessionsQuery->paginate($perPage);

            $sessions->through(function (UserSession $s) use ($currentDeviceToken, $currentTokenId) {
                $isCurrent = false;

                if (!is_null($s->sanctum_token_id) && $currentTokenId && $s->sanctum_token_id === $currentTokenId) {
                    $isCurrent = true;
                }
             
                if (!is_null($s->device_token) && $currentDeviceToken && $s->device_token === $currentDeviceToken) {
                    $isCurrent = true;
                }

                // Verificar si la sesión sigue "activa" (actividad en las últimas 24 horas)
                $isActive = $s->last_activity && $s->last_activity->gt(now()->subDay());

                return [
                    "uuid"          => $s->uuid,
                    "ip_address"    => $s->ip_address,
                    "user_agent"    => $s->user_agent,
                    "location"      => $this->composeLocation($s),
                    "login_at"      => $s->login_at,
                    "last_activity" => $s->last_activity,
                    "logout_at"     => $s->logout_at,
                    "is_current"    => $isCurrent,
                    "is_active"     => $isActive,
                    "device"        => $s->device,
                    "platform"      => $s->platform,
                    "browser"       => $s->browser,
                    "created_at"    => $s->created_at,
                ];
            });

            return response()->json([
                "success" => true,
                "data"    => $sessions,
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                "success" => false,
                "message" => "Error al obtener las sesiones",
                "error"   => $e->getMessage()
            ], 500);
        }
    }

    private function composeLocation(UserSession $s): ?string
    {
        if ($s->city || $s->region || $s->country) {
            $parts = array_filter([$s->city, $s->region, $s->country]);
            return implode(", ", $parts);
        }
        return null;
    }

    /**
     * Revoca y elimina una sesión de dispositivo específica.
     */
    public function revokeSession(string $uuid)
    {
        try {
            $user = Auth::user();
            $session = UserSession::where('uuid', $uuid)
                ->where('user_id', $user->id)
                ->firstOrFail();

            if ($session->sanctum_token_id) {
                $patModel = config('sanctum.personal_access_token_model', \Laravel\Sanctum\PersonalAccessToken::class);
                $patModel::where('id', $session->sanctum_token_id)->delete();
            }

            $session->delete();

            ActivityLog::record(
                'Sesión de dispositivo revocada',
                "El usuario {$user->email} ha revocado la sesión de {$session->browser} en {$session->platform}.",
                'security',
                ['user_id' => $user->id, 'revoked_session_uuid' => $uuid, 'ip_address' => $session->ip_address],
                $user->id
            );

            return response()->json([
                'success' => true,
                'message' => 'La sesión del dispositivo ha sido revocada con éxito.',
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Sesión no encontrada o no tienes permiso para revocarla.',
            ], 404);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al revocar la sesión.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Revoca todas las sesiones excepto la actual.
     */
    public function revokeOtherSessions(Request $request)
    {
        try {
            $user = Auth::user();
            $currentDeviceToken = $request->cookie('device_token');

            if (!$currentDeviceToken) {
                return response()->json([
                    'success' => false,
                    'message' => 'No se pudo identificar la sesión actual.',
                ], 400);
            }

            // Obtener IDs de tokens de Sanctum a revocar
            $tokenIdsToRevoke = UserSession::where('user_id', $user->id)
                ->where('device_token', '!=', $currentDeviceToken)
                ->whereNotNull('sanctum_token_id')
                ->pluck('sanctum_token_id');

            if ($tokenIdsToRevoke->isNotEmpty()) {
                $patModel = config('sanctum.personal_access_token_model', \Laravel\Sanctum\PersonalAccessToken::class);
                $patModel::whereIn('id', $tokenIdsToRevoke)->delete();
            }

            $revokedCount = UserSession::where('user_id', $user->id)
                ->where('device_token', '!=', $currentDeviceToken)
                ->delete();

            ActivityLog::record(
                'Otras sesiones revocadas',
                "El usuario {$user->email} ha revocado {$revokedCount} sesión(es) en otros dispositivos.",
                'security',
                ['user_id' => $user->id, 'kept_device_token' => $currentDeviceToken],
                $user->id
            );

            return response()->json([
                'success' => true,
                'message' => "Se han cerrado {$revokedCount} sesión(es) en otros dispositivos.",
                'revoked_count' => $revokedCount,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'No fue posible revocar las otras sesiones en este momento.',
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
                "current_password" => "required|string",
                "password"         => "required|string|min:8|confirmed",
            ]);

            if (!Hash::check($request->current_password, $user->password)) {
                return response()->json([
                    "success" => false,
                    "message" => "La contraseña actual es incorrecta"
                ], 400);
            }

            $user->update([
                "password" => Hash::make($request->password)
            ]);

            // Registrar actividad
            ActivityLog::record(
                "Contraseña actualizada",
                "El usuario " . $user->email . " cambió su contraseña.",
                "security",
                ["user_id" => $user->id],
                $user->id
            );

            return response()->json([
                "success" => true,
                "message" => "Password updated successfully"
            ]);
        } catch (\Exception $e) {
            return response()->json([
                "success" => false,
                "message" => "Error updating password",
                "error" => $e->getMessage()
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
                "email"    => "required|email|unique:users,email," . $user->id,
                "password" => "required|string",
            ]);

            if (!Hash::check($request->password, $user->password)) {
                return response()->json([
                    "success" => false,
                    "message" => "La contraseña es incorrecta"
                ], 400);
            }

            $oldEmail = $user->email;
            $user->update([
                "email" => $request->email,
                "email_verified_at" => null // Requiere re-verificación
            ]);

            // Registrar actividad
            ActivityLog::record(
                "Email actualizado",
                "El usuario cambió su email de " . $oldEmail . " a " . $request->email,
                "security",
                ["user_id" => $user->id, "old_email" => $oldEmail, "new_email" => $request->email],
                $user->id
            );

            return response()->json([
                "success" => true,
                "message" => "Email updated successfully. Please verify your new email address."
            ]);
        } catch (\Exception $e) {
            return response()->json([
                "success" => false,
                "message" => "Error updating email",
                "error" => $e->getMessage()
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
                "password" => "required|string",
                "confirmation" => "required|string|in:DELETE"
            ]);

            if (!Hash::check($request->password, $user->password)) {
                return response()->json([
                    "success" => false,
                    "message" => "Password is incorrect"
                ], 400);
            }

            if (in_array($user->role, ["super_admin", "admin"])) {
                return response()->json([
                    "success" => false,
                    "message" => "Administrator accounts cannot be deleted"
                ], 403);
            }

            $user->delete();

            ActivityLog::record(
                "Eliminación de cuenta",
                "El usuario " . $user->email . " ha eliminado su cuenta.",
                "security",
                ["user_id" => $user->id],
                $user->id
            );

            return response()->json([
                "success" => true,
                "message" => "Account deleted successfully"
            ]);
        } catch (\Exception $e) {
            return response()->json([
                "success" => false,
                "message" => "Error deleting account",
                "error" => $e->getMessage()
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
                "password_last_changed" => $user->updated_at,
                "two_factor_enabled"    => $user->two_factor_enabled,
                "email_verified"        => !is_null($user->email_verified_at),
                "last_login"            => $user->last_login_at,
                "account_status"        => $user->status,
                "is_google_account"     => $user->is_google_account,
                "login_attempts_today"  => 0,
                "security_score"        => $this->calculateSecurityScore($user),
            ];

            return response()->json([
                "success" => true,
                "data" => $overview
            ]);
        } catch (\Exception $e) {
            return response()->json([
                "success" => false,
                "message" => "Error fetching security overview",
                "error" => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Calculate security score based on user settings.
     */
    private function calculateSecurityScore($user): int
    {
        $score = 20; // base
        if ($user->email_verified_at) $score += 20;
        if ($user->two_factor_enabled) $score += 30;
        if ($user->is_google_account) {
            $score += 15;
        } elseif ($user->updated_at->diffInDays() < 90) {
            $score += 15;
        }
        if ($user->last_login_at && $user->last_login_at->diffInDays() < 7) {
            $score += 15;
        }
        return min($score, 100);
    }
}
