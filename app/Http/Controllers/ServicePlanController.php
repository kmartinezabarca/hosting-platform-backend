<?php

namespace App\Http\Controllers;

use App\Models\ServicePlan;
use App\Models\PlanFeature;
use App\Models\PlanPricing;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ServicePlanController extends Controller
{
    /**
     * Get all active service plans
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = ServicePlan::active()->with(['category', 'features', 'pricing.billingCycle']);

            // Filter by category if provided
            if ($request->has('category_id')) {
                $query->where('category_id', $request->category_id);
            }

            $servicePlans = $query->orderBy('sort_order')
                                ->orderBy('name')
                                ->get();

            return response()->json([
                'success' => true,
                'data' => $servicePlans
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving service plans',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get service plans by category slug
     */
    public function getByCategorySlug(string $categorySlug): JsonResponse
    {
        try {
            $servicePlans = ServicePlan::active()
                                     ->whereHas('category', function ($query) use ($categorySlug) {
                                         $query->where('slug', $categorySlug);
                                     })
                                     ->with(['category', 'features', 'pricing.billingCycle'])
                                     ->orderBy('sort_order')
                                     ->orderBy('name')
                                     ->get();

            return response()->json([
                'success' => true,
                'data' => $servicePlans
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving service plans',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get a specific service plan by UUID
     */
    public function show(string $uuid): JsonResponse
    {
        try {
            $servicePlan = ServicePlan::where('uuid', $uuid)
                                    ->with(['category', 'features', 'pricing.billingCycle'])
                                    ->first();

            if (!$servicePlan) {
                return response()->json([
                    'success' => false,
                    'message' => 'Service plan not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $servicePlan
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving service plan',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create a new service plan (Admin only)
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'category_id' => 'required|exists:categories,id',
                'slug' => 'required|string|max:100|unique:service_plans',
                'name' => 'required|string|max:200',
                'description' => 'nullable|string',
                'base_price' => 'required|numeric|min:0',
                'setup_fee' => 'nullable|numeric|min:0',
                'is_popular' => 'boolean',
                'is_active' => 'boolean',
                'sort_order' => 'nullable|integer',
                'specifications' => 'nullable|array',
                'features' => 'nullable|array',
                'features.*' => 'string|max:500',
                'pricing' => 'nullable|array',
                'pricing.*.billing_cycle_id' => 'required|exists:billing_cycles,id',
                'pricing.*.price' => 'required|numeric|min:0'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            DB::beginTransaction();

            // Create service plan
            $servicePlan = ServicePlan::create($request->only([
                'category_id', 'slug', 'name', 'description', 'base_price', 
                'setup_fee', 'is_popular', 'is_active', 'sort_order', 'specifications'
            ]));

            // Create features if provided
            if ($request->has('features')) {
                foreach ($request->features as $index => $feature) {
                    PlanFeature::create([
                        'service_plan_id' => $servicePlan->id,
                        'feature' => $feature,
                        'sort_order' => $index
                    ]);
                }
            }

            // Create pricing if provided
            if ($request->has('pricing')) {
                foreach ($request->pricing as $pricing) {
                    PlanPricing::create([
                        'service_plan_id' => $servicePlan->id,
                        'billing_cycle_id' => $pricing['billing_cycle_id'],
                        'price' => $pricing['price']
                    ]);
                }
            }

            DB::commit();

            $servicePlan->load(['category', 'features', 'pricing.billingCycle']);

            return response()->json([
                'success' => true,
                'message' => 'Service plan created successfully',
                'data' => $servicePlan
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error creating service plan',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update a service plan (Admin only)
     */
    public function update(Request $request, string $uuid): JsonResponse
    {
        try {
            $servicePlan = ServicePlan::where('uuid', $uuid)->first();

            if (!$servicePlan) {
                return response()->json([
                    'success' => false,
                    'message' => 'Service plan not found'
                ], 404);
            }

            $validator = Validator::make($request->all(), [
                'category_id' => 'sometimes|required|exists:categories,id',
                'slug' => 'sometimes|required|string|max:100|unique:service_plans,slug,' . $servicePlan->id,
                'name' => 'sometimes|required|string|max:200',
                'description' => 'nullable|string',
                'base_price' => 'sometimes|required|numeric|min:0',
                'setup_fee' => 'nullable|numeric|min:0',
                'is_popular' => 'boolean',
                'is_active' => 'boolean',
                'sort_order' => 'nullable|integer',
                'specifications' => 'nullable|array',
                'features' => 'nullable|array',
                'features.*' => 'string|max:500',
                'pricing' => 'nullable|array',
                'pricing.*.billing_cycle_id' => 'required|exists:billing_cycles,id',
                'pricing.*.price' => 'required|numeric|min:0'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            DB::beginTransaction();

            // Update service plan
            $servicePlan->update($request->only([
                'category_id', 'slug', 'name', 'description', 'base_price', 
                'setup_fee', 'is_popular', 'is_active', 'sort_order', 'specifications'
            ]));

            // Update features if provided
            if ($request->has('features')) {
                $servicePlan->features()->delete();
                foreach ($request->features as $index => $feature) {
                    PlanFeature::create([
                        'service_plan_id' => $servicePlan->id,
                        'feature' => $feature,
                        'sort_order' => $index
                    ]);
                }
            }

            // Update pricing if provided
            if ($request->has('pricing')) {
                $servicePlan->pricing()->delete();
                foreach ($request->pricing as $pricing) {
                    PlanPricing::create([
                        'service_plan_id' => $servicePlan->id,
                        'billing_cycle_id' => $pricing['billing_cycle_id'],
                        'price' => $pricing['price']
                    ]);
                }
            }

            DB::commit();

            $servicePlan->load(['category', 'features', 'pricing.billingCycle']);

            return response()->json([
                'success' => true,
                'message' => 'Service plan updated successfully',
                'data' => $servicePlan
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error updating service plan',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete a service plan (Admin only)
     */
    public function destroy(string $uuid): JsonResponse
    {
        try {
            $servicePlan = ServicePlan::where('uuid', $uuid)->first();

            if (!$servicePlan) {
                return response()->json([
                    'success' => false,
                    'message' => 'Service plan not found'
                ], 404);
            }

            // Check if service plan has active services
            if ($servicePlan->services()->count() > 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete service plan with existing services'
                ], 400);
            }

            $servicePlan->delete();

            return response()->json([
                'success' => true,
                'message' => 'Service plan deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error deleting service plan',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}

