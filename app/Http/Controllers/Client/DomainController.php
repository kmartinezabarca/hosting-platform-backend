<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;

use App\Models\Domain;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class DomainController extends Controller
{
    /**
     * Get user's domains
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            $query = Domain::where('user_id', $user->id);

            // Filter by status if provided
            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            $domains = $query->orderBy('created_at', 'desc')
                           ->paginate($request->get('per_page', 15));

            return response()->json([
                'success' => true,
                'data' => $domains
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving domains',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get a specific domain
     */
    public function show(string $uuid): JsonResponse
    {
        try {
            $user = Auth::user();
            $domain = Domain::where('uuid', $uuid)
                          ->where('user_id', $user->id)
                          ->first();

            if (!$domain) {
                return response()->json([
                    'success' => false,
                    'message' => 'Domain not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $domain
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving domain',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Register a new domain
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'domain_name' => 'required|string|max:255',
                'registration_period' => 'required|integer|min:1|max:10',
                'auto_renew' => 'boolean',
                'privacy_protection' => 'boolean',
                'nameservers' => 'nullable|array',
                'nameservers.*' => 'string'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $user = Auth::user();

            // Check if domain is available (mock implementation)
            $isAvailable = $this->checkDomainAvailability($request->domain_name);
            
            if (!$isAvailable) {
                return response()->json([
                    'success' => false,
                    'message' => 'Domain is not available for registration'
                ], 400);
            }

            // Calculate expiry date
            $expiryDate = now()->addYears($request->registration_period);

            // Create domain
            $domain = Domain::create([
                'user_id' => $user->id,
                'domain_name' => strtolower($request->domain_name),
                'registration_date' => now(),
                'expiry_date' => $expiryDate,
                'auto_renew' => $request->get('auto_renew', false),
                'privacy_protection' => $request->get('privacy_protection', false),
                'nameservers' => $request->get('nameservers', []),
                'status' => 'pending'
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Domain registration initiated successfully',
                'data' => $domain
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error registering domain',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update domain settings
     */
    public function update(Request $request, string $uuid): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'auto_renew' => 'boolean',
                'privacy_protection' => 'boolean',
                'nameservers' => 'nullable|array',
                'nameservers.*' => 'string'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $user = Auth::user();
            $domain = Domain::where('uuid', $uuid)
                          ->where('user_id', $user->id)
                          ->first();

            if (!$domain) {
                return response()->json([
                    'success' => false,
                    'message' => 'Domain not found'
                ], 404);
            }

            $domain->update($request->only(['auto_renew', 'privacy_protection', 'nameservers']));

            return response()->json([
                'success' => true,
                'message' => 'Domain updated successfully',
                'data' => $domain
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error updating domain',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Renew a domain
     */
    public function renew(Request $request, string $uuid): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'renewal_period' => 'required|integer|min:1|max:10'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $user = Auth::user();
            $domain = Domain::where('uuid', $uuid)
                          ->where('user_id', $user->id)
                          ->first();

            if (!$domain) {
                return response()->json([
                    'success' => false,
                    'message' => 'Domain not found'
                ], 404);
            }

            // Extend expiry date
            $newExpiryDate = $domain->expiry_date->addYears($request->renewal_period);
            $domain->update(['expiry_date' => $newExpiryDate]);

            return response()->json([
                'success' => true,
                'message' => 'Domain renewed successfully',
                'data' => $domain
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error renewing domain',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Check domain availability
     */
    public function checkAvailability(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'domain_name' => 'required|string|max:255'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $isAvailable = $this->checkDomainAvailability($request->domain_name);

            return response()->json([
                'success' => true,
                'data' => [
                    'domain_name' => $request->domain_name,
                    'available' => $isAvailable,
                    'price' => $isAvailable ? $this->getDomainPrice($request->domain_name) : null
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error checking domain availability',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get domain statistics
     */
    public function getStats(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            
            $stats = [
                'total_domains' => Domain::where('user_id', $user->id)->count(),
                'active_domains' => Domain::where('user_id', $user->id)->where('status', 'active')->count(),
                'pending_domains' => Domain::where('user_id', $user->id)->where('status', 'pending')->count(),
                'expired_domains' => Domain::where('user_id', $user->id)->where('status', 'expired')->count(),
                'expiring_soon' => Domain::where('user_id', $user->id)
                                       ->where('status', 'active')
                                       ->where('expiry_date', '<=', now()->addDays(30))
                                       ->count()
            ];

            return response()->json([
                'success' => true,
                'data' => $stats
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving domain statistics',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Check if domain is available (mock implementation)
     */
    private function checkDomainAvailability(string $domainName): bool
    {
        // Mock implementation - in real scenario, this would call domain registrar API
        // For now, we'll just check if domain already exists in our database
        return !Domain::where('domain_name', strtolower($domainName))->exists();
    }

    /**
     * Get domain price (mock implementation)
     */
    private function getDomainPrice(string $domainName): float
    {
        // Mock implementation - in real scenario, this would get price from registrar API
        $extension = substr($domainName, strrpos($domainName, '.'));
        
        return match($extension) {
            '.com', '.net', '.org' => 12.99,
            '.info', '.biz' => 9.99,
            '.io' => 39.99,
            '.co' => 24.99,
            default => 15.99
        };
    }
}

