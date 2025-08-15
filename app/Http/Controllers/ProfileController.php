<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class ProfileController extends Controller
{
    /**
     * Get user profile information
     */
    public function getProfile()
    {
        try {
            $user = Auth::user();
            
            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $user->id,
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
     * Get user sessions (login history)
     */
    public function getSessions()
    {
        try {
            $user = Auth::user();
            
            // For now, return mock data. In production, you'd track actual sessions
            $sessions = [
                [
                    'id' => 1,
                    'ip_address' => '192.168.1.100',
                    'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                    'location' => 'Mexico City, Mexico',
                    'last_activity' => now()->subMinutes(5),
                    'is_current' => true
                ],
                [
                    'id' => 2,
                    'ip_address' => '192.168.1.101',
                    'user_agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 15_0 like Mac OS X)',
                    'location' => 'Guadalajara, Mexico',
                    'last_activity' => now()->subHours(2),
                    'is_current' => false
                ]
            ];

            return response()->json([
                'success' => true,
                'data' => $sessions
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error fetching sessions',
                'error' => $e->getMessage()
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

