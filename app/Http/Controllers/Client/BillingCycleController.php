<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;

use App\Models\BillingCycle;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class BillingCycleController extends Controller
{
    /**
     * Get all active billing cycles
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $billingCycles = BillingCycle::active()
                                       ->orderBy('sort_order')
                                       ->orderBy('months')
                                       ->get();

            return response()->json([
                'success' => true,
                'data' => $billingCycles
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving billing cycles',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get a specific billing cycle by UUID
     */
    public function show(string $uuid): JsonResponse
    {
        try {
            $billingCycle = BillingCycle::where('uuid', $uuid)->first();

            if (!$billingCycle) {
                return response()->json([
                    'success' => false,
                    'message' => 'Billing cycle not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $billingCycle
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving billing cycle',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create a new billing cycle (Admin only)
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'slug' => 'required|string|max:50|unique:billing_cycles',
                'name' => 'required|string|max:100',
                'months' => 'required|integer|min:1|max:60',
                'discount_percentage' => 'nullable|numeric|min:0|max:100',
                'is_active' => 'boolean',
                'sort_order' => 'nullable|integer'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $billingCycle = BillingCycle::create($request->all());

            return response()->json([
                'success' => true,
                'message' => 'Billing cycle created successfully',
                'data' => $billingCycle
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error creating billing cycle',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update a billing cycle (Admin only)
     */
    public function update(Request $request, string $uuid): JsonResponse
    {
        try {
            $billingCycle = BillingCycle::where('uuid', $uuid)->first();

            if (!$billingCycle) {
                return response()->json([
                    'success' => false,
                    'message' => 'Billing cycle not found'
                ], 404);
            }

            $validator = Validator::make($request->all(), [
                'slug' => 'sometimes|required|string|max:50|unique:billing_cycles,slug,' . $billingCycle->id,
                'name' => 'sometimes|required|string|max:100',
                'months' => 'sometimes|required|integer|min:1|max:60',
                'discount_percentage' => 'nullable|numeric|min:0|max:100',
                'is_active' => 'boolean',
                'sort_order' => 'nullable|integer'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $billingCycle->update($request->all());

            return response()->json([
                'success' => true,
                'message' => 'Billing cycle updated successfully',
                'data' => $billingCycle
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error updating billing cycle',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete a billing cycle (Admin only)
     */
    public function destroy(string $uuid): JsonResponse
    {
        try {
            $billingCycle = BillingCycle::where('uuid', $uuid)->first();

            if (!$billingCycle) {
                return response()->json([
                    'success' => false,
                    'message' => 'Billing cycle not found'
                ], 404);
            }

            // Check if billing cycle has plan pricing
            if ($billingCycle->planPricing()->count() > 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete billing cycle with existing plan pricing'
                ], 400);
            }

            $billingCycle->delete();

            return response()->json([
                'success' => true,
                'message' => 'Billing cycle deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error deleting billing cycle',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}

