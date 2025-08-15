<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class AdminController extends Controller
{
    /**
     * Get admin dashboard statistics
     */
    public function getDashboardStats()
    {
        try {
            $stats = [
                'users' => [
                    'total' => User::count(),
                    'active' => User::where('status', 'active')->count(),
                    'pending' => User::where('status', 'pending_verification')->count(),
                    'suspended' => User::where('status', 'suspended')->count(),
                ],
                'services' => [
                    'total' => Service::count(),
                    'active' => Service::where('status', 'active')->count(),
                    'suspended' => Service::where('status', 'suspended')->count(),
                    'maintenance' => Service::where('status', 'maintenance')->count(),
                ],
                'revenue' => [
                    'monthly' => 15750.00, // This would come from actual calculations
                    'yearly' => 189000.00,
                    'currency' => 'USD'
                ]
            ];

            return response()->json([
                'success' => true,
                'data' => $stats
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error fetching dashboard statistics',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get all users with pagination
     */
    public function getUsers(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 15);
            $search = $request->get('search');

            $query = User::query();

            if ($search) {
                $query->where(function($q) use ($search) {
                    $q->where('first_name', 'like', "%{$search}%")
                      ->orWhere('last_name', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%");
                });
            }

            $users = $query->orderBy('created_at', 'desc')->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $users
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error fetching users',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create a new user
     */
    public function createUser(Request $request)
    {
        try {
            $validated = $request->validate([
                'first_name' => 'required|string|max:100',
                'last_name' => 'required|string|max:100',
                'email' => 'required|email|unique:users,email',
                'password' => 'required|string|min:8',
                'role' => ['required', Rule::in(['admin', 'support', 'client'])],
                'status' => ['required', Rule::in(['active', 'suspended', 'pending_verification'])],
                'phone' => 'nullable|string|max:20',
                'address' => 'nullable|string',
                'city' => 'nullable|string|max:100',
                'state' => 'nullable|string|max:100',
                'country' => 'nullable|string|size:2',
                'postal_code' => 'nullable|string|max:20',
            ]);

            $validated['password'] = Hash::make($validated['password']);
            $validated['uuid'] = \Str::uuid();

            $user = User::create($validated);

            return response()->json([
                'success' => true,
                'message' => 'User created successfully',
                'data' => $user
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error creating user',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update user
     */
    public function updateUser(Request $request, $id)
    {
        try {
            $user = User::findOrFail($id);

            $validated = $request->validate([
                'first_name' => 'sometimes|string|max:100',
                'last_name' => 'sometimes|string|max:100',
                'email' => ['sometimes', 'email', Rule::unique('users')->ignore($user->id)],
                'password' => 'sometimes|string|min:8',
                'role' => ['sometimes', Rule::in(['admin', 'support', 'client'])],
                'status' => ['sometimes', Rule::in(['active', 'suspended', 'pending_verification', 'banned'])],
                'phone' => 'nullable|string|max:20',
                'address' => 'nullable|string',
                'city' => 'nullable|string|max:100',
                'state' => 'nullable|string|max:100',
                'country' => 'nullable|string|size:2',
                'postal_code' => 'nullable|string|max:20',
            ]);

            if (isset($validated['password'])) {
                $validated['password'] = Hash::make($validated['password']);
            }

            $user->update($validated);

            return response()->json([
                'success' => true,
                'message' => 'User updated successfully',
                'data' => $user->fresh()
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error updating user',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete user (soft delete)
     */
    public function deleteUser($id)
    {
        try {
            $user = User::findOrFail($id);
            
            // Prevent deletion of super_admin users
            if ($user->role === 'super_admin') {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete super admin users'
                ], 403);
            }

            $user->delete();

            return response()->json([
                'success' => true,
                'message' => 'User deleted successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error deleting user',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get all services with pagination
     */
    public function getServices(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 15);
            $search = $request->get('search');

            $query = Service::with(['user', 'product']);

            if ($search) {
                $query->where('name', 'like', "%{$search}%");
            }

            $services = $query->orderBy('created_at', 'desc')->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $services
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error fetching services',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}

