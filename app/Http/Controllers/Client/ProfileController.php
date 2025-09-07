<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;

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
use App\Models\ActivityLog; // Importar el modelo ActivityLog

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
            $activeServices = Service::where("user_id", $user->id)->active()->count();

            $avatarFull = $user->avatar_url
                ? asset("storage/" . $user->avatar_url)
                : null;

            return response()->json([
                "success" => true,
                "data" => [
                    "uuid" => $user->uuid,
                    "email" => $user->email,
                    "first_name" => $user->first_name,
                    "last_name" => $user->last_name,
                    "phone" => $user->phone,
                    "address" => $user->address,
                    "city" => $user->city,
                    "state" => $user->state,
                    "country" => $user->country,
                    "postal_code" => $user->postal_code,
                    "role" => $user->role,
                    "status" => $user->status,
                    "two_factor_enabled" => $user->two_factor_enabled,
                    "email_verified_at" => $user->email_verified_at,
                    "last_login_at" => $user->last_login_at,
                    "created_at" => $user->created_at,
                    "avatar_url"     => $avatarFull,
                    "years_with_us"  => $yearsWithUs,
                    "active_services" => $activeServices
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                "success" => false,
                "message" => "Error fetching profile",
                "error" => $e->getMessage()
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
                "first_name" => "sometimes|string|max:100",
                "last_name" => "sometimes|string|max:100",
                "phone" => "nullable|string|max:20",
                "address" => "nullable|string|max:500",
                "city" => "nullable|string|max:100",
                "state" => "nullable|string|max:100",
                "country" => "nullable|string|size:2",
                "postal_code" => "nullable|string|max:20",
            ]);

            $user->update($validated);

            // Registrar actividad de actualización de perfil
            ActivityLog::record(
                "Actualización de perfil",
                "El usuario " . $user->email . " ha actualizado su perfil.",
                "profile",
                ["user_id" => $user->id, "changes" => $validated],
                $user->id
            );

            return response()->json([
                "success" => true,
                "message" => "Profile updated successfully",
                "data" => $user->fresh()
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
     * Update user email
     */
    public function updateEmail(Request $request)
    {
        try {
            $user = Auth::user();

            $validated = $request->validate([
                "email" => ["required", "email", Rule::unique("users")->ignore($user->id)],
                "password" => "required|string"
            ]);

            // Verify current password
            if (!Hash::check($request->password, $user->password)) {
                return response()->json([
                    "success" => false,
                    "message" => "Current password is incorrect"
                ], 400);
            }

            $oldEmail = $user->email;
            $user->update([
                "email" => $validated["email"],
                "email_verified_at" => null // Reset email verification
            ]);

            // Registrar actividad de cambio de email
            ActivityLog::record(
                "Cambio de correo electrónico",
                "El usuario " . $oldEmail . " ha cambiado su correo a " . $user->email . ".",
                "profile",
                ["user_id" => $user->id, "old_email" => $oldEmail, "new_email" => $user->email],
                $user->id
            );

            return response()->json([
                "success" => true,
                "message" => "Email updated successfully. Please verify your new email address.",
                "data" => $user->fresh()
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
     * Update user password
     */
    public function updatePassword(Request $request)
    {
        try {
            $user = Auth::user();

            $validated = $request->validate([
                "current_password" => "required|string",
                "new_password" => "required|string|min:8|confirmed",
            ]);

            // Verify current password
            if (!Hash::check($request->current_password, $user->password)) {
                return response()->json([
                    "success" => false,
                    "message" => "Current password is incorrect"
                ], 400);
            }

            // Update password
            $user->update([
                "password" => Hash::make($validated["new_password"])
            ]);

            // Registrar actividad de cambio de contraseña
            ActivityLog::record(
                "Cambio de contraseña",
                "El usuario " . $user->email . " ha cambiado su contraseña.",
                "profile",
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
     * Update the user's avatar
     */
    public function updateAvatar(Request $request)
    {
        try {
            $user = Auth::user();

            $request->validate([
                "avatar" => ["required", "image", "mimes:jpg,jpeg,png,gif,svg", "max:2048"],
            ]);

            $path = $request->file("avatar")->store("avatars", "public");

            if ($user->avatar_url) {
                Storage::disk("public")->delete($user->avatar_url);
            }

            $user->avatar_url = $path;
            $user->save();

            $publicUrl = asset("storage/" . $path);

            // Registrar actividad de actualización de avatar
            ActivityLog::record(
                "Actualización de avatar",
                "El usuario " . $user->email . " ha actualizado su avatar.",
                "profile",
                ["user_id" => $user->id, "avatar_url" => $publicUrl],
                $user->id
            );

            return response()->json([
                "success" => true,
                "message" => "Avatar actualizado correctamente",
                "data" => [
                    "avatar_url" => $publicUrl,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                "success" => false,
                "message" => "Error al actualizar el avatar",
                "error" => $e->getMessage(),
            ], 500);
        }
    }
    /**
     * Get user sessions (login history)
     */
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
            $sessions = UserSession::where("user_id", $user->id)
                ->orderByDesc("last_activity")
                ->paginate($perPage);

            $sessions->through(function (UserSession $s) use ($currentDeviceToken, $currentTokenId) {
                $isCurrent = false;

    
                if (!is_null($s->sanctum_token_id) && $currentTokenId && $s->sanctum_token_id === $currentTokenId) {
                    $isCurrent = true;
                }
             
                if (!is_null($s->device_token) && $currentDeviceToken && $s->device_token === $currentDeviceToken) {
                    $isCurrent = true;
                }

                return [
                    "uuid"          => $s->uuid,
                    "ip_address"    => $s->ip_address,
                    "user_agent"    => $s->user_agent,
                    "location"      => $this->composeLocation($s),
                    "login_at"      => $s->login_at,
                    "last_activity" => $s->last_activity,
                    "logout_at"     => $s->logout_at,
                    "is_current"    => $isCurrent,
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
                "error"   => $e->getMessage(),
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
     *
     * @param  string  $uuid El UUID de la sesión a revocar.
     * @return \Illuminate\Http\JsonResponse
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
                'message' => 'No fue posible revocar la sesión en este momento.',
            ], 500);
        }
    }

    /**
     * Revoca todas las sesiones de dispositivo del usuario excepto la actual.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function revokeOtherSessions(Request $request)
    {
        try {
            $user = Auth::user();
            $currentDeviceToken = $request->cookie('device_token');

            // 1. Si por alguna razón no hay un token de dispositivo actual, no podemos proceder.
            if (!$currentDeviceToken) {
                return response()->json([
                    'success' => false,
                    'message' => 'No se pudo identificar la sesión actual.',
                ], 400);
            }

            // 2. Eliminar todas las sesiones del usuario que NO coincidan con el token actual.
            $revokedCount = UserSession::where('user_id', $user->id)
                ->where('device_token', '!=', $currentDeviceToken)
                ->delete();

            // (Opcional) También puedes querer eliminar los tokens de Sanctum que no estén asociados a ninguna sesión restante.
            // Esta lógica puede ser más compleja y depende de tus necesidades.

            // 3. Registrar la actividad.
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
            // Log::error('Error al revocar otras sesiones: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'No fue posible revocar las otras sesiones en este momento.',
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

            // Verify password
            if (!Hash::check($request->password, $user->password)) {
                return response()->json([
                    "success" => false,
                    "message" => "Password is incorrect"
                ], 400);
            }

            // Prevent deletion of admin users
            if (in_array($user->role, ["super_admin", "admin"])) {
                return response()->json([
                    "success" => false,
                    "message" => "Administrator accounts cannot be deleted"
                ], 403);
            }

            // Soft delete the user
            $user->delete();

            // Registrar actividad de eliminación de cuenta
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
                "password_last_changed" => $user->updated_at, // Approximate
                "two_factor_enabled" => $user->two_factor_enabled,
                "email_verified" => !is_null($user->email_verified_at),
                "last_login" => $user->last_login_at,
                "account_status" => $user->status,
                "login_attempts_today" => 0, // Would track in production
                "security_score" => $this->calculateSecurityScore($user)
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


